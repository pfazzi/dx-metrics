<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

readonly class OwnershipTrendAnalyzer
{
    public function __construct(private Git $git, private TeamConfig $teamConfig)
    {
    }

    /**
     * @param array<array{from: \DateTimeImmutable, to: \DateTimeImmutable}> $windows
     * @param string[]                                                       $excludePatterns
     *
     * @return OwnershipTrendPeriod[]
     */
    public function analyze(array $windows, int $depth, array $excludePatterns, ?string $filter): array
    {
        return array_map(
            fn (array $w) => $this->analyzeWindow($w['from'], $w['to'], $depth, $excludePatterns, $filter),
            $windows,
        );
    }

    /** @param string[] $excludePatterns */
    private function analyzeWindow(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $depth,
        array $excludePatterns,
        ?string $filter,
    ): OwnershipTrendPeriod {
        $territoryAnalyzer = new TerritoryMapAnalyzer($this->git, $this->teamConfig);
        $output = $territoryAnalyzer->analyze($depth, $from, $to, $excludePatterns, $filter);

        $moduleEntropies = [];
        $moduleDominantTeams = [];
        $moduleDominantPcts = [];

        foreach ($output->modules as $module) {
            $moduleEntropies[$module->name] = $module->ownershipEntropy;
            $moduleDominantTeams[$module->name] = $module->dominantTeam;
            $moduleDominantPcts[$module->name] = $module->dominantTeamPercentage;
        }

        return new OwnershipTrendPeriod(
            $from,
            $to,
            $moduleEntropies,
            $moduleDominantTeams,
            $moduleDominantPcts,
        );
    }
}
