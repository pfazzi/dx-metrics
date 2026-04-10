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
        $output->writeln('<options=bold>Ownership Hotspots</>');
        $output->writeln('');
        $output->writeln('Ranks files with ambiguous team ownership by urgency.');
        $output->writeln('<comment>Dominant Team</comment> is the team with the highest number of commits on a file, shown with its percentage of total commits. A low percentage even for the dominant team signals highly fragmented ownership.');
        $output->writeln('<comment>Entropy</comment> (0–1) measures ownership concentration: 0 means one team made all the commits (clear ownership), 1 means every team contributed equally (no one really owns it).');
        $output->writeln('<comment>Risk Score</comment> = entropy × total commits: a file frequently changed by multiple teams scores higher than a rarely-touched one with the same entropy split.');
        $output->writeln('Start from the top: high-scoring files represent the greatest hidden coordination cost between teams and are the best candidates for an ownership clarification conversation.');
        $output->writeln('');

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
        $excludePatterns = $input->getOption('exclude');
        $minTeams = (int) $input->getOption('min-teams');

        $teamConfig = TeamConfig::fromFile($teamsFile);
        $git = new Git($repoPath);
        $analyzer = new SharedOwnershipAnalyzer($git, $teamConfig);

        $hotspots = OwnershipHotspotsOutput::fromSharedOwnershipOutput($analyzer->analyze($since, $until))
            ->filterByExcludedPatterns($excludePatterns)
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
            ->setDescription('Rank multi-team files by risk score (entropy × commits) to prioritise ownership conversations')
            ->addArgument('path', InputArgument::REQUIRED)
            ->addOption('teams', 'T', InputOption::VALUE_REQUIRED)
            ->addOption('since', 's', InputOption::VALUE_OPTIONAL, default: null)
            ->addOption('until', 'u', InputOption::VALUE_OPTIONAL, default: null)
            ->addOption('filter', 'f', InputOption::VALUE_OPTIONAL, default: null)
            ->addOption('exclude', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, default: [])
            ->addOption('min-teams', 'm', InputOption::VALUE_OPTIONAL, default: 2);
    }
}
