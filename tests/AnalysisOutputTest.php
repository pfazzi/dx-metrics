<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\AnalysisOutput;
use Pfazzi\DxMetrics\AnalysisOutputItem;
use PHPUnit\Framework\TestCase;

class AnalysisOutputTest extends TestCase
{
    public function test_filter_by_path__with_null__returns_all_items(): void
    {
        $output = new AnalysisOutput(
            new AnalysisOutputItem('src/A.php', 'src/B.php', 3),
            new AnalysisOutputItem('tests/ATest.php', 'tests/BTest.php', 1),
        );

        $filtered = $output->filterByPath(null);

        self::assertCount(2, $filtered->items);
    }

    public function test_filter_by_path__keeps_only_pairs_where_both_files_match_prefix(): void
    {
        $output = new AnalysisOutput(
            new AnalysisOutputItem('src/A.php', 'src/B.php', 3),
            new AnalysisOutputItem('src/A.php', 'tests/ATest.php', 2),
            new AnalysisOutputItem('tests/ATest.php', 'tests/BTest.php', 1),
        );

        $filtered = $output->filterByPath('src/');

        self::assertCount(1, $filtered->items);
        self::assertSame('src/A.php', $filtered->items[0]->pathA);
        self::assertSame('src/B.php', $filtered->items[0]->pathB);
    }

    public function test_filter_by_path__excludes_pair_when_only_one_file_matches(): void
    {
        $output = new AnalysisOutput(
            new AnalysisOutputItem('src/A.php', 'config/Config.php', 5),
        );

        $filtered = $output->filterByPath('src/');

        self::assertCount(0, $filtered->items);
    }

    public function test_filter_by_co_changes_threshold__with_zero__returns_all(): void
    {
        $output = new AnalysisOutput(
            new AnalysisOutputItem('src/A.php', 'src/B.php', 1),
            new AnalysisOutputItem('src/C.php', 'src/D.php', 2),
        );

        $filtered = $output->filterByCoChangesThreshold(0);

        self::assertCount(2, $filtered->items);
    }

    public function test_filter_by_co_changes_threshold__with_negative__returns_all(): void
    {
        $output = new AnalysisOutput(
            new AnalysisOutputItem('src/A.php', 'src/B.php', 1),
        );

        $filtered = $output->filterByCoChangesThreshold(-1);

        self::assertCount(1, $filtered->items);
    }

    public function test_filter_by_co_changes_threshold__excludes_items_below_threshold(): void
    {
        $output = new AnalysisOutput(
            new AnalysisOutputItem('src/A.php', 'src/B.php', 5),
            new AnalysisOutputItem('src/C.php', 'src/D.php', 2),
            new AnalysisOutputItem('src/E.php', 'src/F.php', 3),
        );

        $filtered = $output->filterByCoChangesThreshold(3);

        self::assertCount(2, $filtered->items);
        foreach ($filtered->items as $item) {
            self::assertGreaterThanOrEqual(3, $item->coChangeCount);
        }
    }

    public function test_get_unique_file_pairs__removes_symmetric_duplicates(): void
    {
        // Analyzer produces both A→B and B→A for each co-change
        $output = new AnalysisOutput(
            new AnalysisOutputItem('src/A.php', 'src/B.php', 2),
            new AnalysisOutputItem('src/B.php', 'src/A.php', 2),
            new AnalysisOutputItem('src/C.php', 'src/D.php', 1),
            new AnalysisOutputItem('src/D.php', 'src/C.php', 1),
        );

        $unique = $output->getUniqueFilePairs();

        self::assertCount(2, $unique->items);
    }

    public function test_get_unique_file_pairs__keeps_first_occurrence(): void
    {
        $output = new AnalysisOutput(
            new AnalysisOutputItem('src/A.php', 'src/B.php', 3),
            new AnalysisOutputItem('src/B.php', 'src/A.php', 3),
        );

        $unique = $output->getUniqueFilePairs();

        self::assertSame('src/A.php', $unique->items[0]->pathA);
        self::assertSame('src/B.php', $unique->items[0]->pathB);
    }

    public function test_sort_by_co_changes_desc__orders_highest_first(): void
    {
        $output = new AnalysisOutput(
            new AnalysisOutputItem('src/A.php', 'src/B.php', 1),
            new AnalysisOutputItem('src/C.php', 'src/D.php', 5),
            new AnalysisOutputItem('src/E.php', 'src/F.php', 3),
        );

        $sorted = $output->sortByCoChangesDesc();

        self::assertSame(5, $sorted->items[0]->coChangeCount);
        self::assertSame(3, $sorted->items[1]->coChangeCount);
        self::assertSame(1, $sorted->items[2]->coChangeCount);
    }

    public function test_filter_by_path__with_empty_string__matches_all_pairs(): void
    {
        $output = new AnalysisOutput(
            new AnalysisOutputItem('src/A.php', 'src/B.php', 2),
            new AnalysisOutputItem('tests/ATest.php', 'tests/BTest.php', 1),
        );

        $filtered = $output->filterByPath('');

        self::assertCount(2, $filtered->items);
    }

    public function test_get_unique_file_pairs__with_no_duplicates__returns_same_count(): void
    {
        $output = new AnalysisOutput(
            new AnalysisOutputItem('src/A.php', 'src/B.php', 3),
            new AnalysisOutputItem('src/C.php', 'src/D.php', 1),
        );

        $unique = $output->getUniqueFilePairs();

        self::assertCount(2, $unique->items);
    }

    public function test_empty_output__pipelines_return_empty_output(): void
    {
        $output = new AnalysisOutput();

        $result = $output
            ->filterByPath('src/')
            ->filterByCoChangesThreshold(1)
            ->getUniqueFilePairs()
            ->sortByCoChangesDesc();

        self::assertCount(0, $result->items);
    }

    public function test_filter_by_excluded_patterns__with_empty_patterns__returns_all(): void
    {
        $output = new AnalysisOutput(
            new AnalysisOutputItem('src/A.php', 'src/B.php', 3),
            new AnalysisOutputItem('Cargo.lock', 'src/B.php', 2),
        );

        $filtered = $output->filterByExcludedPatterns([]);

        self::assertCount(2, $filtered->items);
    }

    public function test_filter_by_excluded_patterns__excludes_pair_when_path_a_matches(): void
    {
        $output = new AnalysisOutput(
            new AnalysisOutputItem('Cargo.lock', 'src/Order.php', 5),
            new AnalysisOutputItem('src/A.php', 'src/B.php', 3),
        );

        $filtered = $output->filterByExcludedPatterns(['Cargo.lock']);

        self::assertCount(1, $filtered->items);
        self::assertSame('src/A.php', $filtered->items[0]->pathA);
    }

    public function test_filter_by_excluded_patterns__excludes_pair_when_path_b_matches(): void
    {
        $output = new AnalysisOutput(
            new AnalysisOutputItem('src/Order.php', 'Cargo.lock', 5),
            new AnalysisOutputItem('src/A.php', 'src/B.php', 3),
        );

        $filtered = $output->filterByExcludedPatterns(['Cargo.lock']);

        self::assertCount(1, $filtered->items);
        self::assertSame('src/A.php', $filtered->items[0]->pathA);
    }

    public function test_filter_by_excluded_patterns__glob_pattern_matches_nested_paths(): void
    {
        $output = new AnalysisOutput(
            new AnalysisOutputItem('.sqlx/query-abc123.json', 'src/Order.php', 4),
            new AnalysisOutputItem('src/A.php', 'src/B.php', 3),
        );

        $filtered = $output->filterByExcludedPatterns(['.sqlx/*']);

        self::assertCount(1, $filtered->items);
        self::assertSame('src/A.php', $filtered->items[0]->pathA);
    }

    public function test_filter_by_excluded_patterns__multiple_patterns(): void
    {
        $output = new AnalysisOutput(
            new AnalysisOutputItem('Cargo.lock', 'src/A.php', 5),
            new AnalysisOutputItem('.sqlx/query.json', 'src/B.php', 4),
            new AnalysisOutputItem('src/A.php', 'src/B.php', 3),
        );

        $filtered = $output->filterByExcludedPatterns(['Cargo.lock', '.sqlx/*']);

        self::assertCount(1, $filtered->items);
        self::assertSame('src/A.php', $filtered->items[0]->pathA);
    }
}
