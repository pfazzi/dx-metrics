<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** @psalm-suppress UnusedClass */
final class Init extends Command
{
    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoPath = rtrim((string) $input->getArgument('path'), '/');
        $force = (bool) $input->getOption('force');
        $update = (bool) $input->getOption('update');

        $configFile = $repoPath.'/.dx-metrics.json';
        $teamsFile = $repoPath.'/.dx-metrics-teams.json';

        if ($update) {
            return $this->runUpdate($repoPath, $teamsFile, $output);
        }

        if (!$force) {
            $existing = array_filter([$configFile, $teamsFile], 'file_exists');
            if ([] !== $existing) {
                foreach ($existing as $file) {
                    $output->writeln(\sprintf('<error>%s already exists. Use --force to overwrite.</error>', $file));
                }

                return Command::FAILURE;
            }
        }

        $output->writeln('<options=bold>dx-metrics init</>');
        $output->writeln('');

        $authorDetails = $this->scanAuthors($repoPath);

        $output->writeln(\sprintf('Found <info>%d</info> contributors in the last 12 months.', \count($authorDetails)));

        $filter = $this->detectSourceDir($repoPath);
        if (null !== $filter) {
            $output->writeln(\sprintf('Detected source directory: <info>%s</info>', $filter));
        }
        $output->writeln('');

        $teamsTemplate = [
            'teams' => [
                'team-a' => [],
                'team-b' => [],
            ],
            '_unassigned' => array_keys($authorDetails),
            '_unassigned_details' => $authorDetails,
        ];
        file_put_contents($teamsFile, json_encode($teamsTemplate, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");

        $config = ['teams' => '.dx-metrics-teams.json', 'depth' => 2];
        if (null !== $filter) {
            $config['filter'] = $filter;
        }
        $config['exclude'] = [];
        $config['min-teams'] = 2;
        $config['min-coupling'] = 0;
        $config['period'] = '4w';

        file_put_contents($configFile, json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");

        $output->writeln(\sprintf('Written: <info>%s</info>', $configFile));
        $output->writeln(\sprintf('Written: <info>%s</info>', $teamsFile));
        $output->writeln('');
        $output->writeln('<options=bold>Next steps:</>');
        $output->writeln(\sprintf('  1. Edit <comment>%s</comment>', $teamsFile));
        $output->writeln('     Move emails from <comment>_unassigned</comment> into named team arrays, then remove <comment>_unassigned</comment>.');
        $output->writeln(\sprintf('  2. Run: <info>dx-metrics coupling:analyze %s</info>', $repoPath));

        return Command::SUCCESS;
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('init')
            ->setDescription('Scaffold .dx-metrics.json and a teams template in the target repository')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the git repository')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('update', null, InputOption::VALUE_NONE, 'Add new unmapped authors to the existing teams file');
    }

    private function runUpdate(string $repoPath, string $teamsFile, OutputInterface $output): int
    {
        if (!file_exists($teamsFile)) {
            $output->writeln(\sprintf('<error>%s not found. Run init first to create it.</error>', $teamsFile));

            return Command::FAILURE;
        }

        $output->writeln('<options=bold>dx-metrics init --update</>');
        $output->writeln('');

        $existing = json_decode((string) file_get_contents($teamsFile), true);
        if (!\is_array($existing)) {
            $output->writeln('<error>Could not parse existing teams file.</error>');

            return Command::FAILURE;
        }

        // Collect all emails already known (in any team or _unassigned)
        $knownEmails = [];
        foreach ($existing['teams'] ?? [] as $emails) {
            foreach ((array) $emails as $email) {
                $knownEmails[strtolower((string) $email)] = true;
            }
        }
        foreach ($existing['_unassigned'] ?? [] as $email) {
            $knownEmails[strtolower((string) $email)] = true;
        }

        $authorDetails = $this->scanAuthors($repoPath);
        $newAuthors = array_diff_key($authorDetails, $knownEmails);

        if ([] === $newAuthors) {
            $output->writeln('No new contributors found.');

            return Command::SUCCESS;
        }

        // Merge into existing _unassigned / _unassigned_details
        $existingUnassigned = $existing['_unassigned'] ?? [];
        $existingDetails = $existing['_unassigned_details'] ?? [];

        foreach ($newAuthors as $email => $detail) {
            $existingUnassigned[] = $email;
            $existingDetails[$email] = $detail;
        }
        sort($existingUnassigned);
        ksort($existingDetails);

        $existing['_unassigned'] = $existingUnassigned;
        $existing['_unassigned_details'] = $existingDetails;

        file_put_contents($teamsFile, json_encode($existing, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");

        $output->writeln(\sprintf('Added <info>%d</info> new contributor(s) to <info>%s</info>:', \count($newAuthors), $teamsFile));
        foreach ($newAuthors as $email => $detail) {
            $output->writeln(\sprintf('  + %s (%s)', $email, $detail['name']));
        }

        return Command::SUCCESS;
    }

    /**
     * Scans git authors from the last 12 months.
     * Returns email => ['name' => ..., 'example_commit' => ...], sorted by email.
     *
     * @return array<string, array{name: string, example_commit: string}>
     */
    private function scanAuthors(string $repoPath): array
    {
        $git = new Git($repoPath);
        $since = new \DateTimeImmutable('-12 months');
        $commits = $git->getCommitsWithAuthorDetails($since, null);

        $authorDetails = [];
        foreach ($commits as $commit) {
            $email = strtolower($commit['email']);
            // git log is newest-first; overwriting means we keep the oldest commit SHA
            $authorDetails[$email] = ['name' => $commit['name'], 'example_commit' => $commit['sha']];
        }
        ksort($authorDetails);

        return $authorDetails;
    }

    private function detectSourceDir(string $repoPath): ?string
    {
        foreach (['src/', 'app/', 'lib/'] as $dir) {
            if (is_dir($repoPath.'/'.$dir)) {
                return $dir;
            }
        }

        return null;
    }
}
