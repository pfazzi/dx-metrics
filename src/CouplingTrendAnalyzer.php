<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

readonly class CouplingTrendAnalyzer
{
    public function __construct(private Git $git, private TeamConfig $teamConfig)
    {
    }

    /**
     * @param array<array{from: \DateTimeImmutable, to: \DateTimeImmutable}> $windows
     * @param string[]                                                       $excludePatterns
     *
     * @return CouplingTrendPeriod[]
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
    ): CouplingTrendPeriod {
        // Resolve file → dominant team within this window's commit activity
        $ownershipAnalyzer = new SharedOwnershipAnalyzer($this->git, $this->teamConfig);
        $ownership = $ownershipAnalyzer->analyze($from, $to);

        if ([] !== $excludePatterns) {
            $ownership = $ownership->filterByExcludedPatterns($excludePatterns);
        }
        if (null !== $filter) {
            $ownership = $ownership->filterByPath($filter);
        }

        $fileTeam = [];
        foreach ($ownership->items as $item) {
            $fileTeam[$item->filePath] = $item->dominantTeam;
        }

        // Co-change pairs within this window
        $coupleAnalyzer = new Analyzer($this->git);
        $pairs = $coupleAnalyzer->analyze($from, $to)
            ->filterByExcludedPatterns($excludePatterns)
            ->filterByPath($filter)
            ->getUniqueFilePairs();

        // Classify each pair
        $totalCoChanges = 0;
        $crossTeamCoChanges = 0;
        /** @var array<string, array{total: int, cross: int}> $teamStats */
        $teamStats = [];
        /** @var array<string, int> $teamPairCoChanges */
        $teamPairCoChanges = [];

        foreach ($pairs->items as $item) {
            $teamA = $fileTeam[$item->pathA] ?? 'unknown';
            $teamB = $fileTeam[$item->pathB] ?? 'unknown';
            $count = $item->coChangeCount;

            $totalCoChanges += $count;
            $teamStats[$teamA]['total'] = ($teamStats[$teamA]['total'] ?? 0) + $count;
            $teamStats[$teamB]['total'] = ($teamStats[$teamB]['total'] ?? 0) + $count;

            if ($teamA !== $teamB) {
                $crossTeamCoChanges += $count;
                $teamStats[$teamA]['cross'] = ($teamStats[$teamA]['cross'] ?? 0) + $count;
                $teamStats[$teamB]['cross'] = ($teamStats[$teamB]['cross'] ?? 0) + $count;

                [$ta, $tb] = $teamA < $teamB ? [$teamA, $teamB] : [$teamB, $teamA];
                $key = $ta.'|||'.$tb;
                $teamPairCoChanges[$key] = ($teamPairCoChanges[$key] ?? 0) + $count;
            }
        }

        $companyCouplingIndex = $totalCoChanges > 0
            ? round($crossTeamCoChanges / $totalCoChanges, 4)
            : 0.0;

        $teamCouplingScores = [];
        foreach ($teamStats as $team => $stats) {
            $teamCouplingScores[$team] = $stats['total'] > 0
                ? round(($stats['cross'] ?? 0) / $stats['total'], 4)
                : 0.0;
        }

        return new CouplingTrendPeriod(
            $from,
            $to,
            $totalCoChanges,
            $crossTeamCoChanges,
            $companyCouplingIndex,
            $teamCouplingScores,
            $teamPairCoChanges,
        );
    }
}
