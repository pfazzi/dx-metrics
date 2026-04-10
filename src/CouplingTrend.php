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
        $output->writeln('A <comment>co-change</comment> is counted every time two files are modified in the same commit. When those files belong to different teams it becomes a <comment>cross-team co-change</comment> — a signal that two teams had to touch the codebase together, even if no one explicitly coordinated it.');
        $output->writeln('<comment>Company Index</comment> = cross-team co-changes ÷ total co-changes. 0 means every change stayed inside one team\'s code; 1 means every co-change crossed a team boundary. A rising index means coordination cost is spreading across the company.');
        $output->writeln('<comment>Team Score</comment> is the same ratio scoped to a single team: what fraction of that team\'s co-change activity involved another team\'s code.');
        $output->writeln('The pair tables show the raw cross-team co-change count for each team pair over time — use them to trace which specific relationship is driving a company-level trend.');
        $output->writeln('');

        $repoPath = $input->getArgument('path');
        $config = DxMetricsConfig::fromRepoPath($repoPath);

        $teamsFile = $input->getOption('teams') ?? $config->get('teams');
        if (null === $teamsFile) {
            $output->writeln('<error>The --teams option is required.</error>');

            return Command::FAILURE;
        }

        $depth = (int) ($input->getOption('depth') ?? $config->get('depth', 2));
        $excludePatterns = [] !== $input->getOption('exclude') ? $input->getOption('exclude') : ($config->get('exclude') ?? []);
        $filter = $input->getOption('filter') ?? $config->get('filter');
        $periodStr = (string) ($input->getOption('period') ?? $config->get('period', '4w'));

        $since = $input->getOption('since') ?? $config->get('since');
        $since = $since ? new \DateTimeImmutable($since) : new \DateTimeImmutable('-6 months');

        $until = $input->getOption('until') ?? $config->get('until');
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
            ->addOption('period', 'p', InputOption::VALUE_OPTIONAL, 'Window size: Nd (days), Nw (weeks), Nm (months) — e.g. 4w', null)
            ->addOption('depth', 'd', InputOption::VALUE_OPTIONAL, 'Number of path segments that define a module', null)
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
        $table->setHeaders(['Period', 'Total Co-Changes', 'Cross-Team Co-Changes', 'Index', 'Trend']);

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

        // Collect all pairs, sort by total coupling descending (most coupled first)
        $pairTotals = [];
        foreach ($periods as $period) {
            foreach ($period->teamPairCoChanges as $key => $count) {
                $pairTotals[$key] = ($pairTotals[$key] ?? 0) + $count;
            }
        }
        arsort($pairTotals);
        $pairKeys = array_keys($pairTotals);

        if ([] === $pairKeys) {
            $output->writeln('No cross-team co-changes found in the selected range.');

            return;
        }

        // One small table per pair
        foreach ($pairKeys as $key) {
            $label = str_replace('|||', ' ↔ ', $key);
            $output->writeln('');
            $output->writeln(\sprintf('<comment>%s</comment>', $label));

            $table = new Table($output);
            $table->setHeaders(['Period', 'Co-Changes', 'Trend']);

            $prevCount = null;
            foreach ($periods as $period) {
                $count = $period->teamPairCoChanges[$key] ?? null;
                $table->addRow([
                    $period->periodLabel(),
                    null !== $count ? (string) $count : '—',
                    null !== $count ? $this->trend($prevCount, (float) $count) : '',
                ]);
                if (null !== $count) {
                    $prevCount = (float) $count;
                }
            }

            $table->render();
        }
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
