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
        $command = "git show --pretty=\"\" --name-only " . escapeshellarg($sha);

        return $this->runCommand($command, $this->repoPath);
    }
}