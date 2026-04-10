<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** @psalm-suppress UnusedClass */
class SharedOwnershipInit extends Command
{
    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoPath = $input->getArgument('path');
        $outputFile = $input->getOption('output');

        $since = $input->getOption('since');
        if ($since) {
            $since = new \DateTimeImmutable($since);
        }
        $until = $input->getOption('until');
        if ($until) {
            $until = new \DateTimeImmutable($until);
        }

        $git = new Git($repoPath);
        $commits = $git->getCommitsWithAuthorEmail($since, $until);

        $emails = [];
        foreach ($commits as $commit) {
            $email = strtolower($commit['email']);
            $emails[$email] = true;
        }

        ksort($emails);
        $unassigned = array_keys($emails);

        $template = [
            'teams' => [
                'team-a' => [],
                'team-b' => [],
            ],
            '_unassigned' => $unassigned,
        ];

        $json = json_encode($template, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n";

        if (null !== $outputFile) {
            $output->writeln('<options=bold>Shared Ownership — Team Config Init</>');
            $output->writeln('');
            $output->writeln('Scans git author history and generates a teams configuration template.');
            $output->writeln('Authors found in the repository are placed in <comment>_unassigned</comment>; rename the team keys and move emails into the appropriate arrays to start using the ownership analysis commands.');
            $output->writeln('');
            file_put_contents($outputFile, $json);
            $output->writeln(\sprintf('Teams template written to <info>%s</info> (%d authors found).', $outputFile, \count($unassigned)));
            $output->writeln('Edit the file: move emails from <comment>_unassigned</comment> into your team arrays, then rename the team keys.');
        } else {
            $output->write($json);
        }

        return Command::SUCCESS;
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('shared-ownership:init')
            ->setDescription('Scaffold a teams JSON config from git author history')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the git repository')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Write the template to this file instead of stdout', null)
            ->addOption('since', 's', InputOption::VALUE_OPTIONAL, 'Only include authors from commits after this date (e.g. 2024-01-01)', null)
            ->addOption('until', 'u', InputOption::VALUE_OPTIONAL, 'Only include authors from commits before this date (e.g. 2024-12-31)', null);
    }
}
