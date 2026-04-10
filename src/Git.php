<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

readonly class Git
{
    use CommandLineTrait;

    public function __construct(private string $repoPath)
    {
    }

    public function getChangesFromCommit(string $sha): array
    {
        $command = 'git show --pretty="" --name-only '.escapeshellarg($sha);

        return $this->runCommand($command, $this->repoPath);
    }

    /**
     * Returns commits as [['sha' => string, 'email' => string], ...].
     *
     * @return array<int, array{sha: string, email: string}>
     */
    public function getCommitsWithAuthorEmail(?\DateTimeImmutable $since = null, ?\DateTimeImmutable $until = null): array
    {
        $command = 'git log --all --format="%H %ae"';

        if ($since) {
            $command .= " --since=\"{$since->format('Y-m-d 00:00:00')}\"";
        }
        if ($until) {
            $command .= " --until=\"{$until->format('Y-m-d 23:59:59')}\"";
        }

        $lines = $this->runCommand($command, $this->repoPath);

        $commits = [];
        foreach ($lines as $line) {
            if ('' === $line) {
                continue;
            }
            [$sha, $email] = explode(' ', $line, 2);
            $commits[] = ['sha' => $sha, 'email' => trim($email)];
        }

        return $commits;
    }

    public function getCommitsSha(?\DateTimeImmutable $since = null, ?\DateTimeImmutable $until = null): array
    {
        $command = 'git rev-list --all';

        if ($since) {
            $command .= " --since=\"{$since->format('Y-m-d 00:00:00')}\"";
        }

        if ($until) {
            $command .= " --until=\"{$until->format('Y-m-d 23:59:59')}\"";
        }

        return $this->runCommand($command, $this->repoPath);
    }

    /** Returns all file paths tracked by git (git ls-files). */
    public function getAllTrackedFiles(): array
    {
        return $this->runCommand('git ls-files', $this->repoPath);
    }

    /**
     * Returns the author date of the most recent commit touching this file,
     * or null if the file has never been committed.
     * Uses: git log -1 --format="%aI" -- <file>.
     */
    public function getLastCommitDateForFile(string $filePath): ?\DateTimeImmutable
    {
        $lines = $this->runCommand('git log -1 --format="%aI" -- '.escapeshellarg($filePath), $this->repoPath);
        $line = trim($lines[0] ?? '');
        if ('' === $line) {
            return null;
        }

        return new \DateTimeImmutable($line);
    }

    /**
     * Returns the author email of the most recent commit touching this file.
     * Uses: git log -1 --format="%ae" -- <file>.
     */
    public function getLastCommitAuthorEmailForFile(string $filePath): ?string
    {
        $lines = $this->runCommand('git log -1 --format="%ae" -- '.escapeshellarg($filePath), $this->repoPath);
        $line = trim($lines[0] ?? '');

        return '' !== $line ? $line : null;
    }
}
