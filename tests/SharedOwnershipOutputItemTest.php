<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\SharedOwnershipOutputItem;
use PHPUnit\Framework\TestCase;

class SharedOwnershipOutputItemTest extends TestCase
{
    public function test_single_team_ownership_has_zero_entropy(): void
    {
        $item = new SharedOwnershipOutputItem('src/Foo.php', ['platform' => 10]);

        self::assertSame(0.0, $item->ownershipEntropy);
        self::assertSame('platform', $item->dominantTeam);
        self::assertSame(100.0, $item->dominantTeamPercentage);
        self::assertSame(1, $item->teamCount);
        self::assertSame(10, $item->totalCommits);
    }

    public function test_two_teams_equal_split_has_maximum_entropy(): void
    {
        $item = new SharedOwnershipOutputItem('src/Foo.php', [
            'platform' => 5,
            'payments' => 5,
        ]);

        // 2 teams, 50/50 → entropy = 1.0
        self::assertSame(1.0, $item->ownershipEntropy);
        self::assertSame(2, $item->teamCount);
        self::assertSame(10, $item->totalCommits);
    }

    public function test_two_teams_unequal_split_has_intermediate_entropy(): void
    {
        $item = new SharedOwnershipOutputItem('src/Foo.php', [
            'platform' => 9,
            'payments' => 1,
        ]);

        // Dominant team has 90% → entropy should be low but > 0
        self::assertGreaterThan(0.0, $item->ownershipEntropy);
        self::assertLessThan(1.0, $item->ownershipEntropy);
    }

    public function test_three_teams_equal_split_has_maximum_entropy(): void
    {
        $item = new SharedOwnershipOutputItem('src/Foo.php', [
            'platform' => 3,
            'payments' => 3,
            'identity' => 3,
        ]);

        self::assertSame(1.0, $item->ownershipEntropy);
        self::assertSame(3, $item->teamCount);
    }

    public function test_dominant_team_is_the_one_with_most_commits(): void
    {
        $item = new SharedOwnershipOutputItem('src/Foo.php', [
            'platform' => 2,
            'payments' => 7,
            'identity' => 1,
        ]);

        self::assertSame('payments', $item->dominantTeam);
        self::assertSame(70.0, $item->dominantTeamPercentage);
    }

    public function test_entropy_is_between_zero_and_one(): void
    {
        $item = new SharedOwnershipOutputItem('src/Foo.php', [
            'platform' => 10,
            'payments' => 3,
            'identity' => 2,
        ]);

        self::assertGreaterThanOrEqual(0.0, $item->ownershipEntropy);
        self::assertLessThanOrEqual(1.0, $item->ownershipEntropy);
    }
}
