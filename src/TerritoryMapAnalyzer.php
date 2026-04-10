<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

readonly class TerritoryMapAnalyzer
{
    public function __construct(private Git $git, private TeamConfig $teamConfig)
    {
    }

    /**
     * @param string[] $excludePatterns
     */
    public function analyze(
        int $depth,
        ?\DateTimeImmutable $since = null,
        ?\DateTimeImmutable $until = null,
        array $excludePatterns = [],
        ?string $filter = null,
    ): TerritoryMapOutput {
        return new TerritoryMapOutput(
            $this->buildModules($depth, $since, $until, $excludePatterns, $filter),
            $this->buildEdges($depth, $since, $until, $excludePatterns, $filter),
        );
    }

    /** @param string[] $excludePatterns */
    private function buildModules(
        int $depth,
        ?\DateTimeImmutable $since,
        ?\DateTimeImmutable $until,
        array $excludePatterns,
        ?string $filter,
    ): array {
        $ownershipAnalyzer = new SharedOwnershipAnalyzer($this->git, $this->teamConfig);
        $ownership = $ownershipAnalyzer->analyze($since, $until);

        if ([] !== $excludePatterns) {
            $ownership = $ownership->filterByExcludedPatterns($excludePatterns);
        }

        if (null !== $filter) {
            $ownership = $ownership->filterByPath($filter);
        }

        /** @var array<string, array<string, int>> $moduleTeamCounts module => [team => count] */
        $moduleTeamCounts = [];
        foreach ($ownership->items as $item) {
            $module = $this->moduleOf($item->filePath, $depth);
            foreach ($item->teamCommitCounts as $team => $count) {
                $moduleTeamCounts[$module][$team] ??= 0;
                $moduleTeamCounts[$module][$team] += $count;
            }
        }

        $modules = [];
        foreach ($moduleTeamCounts as $module => $teamCounts) {
            $modules[] = new TerritoryMapModule($module, $teamCounts);
        }

        return $modules;
    }

    /** @param string[] $excludePatterns */
    private function buildEdges(
        int $depth,
        ?\DateTimeImmutable $since,
        ?\DateTimeImmutable $until,
        array $excludePatterns,
        ?string $filter,
    ): array {
        $coupleAnalyzer = new Analyzer($this->git);
        $coupling = $coupleAnalyzer->analyze($since, $until)
            ->filterByExcludedPatterns($excludePatterns)
            ->filterByPath($filter)
            ->getUniqueFilePairs();

        /** @var array<string, array<string, int>> $edgeCounts moduleA => [moduleB => totalCoChanges] */
        $edgeCounts = [];
        foreach ($coupling->items as $item) {
            $modA = $this->moduleOf($item->pathA, $depth);
            $modB = $this->moduleOf($item->pathB, $depth);

            if ($modA === $modB) {
                continue;
            }

            // Normalise pair order so A < B alphabetically
            if ($modA > $modB) {
                [$modA, $modB] = [$modB, $modA];
            }

            $edgeCounts[$modA][$modB] ??= 0;
            $edgeCounts[$modA][$modB] += $item->coChangeCount;
        }

        $edges = [];
        foreach ($edgeCounts as $modA => $targets) {
            foreach ($targets as $modB => $count) {
                $edges[] = new TerritoryMapEdge($modA, $modB, $count);
            }
        }

        return $edges;
    }

    private function moduleOf(string $filePath, int $depth): string
    {
        $parts = explode('/', $filePath);

        return implode('/', \array_slice($parts, 0, $depth));
    }
}
