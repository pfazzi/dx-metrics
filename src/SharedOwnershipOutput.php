<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

readonly class SharedOwnershipOutput
{
    /** @var SharedOwnershipOutputItem[] */
    public array $items;

    public function __construct(SharedOwnershipOutputItem ...$items)
    {
        $this->items = $items;
    }

    /** @param string[] $patterns */
    public function filterByExcludedPatterns(array $patterns): self
    {
        if ([] === $patterns) {
            return $this;
        }

        $filtered = array_filter(
            $this->items,
            static fn (SharedOwnershipOutputItem $item) => !self::matchesAnyPattern($item->filePath, $patterns),
        );

        return new self(...array_values($filtered));
    }

    public function filterByMinTeams(int $minTeams): self
    {
        $filtered = array_filter($this->items, static fn ($i) => $i->teamCount >= $minTeams);

        return new self(...array_values($filtered));
    }

    public function filterByPath(?string $prefix): self
    {
        if (null === $prefix) {
            return $this;
        }

        $filtered = array_filter($this->items, static fn ($i) => str_starts_with($i->filePath, $prefix));

        return new self(...array_values($filtered));
    }

    public function sortByEntropyDesc(): self
    {
        $items = $this->items;
        usort($items, static fn ($a, $b) => $b->ownershipEntropy <=> $a->ownershipEntropy);

        return new self(...$items);
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
