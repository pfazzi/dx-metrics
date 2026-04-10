<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

final readonly class OwnershipHotspotsOutput
{
    /** @param SharedOwnershipOutputItem[] $items */
    public function __construct(public array $items)
    {
    }

    public static function fromSharedOwnershipOutput(SharedOwnershipOutput $output): self
    {
        return new self($output->items);
    }

    /** @param string[] $patterns */
    public function filterByExcludedPatterns(array $patterns): self
    {
        if ([] === $patterns) {
            return $this;
        }

        return new self(array_values(array_filter(
            $this->items,
            static fn (SharedOwnershipOutputItem $item) => !self::matchesAnyPattern($item->filePath, $patterns),
        )));
    }

    public function filterByPath(?string $prefix): self
    {
        if (null === $prefix) {
            return $this;
        }

        return new self(array_values(array_filter(
            $this->items,
            static fn (SharedOwnershipOutputItem $item) => str_starts_with($item->filePath, $prefix),
        )));
    }

    public function filterByMinTeams(int $minTeams): self
    {
        return new self(array_values(array_filter(
            $this->items,
            static fn (SharedOwnershipOutputItem $item) => $item->teamCount >= $minTeams,
        )));
    }

    public function sortByRiskDesc(): self
    {
        $items = $this->items;
        usort(
            $items,
            static fn (SharedOwnershipOutputItem $a, SharedOwnershipOutputItem $b): int => ($b->ownershipEntropy * $b->totalCommits) <=> ($a->ownershipEntropy * $a->totalCommits),
        );

        return new self($items);
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
