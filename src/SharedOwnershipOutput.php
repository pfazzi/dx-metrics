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
}
