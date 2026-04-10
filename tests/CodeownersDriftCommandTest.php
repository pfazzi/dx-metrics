<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\CodeownersDrift;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Documents codeowners:drift behavior: CODEOWNERS pattern matching and drift calculation.
 *
 * Drift is the percentage of commits that came from teams other than the dominant team for a
 * given CODEOWNERS pattern. A drift of 0% means one team made all commits. A drift of 50% means
 * the top team made only half of the commits — ownership is contested.
 *
 * Key pattern matching rules (mirrors GitHub's CODEOWNERS spec):
 *   - "src/"        matches any file whose path starts with "src/"
 *   - "/src/"       root-anchored, treated identically (leading slash stripped)
 *   - "*.php"       glob: matches any .php file at any depth
 *   - "src/Foo.php" exact path or directory prefix
 *
 * CODEOWNERS auto-detection checks (in order): CODEOWNERS, .github/CODEOWNERS, docs/CODEOWNERS
 *
 * Note: all executeCommand calls pass '--since' => '2020-01-01' because the command defaults to
 * '-6 months' and test commits use fixed historical dates.
 */
class CodeownersDriftCommandTest extends TestCase
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
        $codeownersPath = $this->writeCODEOWNERS("src/ @platform\n");
        $tester = $this->executeCommand(['path' => $this->repoPath, '--codeowners' => $codeownersPath]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--teams', $tester->getDisplay());
    }

    public function test_fails_when_codeowners_file_is_not_found(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);
        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--codeowners' => '/nonexistent/CODEOWNERS',
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    public function test_auto_detects_codeowners_in_repository_root(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);
        // Write CODEOWNERS directly in repo root (no --codeowners option needed)
        file_put_contents($this->repoPath.'/CODEOWNERS', "src/ @platform\n");

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: init', ['/src/Foo.php' => "v1\n"], 'alice@example.com');

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--since' => '2020-01-01',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('src/', $tester->getDisplay());
    }

    public function test_auto_detects_codeowners_in_github_directory(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);
        mkdir($this->repoPath.'/.github', 0777, true);
        file_put_contents($this->repoPath.'/.github/CODEOWNERS', "src/ @platform\n");

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: init', ['/src/Foo.php' => "v1\n"], 'alice@example.com');

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--since' => '2020-01-01',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('src/', $tester->getDisplay());
    }

    public function test_directory_pattern_matches_all_files_under_that_path(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);
        $codeownersPath = $this->writeCODEOWNERS("src/ @platform\n");

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: add src files', [
                '/src/Order.php' => "v1\n",
                '/src/domain/Invoice.php' => "v1\n",
            ], 'alice@example.com');

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--codeowners' => $codeownersPath,
            '--since' => '2020-01-01',
        ]);

        $output = $tester->getDisplay();
        // Pattern matched: actual owner shown (not "no data")
        self::assertStringContainsString('platform', $output);
        self::assertStringNotContainsString('no data', $output);
    }

    public function test_root_anchored_pattern_matches_same_as_directory_pattern(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);
        // /src/ with leading slash should be treated identically to src/
        $codeownersPath = $this->writeCODEOWNERS("/src/ @platform\n");

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: platform commit', ['/src/Order.php' => "v1\n"], 'alice@example.com');

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--codeowners' => $codeownersPath,
            '--since' => '2020-01-01',
        ]);

        $output = $tester->getDisplay();
        // Pattern matched: actual owner should be shown (not "no data")
        self::assertStringNotContainsString('no data', $output);
        self::assertStringContainsString('platform', $output);
    }

    public function test_glob_pattern_matches_files_by_extension_at_any_depth(): void
    {
        $this->writeTeams(['teams' => ['devops' => ['ops@example.com']]]);
        $codeownersPath = $this->writeCODEOWNERS("*.lock @devops\n");

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: lock files', [
                '/Cargo.lock' => "lock\n",
                '/package.lock' => "lock\n",
            ], 'ops@example.com');

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--codeowners' => $codeownersPath,
            '--since' => '2020-01-01',
        ]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('devops', $output);
        self::assertStringNotContainsString('no data', $output);
    }

    public function test_drift_is_zero_when_single_team_owns_all_files_in_pattern(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);
        $codeownersPath = $this->writeCODEOWNERS("src/ @platform\n");

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: alice', ['/src/Foo.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: alice 2', ['/src/Bar.php' => "v1\n"], 'alice@example.com');

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--codeowners' => $codeownersPath,
            '--since' => '2020-01-01',
        ]);

        $output = $tester->getDisplay();
        // Drift 0% → status "ok"
        self::assertStringContainsString('0%', $output);
        self::assertStringContainsString('ok', $output);
    }

    public function test_drift_is_high_when_pattern_is_contested_between_two_teams(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);
        $codeownersPath = $this->writeCODEOWNERS("src/ @platform\n");

        // alice and bob contribute equally → dominant team at 50%, drift 50%
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: alice', ['/src/Foo.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: bob', ['/src/Bar.php' => "v1\n"], 'bob@example.com');

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--codeowners' => $codeownersPath,
            '--since' => '2020-01-01',
        ]);

        $output = $tester->getDisplay();
        // 50% drift → "review" or "drift" status (>10%)
        self::assertStringContainsString('50%', $output);
    }

    public function test_filter_option_limits_analysis_to_path_prefix(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'devops' => ['ops@example.com'],
            ],
        ]);
        $codeownersPath = $this->writeCODEOWNERS("src/ @platform\nconfig/ @devops\n");

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: src', ['/src/Foo.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: config', ['/config/app.php' => "v1\n"], 'ops@example.com');

        // With --filter src/, only commits under src/ are considered
        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--codeowners' => $codeownersPath,
            '--filter' => 'src/',
            '--since' => '2020-01-01',
        ]);

        $output = $tester->getDisplay();
        // src/ pattern has data, config/ has no data under the src/ filter
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('src/', $output);
    }

    public function test_pattern_with_no_matching_commits_shows_no_data(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);
        $codeownersPath = $this->writeCODEOWNERS("src/ @platform\n");

        // Commit to a completely different path — src/ pattern won't match
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: only config', ['/config/app.php' => "v1\n"], 'alice@example.com');

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--codeowners' => $codeownersPath,
            '--since' => '2020-01-01',
        ]);

        self::assertStringContainsString('no data', $tester->getDisplay());
    }

    private function writeCODEOWNERS(string $content): string
    {
        $path = sys_get_temp_dir().'/CODEOWNERS-'.bin2hex(random_bytes(4));
        file_put_contents($path, $content);

        return $path;
    }

    private function executeCommand(array $args): CommandTester
    {
        $app = new Application();
        $app->add(new CodeownersDrift());
        $tester = new CommandTester($app->find('codeowners:drift'));
        $tester->execute($args);

        return $tester;
    }

    /** @param array<string, mixed> $data */
    private function writeTeams(array $data): void
    {
        file_put_contents($this->teamsFile, json_encode($data));
    }
}
