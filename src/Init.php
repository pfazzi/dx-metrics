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

        $configFile = $repoPath.'/.dx-metrics.json';
        $teamsFile = $repoPath.'/.dx-metrics-teams.json';

        if (file_exists($configFile) && !$force) {
            $output->writeln(\sprintf('<error>.dx-metrics.json already exists in %s. Use --force to overwrite.</error>', $repoPath));

            return Command::FAILURE;
        }

        $output->writeln('<options=bold>dx-metrics init</>');
        $output->writeln('');

        // Scan git authors from the last 12 months
        $git = new Git($repoPath);
        $since = new \DateTimeImmutable('-12 months');
        $commits = $git->getCommitsWithAuthorEmail($since, null);

        $emails = [];
        foreach ($commits as $commit) {
            $email = strtolower($commit['email']);
            $emails[$email] = true;
        }
        ksort($emails);
        $unassigned = array_keys($emails);

        $output->writeln(\sprintf('Found <info>%d</info> contributors in the last 12 months.', \count($unassigned)));

        // Auto-detect source directory
        $filter = $this->detectSourceDir($repoPath);
        if (null !== $filter) {
            $output->writeln(\sprintf('Detected source directory: <info>%s</info>', $filter));
        }
        $output->writeln('');

        // Write teams template
        $teamsTemplate = [
            'teams' => [
                'team-a' => [],
                'team-b' => [],
            ],
            '_unassigned' => $unassigned,
        ];
        file_put_contents($teamsFile, json_encode($teamsTemplate, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");

        // Build config, omitting null values
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
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing .dx-metrics.json');
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
