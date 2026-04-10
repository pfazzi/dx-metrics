<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\Git;
use Pfazzi\DxMetrics\TeamConfig;
use Pfazzi\DxMetrics\TerritoryMapAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Documents how TerritoryMapAnalyzer groups files into modules and computes coupling edges.
 *
 * Module grouping:
 *   - A module is defined by the first N path segments (controlled by the `depth` parameter).
 *   - e.g., at depth=2: "src/Orders/Order.php" → module "src/Orders"
 *   - Files in the same module have their team commit counts merged.
 *
 * Edge rules:
 *   - An edge exists between two modules when files from each module appear in the same commit.
 *   - Files co-changed within the same module do NOT produce an edge.
 *   - Pair order is normalised (A < B alphabetically) so each edge appears once.
 */
class TerritoryMapAnalyzerTest extends TestCase
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

    public function test_files_are_grouped_into_modules_by_depth(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);

        // Three files: two in src/Orders, one in src/Invoices
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: add domain files', [
                '/src/Orders/Order.php' => "v1\n",
                '/src/Orders/OrderItem.php' => "v1\n",
                '/src/Invoices/Invoice.php' => "v1\n",
            ], 'alice@example.com');

        $result = $this->makeAnalyzer()->analyze(depth: 2);

        $moduleNames = array_map(static fn ($m) => $m->name, $result->modules);
        sort($moduleNames);

        self::assertSame(['src/Invoices', 'src/Orders'], $moduleNames);
    }

    public function test_team_commits_are_aggregated_across_files_within_same_module(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        // alice touches both files in src/Orders at different times
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: alice order', ['/src/Orders/Order.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: bob order item', ['/src/Orders/OrderItem.php' => "v1\n"], 'bob@example.com');

        $result = $this->makeAnalyzer()->analyze(depth: 2);

        $ordersModule = null;
        foreach ($result->modules as $module) {
            if ('src/Orders' === $module->name) {
                $ordersModule = $module;
                break;
            }
        }

        self::assertNotNull($ordersModule);
        self::assertSame(2, $ordersModule->totalCommits);
        // Both teams contributed → shared ownership
        self::assertGreaterThan(0.0, $ordersModule->ownershipEntropy);
    }

    public function test_co_changes_within_same_module_do_not_produce_an_edge(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);

        // Two files in the same module changed together
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: two files same module', [
                '/src/Orders/Order.php' => "v1\n",
                '/src/Orders/OrderItem.php' => "v1\n",
            ], 'alice@example.com');

        $result = $this->makeAnalyzer()->analyze(depth: 2);

        self::assertSame([], $result->edges);
    }

    public function test_co_changes_between_different_modules_produce_an_edge(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: cross-module change', [
                '/src/Orders/Order.php' => "v1\n",
                '/src/Invoices/Invoice.php' => "v1\n",
            ], 'alice@example.com');

        $result = $this->makeAnalyzer()->analyze(depth: 2);

        self::assertCount(1, $result->edges);
        $edge = $result->edges[0];

        // Pair is always normalised: "src/Invoices" < "src/Orders"
        self::assertSame('src/Invoices', $edge->moduleA);
        self::assertSame('src/Orders', $edge->moduleB);
        self::assertSame(1, $edge->coChanges);
    }

    public function test_multiple_co_changes_between_same_modules_accumulate_on_single_edge(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);

        for ($i = 1; $i <= 3; ++$i) {
            $this->commit($this->repoPath, new \DateTimeImmutable("2024-01-0{$i}T12:00:00+0000"),
                "feat: co-change {$i}", [
                    '/src/Orders/Order.php' => "v{$i}\n",
                    '/src/Invoices/Invoice.php' => "v{$i}\n",
                ], 'alice@example.com');
        }

        $result = $this->makeAnalyzer()->analyze(depth: 2);

        self::assertCount(1, $result->edges);
        self::assertSame(3, $result->edges[0]->coChanges);
    }

    public function test_depth_one_groups_by_top_level_directory(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: deep files', [
                '/src/Domain/Orders/Order.php' => "v1\n",
                '/src/Domain/Invoices/Invoice.php' => "v1\n",
            ], 'alice@example.com');

        $result = $this->makeAnalyzer()->analyze(depth: 1);

        // At depth=1, both files resolve to module "src" → no edge between them
        $moduleNames = array_map(static fn ($m) => $m->name, $result->modules);
        self::assertSame(['src'], $moduleNames);
        self::assertSame([], $result->edges);
    }

    private function makeAnalyzer(): TerritoryMapAnalyzer
    {
        return new TerritoryMapAnalyzer(
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
