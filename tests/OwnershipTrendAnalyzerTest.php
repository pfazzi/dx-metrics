<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\Git;
use Pfazzi\DxMetrics\OwnershipTrendAnalyzer;
use Pfazzi\DxMetrics\TeamConfig;
use PHPUnit\Framework\TestCase;

class OwnershipTrendAnalyzerTest extends TestCase
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

    public function test_module_with_single_team_has_zero_entropy_in_all_periods(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
            ],
        ]);

        // Period 1: alice commits to src/core/
        $this->commitAs('alice@example.com', 'feat: core v1', [
            '/src/core/Service.php' => "v1\n",
        ], new \DateTimeImmutable('2024-01-05T12:00:00+0000'));

        // Period 2: alice again
        $this->commitAs('alice@example.com', 'feat: core v2', [
            '/src/core/Service.php' => "v2\n",
        ], new \DateTimeImmutable('2024-02-05T12:00:00+0000'));

        $windows = [
            ['from' => new \DateTimeImmutable('2024-01-01'), 'to' => new \DateTimeImmutable('2024-01-31')],
            ['from' => new \DateTimeImmutable('2024-02-01'), 'to' => new \DateTimeImmutable('2024-02-28')],
        ];

        $periods = $this->makeAnalyzer()->analyze($windows, 2, [], null);

        self::assertCount(2, $periods);

        foreach ($periods as $period) {
            $entropy = $period->moduleEntropies['src/core'] ?? null;
            self::assertNotNull($entropy, 'Module src/core should be present in period '.$period->periodLabel());
            self::assertSame(0.0, $entropy, 'Single-team module should have zero entropy in period '.$period->periodLabel());
            self::assertSame('platform', $period->moduleDominantTeams['src/core']);
        }
    }

    public function test_entropy_rises_when_second_team_joins_in_later_period(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        // Period 1: only alice commits to src/shared/
        $this->commitAs('alice@example.com', 'feat: shared v1', [
            '/src/shared/Utils.php' => "v1\n",
        ], new \DateTimeImmutable('2024-01-05T12:00:00+0000'));

        // Period 2: both alice and bob commit to src/shared/
        $this->commitAs('alice@example.com', 'feat: shared v2', [
            '/src/shared/Utils.php' => "v2\n",
        ], new \DateTimeImmutable('2024-02-05T12:00:00+0000'));

        $this->commitAs('bob@example.com', 'feat: shared v3 by payments', [
            '/src/shared/Utils.php' => "v3\n",
        ], new \DateTimeImmutable('2024-02-15T12:00:00+0000'));

        $windows = [
            ['from' => new \DateTimeImmutable('2024-01-01'), 'to' => new \DateTimeImmutable('2024-01-31')],
            ['from' => new \DateTimeImmutable('2024-02-01'), 'to' => new \DateTimeImmutable('2024-02-28')],
        ];

        $periods = $this->makeAnalyzer()->analyze($windows, 2, [], null);

        self::assertCount(2, $periods);

        $period1 = $periods[0];
        $period2 = $periods[1];

        // Period 1: only alice — entropy should be 0
        $entropy1 = $period1->moduleEntropies['src/shared'] ?? null;
        self::assertNotNull($entropy1, 'Module src/shared should be present in period 1');
        self::assertSame(0.0, $entropy1, 'Single-team module should have zero entropy in period 1');
        self::assertSame('platform', $period1->moduleDominantTeams['src/shared']);

        // Period 2: alice + bob — entropy should be > 0
        $entropy2 = $period2->moduleEntropies['src/shared'] ?? null;
        self::assertNotNull($entropy2, 'Module src/shared should be present in period 2');
        self::assertGreaterThan(0.0, $entropy2, 'Multi-team module should have entropy > 0 in period 2');
    }

    private function makeAnalyzer(): OwnershipTrendAnalyzer
    {
        return new OwnershipTrendAnalyzer(
            new Git($this->repoPath),
            TeamConfig::fromFile($this->teamsFile),
        );
    }

    private function commitAs(string $email, string $message, array $files, ?\DateTimeImmutable $date = null): void
    {
        $this->commit(
            repoPath: $this->repoPath,
            date: $date ?? new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            message: $message,
            files: $files,
            authorEmail: $email,
        );
    }

    private function writeTeams(array $data): void
    {
        file_put_contents($this->teamsFile, json_encode($data));
    }
}
