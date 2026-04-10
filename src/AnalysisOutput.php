<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

readonly class AnalysisOutput
{
    /** @var AnalysisOutputItem[] */
    public array $items;

    public function __construct(AnalysisOutputItem ...$analysisOutputItems)
    {
        $this->items = $analysisOutputItems;
    }

    public function getUniqueFilePairs()
    {
        // Filter unique pairs
        $uniques = [];
        foreach ($this->items as $item) {
            $keyA = $item->pathA.$item->pathB;
            $keyB = $item->pathB.$item->pathA;
            if (isset($uniques[$keyA]) || isset($uniques[$keyB])) {
                continue;
            }
            $uniques[$keyA] = $item;
        }

        return new self(...array_values($uniques));
    }

    /** @param string[] $patterns */
    public function filterByExcludedPatterns(array $patterns): self
    {
        if ([] === $patterns) {
            return $this;
        }

        $filtered = array_filter(
            $this->items,
            static fn (AnalysisOutputItem $item) => !self::matchesAnyPattern($item->pathA, $patterns)
                && !self::matchesAnyPattern($item->pathB, $patterns),
        );

        return new self(...array_values($filtered));
    }

    public function filterByPath(?string $filter): self
    {
        if (null === $filter) {
            return $this;
        }

        $filtered = [];

        foreach ($this->items as $item) {
            if (!str_starts_with($item->pathA, $filter)
                || !str_starts_with($item->pathB, $filter)) {
                continue;
            }

            $filtered[] = $item;
        }

        return new self(...array_values($filtered));
    }

    public function filterByCoChangesThreshold(int $threshold): self
    {
        if ($threshold <= 0) {
            return $this;
        }

        $analysisOutputItems = array_filter(
            $this->items,
            static fn ($c) => $c->coChangeCount >= $threshold
        );

        return new self(...array_values($analysisOutputItems));
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function sortByCoChangesDesc(): self
    {
        $items = array_values($this->items);

        usort($items, static fn (AnalysisOutputItem $a, AnalysisOutputItem $b) => $b->coChangeCount <=> $a->coChangeCount);

        return new self(...array_values($items));
    }

    /** @param string[] $patterns */
    private static function matchesAnyPattern(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
