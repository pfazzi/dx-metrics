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
final class OwnershipHotspots extends Command
{
    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $teamsFile = $input->getOption('teams');
        if (null === $teamsFile) {
            $output->writeln('<error>The --teams option is required.</error>');

            return Command::FAILURE;
        }

        $repoPath = $input->getArgument('path');

        $since = $input->getOption('since');
        if ($since) {
            $since = new \DateTimeImmutable($since);
        }
        $until = $input->getOption('until');
        if ($until) {
            $until = new \DateTimeImmutable($until);
        }

        $filter = $input->getOption('filter');
        $minTeams = (int) $input->getOption('min-teams');

        $teamConfig = TeamConfig::fromFile($teamsFile);
        $git = new Git($repoPath);
        $analyzer = new SharedOwnershipAnalyzer($git, $teamConfig);

        $hotspots = OwnershipHotspotsOutput::fromSharedOwnershipOutput($analyzer->analyze($since, $until))
            ->filterByPath($filter)
            ->filterByMinTeams($minTeams)
            ->sortByRiskDesc();

        if ([] === $hotspots->items) {
            $output->writeln('No ownership hotspots found.');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['File', 'Commits', 'Teams', 'Dominant Team', 'Entropy', 'Risk Score']);

        foreach ($hotspots->items as $item) {
            $table->addRow([
                $item->filePath,
                $item->totalCommits,
                $item->teamCount,
                \sprintf('%s (%d%%)', $item->dominantTeam, $item->dominantTeamPercentage),
                number_format($item->ownershipEntropy, 2),
                number_format($item->ownershipEntropy * $item->totalCommits, 1),
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('ownership-hotspots')
            ->addArgument('path', InputArgument::REQUIRED)
            ->addOption('teams', 'T', InputOption::VALUE_REQUIRED)
            ->addOption('since', 's', InputOption::VALUE_OPTIONAL, default: null)
            ->addOption('until', 'u', InputOption::VALUE_OPTIONAL, default: null)
            ->addOption('filter', 'f', InputOption::VALUE_OPTIONAL, default: null)
            ->addOption('min-teams', 'm', InputOption::VALUE_OPTIONAL, default: 2);
    }
}
