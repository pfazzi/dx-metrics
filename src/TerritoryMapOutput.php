<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

readonly class TerritoryMapOutput
{
    /**
     * @param TerritoryMapModule[] $modules
     * @param TerritoryMapEdge[]   $edges
     */
    public function __construct(
        public array $modules,
        public array $edges,
    ) {
    }
}
