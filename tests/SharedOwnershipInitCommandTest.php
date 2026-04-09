<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\SharedOwnershipInit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class SharedOwnershipInitCommandTest extends TestCase
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

    public function test_empty_repo_outputs_empty_unassigned_list(): void
    {
        $tester = $this->executeCommand(['path' => $this->repoPath]);

        self::assertSame(0, $tester->getStatusCode());
        $data = json_decode($tester->getDisplay(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('_unassigned', $data);
        self::assertCount(0, $data['_unassigned']);
    }

    public function test_outputs_valid_json_with_teams_and_unassigned_keys(): void
    {
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: first', ['/src/A.php' => "v1\n"], 'alice@example.com');

        $tester = $this->executeCommand(['path' => $this->repoPath]);

        $data = json_decode($tester->getDisplay(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('teams', $data);
        self::assertArrayHasKey('_unassigned', $data);
        self::assertIsArray($data['teams']);
        self::assertIsArray($data['_unassigned']);
    }

    public function test_discovered_authors_appear_in_unassigned(): void
    {
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: alice', ['/src/A.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: bob', ['/src/B.php' => "v1\n"], 'bob@example.com');

        $tester = $this->executeCommand(['path' => $this->repoPath]);

        $data = json_decode($tester->getDisplay(), true);
        self::assertContains('alice@example.com', $data['_unassigned']);
        self::assertContains('bob@example.com', $data['_unassigned']);
    }

    public function test_duplicate_authors_appear_only_once(): void
    {
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: first', ['/src/A.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: second', ['/src/B.php' => "v1\n"], 'alice@example.com');

        $tester = $this->executeCommand(['path' => $this->repoPath]);

        $data = json_decode($tester->getDisplay(), true);
        self::assertCount(1, array_filter($data['_unassigned'], fn ($e) => 'alice@example.com' === $e));
    }

    public function test_authors_are_normalized_to_lowercase(): void
    {
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: mixed case', ['/src/A.php' => "v1\n"], 'Alice@Example.COM');

        $tester = $this->executeCommand(['path' => $this->repoPath]);

        $data = json_decode($tester->getDisplay(), true);
        self::assertContains('alice@example.com', $data['_unassigned']);
        self::assertNotContains('Alice@Example.COM', $data['_unassigned']);
    }

    public function test_unassigned_authors_are_sorted_alphabetically(): void
    {
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: charlie', ['/src/C.php' => "v1\n"], 'charlie@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: alice', ['/src/A.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-03T12:00:00+0000'),
            'feat: bob', ['/src/B.php' => "v1\n"], 'bob@example.com');

        $tester = $this->executeCommand(['path' => $this->repoPath]);

        $data = json_decode($tester->getDisplay(), true);
        self::assertSame(['alice@example.com', 'bob@example.com', 'charlie@example.com'], $data['_unassigned']);
    }

    public function test_since_option_excludes_old_authors(): void
    {
        $this->commit($this->repoPath, new \DateTimeImmutable('2023-01-01T12:00:00+0000'),
            'feat: old', ['/src/Old.php' => "v1\n"], 'oldtimer@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-06-01T12:00:00+0000'),
            'feat: new', ['/src/New.php' => "v1\n"], 'recent@example.com');

        $tester = $this->executeCommand(['path' => $this->repoPath, '--since' => '2024-01-01']);

        $data = json_decode($tester->getDisplay(), true);
        self::assertContains('recent@example.com', $data['_unassigned']);
        self::assertNotContains('oldtimer@example.com', $data['_unassigned']);
    }

    public function test_output_option_writes_file_and_prints_summary(): void
    {
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: alice', ['/src/A.php' => "v1\n"], 'alice@example.com');

        $outputFile = sys_get_temp_dir() . '/teams-init-' . bin2hex(random_bytes(4)) . '.json';
        try {
            $tester = $this->executeCommand(['path' => $this->repoPath, '--output' => $outputFile]);

            self::assertSame(0, $tester->getStatusCode());
            self::assertFileExists($outputFile);

            $data = json_decode(file_get_contents($outputFile), true);
            self::assertContains('alice@example.com', $data['_unassigned']);

            self::assertStringContainsString($outputFile, $tester->getDisplay());
            self::assertStringContainsString('1 authors', $tester->getDisplay());
        } finally {
            if (file_exists($outputFile)) {
                unlink($outputFile);
            }
        }
    }

    public function test_template_includes_placeholder_teams(): void
    {
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: alice', ['/src/A.php' => "v1\n"], 'alice@example.com');

        $tester = $this->executeCommand(['path' => $this->repoPath]);

        $data = json_decode($tester->getDisplay(), true);
        self::assertArrayHasKey('team-a', $data['teams']);
        self::assertArrayHasKey('team-b', $data['teams']);
        self::assertSame([], $data['teams']['team-a']);
        self::assertSame([], $data['teams']['team-b']);
    }

    private function executeCommand(array $args): CommandTester
    {
        $app = new Application();
        $app->add(new SharedOwnershipInit());
        $tester = new CommandTester($app->find('shared-ownership:init'));
        $tester->execute($args);

        return $tester;
    }
}
