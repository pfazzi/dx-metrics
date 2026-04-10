<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

readonly class TerritoryMapModule
{
    public int $totalCommits;
    public string $dominantTeam;
    public float $dominantTeamPercentage;
    public float $ownershipEntropy;
    public int $teamCount;

    /** @param array<string, int> $teamCommitCounts */
    public function __construct(
        public string $name,
        public array $teamCommitCounts,
    ) {
        $this->totalCommits = array_sum($teamCommitCounts);
        $this->teamCount = \count($teamCommitCounts);

        $sorted = $teamCommitCounts;
        arsort($sorted);
        $dominant = (string) array_key_first($sorted);
        $this->dominantTeam = $dominant;
        $this->dominantTeamPercentage = $this->totalCommits > 0
            ? round($teamCommitCounts[$dominant] / $this->totalCommits * 100, 1)
            : 0.0;

        $this->ownershipEntropy = $this->computeEntropy();
    }

    private function computeEntropy(): float
    {
        if ($this->teamCount <= 1 || 0 === $this->totalCommits) {
            return 0.0;
        }

        $h = 0.0;
        foreach ($this->teamCommitCounts as $count) {
            $p = $count / $this->totalCommits;
            $h -= $p * log($p, 2);
        }

        return round($h / log($this->teamCount, 2), 3);
    }
}
