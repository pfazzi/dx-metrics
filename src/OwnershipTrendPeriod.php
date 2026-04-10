<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

readonly class OwnershipTrendPeriod
{
    /**
     * @param array<string, float>  $moduleEntropies     module => ownershipEntropy
     * @param array<string, string> $moduleDominantTeams module => dominantTeam
     * @param array<string, float>  $moduleDominantPcts  module => dominantTeamPercentage
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public array $moduleEntropies,
        public array $moduleDominantTeams,
        public array $moduleDominantPcts,
    ) {
    }

    public function periodLabel(): string
    {
        return $this->from->format('Y-m-d').' → '.$this->to->format('Y-m-d');
    }
}
