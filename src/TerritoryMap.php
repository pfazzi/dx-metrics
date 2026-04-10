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
    /** @var list<string> Node fill colors, one per team (sorted alphabetically) */
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

    /** @var list<string> Lighter background for the cluster box, paired with COLORS */
    private const array CLUSTER_COLORS = [
        '#EBF5FB', // light blue
        '#EAFAF1', // light green
        '#FEF9E7', // light orange
        '#FDEDEC', // light red
        '#F5EEF8', // light purple
        '#FEFDF0', // light yellow
        '#E8F8F5', // light teal
        '#FEF5EC', // light peach
    ];

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<options=bold>Territory Map</>');
        $output->writeln('');
        $output->writeln('Groups files into modules by path depth and shows who owns each module and how tightly modules are coupled by commit history.');
        $output->writeln('<comment>Dominant Team</comment> is the team with the most commits across all files in a module, shown with its percentage of total module commits.');
        $output->writeln('<comment>Entropy</comment> (0–1) measures ownership concentration within the module: 0 means one team made all the commits, 1 means every team contributed equally.');
        $output->writeln('<comment>Coupling</comment> between modules is the number of commits that touched files in both modules — the higher the number, the more coordinated those teams must be.');
        $output->writeln('The generated image groups modules by dominant team. Edges between boxes are Conway\'s Law violations: two teams coordinating implicitly through shared code.');
        $output->writeln('');

        $teamsFile = $input->getOption('teams');
        if (null === $teamsFile) {
            $output->writeln('<error>The --teams option is required.</error>');

            return Command::FAILURE;
        }

        $repoPath = $input->getArgument('path');
        $depth = (int) $input->getOption('depth');
        $minCoupling = (int) $input->getOption('min-coupling');
        $outputDir = $input->getOption('output-dir') ?? (string) getcwd();
        $excludePatterns = $input->getOption('exclude');
        $filter = $input->getOption('filter');

        $since = $input->getOption('since');
        if ($since) {
            $since = new \DateTimeImmutable($since);
        }
        $until = $input->getOption('until');
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

        [$teamColors, $clusterColors] = $this->assignColors($result->modules);

        $table = new Table($output);
        $table->setHeaders(['Module', 'Dominant Team', 'Entropy', 'Commits', 'Teams']);
        foreach ($result->modules as $module) {
            $table->addRow([
                $module->name,
                \sprintf('%s (%d%%)', $module->dominantTeam, $module->dominantTeamPercentage),
                number_format($module->ownershipEntropy, 2),
                $module->totalCommits,
                $module->teamCount,
            ]);
        }
        $table->render();

        $this->generateDot($outputDir, $result, $teamColors, $clusterColors, $minCoupling);

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
            ->setDescription('Visual map of modules grouped by team with cross-team volatility coupling edges')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the git repository')
            ->addOption('teams', 'T', InputOption::VALUE_REQUIRED, 'Path to teams JSON config file')
            ->addOption('depth', 'd', InputOption::VALUE_OPTIONAL, 'Number of path segments that define a module (e.g. 2 = src/Domain)', 2)
            ->addOption('filter', 'f', InputOption::VALUE_OPTIONAL, 'Only include files matching this path prefix (e.g. src/)', default: null)
            ->addOption('min-coupling', 'c', InputOption::VALUE_OPTIONAL, 'Minimum cross-team co-change count to draw an edge (default: 0)', 0)
            ->addOption('since', 's', InputOption::VALUE_OPTIONAL, default: null)
            ->addOption('until', 'u', InputOption::VALUE_OPTIONAL, default: null)
            ->addOption('exclude', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, default: [])
            ->addOption('output-dir', 'o', InputOption::VALUE_OPTIONAL, default: null);
    }

    /**
     * @param TerritoryMapModule[] $modules
     *
     * @return array{array<string, string>, array<string, string>} [teamColors, clusterColors] both keyed by team name
     */
    private function assignColors(array $modules): array
    {
        $teams = [];
        foreach ($modules as $module) {
            $teams[$module->dominantTeam] = true;
        }
        $teams = array_keys($teams);
        sort($teams);

        $teamColors = [];
        $clusterColors = [];
        foreach ($teams as $i => $team) {
            $idx = $i % \count(self::COLORS);
            $teamColors[$team] = self::COLORS[$idx];
            $clusterColors[$team] = self::CLUSTER_COLORS[$idx];
        }

        return [$teamColors, $clusterColors];
    }

    /**
     * @param array<string, string> $teamColors
     * @param array<string, string> $clusterColors
     */
    private function generateDot(
        string $outputDir,
        TerritoryMapOutput $result,
        array $teamColors,
        array $clusterColors,
        int $minCoupling,
    ): void {
        // Index modules by name for team lookup
        $moduleTeamIndex = [];
        foreach ($result->modules as $module) {
            $moduleTeamIndex[$module->name] = $module->dominantTeam;
        }

        // Group modules by dominant team
        /** @var array<string, TerritoryMapModule[]> $teamModules */
        $teamModules = [];
        foreach ($result->modules as $module) {
            $teamModules[$module->dominantTeam][] = $module;
        }
        ksort($teamModules);

        $dot = "graph G {\n";
        $dot .= "  graph [overlap=false, splines=true, pad=0.5];\n";
        $dot .= "  node [shape=box, fontsize=11, style=filled];\n";
        $dot .= "\n";

        // One cluster subgraph per team — nodes inside are that team's modules
        foreach ($teamModules as $team => $modules) {
            $nodeColor = $teamColors[$team] ?? '#EEEEEE';
            $bgColor = $clusterColors[$team] ?? '#F8F9FA';
            $clusterId = preg_replace('/[^a-zA-Z0-9]/', '_', $team);

            $dot .= \sprintf("  subgraph cluster_%s {\n", $clusterId);
            $dot .= \sprintf(
                "    label=\"%s\"; style=filled; fillcolor=\"%s\"; color=\"%s\"; fontsize=13; penwidth=2;\n",
                addslashes($team),
                $bgColor,
                $nodeColor,
            );

            foreach ($modules as $module) {
                // Label shows module name + entropy + commits (team is implicit from cluster)
                $label = \sprintf(
                    '%s\\nentropy: %.2f  |  %d commits',
                    addslashes($module->name),
                    $module->ownershipEntropy,
                    $module->totalCommits,
                );
                $dot .= \sprintf(
                    "    \"%s\" [fillcolor=\"%s\", label=\"%s\"];\n",
                    addslashes($module->name),
                    $nodeColor,
                    $label,
                );
            }

            $dot .= "  }\n\n";
        }

        // Cross-team edges only, filtered by min-coupling threshold
        $crossTeamEdges = array_filter(
            $result->edges,
            static function (TerritoryMapEdge $edge) use ($moduleTeamIndex, $minCoupling): bool {
                if ($edge->coChanges < $minCoupling) {
                    return false;
                }
                $teamA = $moduleTeamIndex[$edge->moduleA] ?? null;
                $teamB = $moduleTeamIndex[$edge->moduleB] ?? null;

                return null !== $teamA && null !== $teamB && $teamA !== $teamB;
            },
        );

        if ([] !== $crossTeamEdges) {
            $maxCoChanges = max(array_map(static fn (TerritoryMapEdge $e) => $e->coChanges, array_values($crossTeamEdges)));
            foreach ($crossTeamEdges as $edge) {
                $penwidth = max(1, (int) round($edge->coChanges * 5 / $maxCoChanges));
                $dot .= \sprintf(
                    "  \"%s\" -- \"%s\" [penwidth=%d, label=\"%d\", color=\"#CC0000\", fontcolor=\"#CC0000\"];\n",
                    addslashes($edge->moduleA),
                    addslashes($edge->moduleB),
                    $penwidth,
                    $edge->coChanges,
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
