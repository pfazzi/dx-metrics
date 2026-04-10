<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\SharedOwnershipOutput;
use Pfazzi\DxMetrics\SharedOwnershipOutputItem;
use PHPUnit\Framework\TestCase;

class SharedOwnershipOutputTest extends TestCase
{
    public function test_filter_by_min_teams__excludes_single_team_files(): void
    {
        $output = new SharedOwnershipOutput(
            new SharedOwnershipOutputItem('src/Owned.php', ['platform' => 5]),
            new SharedOwnershipOutputItem('src/Shared.php', ['platform' => 3, 'payments' => 2]),
        );

        $filtered = $output->filterByMinTeams(2);

        self::assertCount(1, $filtered->items);
        self::assertSame('src/Shared.php', $filtered->items[0]->filePath);
    }

    public function test_filter_by_min_teams__with_one_includes_all(): void
    {
        $output = new SharedOwnershipOutput(
            new SharedOwnershipOutputItem('src/A.php', ['platform' => 5]),
            new SharedOwnershipOutputItem('src/B.php', ['platform' => 3, 'payments' => 2]),
        );

        $filtered = $output->filterByMinTeams(1);

        self::assertCount(2, $filtered->items);
    }

    public function test_filter_by_path__keeps_only_matching_files(): void
    {
        $output = new SharedOwnershipOutput(
            new SharedOwnershipOutputItem('src/Order.php', ['platform' => 3, 'payments' => 2]),
            new SharedOwnershipOutputItem('config/Config.php', ['platform' => 2, 'payments' => 1]),
        );

        $filtered = $output->filterByPath('src/');

        self::assertCount(1, $filtered->items);
        self::assertSame('src/Order.php', $filtered->items[0]->filePath);
    }

    public function test_filter_by_path__with_null_returns_all(): void
    {
        $output = new SharedOwnershipOutput(
            new SharedOwnershipOutputItem('src/A.php', ['platform' => 3, 'payments' => 2]),
            new SharedOwnershipOutputItem('config/B.php', ['platform' => 2, 'payments' => 1]),
        );

        $filtered = $output->filterByPath(null);

        self::assertCount(2, $filtered->items);
    }

    public function test_sort_by_entropy_desc__orders_most_shared_first(): void
    {
        $output = new SharedOwnershipOutput(
            new SharedOwnershipOutputItem('src/A.php', ['platform' => 9, 'payments' => 1]),   // low entropy
            new SharedOwnershipOutputItem('src/B.php', ['platform' => 5, 'payments' => 5]),   // entropy = 1.0
            new SharedOwnershipOutputItem('src/C.php', ['platform' => 7, 'payments' => 3]),   // medium entropy
        );

        $sorted = $output->sortByEntropyDesc();

        self::assertSame('src/B.php', $sorted->items[0]->filePath);
        self::assertSame('src/C.php', $sorted->items[1]->filePath);
        self::assertSame('src/A.php', $sorted->items[2]->filePath);
    }

    public function test_empty_output__all_filters_return_empty(): void
    {
        $output = new SharedOwnershipOutput();

        $result = $output
            ->filterByPath('src/')
            ->filterByMinTeams(2)
            ->sortByEntropyDesc();

        self::assertCount(0, $result->items);
    }

    public function test_filter_by_excluded_patterns__with_empty_patterns__returns_all(): void
    {
        $output = new SharedOwnershipOutput(
            new SharedOwnershipOutputItem('Cargo.lock', ['team-a' => 3, 'team-b' => 2]),
            new SharedOwnershipOutputItem('src/Order.php', ['team-a' => 3, 'team-b' => 2]),
        );

        $filtered = $output->filterByExcludedPatterns([]);

        self::assertCount(2, $filtered->items);
    }

    public function test_filter_by_excluded_patterns__excludes_exact_match(): void
    {
        $output = new SharedOwnershipOutput(
            new SharedOwnershipOutputItem('Cargo.lock', ['team-a' => 3, 'team-b' => 2]),
            new SharedOwnershipOutputItem('src/Order.php', ['team-a' => 3, 'team-b' => 2]),
        );

        $filtered = $output->filterByExcludedPatterns(['Cargo.lock']);

        self::assertCount(1, $filtered->items);
        self::assertSame('src/Order.php', $filtered->items[0]->filePath);
    }

    public function test_filter_by_excluded_patterns__glob_matches_nested_path(): void
    {
        $output = new SharedOwnershipOutput(
            new SharedOwnershipOutputItem('.sqlx/query-abc123.json', ['team-a' => 3, 'team-b' => 2]),
            new SharedOwnershipOutputItem('src/Order.php', ['team-a' => 3, 'team-b' => 2]),
        );

        $filtered = $output->filterByExcludedPatterns(['.sqlx/*']);

        self::assertCount(1, $filtered->items);
        self::assertSame('src/Order.php', $filtered->items[0]->filePath);
    }
}
