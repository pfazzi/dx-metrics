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
final class TerritoryMap extends Command
{
    /** @var list<string> One colour per team, assigned in sorted team name order */
    private const array COLORS = [
        '#AED6F1', // blue
        '#A9DFBF', // green
        '#FAD7A0', // orange
        '#F1948A', // red
        '#D7BDE2', // purple
        '#F9E79F', // yellow
        '#A3E4D7', // teal
        '#F5CBA7', // peach
    ];

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<options=bold>Territory Map</>');
        $output->writeln('');
        $output->writeln('Shows one node per team. Node size reflects commit volume; edge thickness reflects cross-team volatility coupling (commits that touched both teams\' code).');
        $output->writeln('<comment>Avg Entropy</comment> is the average ownership entropy across all modules a team dominates: a high value means the team\'s own territory is contested by other teams.');
        $output->writeln('<comment>Coupling</comment> on an edge is the total number of co-changed commit pairs between the two teams\' modules — the higher the number, the more implicit coordination is required.');
        $output->writeln('A thick edge between two teams is a Conway\'s Law violation: they share code changes without a shared owner.');
        $output->writeln('Use the module table below to drill into which specific modules drive the coupling.');
        $output->writeln('');

        $repoPath = $input->getArgument('path');
        $config = DxMetricsConfig::fromRepoPath($repoPath);

        $teamsFile = $input->getOption('teams') ?? $config->get('teams');
        if (null === $teamsFile) {
            $output->writeln('<error>The --teams option is required.</error>');

            return Command::FAILURE;
        }

        $depth = (int) ($input->getOption('depth') ?? $config->get('depth', 2));
        $minCoupling = (int) ($input->getOption('min-coupling') ?? $config->get('min-coupling', 0));
        $outputDir = $input->getOption('output-dir') ?? (string) getcwd();
        $excludePatterns = [] !== $input->getOption('exclude') ? $input->getOption('exclude') : ($config->get('exclude') ?? []);
        $filter = $input->getOption('filter') ?? $config->get('filter');

        $since = $input->getOption('since') ?? $config->get('since');
        if ($since) {
            $since = new \DateTimeImmutable($since);
        }
        $until = $input->getOption('until') ?? $config->get('until');
        if ($until) {
            $until = new \DateTimeImmutable($until);
        }

        $teamConfig = TeamConfig::fromFile($teamsFile);
        $git = new Git($repoPath);
        $analyzer = new TerritoryMapAnalyzer($git, $teamConfig);

        $result = $analyzer->analyze($depth, $since, $until, $excludePatterns, $filter);

        if ([] === $result->modules) {
            $output->writeln('No data found.');

            return Command::SUCCESS;
        }

        $teamColors = $this->assignColors($result->modules);

        // Team-level summary table
        [$teamStats, $teamEdges] = $this->aggregateToTeamLevel($result);

        $output->writeln('<options=bold>Team summary</>');
        $teamTable = new Table($output);
        $teamTable->setHeaders(['Team', 'Modules', 'Commits', 'Avg Entropy']);
        ksort($teamStats);
        foreach ($teamStats as $team => $stats) {
            $avgEntropy = $stats['modules'] > 0 ? $stats['entropy_sum'] / $stats['modules'] : 0.0;
            $teamTable->addRow([
                $team,
                $stats['modules'],
                $stats['commits'],
                number_format($avgEntropy, 2),
            ]);
        }
        $teamTable->render();

        // Module drill-down table
        $output->writeln('');
        $output->writeln('<options=bold>Module detail</>');
        $moduleTable = new Table($output);
        $moduleTable->setHeaders(['Module', 'Dominant Team', 'Entropy', 'Commits', 'Teams']);
        foreach ($result->modules as $module) {
            $moduleTable->addRow([
                $module->name,
                \sprintf('%s (%d%%)', $module->dominantTeam, $module->dominantTeamPercentage),
                number_format($module->ownershipEntropy, 2),
                $module->totalCommits,
                $module->teamCount,
            ]);
        }
        $moduleTable->render();

        $this->generateDot($outputDir, $teamStats, $teamEdges, $teamColors, $minCoupling);

        $dotFile = $outputDir.'/territory.dot';
        $pngFile = $outputDir.'/territory.png';
        $output->writeln('');
        $output->writeln(\sprintf('Written: <info>%s</info>', $dotFile));
        if (file_exists($pngFile)) {
            $output->writeln(\sprintf('Written: <info>%s</info>', $pngFile));
        }

        return Command::SUCCESS;
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('territory-map')
            ->setDescription('Visual map of teams with cross-team volatility coupling — one node per team')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the git repository')
            ->addOption('teams', 'T', InputOption::VALUE_REQUIRED, 'Path to teams JSON config file')
            ->addOption('depth', 'd', InputOption::VALUE_OPTIONAL, 'Number of path segments that define a module (e.g. 2 = src/Domain)', null)
            ->addOption('filter', 'f', InputOption::VALUE_OPTIONAL, 'Only include files matching this path prefix (e.g. src/)', default: null)
            ->addOption('min-coupling', 'c', InputOption::VALUE_OPTIONAL, 'Minimum cross-team co-change count to draw an edge (default: 0)', null)
            ->addOption('since', 's', InputOption::VALUE_OPTIONAL, default: null)
            ->addOption('until', 'u', InputOption::VALUE_OPTIONAL, default: null)
            ->addOption('exclude', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, default: [])
            ->addOption('output-dir', 'o', InputOption::VALUE_OPTIONAL, default: null);
    }

    /**
     * @param TerritoryMapModule[] $modules
     *
     * @return array<string, string> team => hex color
     */
    private function assignColors(array $modules): array
    {
        $teams = [];
        foreach ($modules as $module) {
            $teams[$module->dominantTeam] = true;
        }
        $teams = array_keys($teams);
        sort($teams);

        $colors = [];
        foreach ($teams as $i => $team) {
            $colors[$team] = self::COLORS[$i % \count(self::COLORS)];
        }

        return $colors;
    }

    /**
     * Aggregates module-level data up to team level.
     *
     * @return array{
     *   array<string, array{modules: int, commits: int, entropy_sum: float}>,
     *   array<string, int>
     * } [teamStats, teamEdges] where teamEdges keys are "teamA|||teamB"
     */
    private function aggregateToTeamLevel(TerritoryMapOutput $result): array
    {
        // Index module → dominant team
        $moduleTeam = [];
        foreach ($result->modules as $module) {
            $moduleTeam[$module->name] = $module->dominantTeam;
        }

        // Aggregate modules per team
        $teamStats = [];
        foreach ($result->modules as $module) {
            $team = $module->dominantTeam;
            $teamStats[$team]['modules'] = ($teamStats[$team]['modules'] ?? 0) + 1;
            $teamStats[$team]['commits'] = ($teamStats[$team]['commits'] ?? 0) + $module->totalCommits;
            $teamStats[$team]['entropy_sum'] = ($teamStats[$team]['entropy_sum'] ?? 0.0) + $module->ownershipEntropy;
        }

        // Aggregate cross-team edges to team-pair level (sum co-changes)
        $teamEdges = [];
        foreach ($result->edges as $edge) {
            $teamA = $moduleTeam[$edge->moduleA] ?? null;
            $teamB = $moduleTeam[$edge->moduleB] ?? null;
            if (null === $teamA || null === $teamB || $teamA === $teamB) {
                continue;
            }
            [$ta, $tb] = $teamA < $teamB ? [$teamA, $teamB] : [$teamB, $teamA];
            $key = $ta.'|||'.$tb;
            $teamEdges[$key] = ($teamEdges[$key] ?? 0) + $edge->coChanges;
        }

        return [$teamStats, $teamEdges];
    }

    /**
     * @param array<string, array{modules: int, commits: int, entropy_sum: float}> $teamStats
     * @param array<string, int>                                                   $teamEdges
     * @param array<string, string>                                                $teamColors
     */
    private function generateDot(
        string $outputDir,
        array $teamStats,
        array $teamEdges,
        array $teamColors,
        int $minCoupling,
    ): void {
        $dot = "graph G {\n";
        $dot .= "  graph [overlap=false, splines=true, pad=0.5];\n";
        $dot .= "  node [shape=box, fontsize=13, style=filled, margin=\"0.4,0.25\"];\n";
        $dot .= "\n";

        // One node per team
        $maxCommits = max(array_column($teamStats, 'commits'));
        ksort($teamStats);
        foreach ($teamStats as $team => $stats) {
            $color = $teamColors[$team] ?? '#EEEEEE';
            $avgEntropy = $stats['modules'] > 0 ? $stats['entropy_sum'] / $stats['modules'] : 0.0;
            // Scale node width by commit volume relative to max
            $width = max(1.5, round($stats['commits'] / $maxCommits * 4, 1));
            $label = \sprintf(
                '%s\\n%d modules  |  %d commits\\navg entropy: %.2f',
                addslashes($team),
                $stats['modules'],
                $stats['commits'],
                $avgEntropy,
            );
            $dot .= \sprintf(
                "  \"%s\" [fillcolor=\"%s\", label=\"%s\", width=%.1f, fixedsize=false];\n",
                addslashes($team),
                $color,
                $label,
                $width,
            );
        }

        // Team-pair edges above threshold
        $filteredEdges = array_filter($teamEdges, static fn (int $count): bool => $count >= $minCoupling);
        if ([] !== $filteredEdges) {
            $maxCount = max($filteredEdges);
            $dot .= "\n";
            arsort($filteredEdges);
            foreach ($filteredEdges as $key => $count) {
                [$ta, $tb] = explode('|||', $key, 2);
                $penwidth = max(1, (int) round($count * 8 / $maxCount));
                $dot .= \sprintf(
                    "  \"%s\" -- \"%s\" [penwidth=%d, label=\"%d\"];\n",
                    addslashes($ta),
                    addslashes($tb),
                    $penwidth,
                    $count,
                );
            }
        }

        $dot .= "}\n";

        $dotFile = $outputDir.'/territory.dot';
        $pngFile = $outputDir.'/territory.png';

        file_put_contents($dotFile, $dot);
        exec('fdp -Tpng '.escapeshellarg($dotFile).' -o '.escapeshellarg($pngFile));
    }
}
