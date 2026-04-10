<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\Init;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Documents init command behavior: scaffolds .dx-metrics.json and a teams template.
 *
 * Key behaviors:
 *   - Writes .dx-metrics.json with all configurable keys (teams, depth, filter, exclude, etc.)
 *   - Writes .dx-metrics-teams.json with discovered author emails in _unassigned
 *   - Auto-detects src/ as filter when the directory exists
 *   - Refuses to overwrite an existing .dx-metrics.json unless --force is passed
 */
class InitCommandTest extends TestCase
{
    use GitTestTrait;

    private string $repoPath;

    protected function setUp(): void
    {
        $this->repoPath = $this->makeTempDir();
        $this->setUpTestRepo($this->repoPath);
    }

    protected function tearDown(): void
    {
        $this->cleanUpTestRepo($this->repoPath);
    }

    public function test_writes_dx_metrics_json_with_all_config_keys(): void
    {
        $this->commit($this->repoPath, new \DateTimeImmutable('-1 month'),
            'feat: init', ['/src/Foo.php' => "v1\n"], 'alice@example.com');

        $tester = $this->executeCommand(['path' => $this->repoPath]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertFileExists($this->repoPath.'/.dx-metrics.json');

        $config = json_decode((string) file_get_contents($this->repoPath.'/.dx-metrics.json'), true);
        self::assertIsArray($config);
        self::assertArrayHasKey('teams', $config);
        self::assertArrayHasKey('depth', $config);
        self::assertArrayHasKey('exclude', $config);
        self::assertArrayHasKey('min-teams', $config);
        self::assertArrayHasKey('min-coupling', $config);
        self::assertArrayHasKey('period', $config);
        self::assertSame(2, $config['depth']);
        self::assertSame('4w', $config['period']);
    }

    public function test_writes_teams_template_with_discovered_authors_in_unassigned(): void
    {
        $this->commit($this->repoPath, new \DateTimeImmutable('-1 month'),
            'feat: alice', ['/src/Foo.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('-2 weeks'),
            'feat: bob', ['/src/Bar.php' => "v1\n"], 'bob@example.com');

        $tester = $this->executeCommand(['path' => $this->repoPath]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertFileExists($this->repoPath.'/.dx-metrics-teams.json');

        $teams = json_decode((string) file_get_contents($this->repoPath.'/.dx-metrics-teams.json'), true);
        self::assertIsArray($teams);
        self::assertArrayHasKey('_unassigned', $teams);
        self::assertContains('alice@example.com', $teams['_unassigned']);
        self::assertContains('bob@example.com', $teams['_unassigned']);
    }

    public function test_unassigned_details_contains_name_and_example_commit_for_each_author(): void
    {
        $this->commit($this->repoPath, new \DateTimeImmutable('-1 month'),
            'feat: work', ['/src/Foo.php' => "v1\n"], 'alice@example.com');

        $this->executeCommand(['path' => $this->repoPath]);

        $teams = json_decode((string) file_get_contents($this->repoPath.'/.dx-metrics-teams.json'), true);
        self::assertArrayHasKey('_unassigned_details', $teams);
        self::assertArrayHasKey('alice@example.com', $teams['_unassigned_details']);

        $detail = $teams['_unassigned_details']['alice@example.com'];
        self::assertArrayHasKey('name', $detail);
        self::assertArrayHasKey('example_commit', $detail);
        self::assertNotEmpty($detail['name']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $detail['example_commit']);
    }

    public function test_auto_detects_src_directory_as_filter(): void
    {
        mkdir($this->repoPath.'/src', 0777, true);
        $this->commit($this->repoPath, new \DateTimeImmutable('-1 month'),
            'feat: init', ['/src/Foo.php' => "v1\n"], 'alice@example.com');

        $this->executeCommand(['path' => $this->repoPath]);

        $config = json_decode((string) file_get_contents($this->repoPath.'/.dx-metrics.json'), true);
        self::assertSame('src/', $config['filter']);
    }

    public function test_no_filter_key_when_no_standard_source_dir_exists(): void
    {
        $this->commit($this->repoPath, new \DateTimeImmutable('-1 month'),
            'feat: init', ['/custom/Foo.php' => "v1\n"], 'alice@example.com');

        $this->executeCommand(['path' => $this->repoPath]);

        $config = json_decode((string) file_get_contents($this->repoPath.'/.dx-metrics.json'), true);
        self::assertArrayNotHasKey('filter', $config);
    }

    public function test_fails_when_config_already_exists_without_force(): void
    {
        file_put_contents($this->repoPath.'/.dx-metrics.json', '{}');

        $tester = $this->executeCommand(['path' => $this->repoPath]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('already exists', $tester->getDisplay());
        self::assertStringContainsString('--force', $tester->getDisplay());
    }

    public function test_fails_when_teams_file_already_exists_without_force(): void
    {
        file_put_contents($this->repoPath.'/.dx-metrics-teams.json', '{}');

        $tester = $this->executeCommand(['path' => $this->repoPath]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('already exists', $tester->getDisplay());
        self::assertStringContainsString('--force', $tester->getDisplay());
    }

    public function test_force_flag_overwrites_existing_config(): void
    {
        file_put_contents($this->repoPath.'/.dx-metrics.json', '{"old": true}');
        file_put_contents($this->repoPath.'/.dx-metrics-teams.json', '{"old": true}');
        $this->commit($this->repoPath, new \DateTimeImmutable('-1 month'),
            'feat: init', ['/src/Foo.php' => "v1\n"], 'alice@example.com');

        $tester = $this->executeCommand(['path' => $this->repoPath, '--force' => true]);

        self::assertSame(0, $tester->getStatusCode());
        $config = json_decode((string) file_get_contents($this->repoPath.'/.dx-metrics.json'), true);
        self::assertArrayNotHasKey('old', $config);
        self::assertArrayHasKey('teams', $config);
    }

    public function test_teams_file_points_to_dx_metrics_teams_json(): void
    {
        $this->commit($this->repoPath, new \DateTimeImmutable('-1 month'),
            'feat: init', ['/src/Foo.php' => "v1\n"], 'alice@example.com');

        $this->executeCommand(['path' => $this->repoPath]);

        $config = json_decode((string) file_get_contents($this->repoPath.'/.dx-metrics.json'), true);
        self::assertSame('.dx-metrics-teams.json', $config['teams']);
    }

    public function test_update_adds_new_authors_to_existing_teams_file(): void
    {
        // Run init to create the teams file with alice
        $this->commit($this->repoPath, new \DateTimeImmutable('-2 months'),
            'feat: alice', ['/src/Foo.php' => "v1\n"], 'alice@example.com');
        $this->executeCommand(['path' => $this->repoPath]);

        // Now bob appears in a new commit
        $this->commit($this->repoPath, new \DateTimeImmutable('-1 week'),
            'feat: bob', ['/src/Bar.php' => "v1\n"], 'bob@example.com');

        $tester = $this->executeCommand(['path' => $this->repoPath, '--update' => true]);

        self::assertSame(0, $tester->getStatusCode());
        $teams = json_decode((string) file_get_contents($this->repoPath.'/.dx-metrics-teams.json'), true);
        self::assertContains('bob@example.com', $teams['_unassigned']);
        self::assertStringContainsString('bob@example.com', $tester->getDisplay());
    }

    public function test_update_does_not_add_authors_already_assigned_to_a_team(): void
    {
        // alice is already assigned to a team in the teams file
        $teamsData = [
            'teams' => ['platform' => ['alice@example.com']],
            '_unassigned' => [],
        ];
        file_put_contents($this->repoPath.'/.dx-metrics-teams.json', json_encode($teamsData));

        $this->commit($this->repoPath, new \DateTimeImmutable('-1 month'),
            'feat: alice again', ['/src/Foo.php' => "v1\n"], 'alice@example.com');

        $tester = $this->executeCommand(['path' => $this->repoPath, '--update' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No new contributors', $tester->getDisplay());
        $teams = json_decode((string) file_get_contents($this->repoPath.'/.dx-metrics-teams.json'), true);
        self::assertNotContains('alice@example.com', $teams['_unassigned']);
    }

    public function test_update_does_not_add_authors_already_in_unassigned(): void
    {
        $teamsData = [
            'teams' => ['team-a' => []],
            '_unassigned' => ['alice@example.com'],
        ];
        file_put_contents($this->repoPath.'/.dx-metrics-teams.json', json_encode($teamsData));

        $this->commit($this->repoPath, new \DateTimeImmutable('-1 month'),
            'feat: alice', ['/src/Foo.php' => "v1\n"], 'alice@example.com');

        $tester = $this->executeCommand(['path' => $this->repoPath, '--update' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No new contributors', $tester->getDisplay());
    }

    public function test_update_fails_when_teams_file_does_not_exist(): void
    {
        $tester = $this->executeCommand(['path' => $this->repoPath, '--update' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not found', $tester->getDisplay());
        self::assertStringContainsString('init first', $tester->getDisplay());
    }

    private function executeCommand(array $args): CommandTester
    {
        $app = new Application();
        $app->add(new Init());
        $tester = new CommandTester($app->find('init'));
        $tester->execute($args);

        return $tester;
    }
}
