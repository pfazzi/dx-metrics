<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Analyze extends Command
{
    private function renderOutput(OutputInterface $output, array $coupling): void
    {
        $table = new Table($output);
        $table->setHeaders(['File', 'File', 'Changes', 'Distance', 'Coupling', 'Cohesion']);

        foreach ($coupling as $filesCouple) {
            $table->addRow([
                $filesCouple['files'][0],
                $filesCouple['files'][1],
                $filesCouple['changes'],
                $filesCouple['distance'],
                number_format($filesCouple['coupling'], 3),
                number_format($filesCouple['cohesion'], 3),
            ]);
        }

        $table->render();
    }

    private function computeCoupling(Analyzer $analyzer, ?\DateTimeImmutable $since, ?\DateTimeImmutable $until, int $threshold, ?string $filter): array
    {
        $coupling = $analyzer->computeCoupling($since, $until);

        // Filter unique pairs
        $uniques = [];
        foreach ($coupling as $filesCouple) {
            if (!str_starts_with($filesCouple['files'][0], $filter)
            || !str_starts_with($filesCouple['files'][1], $filter)) {
                continue;
            }

            $keyA = $filesCouple['files'][0].$filesCouple['files'][1];
            $keyB = $filesCouple['files'][1].$filesCouple['files'][0];
            if (isset($uniques[$keyA]) || isset($uniques[$keyB])) {
                continue;
            }
            $uniques[$keyA] = $filesCouple;
        }
        $coupling = array_values($uniques);

        // Filter by threshold
        if ($threshold > 0) {
            $coupling = array_filter($coupling, fn ($c) => $c['changes'] > $threshold);
        }

        // Sort by changes
        usort($coupling, fn ($a, $b) => $b['changes'] <=> $a['changes']);

        $this->computeCouplingAndCohesion($coupling);

        return $coupling;
    }

    private function getParams(InputInterface $input): array
    {
        $repoPath = $input->getArgument('path');
        $since = $input->getOption('since');
        if ($since) {
            $since = new \DateTimeImmutable($since);
        }
        $until = $input->getOption('until');
        if ($until) {
            $until = new \DateTimeImmutable($until);
        }
        $threshold = (int) $input->getOption('threshold');

        $filter = $input->getOption('filter');

        return [$repoPath, $since, $until, $threshold, $filter];
    }

    protected function configure(): void
    {
        $this->setName('analyze')
            ->addArgument('path', InputArgument::REQUIRED)
            ->addOption('since', 's', InputOption::VALUE_OPTIONAL, default: null)
            ->addOption('until', 'u', InputOption::VALUE_OPTIONAL, default: null)
            ->addOption('threshold', 't', InputOption::VALUE_OPTIONAL, default: 0)
            ->addOption('filter', 'f', InputOption::VALUE_OPTIONAL, default: null);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        [$repoPath, $since, $until, $threshold, $filter] = $this->getParams($input);

        $git = new Git($repoPath);
        $analyzer = new Analyzer($git);

        $coupling = $this->computeCoupling($analyzer, $since, $until, $threshold, $filter);

        $this->renderOutput($output, $coupling);

        $this->plotOutput($coupling);

        return Command::SUCCESS;
    }

    private function plotOutput(array $coupling): void
    {
        $min = $coupling[array_key_last($coupling)]['changes'];

        $dot = "graph G {\n"
            ."  graph [overlap=false, splines=true];\n"
            ."  node [shape=box, fontsize=10];\n";

        foreach ($coupling as $p) {
            $a = addslashes($p['files'][0]);
            $b = addslashes($p['files'][1]);
            $w = $p['changes'];
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

        file_put_contents('coupling.dot', $dot);

        exec('dot -Tpng coupling.dot -o coupling.png');
    }

    public function pathDistance(string $a, string $b): float
    {
        $as = array_values(array_filter(explode('/', $a)));
        $bs = array_values(array_filter(explode('/', $b)));
        $lca = 0;
        $n = min(\count($as), \count($bs));
        for ($i = 0; $i < $n; ++$i) {
            if ($as[$i] !== $bs[$i]) {
                break;
            }
            ++$lca;
        }

        return (\count($as) - $lca) + (\count($bs) - $lca);
    }

    private function computeCouplingAndCohesion(array &$coupling): void
    {
        $maxChanges = max(array_column($coupling, 'changes')) ?: 1;

        foreach ($coupling as &$files) {
            $files['distance'] = $this->pathDistance(
                $files['files'][0],
                $files['files'][1]
            );
        }

        $maxDistance = max(array_column($coupling, 'distance')) ?: 1;

        foreach ($coupling as &$files) {
            $files['cohesion'] = ($files['changes'] / $maxChanges) * (1 - $files['distance'] / $maxDistance);
            $files['coupling'] = ($files['changes'] / $maxChanges) * ($files['distance'] / $maxDistance);
        }
    }
}
