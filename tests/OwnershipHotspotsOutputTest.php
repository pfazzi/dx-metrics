<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\OwnershipHotspotsOutput;
use Pfazzi\DxMetrics\SharedOwnershipOutputItem;
use PHPUnit\Framework\TestCase;

class OwnershipHotspotsOutputTest extends TestCase
{
    public function test_sort_by_risk_desc_orders_by_entropy_times_total_commits(): void
    {
        // high entropy but few commits → lower risk
        $lowRisk = new SharedOwnershipOutputItem('src/Rare.php', ['team-a' => 2, 'team-b' => 2]);
        // lower entropy but many commits → higher risk
        $highRisk = new SharedOwnershipOutputItem('src/Hot.php', ['team-a' => 60, 'team-b' => 40]);

        $output = new OwnershipHotspotsOutput([$lowRisk, $highRisk]);
        $sorted = $output->sortByRiskDesc();

        self::assertSame('src/Hot.php', $sorted->items[0]->filePath);
        self::assertSame('src/Rare.php', $sorted->items[1]->filePath);
    }

    public function test_sort_by_risk_desc_breaks_tie_by_commits_when_entropy_is_equal(): void
    {
        $moreCommits = new SharedOwnershipOutputItem('src/A.php', ['team-a' => 50, 'team-b' => 50]);
        $fewerCommits = new SharedOwnershipOutputItem('src/B.php', ['team-a' => 10, 'team-b' => 10]);

        $output = new OwnershipHotspotsOutput([$fewerCommits, $moreCommits]);
        $sorted = $output->sortByRiskDesc();

        self::assertSame('src/A.php', $sorted->items[0]->filePath);
    }

    public function test_filter_by_min_teams_excludes_single_team_files(): void
    {
        $singleTeam = new SharedOwnershipOutputItem('src/Clean.php', ['team-a' => 10]);
        $multiTeam = new SharedOwnershipOutputItem('src/Shared.php', ['team-a' => 5, 'team-b' => 5]);

        $output = new OwnershipHotspotsOutput([$singleTeam, $multiTeam]);
        $filtered = $output->filterByMinTeams(2);

        self::assertCount(1, $filtered->items);
        self::assertSame('src/Shared.php', $filtered->items[0]->filePath);
    }

    public function test_filter_by_path_keeps_only_matching_prefix(): void
    {
        $srcFile = new SharedOwnershipOutputItem('src/Order.php', ['team-a' => 5, 'team-b' => 5]);
        $testFile = new SharedOwnershipOutputItem('tests/OrderTest.php', ['team-a' => 3, 'team-b' => 3]);

        $output = new OwnershipHotspotsOutput([$srcFile, $testFile]);
        $filtered = $output->filterByPath('src/');

        self::assertCount(1, $filtered->items);
        self::assertSame('src/Order.php', $filtered->items[0]->filePath);
    }

    public function test_filter_by_path_null_returns_all_items(): void
    {
        $a = new SharedOwnershipOutputItem('src/A.php', ['team-a' => 5, 'team-b' => 5]);
        $b = new SharedOwnershipOutputItem('src/B.php', ['team-a' => 5, 'team-b' => 5]);

        $output = new OwnershipHotspotsOutput([$a, $b]);
        $filtered = $output->filterByPath(null);

        self::assertCount(2, $filtered->items);
    }

    public function test_methods_return_new_instance(): void
    {
        $item = new SharedOwnershipOutputItem('src/A.php', ['team-a' => 5, 'team-b' => 5]);
        $output = new OwnershipHotspotsOutput([$item]);

        self::assertNotSame($output, $output->sortByRiskDesc());
        self::assertNotSame($output, $output->filterByMinTeams(2));
        self::assertNotSame($output, $output->filterByPath('src/'));
    }
}
