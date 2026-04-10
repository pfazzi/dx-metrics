<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\TerritoryMapModule;
use PHPUnit\Framework\TestCase;

/**
 * Documents the ownership-entropy contract for a TerritoryMapModule.
 *
 * TerritoryMapModule aggregates file-level commit counts into a module and derives:
 *   - dominant team  (most commits)
 *   - dominance %    (dominant team's share of all commits)
 *   - ownership entropy (normalised Shannon entropy, 0 = single owner, 1 = perfectly contested)
 *
 * These metrics are identical in design to SharedOwnershipOutputItem but operate at module
 * granularity. Having them independently tested keeps the two classes safe to evolve in parallel.
 */
class TerritoryMapModuleTest extends TestCase
{
    public function test_single_team_ownership_has_zero_entropy(): void
    {
        $module = new TerritoryMapModule('src/Orders', ['platform' => 10]);

        self::assertSame(0.0, $module->ownershipEntropy);
        self::assertSame('platform', $module->dominantTeam);
        self::assertSame(100.0, $module->dominantTeamPercentage);
        self::assertSame(1, $module->teamCount);
        self::assertSame(10, $module->totalCommits);
    }

    public function test_two_teams_equal_split_has_maximum_entropy(): void
    {
        $module = new TerritoryMapModule('src/Orders', [
            'platform' => 5,
            'payments' => 5,
        ]);

        // Normalised Shannon entropy with 2 equal buckets = 1.0
        self::assertSame(1.0, $module->ownershipEntropy);
        self::assertSame(2, $module->teamCount);
        self::assertSame(10, $module->totalCommits);
    }

    public function test_two_teams_unequal_split_has_intermediate_entropy(): void
    {
        $module = new TerritoryMapModule('src/Orders', [
            'platform' => 9,
            'payments' => 1,
        ]);

        // Dominant team has 90% → contested but not equal
        self::assertGreaterThan(0.0, $module->ownershipEntropy);
        self::assertLessThan(1.0, $module->ownershipEntropy);
    }

    public function test_three_teams_equal_split_has_maximum_entropy(): void
    {
        $module = new TerritoryMapModule('src/Orders', [
            'platform' => 3,
            'payments' => 3,
            'identity' => 3,
        ]);

        self::assertSame(1.0, $module->ownershipEntropy);
        self::assertSame(3, $module->teamCount);
    }

    public function test_dominant_team_is_the_one_with_most_commits(): void
    {
        $module = new TerritoryMapModule('src/Orders', [
            'platform' => 2,
            'payments' => 7,
            'identity' => 1,
        ]);

        self::assertSame('payments', $module->dominantTeam);
        self::assertSame(70.0, $module->dominantTeamPercentage);
    }

    public function test_entropy_is_always_normalised_between_zero_and_one(): void
    {
        $module = new TerritoryMapModule('src/Orders', [
            'platform' => 10,
            'payments' => 3,
            'identity' => 2,
        ]);

        self::assertGreaterThanOrEqual(0.0, $module->ownershipEntropy);
        self::assertLessThanOrEqual(1.0, $module->ownershipEntropy);
    }

    public function test_module_name_is_preserved(): void
    {
        $module = new TerritoryMapModule('src/Domain/Orders', ['platform' => 5]);

        self::assertSame('src/Domain/Orders', $module->name);
    }
}
