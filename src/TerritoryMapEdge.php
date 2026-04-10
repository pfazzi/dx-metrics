<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

readonly class TerritoryMapEdge
{
    public function __construct(
        public string $moduleA,
        public string $moduleB,
        public int $coChanges,
    ) {
    }
}
