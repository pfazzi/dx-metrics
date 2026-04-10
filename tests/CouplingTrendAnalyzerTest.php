<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\CouplingTrendAnalyzer;
use Pfazzi\DxMetrics\Git;
use Pfazzi\DxMetrics\TeamConfig;
use PHPUnit\Framework\TestCase;

/**
 * Documents how CouplingTrendAnalyzer classifies co-changes as same-team or cross-team.
 *
 * A co-change is a pair of files modified in the same commit.
 * A cross-team co-change is one where the two files are owned by different teams.
 * File ownership is resolved by the dominant team in the selected window.
 *
 * Key invariants:
 *   - company index = cross-team co-changes / total co-changes  (0 = no cross-team, 1 = all cross-team)
 *   - team coupling score = team's cross-team co-changes / team's total co-changes
 *   - pair keys use the format "teamA|||teamB" with teams alphabetically sorted
 */
class CouplingTrendAnalyzerTest extends TestCase
{
    use GitTestTrait;

    private string $repoPath;
    private string $teamsFile;

    protected function setUp(): void
    {
        $this->repoPath = $this->makeTempDir();
        $this->setUpTestRepo($this->repoPath);
        $this->teamsFile = sys_get_temp_dir().'/teams-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        $this->cleanUpTestRepo($this->repoPath);
        if (file_exists($this->teamsFile)) {
            unlink($this->teamsFile);
        }
    }

    public function test_company_index_is_zero_when_all_co_changes_are_within_same_team(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);

        // Both files touched by alice in the same commit → same-team co-change
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: add order and invoice', [
                '/src/Order.php' => "v1\n",
                '/src/Invoice.php' => "v1\n",
            ], 'alice@example.com');

        [$period] = $this->makeAnalyzer()->analyze(
            [['from' => new \DateTimeImmutable('2024-01-01'), 'to' => new \DateTimeImmutable('2024-12-31')]],
            depth: 1,
            excludePatterns: [],
            filter: null,
        );

        self::assertSame(0.0, $period->companyCouplingIndex);
        self::assertSame(0, $period->crossTeamCoChanges);
        self::assertSame([], $period->teamPairCoChanges);
    }

    public function test_company_index_is_one_when_all_co_changes_are_cross_team(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        // Establish ownership: alice owns Order.php, bob owns Invoice.php
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: platform work', ['/src/Order.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: platform work 2', ['/src/Order.php' => "v2\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-03T12:00:00+0000'),
            'feat: payments work', ['/src/Invoice.php' => "v1\n"], 'bob@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-04T12:00:00+0000'),
            'feat: payments work 2', ['/src/Invoice.php' => "v2\n"], 'bob@example.com');

        // Cross-team co-change: alice commits both files → Order (platform) ↔ Invoice (payments)
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: cross-team change', [
                '/src/Order.php' => "v3\n",
                '/src/Invoice.php' => "v3\n",
            ], 'alice@example.com');

        [$period] = $this->makeAnalyzer()->analyze(
            [['from' => new \DateTimeImmutable('2024-01-01'), 'to' => new \DateTimeImmutable('2024-12-31')]],
            depth: 1,
            excludePatterns: [],
            filter: null,
        );

        self::assertSame(1.0, $period->companyCouplingIndex);
        self::assertSame($period->totalCoChanges, $period->crossTeamCoChanges);
    }

    public function test_team_pair_key_is_alphabetically_sorted_with_pipe_separator(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        // Establish unambiguous ownership: alice owns Order.php (2 commits), bob owns Invoice.php (3 commits)
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: platform', ['/src/Order.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: payments 1', ['/src/Invoice.php' => "v1\n"], 'bob@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-03T12:00:00+0000'),
            'feat: payments 2', ['/src/Invoice.php' => "v2\n"], 'bob@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-04T12:00:00+0000'),
            'feat: payments 3', ['/src/Invoice.php' => "v3\n"], 'bob@example.com');

        // Cross-team co-change: alice commits both → Order(platform) ↔ Invoice(payments dominant: bob 3/4)
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: cross-team', [
                '/src/Order.php' => "v2\n",
                '/src/Invoice.php' => "v4\n",
            ], 'alice@example.com');

        [$period] = $this->makeAnalyzer()->analyze(
            [['from' => new \DateTimeImmutable('2024-01-01'), 'to' => new \DateTimeImmutable('2024-12-31')]],
            depth: 1,
            excludePatterns: [],
            filter: null,
        );

        // "payments" < "platform" alphabetically → key must be "payments|||platform"
        self::assertArrayHasKey('payments|||platform', $period->teamPairCoChanges);
        self::assertArrayNotHasKey('platform|||payments', $period->teamPairCoChanges);
    }

    public function test_team_coupling_score_reflects_each_teams_cross_team_ratio(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        // platform-only co-change: two platform files change together
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: platform only', [
                '/src/Order.php' => "v1\n",
                '/src/OrderItem.php' => "v1\n",
            ], 'alice@example.com');

        // Establish bob's ownership of Invoice.php (3 commits → 3/4 dominance after alice's cross-team commit)
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: bob invoice 1', ['/src/Invoice.php' => "v1\n"], 'bob@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-03T12:00:00+0000'),
            'feat: bob invoice 2', ['/src/Invoice.php' => "v2\n"], 'bob@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-04T12:00:00+0000'),
            'feat: bob invoice 3', ['/src/Invoice.php' => "v3\n"], 'bob@example.com');

        // Cross-team co-change: Order (platform) ↔ Invoice (payments)
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: cross', [
                '/src/Order.php' => "v2\n",
                '/src/Invoice.php' => "v4\n",
            ], 'alice@example.com');

        [$period] = $this->makeAnalyzer()->analyze(
            [['from' => new \DateTimeImmutable('2024-01-01'), 'to' => new \DateTimeImmutable('2024-12-31')]],
            depth: 1,
            excludePatterns: [],
            filter: null,
        );

        // The algorithm increments `total` for BOTH files in a pair, so a same-team pair where
        // both files belong to platform counts as 2 towards platform's total.
        //
        // Unique pairs:
        //   (Order=platform, OrderItem=platform) → same-team: platform.total += 2 (both sides)
        //   (Order=platform, Invoice=payments)   → cross-team: platform.total += 1, payments.total += 1
        //
        // platform: total=3, cross=1, score=1/3 ≈ 0.333
        // payments: total=1, cross=1, score=1/1 = 1.0
        self::assertArrayHasKey('platform', $period->teamCouplingScores);
        self::assertEqualsWithDelta(0.333, $period->teamCouplingScores['platform'], 0.01);

        // payments only appears in the cross-team pair → score = 1.0
        self::assertArrayHasKey('payments', $period->teamCouplingScores);
        self::assertEqualsWithDelta(1.0, $period->teamCouplingScores['payments'], 0.01);
    }

    public function test_multiple_windows_produce_one_period_each(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-15T12:00:00+0000'),
            'feat: january', ['/src/A.php' => "v1\n", '/src/B.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-02-15T12:00:00+0000'),
            'feat: february', ['/src/A.php' => "v2\n", '/src/B.php' => "v2\n"], 'alice@example.com');

        $periods = $this->makeAnalyzer()->analyze(
            [
                ['from' => new \DateTimeImmutable('2024-01-01'), 'to' => new \DateTimeImmutable('2024-01-31')],
                ['from' => new \DateTimeImmutable('2024-02-01'), 'to' => new \DateTimeImmutable('2024-02-28')],
            ],
            depth: 1,
            excludePatterns: [],
            filter: null,
        );

        self::assertCount(2, $periods);
        self::assertGreaterThan(0, $periods[0]->totalCoChanges);
        self::assertGreaterThan(0, $periods[1]->totalCoChanges);
    }

    private function makeAnalyzer(): CouplingTrendAnalyzer
    {
        return new CouplingTrendAnalyzer(
            new Git($this->repoPath),
            TeamConfig::fromFile($this->teamsFile),
        );
    }

    /** @param array<string, mixed> $data */
    private function writeTeams(array $data): void
    {
        file_put_contents($this->teamsFile, json_encode($data));
    }
}
