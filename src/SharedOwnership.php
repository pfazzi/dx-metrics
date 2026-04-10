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
class SharedOwnership extends Command
{
    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<options=bold>Shared Ownership Analysis</>');
        $output->writeln('');
        $output->writeln('Lists files touched by more than one team, sorted by ownership entropy.');
        $output->writeln('<comment>Dominant Team</comment> is the team with the highest number of commits on a file, shown with its percentage of total commits.');
        $output->writeln('<comment>Entropy</comment> (0–1) measures ownership concentration: 0 means one team made all the commits (clear ownership), 1 means every team contributed equally (no one really owns it).');
        $output->writeln('A high-entropy file has no dominant owner — changes are likely uncoordinated, increasing the risk of conflicts and unexpected regressions.');
        $output->writeln('');

        $repoPath = $input->getArgument('path');
        $teamsFile = $input->getOption('teams');
        $pathFilter = $input->getOption('filter');
        $minTeams = (int) $input->getOption('min-teams');

        $since = $input->getOption('since');
        if ($since) {
            $since = new \DateTimeImmutable($since);
        }
        $until = $input->getOption('until');
        if ($until) {
            $until = new \DateTimeImmutable($until);
        }

        if (null === $teamsFile) {
            $output->writeln('<error>Option --teams is required.</error>');

            return Command::FAILURE;
        }

        $teamConfig = TeamConfig::fromFile($teamsFile);
        $git = new Git($repoPath);
        $analyzer = new SharedOwnershipAnalyzer($git, $teamConfig);

        $result = $analyzer->analyze($since, $until)
            ->filterByPath($pathFilter)
            ->filterByMinTeams($minTeams)
            ->sortByEntropyDesc();

        if ([] === $result->items) {
            $output->writeln('No files with shared ownership found.');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['File', 'Teams', 'Dominant Team', 'Entropy']);

        foreach ($result->items as $item) {
            $table->addRow([
                $item->filePath,
                $item->teamCount,
                \sprintf('%s (%.0f%%)', $item->dominantTeam, $item->dominantTeamPercentage),
                number_format($item->ownershipEntropy, 2),
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('shared-ownership')
            ->setDescription('List files by shared ownership across teams')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the git repository')
            ->addOption('teams', 'T', InputOption::VALUE_REQUIRED, 'Path to teams JSON config file')
            ->addOption('since', 's', InputOption::VALUE_OPTIONAL, 'Include commits after this date (e.g. 2024-01-01)', null)
            ->addOption('until', 'u', InputOption::VALUE_OPTIONAL, 'Include commits before this date (e.g. 2024-12-31)', null)
            ->addOption('filter', 'f', InputOption::VALUE_OPTIONAL, 'Only show files matching this path prefix', null)
            ->addOption('min-teams', 'm', InputOption::VALUE_OPTIONAL, 'Minimum number of teams to include a file (default: 2)', 2);
    }
}
