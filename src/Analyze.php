<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Analyze extends Command
{
    protected function configure(): void
    {
        $this->setName('analyze')
            ->addArgument('path', InputArgument::REQUIRED);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoPath = $input->getArgument('path');

        $git = new Git($repoPath);
        $analyzer = new Analyzer($git);

        $output->writeln('<info>Analyze</info>');
        $analyzer->computeCoupling();

        return Command::SUCCESS;
    }
}
