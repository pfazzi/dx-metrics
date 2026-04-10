<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\SharedOwnership;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class SharedOwnershipCommandTest extends TestCase
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

    public function test_command_fails_when_teams_option_is_missing(): void
    {
        $tester = $this->executeCommand(['path' => $this->repoPath]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--teams', $tester->getDisplay());
    }

    public function test_command_outputs_shared_files_table(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: platform', ['/src/Shared.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: payments', ['/src/Shared.php' => "v2\n"], 'bob@example.com');

        $tester = $this->executeCommand(['path' => $this->repoPath, '--teams' => $this->teamsFile]);

        self::assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('src/Shared.php', $output);
        self::assertStringContainsString('2', $output); // 2 teams
    }

    public function test_command_shows_message_when_no_shared_files_found(): void
    {
        $this->writeTeams([
            'teams' => ['platform' => ['alice@example.com']],
        ]);

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: solo', ['/src/Owned.php' => "v1\n"], 'alice@example.com');

        $tester = $this->executeCommand(['path' => $this->repoPath, '--teams' => $this->teamsFile]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No files with shared ownership', $tester->getDisplay());
    }

    public function test_filter_option_limits_results_to_path_prefix(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: src', ['/src/Shared.php' => "v1\n", '/config/Config.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: payments', ['/src/Shared.php' => "v2\n", '/config/Config.php' => "v2\n"], 'bob@example.com');

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--filter' => 'src/',
        ]);
        $output = $tester->getDisplay();

        self::assertStringContainsString('src/Shared.php', $output);
        self::assertStringNotContainsString('config/Config.php', $output);
    }

    public function test_min_teams_option_filters_by_team_count(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
                'identity' => ['charlie@example.com'],
            ],
        ]);

        // FileA: touched by 2 teams
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: a1', ['/src/FileA.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: a2', ['/src/FileA.php' => "v2\n"], 'bob@example.com');

        // FileB: touched by 3 teams
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-03T12:00:00+0000'),
            'feat: b1', ['/src/FileB.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-04T12:00:00+0000'),
            'feat: b2', ['/src/FileB.php' => "v2\n"], 'bob@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: b3', ['/src/FileB.php' => "v3\n"], 'charlie@example.com');

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--min-teams' => 3,
        ]);
        $output = $tester->getDisplay();

        self::assertStringContainsString('src/FileB.php', $output);
        self::assertStringNotContainsString('src/FileA.php', $output);
    }

    public function test_entropy_column_is_shown_in_output(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: a', ['/src/File.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: b', ['/src/File.php' => "v2\n"], 'bob@example.com');

        $tester = $this->executeCommand(['path' => $this->repoPath, '--teams' => $this->teamsFile]);
        $output = $tester->getDisplay();

        self::assertStringContainsString('Entropy', $output);
        self::assertStringContainsString('1.00', $output); // 50/50 = entropy 1.0
    }

    public function test_since_and_until_options_filter_by_date_window(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        // Old commit — outside the window, should be excluded
        $this->commit($this->repoPath, new \DateTimeImmutable('2023-01-01T12:00:00+0000'),
            'feat: old', ['/src/Old.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2023-01-02T12:00:00+0000'),
            'feat: old2', ['/src/Old.php' => "v2\n"], 'bob@example.com');

        // Recent commits — inside the window
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-06-01T12:00:00+0000'),
            'feat: new', ['/src/New.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-06-02T12:00:00+0000'),
            'feat: new2', ['/src/New.php' => "v2\n"], 'bob@example.com');

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--since' => '2024-01-01',
            '--until' => '2024-12-31',
        ]);
        $output = $tester->getDisplay();

        self::assertStringContainsString('src/New.php', $output);
        self::assertStringNotContainsString('src/Old.php', $output);
    }

    public function test_exclude_option_hides_matching_files(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: shared', ['/src/Shared.php' => "v1\n", '/Cargo.lock' => "lock\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: update', ['/src/Shared.php' => "v2\n", '/Cargo.lock' => "lock2\n"], 'bob@example.com');

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--exclude' => ['Cargo.lock'],
        ]);
        $output = $tester->getDisplay();

        self::assertStringContainsString('src/Shared.php', $output);
        self::assertStringNotContainsString('Cargo.lock', $output);
    }

    private function executeCommand(array $args): CommandTester
    {
        $app = new Application();
        $app->add(new SharedOwnership());
        $tester = new CommandTester($app->find('shared-ownership'));
        $tester->execute($args);

        return $tester;
    }

    private function writeTeams(array $data): void
    {
        file_put_contents($this->teamsFile, json_encode($data));
    }
}
