<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** @psalm-suppress UnusedClass */
final class CouplingTrend extends Command
{
    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<options=bold>Coupling Trend</>');
        $output->writeln('');
        $output->writeln('Tracks cross-team volatility coupling over time, divided into equal periods.');
        $output->writeln('<comment>Company Index</comment> is the fraction of all co-changes that crossed a team boundary: 0 = all changes are team-internal, 1 = every co-change involved two teams. A rising index means the overall coordination cost is growing.');
        $output->writeln('<comment>Team Score</comment> is the same ratio scoped to a single team: how much of its co-change activity spilled into other teams\' code.');
        $output->writeln('Use the pair table to identify which specific team relationship is driving a trend.');
        $output->writeln('');

        $teamsFile = $input->getOption('teams');
        if (null === $teamsFile) {
            $output->writeln('<error>The --teams option is required.</error>');

            return Command::FAILURE;
        }

        $repoPath = $input->getArgument('path');
        $depth = (int) $input->getOption('depth');
        $excludePatterns = $input->getOption('exclude');
        $filter = $input->getOption('filter');
        $periodStr = (string) $input->getOption('period');

        $since = $input->getOption('since');
        $since = $since ? new \DateTimeImmutable($since) : new \DateTimeImmutable('-6 months');

        $until = $input->getOption('until');
        $until = $until ? new \DateTimeImmutable($until) : new \DateTimeImmutable();

        try {
            $interval = $this->parsePeriod($periodStr);
        } catch (\InvalidArgumentException $e) {
            $output->writeln(\sprintf('<error>%s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        $windows = $this->buildWindows($since, $until, $interval);
        if ([] === $windows) {
            $output->writeln('<error>The date range is shorter than one period. Use a wider --since/--until range or a shorter --period.</error>');

            return Command::FAILURE;
        }

        $teamConfig = TeamConfig::fromFile($teamsFile);
        $git = new Git($repoPath);
        $analyzer = new CouplingTrendAnalyzer($git, $teamConfig);

        $periods = $analyzer->analyze($windows, $depth, $excludePatterns, $filter);

        $this->printCompanyTable($output, $periods);
        $output->writeln('');
        $this->printTeamTable($output, $periods);
        $output->writeln('');
        $this->printPairTable($output, $periods);

        return Command::SUCCESS;
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('coupling-trend')
            ->setDescription('Track cross-team coupling index over time at company, team, and pair level')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the git repository')
            ->addOption('teams', 'T', InputOption::VALUE_REQUIRED, 'Path to teams JSON config file')
            ->addOption('period', 'p', InputOption::VALUE_OPTIONAL, 'Window size: Nd (days), Nw (weeks), Nm (months) — e.g. 4w', '4w')
            ->addOption('depth', 'd', InputOption::VALUE_OPTIONAL, 'Number of path segments that define a module', 2)
            ->addOption('filter', 'f', InputOption::VALUE_OPTIONAL, 'Only include files matching this path prefix (e.g. src/)', default: null)
            ->addOption('since', 's', InputOption::VALUE_OPTIONAL, 'Start date (default: 6 months ago)', null)
            ->addOption('until', 'u', InputOption::VALUE_OPTIONAL, 'End date (default: today)', null)
            ->addOption('exclude', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, default: []);
    }

    private function parsePeriod(string $period): \DateInterval
    {
        if (1 === preg_match('/^(\d+)d$/', $period, $m)) {
            return new \DateInterval(\sprintf('P%dD', $m[1]));
        }
        if (1 === preg_match('/^(\d+)w$/', $period, $m)) {
            return new \DateInterval(\sprintf('P%dW', $m[1]));
        }
        if (1 === preg_match('/^(\d+)m$/', $period, $m)) {
            return new \DateInterval(\sprintf('P%dM', $m[1]));
        }

        throw new \InvalidArgumentException(\sprintf("Invalid period '%s'. Use Nd, Nw or Nm (e.g. 4w, 30d, 1m).", $period));
    }

    /**
     * @return array<array{from: \DateTimeImmutable, to: \DateTimeImmutable}>
     */
    private function buildWindows(\DateTimeImmutable $since, \DateTimeImmutable $until, \DateInterval $interval): array
    {
        $windows = [];
        $cursor = $since;

        while ($cursor < $until) {
            $end = $cursor->add($interval);
            if ($end > $until) {
                $end = $until;
            }
            // Skip windows shorter than half the interval (leftover tail)
            $windows[] = ['from' => $cursor, 'to' => $end];
            $cursor = $end;
        }

        return $windows;
    }

    /** @param CouplingTrendPeriod[] $periods */
    private function printCompanyTable(OutputInterface $output, array $periods): void
    {
        $output->writeln('<options=bold>Company coupling index</>');

        $table = new Table($output);
        $table->setHeaders(['Period', 'Total Co-Changes', 'Cross-Team', 'Index', 'Trend']);

        $prevIndex = null;
        foreach ($periods as $period) {
            $table->addRow([
                $period->periodLabel(),
                $period->totalCoChanges,
                $period->crossTeamCoChanges,
                number_format($period->companyCouplingIndex, 3),
                $this->trend($prevIndex, $period->companyCouplingIndex),
            ]);
            $prevIndex = $period->companyCouplingIndex;
        }

        $table->render();
    }

    /** @param CouplingTrendPeriod[] $periods */
    private function printTeamTable(OutputInterface $output, array $periods): void
    {
        $output->writeln('<options=bold>Team coupling score</>');

        // Collect all teams across all periods, sorted
        $teams = [];
        foreach ($periods as $period) {
            foreach (array_keys($period->teamCouplingScores) as $team) {
                $teams[$team] = true;
            }
        }
        $teams = array_keys($teams);
        sort($teams);

        $table = new Table($output);
        $table->setHeaders(['Period', ...$teams]);

        $prevScores = [];
        foreach ($periods as $period) {
            $row = [$period->periodLabel()];
            foreach ($teams as $team) {
                $score = $period->teamCouplingScores[$team] ?? null;
                $cell = null !== $score
                    ? number_format($score, 3).' '.$this->trend($prevScores[$team] ?? null, $score)
                    : '—';
                $row[] = $cell;
            }
            $table->addRow($row);
            $prevScores = $period->teamCouplingScores;
        }

        $table->render();
    }

    /** @param CouplingTrendPeriod[] $periods */
    private function printPairTable(OutputInterface $output, array $periods): void
    {
        $output->writeln('<options=bold>Team-pair coupling</>');

        // Collect all pairs across all periods, sorted
        $pairs = [];
        foreach ($periods as $period) {
            foreach (array_keys($period->teamPairCoChanges) as $key) {
                $pairs[$key] = true;
            }
        }
        $pairKeys = array_keys($pairs);
        sort($pairKeys);

        // Build human-readable pair labels
        $pairLabels = array_map(
            static fn (string $k) => str_replace('|||', ' ↔ ', $k),
            $pairKeys,
        );

        $table = new Table($output);
        $table->setHeaders(['Period', ...$pairLabels]);

        $prevCounts = [];
        foreach ($periods as $period) {
            $row = [$period->periodLabel()];
            foreach ($pairKeys as $key) {
                $count = $period->teamPairCoChanges[$key] ?? null;
                $cell = null !== $count
                    ? $count.' '.$this->trend($prevCounts[$key] ?? null, (float) $count)
                    : '—';
                $row[] = $cell;
            }
            $table->addRow($row);
            $prevCounts = $period->teamPairCoChanges;
        }

        $table->render();
    }

    private function trend(?float $prev, float $current): string
    {
        if (null === $prev || 0.0 === $prev) {
            return '';
        }

        $change = ($current - $prev) / $prev;

        if ($change > 0.20) {
            return '↑↑';
        }
        if ($change > 0.05) {
            return '↑';
        }
        if ($change < -0.20) {
            return '↓↓';
        }
        if ($change < -0.05) {
            return '↓';
        }

        return '→';
    }
}
