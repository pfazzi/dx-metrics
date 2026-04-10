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
final class CodeownersDrift extends Command
{
    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<options=bold>CODEOWNERS Drift</>');
        $output->writeln('');
        $output->writeln('Compares declared ownership in CODEOWNERS against who actually committed to each path in the selected period.');
        $output->writeln('<comment>Actual Owner</comment> is the team with the most commits to files matching the CODEOWNERS pattern.');
        $output->writeln('<comment>Drift</comment> is the percentage of commits that came from teams other than the declared owner.');
        $output->writeln('A high drift score means the declared owner is not who is actually maintaining the code — an ownership conversation is overdue.');
        $output->writeln('');

        $teamsFile = $input->getOption('teams');
        if (null === $teamsFile) {
            $output->writeln('<error>The --teams option is required.</error>');

            return Command::FAILURE;
        }

        $repoPath = $input->getArgument('path');
        $codeownersPath = $input->getOption('codeowners') ?? ($repoPath.'/.github/CODEOWNERS');
        $excludePatterns = $input->getOption('exclude');

        if (!file_exists($codeownersPath)) {
            $output->writeln(\sprintf('<error>CODEOWNERS file not found: %s</error>', $codeownersPath));

            return Command::FAILURE;
        }

        $since = $input->getOption('since');
        $since = $since ? new \DateTimeImmutable($since) : new \DateTimeImmutable('-6 months');

        $until = $input->getOption('until');
        $until = $until ? new \DateTimeImmutable($until) : new \DateTimeImmutable();

        $teamConfig = TeamConfig::fromFile($teamsFile);
        $git = new Git($repoPath);

        // Build file → teamCommitCounts index from recent history
        $ownershipAnalyzer = new SharedOwnershipAnalyzer($git, $teamConfig);
        $ownership = $ownershipAnalyzer->analyze($since, $until);

        if ([] !== $excludePatterns) {
            $ownership = $ownership->filterByExcludedPatterns($excludePatterns);
        }

        /** @var array<string, array<string, int>> $fileTeamCounts filePath → [team → commits] */
        $fileTeamCounts = [];
        foreach ($ownership->items as $item) {
            $fileTeamCounts[$item->filePath] = $item->teamCommitCounts;
        }

        // Parse CODEOWNERS rules
        $rules = $this->parseCodeowners($codeownersPath);

        if ([] === $rules) {
            $output->writeln('No rules found in CODEOWNERS file.');

            return Command::SUCCESS;
        }

        // Analyse each rule
        $table = new Table($output);
        $table->setHeaders(['Pattern', 'Declared Owner', 'Actual Owner', 'Drift', 'Status', 'Files']);

        $hasWarning = false;
        foreach ($rules as [$pattern, $declaredOwners]) {
            [$actualTeam, $actualPct, $driftPct, $matchedFiles] = $this->analyzeRule(
                $pattern,
                $fileTeamCounts,
            );

            if (null === $actualTeam) {
                $table->addRow([$pattern, implode(' ', $declaredOwners), '—', '—', '<comment>no data</comment>', 0]);
                continue;
            }

            $status = $this->status($driftPct);
            if ('ok' !== $status) {
                $hasWarning = true;
            }

            $table->addRow([
                $pattern,
                implode(' ', $declaredOwners),
                \sprintf('%s (%d%%)', $actualTeam, $actualPct),
                \sprintf('%d%%', $driftPct),
                $this->statusLabel($status),
                $matchedFiles,
            ]);
        }

        $table->render();

        if ($hasWarning) {
            $output->writeln('');
            $output->writeln('<comment>Status legend: ok = drift ≤10% | review = drift 11–40% | drift = drift >40%</comment>');
            $output->writeln('Run <info>codeowners:suggest</info> to generate an updated CODEOWNERS based on recent history.');
        }

        return Command::SUCCESS;
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('codeowners:drift')
            ->setDescription('Compare CODEOWNERS declared ownership against recent commit history')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to the git repository')
            ->addOption('teams', 'T', InputOption::VALUE_REQUIRED, 'Path to teams JSON config file')
            ->addOption('codeowners', null, InputOption::VALUE_OPTIONAL, 'Path to CODEOWNERS file (default: <repo>/.github/CODEOWNERS)', null)
            ->addOption('since', 's', InputOption::VALUE_OPTIONAL, 'Start of the commit window (default: 6 months ago)', null)
            ->addOption('until', 'u', InputOption::VALUE_OPTIONAL, 'End of the commit window (default: today)', null)
            ->addOption('exclude', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, default: []);
    }

    /**
     * @return array<array{string, string[]}> list of [pattern, owners[]]
     */
    private function parseCodeowners(string $filePath): array
    {
        $rules = [];
        $lines = file($filePath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            return [];
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            $pattern = array_shift($parts);
            if (null !== $pattern && [] !== $parts) {
                $rules[] = [$pattern, $parts];
            }
        }

        return $rules;
    }

    /**
     * For a CODEOWNERS pattern, aggregate team commit counts from all matching files.
     *
     * @param array<string, array<string, int>> $fileTeamCounts
     *
     * @return array{string|null, int, int, int} [dominantTeam, dominantPct, driftPct, matchedFileCount]
     */
    private function analyzeRule(string $pattern, array $fileTeamCounts): array
    {
        /** @var array<string, int> $teamCounts */
        $teamCounts = [];
        $matchedFiles = 0;

        foreach ($fileTeamCounts as $filePath => $counts) {
            if (!$this->matchesPattern($filePath, $pattern)) {
                continue;
            }
            ++$matchedFiles;
            foreach ($counts as $team => $n) {
                $teamCounts[$team] = ($teamCounts[$team] ?? 0) + $n;
            }
        }

        if ([] === $teamCounts) {
            return [null, 0, 0, 0];
        }

        $total = array_sum($teamCounts);
        arsort($teamCounts);
        $dominant = (string) array_key_first($teamCounts);
        $dominantPct = (int) round($teamCounts[$dominant] / $total * 100);
        $driftPct = 100 - $dominantPct;

        return [$dominant, $dominantPct, $driftPct, $matchedFiles];
    }

    /**
     * Matches a file path against a CODEOWNERS-style pattern.
     * Handles: directory patterns (ending /), root-anchored (/pattern),
     * glob wildcards, and bare filename patterns.
     */
    private function matchesPattern(string $filePath, string $pattern): bool
    {
        // Strip leading slash (root-anchored patterns)
        $pattern = ltrim($pattern, '/');

        // Directory pattern: matches everything under that directory
        if (str_ends_with($pattern, '/')) {
            return str_starts_with($filePath, $pattern)
                || str_starts_with($filePath, rtrim($pattern, '/').'/');
        }

        // Glob pattern
        if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
            return fnmatch($pattern, $filePath) || fnmatch('*/'.$pattern, $filePath);
        }

        // Exact file or directory prefix
        return $filePath === $pattern
            || str_starts_with($filePath, $pattern.'/')
            || str_ends_with($filePath, '/'.$pattern);
    }

    private function status(int $driftPct): string
    {
        if ($driftPct <= 10) {
            return 'ok';
        }
        if ($driftPct <= 40) {
            return 'review';
        }

        return 'drift';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'ok' => '<info>ok</info>',
            'review' => '<comment>review</comment>',
            default => '<error>drift</error>',
        };
    }
}
