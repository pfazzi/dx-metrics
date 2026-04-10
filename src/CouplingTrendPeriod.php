<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

readonly class CouplingTrendPeriod
{
    /**
     * @param array<string, float> $teamCouplingScores team => cross-team ratio (0–1)
     * @param array<string, int>   $teamPairCoChanges  "teamA|||teamB" => count
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public int $totalCoChanges,
        public int $crossTeamCoChanges,
        public float $companyCouplingIndex,
        public array $teamCouplingScores,
        public array $teamPairCoChanges,
    ) {
    }

    public function periodLabel(): string
    {
        return $this->from->format('Y-m-d').' → '.$this->to->format('Y-m-d');
    }
}
