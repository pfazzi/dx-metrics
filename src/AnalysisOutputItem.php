<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

class AnalysisOutputItem
{
    public function __construct(
        public string $pathA,
        public string $pathB,
        public int $coChangeCount,
    ) {
    }

    public function coChangeCountIncrease(): void
    {
        ++$this->coChangeCount;
    }
}
