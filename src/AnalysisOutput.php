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
            fn ($c) => $c->coChangeCount >= $threshold
        );

        return new self(...array_values($analysisOutputItems));
    }

    public function sortByCoChangesDesc(): self
    {
        $items = array_values($this->items);

        usort($items, fn (AnalysisOutputItem $a, AnalysisOutputItem $b) => $b->coChangeCount <=> $a->coChangeCount);

        return new self(...array_values($items));
    }
}
