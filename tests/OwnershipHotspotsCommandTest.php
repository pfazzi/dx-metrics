<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\OwnershipHotspots;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class OwnershipHotspotsCommandTest extends TestCase
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

    public function test_shows_hotspot_table_with_risk_score(): void
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
        self::assertStringContainsString('Risk Score', $output);
        self::assertStringContainsString('Entropy', $output);
    }

    public function test_files_owned_by_single_team_are_excluded_by_default(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        // Only touched by alice → single-team ownership
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: solo', ['/src/Solo.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: solo 2', ['/src/Solo.php' => "v2\n"], 'alice@example.com');

        $tester = $this->executeCommand(['path' => $this->repoPath, '--teams' => $this->teamsFile]);

        self::assertStringContainsString('No ownership hotspots found.', $tester->getDisplay());
    }

    public function test_filter_option_limits_output_to_path_prefix(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: src', ['/src/Shared.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: config', ['/config/Config.php' => "v1\n", '/src/Shared.php' => "v2\n"], 'bob@example.com');

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--filter' => 'src/',
        ]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('src/Shared.php', $output);
        self::assertStringNotContainsString('config/Config.php', $output);
    }

    public function test_higher_risk_file_appears_first(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        // Hot.php: 10 commits split 50/50 → risk = 1.0 * 10 = 10.0
        for ($i = 1; $i <= 5; ++$i) {
            $this->commit($this->repoPath, new \DateTimeImmutable("2024-01-0{$i}T12:00:00+0000"),
                "feat: hot alice {$i}", ['/src/Hot.php' => "v{$i}\n"], 'alice@example.com');
            $this->commit($this->repoPath, new \DateTimeImmutable("2024-01-0{$i}T13:00:00+0000"),
                "feat: hot bob {$i}", ['/src/Hot.php' => "w{$i}\n"], 'bob@example.com');
        }

        // Cold.php: 2 commits split 50/50 → risk = 1.0 * 2 = 2.0
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-02-01T12:00:00+0000'),
            'feat: cold alice', ['/src/Cold.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-02-02T12:00:00+0000'),
            'feat: cold bob', ['/src/Cold.php' => "v2\n"], 'bob@example.com');

        $tester = $this->executeCommand(['path' => $this->repoPath, '--teams' => $this->teamsFile]);
        $output = $tester->getDisplay();

        self::assertLessThan(
            strpos($output, 'Cold.php'),
            strpos($output, 'Hot.php'),
            'Hot.php (higher risk) should appear before Cold.php',
        );
    }

    public function test_no_hotspots_shows_informational_message(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);

        $tester = $this->executeCommand(['path' => $this->repoPath, '--teams' => $this->teamsFile]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No ownership hotspots found.', $tester->getDisplay());
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
        $app->add(new OwnershipHotspots());
        $tester = new CommandTester($app->find('ownership-hotspots'));
        $tester->execute($args);

        return $tester;
    }

    /** @param array<string, mixed> $data */
    private function writeTeams(array $data): void
    {
        file_put_contents($this->teamsFile, json_encode($data));
    }
}
