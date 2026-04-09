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
}
