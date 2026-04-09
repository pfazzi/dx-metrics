<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

readonly class SharedOwnershipAnalyzer
{
    public function __construct(private Git $git, private TeamConfig $teamConfig)
    {
    }

    public function analyze(?\DateTimeImmutable $since = null, ?\DateTimeImmutable $until = null): SharedOwnershipOutput
    {
        /** @var array<string, array<string, int>> $fileTeamCounts filePath => [team => commitCount] */
        $fileTeamCounts = [];

        foreach ($this->git->getCommitsWithAuthorEmail($since, $until) as ['sha' => $sha, 'email' => $email]) {
            $team = $this->teamConfig->getTeam($email);

            foreach ($this->git->getChangesFromCommit($sha) as $file) {
                $fileTeamCounts[$file][$team] ??= 0;
                ++$fileTeamCounts[$file][$team];
            }
        }

        $items = [];
        foreach ($fileTeamCounts as $file => $teamCounts) {
            $items[] = new SharedOwnershipOutputItem($file, $teamCounts);
        }

        return new SharedOwnershipOutput(...$items);
    }
}
