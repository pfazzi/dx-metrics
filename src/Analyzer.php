<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

readonly class Analyzer
{
    public function __construct(private Git $git)
    {
    }

    public function analyze(?\DateTimeImmutable $since = null, ?\DateTimeImmutable $until = null): AnalysisOutput
    {
        $coupling = [];

        $commits = $this->git->getCommitsSha($since, $until);

        foreach ($commits as $commit) {
            $changes = $this->git->getChangesFromCommit($commit);

            foreach ($changes as $fileChangedLeft) {
                foreach ($changes as $fileChangedRight) {
                    if ($fileChangedLeft === $fileChangedRight) {
                        continue;
                    }

                    $coupling[$fileChangedLeft.$fileChangedRight] ??= new AnalysisOutputItem(
                        $fileChangedLeft,
                        $fileChangedRight,
                        0,
                    );

                    $coupling[$fileChangedLeft.$fileChangedRight]->coChangeCountIncrease();
                }
            }
        }

        return new AnalysisOutput(...array_values($coupling));
    }
}
