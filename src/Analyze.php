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
    public function renderOutput(OutputInterface $output, array $coupling): void
    {
        $table = new Table($output);
        $table->setHeaders(['File', 'File', 'Changes']);

        foreach ($coupling as $filesCouple) {
            $table->addRow([
                $filesCouple['files'][0],
                $filesCouple['files'][1],
                $filesCouple['changes'],
            ]);
        }

        $table->render();
    }

    public function computeCoupling(Analyzer $analyzer, mixed $since, mixed $until, int $threshold): array
    {
        $coupling = $analyzer->computeCoupling($since, $until);

        $uniques = [];
        foreach ($coupling as $filesCouple) {
            $keyA = $filesCouple['files'][0].$filesCouple['files'][1];
            $keyB = $filesCouple['files'][1].$filesCouple['files'][0];
            if (isset($uniques[$keyA]) || isset($uniques[$keyB])) {
                continue;
            }
            $uniques[$keyA] = $filesCouple;
        }
        $coupling = array_values($uniques);

        if ($threshold > 0) {
            $coupling = array_filter($coupling, fn ($c) => $c['changes'] > $threshold);
        }

        usort($coupling, fn ($a, $b) => $b['changes'] <=> $a['changes']);

        return $coupling;
    }

    public function getParams(InputInterface $input): array
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

        return [$repoPath, $since, $until, $threshold];
    }

    protected function configure(): void
    {
        $this->setName('analyze')
            ->addArgument('path', InputArgument::REQUIRED)
            ->addOption('since', 's', InputOption::VALUE_OPTIONAL, default: null)
            ->addOption('until', 'u', InputOption::VALUE_OPTIONAL, default: null)
            ->addOption('threshold', 't', InputOption::VALUE_OPTIONAL, default: 0);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        [$repoPath, $since, $until, $threshold] = $this->getParams($input);

        $git = new Git($repoPath);
        $analyzer = new Analyzer($git);

        $coupling = $this->computeCoupling($analyzer, $since, $until, $threshold);

        $this->renderOutput($output, $coupling);

        return Command::SUCCESS;
    }
}
