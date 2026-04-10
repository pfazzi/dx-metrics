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
class Analyze extends Command
{
    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<options=bold>Volatility Coupling Analysis</>');
        $output->writeln('');
        $output->writeln('Counts how often pairs of files are modified in the same commit.');
        $output->writeln('A high <comment>Co-changes Count</comment> means that changing one file almost always requires changing the other — a hidden dependency that increases coordination cost and regression risk.');
        $output->writeln('Pairs with strong coupling across module or team boundaries are prime candidates for decoupling or explicit interface extraction.');
        $output->writeln('');

        [$repoPath, $since, $until, $coChangesThreshold, $pathFilter, $excludePatterns, $outputDir] = $this->getParams($input);

        $git = new Git($repoPath);
        $analyzer = new Analyzer($git);

        $analysisOutput = $analyzer->analyze($since, $until)
            ->filterByExcludedPatterns($excludePatterns)
            ->filterByPath($pathFilter)
            ->filterByCoChangesThreshold($coChangesThreshold)
            ->getUniqueFilePairs()
            ->sortByCoChangesDesc();

        $this->printAnalysisOutput($output, $analysisOutput);

        $this->plotAnalysisOutput($outputDir, $analysisOutput);

        return Command::SUCCESS;
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('analyze')
            ->setDescription('Find files that change together across commits (volatility coupling)')
            ->addArgument('path', InputArgument::REQUIRED)
            ->addOption('since', 's', InputOption::VALUE_OPTIONAL, default: null)
            ->addOption('until', 'u', InputOption::VALUE_OPTIONAL, default: null)
            ->addOption('threshold', 't', InputOption::VALUE_OPTIONAL, default: 0)
            ->addOption('filter', 'f', InputOption::VALUE_OPTIONAL, default: null)
            ->addOption('exclude', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, default: [])
            ->addOption('output-dir', 'o', InputOption::VALUE_OPTIONAL, default: null);
    }

    private function printAnalysisOutput(OutputInterface $output, AnalysisOutput $analysisOutput): void
    {
        $table = new Table($output);
        $table->setHeaders(['Path A', 'Path B', 'Co-changes Count']);

        foreach ($analysisOutput->items as $item) {
            $table->addRow([
                $item->pathA,
                $item->pathB,
                $item->coChangeCount,
            ]);
        }

        $table->render();
    }

    private function getParams(InputInterface $input): array
    {
        $repoPath = $input->getArgument('path');
        $config = DxMetricsConfig::fromRepoPath($repoPath);

        $since = $input->getOption('since') ?? $config->get('since');
        if ($since) {
            $since = new \DateTimeImmutable($since);
        }
        $until = $input->getOption('until') ?? $config->get('until');
        if ($until) {
            $until = new \DateTimeImmutable($until);
        }
        $threshold = (int) $input->getOption('threshold');

        $filter = $input->getOption('filter') ?? $config->get('filter');

        $excludePatterns = [] !== $input->getOption('exclude') ? $input->getOption('exclude') : ($config->get('exclude') ?? []);

        $outputDir = $input->getOption('output-dir') ?? (string) getcwd();

        return [$repoPath, $since, $until, $threshold, $filter, $excludePatterns, $outputDir];
    }

    private function plotAnalysisOutput(string $outputDir, AnalysisOutput $analysisOutput): void
    {
        if ([] === $analysisOutput->items) {
            return;
        }

        $min = $analysisOutput->items[array_key_last($analysisOutput->items)]->coChangeCount;

        $dot = "graph G {\n"
            ."  graph [overlap=false, splines=true];\n"
            ."  node [shape=box, fontsize=10];\n";

        foreach ($analysisOutput->items as $item) {
            $a = addslashes($item->pathA);
            $b = addslashes($item->pathB);
            $w = $item->coChangeCount;
            $pen = $w * 2 / $min;
            $dot .= \sprintf(
                "  \"%s\" -- \"%s\" [penwidth=%d, label=\"%d\"];\n",
                $a,
                $b,
                $pen,
                $w
            );
        }
        $dot .= "}\n";

        $dotFile = $outputDir.'/coupling.dot';
        $pngFile = $outputDir.'/coupling.png';

        file_put_contents($dotFile, $dot);

        exec('dot -Tpng '.escapeshellarg($dotFile).' -o '.escapeshellarg($pngFile));
    }
}
