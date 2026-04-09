<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\Git;
use Pfazzi\DxMetrics\SharedOwnershipAnalyzer;
use Pfazzi\DxMetrics\TeamConfig;
use PHPUnit\Framework\TestCase;

class SharedOwnershipAnalyzerTest extends TestCase
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

    public function test_analyze__empty_repo__returns_empty_output(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);
        $analyzer = $this->makeAnalyzer();

        $result = $analyzer->analyze();

        self::assertCount(0, $result->items);
    }

    public function test_analyze__single_author__files_owned_by_one_team(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);

        $this->commitAs('alice@example.com', 'feat: add files', [
            '/src/Order.php' => "content\n",
            '/src/Invoice.php' => "content\n",
        ]);

        $result = $this->makeAnalyzer()->analyze();

        foreach ($result->items as $item) {
            self::assertSame(1, $item->teamCount);
            self::assertSame('platform', $item->dominantTeam);
            self::assertSame(0.0, $item->ownershipEntropy);
        }
    }

    public function test_analyze__two_teams_touch_same_file__entropy_greater_than_zero(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        $this->commitAs('alice@example.com', 'feat: platform change', [
            '/src/Shared.php' => "v1\n",
        ]);
        $this->commitAs('bob@example.com', 'feat: payments change', [
            '/src/Shared.php' => "v2\n",
        ]);

        $result = $this->makeAnalyzer()->analyze();

        $sharedItem = null;
        foreach ($result->items as $item) {
            if ('src/Shared.php' === $item->filePath) {
                $sharedItem = $item;
                break;
            }
        }

        self::assertNotNull($sharedItem);
        self::assertSame(2, $sharedItem->teamCount);
        self::assertSame(1.0, $sharedItem->ownershipEntropy);
    }

    public function test_analyze__unknown_author__assigned_to_unknown_team(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);

        $this->commitAs('stranger@example.com', 'feat: unknown author', [
            '/src/Mystery.php' => "content\n",
        ]);

        $result = $this->makeAnalyzer()->analyze();

        $item = $result->items[0] ?? null;
        self::assertNotNull($item);
        self::assertSame('unknown', $item->dominantTeam);
    }

    public function test_analyze__commit_counts_accumulate_across_commits(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        // platform touches the file 3x, payments 1x
        $this->commitAs('alice@example.com', 'feat: v1', ['/src/Hot.php' => "v1\n"]);
        $this->commitAs('alice@example.com', 'feat: v2', ['/src/Hot.php' => "v2\n"]);
        $this->commitAs('alice@example.com', 'feat: v3', ['/src/Hot.php' => "v3\n"]);
        $this->commitAs('bob@example.com', 'feat: v4', ['/src/Hot.php' => "v4\n"]);

        $result = $this->makeAnalyzer()->analyze();

        $item = null;
        foreach ($result->items as $i) {
            if ('src/Hot.php' === $i->filePath) {
                $item = $i;
                break;
            }
        }

        self::assertNotNull($item);
        self::assertSame(4, $item->totalCommits);
        self::assertSame('platform', $item->dominantTeam);
        self::assertSame(75.0, $item->dominantTeamPercentage);
    }

    public function test_analyze__since_filter__excludes_old_commits(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        // Old commit by payments — should be excluded
        $this->commitAs('bob@example.com', 'feat: old', ['/src/File.php' => "v1\n"],
            new \DateTimeImmutable('2023-01-01T12:00:00+0000'));
        // Recent commit by platform — should be included
        $this->commitAs('alice@example.com', 'feat: new', ['/src/File.php' => "v2\n"],
            new \DateTimeImmutable('2024-06-01T12:00:00+0000'));

        $result = $this->makeAnalyzer()->analyze(since: new \DateTimeImmutable('2024-01-01'));

        $item = null;
        foreach ($result->items as $i) {
            if ('src/File.php' === $i->filePath) {
                $item = $i;
                break;
            }
        }

        self::assertNotNull($item);
        self::assertSame(1, $item->teamCount);
        self::assertSame('platform', $item->dominantTeam);
    }

    private function makeAnalyzer(): SharedOwnershipAnalyzer
    {
        return new SharedOwnershipAnalyzer(
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
