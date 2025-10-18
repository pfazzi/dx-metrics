<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

readonly class Analyzer
{
    public function __construct(private Git $git)
    {
    }

    public function computeCoupling(): array
    {
        $coupling = [];

        $commits = $this->git->getCommitsSha();

        foreach ($commits as $commit) {
            $changes = $this->git->getChangesFromCommit($commit);

            foreach ($changes as $fileChangedLeft) {
                foreach ($changes as $fileChangedRight) {
                    if ($fileChangedLeft === $fileChangedRight) {
                        continue;
                    }

                    $coupling[$fileChangedLeft.$fileChangedRight] ??= [
                        'files' => [$fileChangedLeft, $fileChangedRight],
                        'changes' => 0,
                    ];

                    ++$coupling[$fileChangedLeft.$fileChangedRight]['changes'];
                }
            }
        }

        return $coupling;
    }
}
