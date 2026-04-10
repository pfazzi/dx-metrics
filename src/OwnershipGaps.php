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
final class OwnershipGaps extends Command
{
    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<options=bold>Ownership Gaps</>');
        $output->writeln('');
        $output->writeln('Lists all tracked files that have not been committed in the selected period.');
        $output->writeln('Stale files are an ownership risk: no team actively maintains them, so bugs are slower to fix and refactors are risky.');
        $output->writeln('Use this report to start conversations about archiving, deleting, or explicitly re-owning these files.');
        $output->writeln('');

        $teamsFile = $input->getOption('teams');
        if (null === $teamsFile) {
            $output->writeln('<error>The --teams option is required.</error>');

            return Command::FAILURE;
        }

        $repoPath = $input->getArgument('path');
        $staleMonths = (int) $input->getOption('stale-months');
        $filter = $input->getOption('filter');
        $excludePatterns = $input->getOption('exclude');

        $teamConfig = TeamConfig::fromFile($teamsFile);
        $git = new Git($repoPath);

        $cutoff = new \DateTimeImmutable("-{$staleMonths} months");
        $allFiles = $git->getAllTrackedFiles();

        $staleFiles = [];

        foreach ($allFiles as $filePath) {
            if ('' === $filePath) {
                continue;
            }

            if (null !== $filter && !str_starts_with($filePath, $filter)) {
                continue;
            }

            if ($this->isExcluded($filePath, $excludePatterns)) {
                continue;
            }

            $lastDate = $git->getLastCommitDateForFile($filePath);

            if (null === $lastDate || $lastDate < $cutoff) {
                $lastAuthorEmail = null === $lastDate ? null : $git->getLastCommitAuthorEmailForFile($filePath);
                $lastTeam = null === $lastAuthorEmail ? null : $teamConfig->getTeam($lastAuthorEmail);
                $daysStale = null === $lastDate ? null : (int) $lastDate->diff(new \DateTimeImmutable())->days;

                $staleFiles[] = [
                    'file' => $filePath,
                    'lastDate' => $lastDate,
                    'daysStale' => $daysStale,
                    'lastTeam' => $lastTeam,
                ];
            }
        }

        usort($staleFiles, static function (array $a, array $b): int {
            $daysA = $a['daysStale'] ?? \PHP_INT_MAX;
            $daysB = $b['daysStale'] ?? \PHP_INT_MAX;

            return $daysB <=> $daysA;
        });

        if ([] === $staleFiles) {
            $output->writeln("No stale files found. All tracked files have been committed in the last {$staleMonths} months.");

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['File', 'Last Commit', 'Days Stale', 'Last Team']);

        foreach ($staleFiles as $item) {
            $table->addRow([
                $item['file'],
                null !== $item['lastDate'] ? $item['lastDate']->format('Y-m-d') : '—',
                null !== $item['daysStale'] ? (string) $item['daysStale'] : '—',
                null !== $item['lastTeam'] ? $item['lastTeam'] : '—',
            ]);
        }

        $table->render();

        $output->writeln('');
        $output->writeln('Run <info>ownership-hotspots</info> to see which of these files are also contested between teams.');

        return Command::SUCCESS;
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('ownership:gaps')
            ->setDescription('Find files with no recent commits — stale files nobody is actively maintaining')
            ->addArgument('path', InputArgument::REQUIRED)
            ->addOption('teams', 'T', InputOption::VALUE_REQUIRED)
            ->addOption('stale-months', null, InputOption::VALUE_OPTIONAL, 'Files with no commits in this many months are considered stale', 12)
            ->addOption('filter', 'f', InputOption::VALUE_OPTIONAL, default: null)
            ->addOption('exclude', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, default: []);
    }

    /** @param string[] $excludePatterns */
    private function isExcluded(string $filePath, array $excludePatterns): bool
    {
        foreach ($excludePatterns as $pattern) {
            if (fnmatch($pattern, $filePath) || fnmatch($pattern, basename($filePath))) {
                return true;
            }
        }

        return false;
    }
}
