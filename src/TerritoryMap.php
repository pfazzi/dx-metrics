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
    /** @var list<string> */
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
        $output->writeln('Groups files into modules by path depth and shows who owns each module and how tightly modules are coupled by commit history.');
        $output->writeln('<comment>Dominant Team</comment> is the team with the most commits across all files in a module, shown with its percentage of total module commits.');
        $output->writeln('<comment>Entropy</comment> (0–1) measures ownership concentration within the module: 0 means one team made all the commits, 1 means every team contributed equally.');
        $output->writeln('<comment>Coupling</comment> between modules is the number of commits that touched files in both modules — the higher the number, the more coordinated those teams must be.');
        $output->writeln('The generated image colors each module by its dominant team so you can spot Conway\'s Law violations at a glance: coupling between differently-coloured modules means two teams share implicit coordination cost.');
        $output->writeln('');

        $teamsFile = $input->getOption('teams');
        if (null === $teamsFile) {
            $output->writeln('<error>The --teams option is required.</error>');

            return Command::FAILURE;
        }

        $repoPath = $input->getArgument('path');
        $depth = (int) $input->getOption('depth');
        $outputDir = $input->getOption('output-dir') ?? (string) getcwd();
        $excludePatterns = $input->getOption('exclude');

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

        $result = $analyzer->analyze($depth, $since, $until, $excludePatterns);

        if ([] === $result->modules) {
            $output->writeln('No data found.');

            return Command::SUCCESS;
        }

        $teamColors = $this->assignTeamColors($result->modules);

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

        $this->generateDot($outputDir, $result, $teamColors);

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
            ->setDescription('Visual map of modules colored by dominant team with volatility coupling edges')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the git repository')
            ->addOption('teams', 'T', InputOption::VALUE_REQUIRED, 'Path to teams JSON config file')
            ->addOption('depth', 'd', InputOption::VALUE_OPTIONAL, 'Number of path segments that define a module (e.g. 2 = src/Domain)', 2)
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
    private function assignTeamColors(array $modules): array
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

    /** @param array<string, string> $teamColors */
    private function generateDot(string $outputDir, TerritoryMapOutput $result, array $teamColors): void
    {
        $dot = "graph G {\n";
        $dot .= "  graph [overlap=false, splines=true];\n";
        $dot .= "  node [shape=box, fontsize=11, style=filled];\n";
        $dot .= "\n";

        // Legend subgraph
        $dot .= "  subgraph cluster_legend {\n";
        $dot .= "    label=\"Teams\"; style=filled; fillcolor=\"#F8F9FA\";\n";
        foreach ($teamColors as $team => $color) {
            $dot .= \sprintf("    \"%s\" [label=\"%s\", fillcolor=\"%s\"];\n", addslashes('_legend_'.$team), addslashes($team), $color);
        }
        $dot .= "  }\n\n";

        // Module nodes
        foreach ($result->modules as $module) {
            $color = $teamColors[$module->dominantTeam] ?? '#EEEEEE';
            $label = \sprintf(
                '%s\\n%s (%d%%)\\nentropy: %.2f',
                addslashes($module->name),
                addslashes($module->dominantTeam),
                $module->dominantTeamPercentage,
                $module->ownershipEntropy,
            );
            $dot .= \sprintf(
                "  \"%s\" [fillcolor=\"%s\", label=\"%s\"];\n",
                addslashes($module->name),
                $color,
                $label,
            );
        }

        // Coupling edges
        if ([] !== $result->edges) {
            $maxCoChanges = max(array_map(static fn (TerritoryMapEdge $e) => $e->coChanges, $result->edges));
            $dot .= "\n";
            foreach ($result->edges as $edge) {
                $penwidth = max(1, (int) round($edge->coChanges * 5 / $maxCoChanges));
                $dot .= \sprintf(
                    "  \"%s\" -- \"%s\" [penwidth=%d, label=\"%d\"];\n",
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
        exec('dot -Tpng '.escapeshellarg($dotFile).' -o '.escapeshellarg($pngFile));
    }
}
