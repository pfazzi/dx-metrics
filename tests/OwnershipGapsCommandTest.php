<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\OwnershipGaps;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class OwnershipGapsCommandTest extends TestCase
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

    public function test_fails_when_teams_option_is_missing(): void
    {
        $tester = $this->executeCommand(['path' => $this->repoPath]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--teams', $tester->getDisplay());
    }

    public function test_shows_no_stale_files_when_all_files_recently_committed(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
            ],
        ]);

        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('now'),
            'feat: recent file',
            ['/src/Recent.php' => "<?php\n"],
            'alice@example.com',
        );

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--stale-months' => '1',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No stale files found', $tester->getDisplay());
    }

    public function test_shows_stale_file_when_last_commit_is_older_than_threshold(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
            ],
        ]);

        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2022-01-01T12:00:00+0000'),
            'feat: old file',
            ['/src/Old.php' => "<?php\n"],
            'alice@example.com',
        );

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--stale-months' => '6',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('src/Old.php', $output);
        self::assertStringContainsString('2022-01-01', $output);
    }

    public function test_filter_option_limits_output_to_path_prefix(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
            ],
        ]);

        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2022-01-01T12:00:00+0000'),
            'feat: src file',
            ['/src/OldSrc.php' => "<?php\n"],
            'alice@example.com',
        );

        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2022-01-02T12:00:00+0000'),
            'feat: config file',
            ['/config/OldConfig.php' => "<?php\n"],
            'alice@example.com',
        );

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--stale-months' => '6',
            '--filter' => 'src/',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('src/OldSrc.php', $output);
        self::assertStringNotContainsString('config/OldConfig.php', $output);
    }

    public function test_exclude_option_hides_matching_files(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
            ],
        ]);

        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2022-01-01T12:00:00+0000'),
            'feat: add lock and src',
            ['/src/Old.php' => "<?php\n", '/Cargo.lock' => "lock\n"],
            'alice@example.com',
        );

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--stale-months' => '6',
            '--exclude' => ['*.lock'],
        ]);

        self::assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('src/Old.php', $output);
        self::assertStringNotContainsString('Cargo.lock', $output);
    }

    private function executeCommand(array $args): CommandTester
    {
        $app = new Application();
        $app->add(new OwnershipGaps());
        $tester = new CommandTester($app->find('ownership:gaps'));
        $tester->execute($args);

        return $tester;
    }

    /** @param array<string, mixed> $data */
    private function writeTeams(array $data): void
    {
        file_put_contents($this->teamsFile, json_encode($data));
    }
}
