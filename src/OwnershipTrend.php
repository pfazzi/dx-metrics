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
final class OwnershipTrend extends Command
{
    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<options=bold>Ownership Entropy Trend</>');
        $output->writeln('');
        $output->writeln('Tracks how ownership entropy evolves over time for each module.');
        $output->writeln('A rising entropy means the module is being claimed by more teams over time.');
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
        $minEntropy = (float) $input->getOption('min-entropy');

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
        $analyzer = new OwnershipTrendAnalyzer($git, $teamConfig);

        $periods = $analyzer->analyze($windows, $depth, $excludePatterns, $filter);

        // Collect all modules that appeared in any period
        $allModules = [];
        foreach ($periods as $period) {
            foreach (array_keys($period->moduleEntropies) as $module) {
                $allModules[$module] = true;
            }
        }
        ksort($allModules);
        $allModules = array_keys($allModules);

        // Filter by min-entropy threshold
        $filteredModules = array_filter($allModules, static function (string $module) use ($periods, $minEntropy): bool {
            $maxEntropy = 0.0;
            foreach ($periods as $period) {
                $entropy = $period->moduleEntropies[$module] ?? 0.0;
                if ($entropy > $maxEntropy) {
                    $maxEntropy = $entropy;
                }
            }

            return $maxEntropy >= $minEntropy;
        });

        if ([] === $filteredModules) {
            $output->writeln('No modules found above the entropy threshold.');

            return Command::SUCCESS;
        }

        foreach ($filteredModules as $module) {
            $output->writeln(\sprintf('<comment>Module: %s</comment>', $module));

            $table = new Table($output);
            $table->setHeaders(['Period', 'Entropy', 'Dominant Team', 'Dominance', 'Trend']);

            $prevEntropy = null;
            foreach ($periods as $period) {
                $entropy = $period->moduleEntropies[$module] ?? null;
                $dominantTeam = $period->moduleDominantTeams[$module] ?? '—';
                $dominancePct = $period->moduleDominantPcts[$module] ?? null;

                $table->addRow([
                    $period->periodLabel(),
                    null !== $entropy ? number_format($entropy, 3) : '—',
                    $dominantTeam,
                    null !== $dominancePct ? number_format($dominancePct, 1).'%' : '—',
                    null !== $entropy ? $this->trend($prevEntropy, $entropy) : '',
                ]);

                if (null !== $entropy) {
                    $prevEntropy = $entropy;
                }
            }

            $table->render();
            $output->writeln('');
        }

        $output->writeln('Entropy legend: 0 = single owner (healthy) | 1 = perfectly contested (needs ownership conversation)');
        $output->writeln('A rising trend means the module is being claimed by more teams over time.');

        return Command::SUCCESS;
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('ownership:trend')
            ->setDescription('Track ownership entropy per module over time to spot gradual territory drift')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the git repository')
            ->addOption('teams', 'T', InputOption::VALUE_REQUIRED, 'Path to teams JSON config file')
            ->addOption('depth', 'd', InputOption::VALUE_OPTIONAL, 'Number of path segments that define a module', 2)
            ->addOption('filter', 'f', InputOption::VALUE_OPTIONAL, 'Only include files matching this path prefix (e.g. src/)', default: null)
            ->addOption('since', 's', InputOption::VALUE_OPTIONAL, 'Start date (default: 6 months ago)', null)
            ->addOption('until', 'u', InputOption::VALUE_OPTIONAL, 'End date (default: today)', null)
            ->addOption('period', 'p', InputOption::VALUE_OPTIONAL, 'Window size: Nd (days), Nw (weeks), Nm (months) — e.g. 4w', '4w')
            ->addOption('exclude', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, default: [])
            ->addOption('min-entropy', null, InputOption::VALUE_OPTIONAL, 'Only show modules where max entropy across periods exceeds this threshold', 0.0);
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
            $windows[] = ['from' => $cursor, 'to' => $end];
            $cursor = $end;
        }

        return $windows;
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
