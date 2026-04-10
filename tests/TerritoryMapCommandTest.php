<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\TerritoryMap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class TerritoryMapCommandTest extends TestCase
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

    public function test_mermaid_format_outputs_graph_lr_syntax(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        // Alice dominates Orders with multiple commits
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: orders init', ['/src/Orders/Order.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: orders v2', ['/src/Orders/Order.php' => "v2\n"], 'alice@example.com');

        // Bob dominates Invoices with multiple commits
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-03T12:00:00+0000'),
            'feat: invoices init', ['/src/Invoices/Invoice.php' => "v1\n"], 'bob@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-04T12:00:00+0000'),
            'feat: invoices v2', ['/src/Invoices/Invoice.php' => "v2\n"], 'bob@example.com');

        // Cross-module commit creating coupling between the two teams' modules
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: cross-team change', [
                '/src/Orders/Order.php' => "v3\n",
                '/src/Invoices/Invoice.php' => "v3\n",
            ], 'alice@example.com');

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--depth' => '2',
            '--format' => 'mermaid',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('graph LR', $output);
        self::assertStringContainsString('platform', $output);
        self::assertStringContainsString('payments', $output);
        self::assertStringContainsString('co-changes', $output);
    }

    public function test_mermaid_format_writes_mmd_file_to_output_dir(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: orders init', ['/src/Orders/Order.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: orders v2', ['/src/Orders/Order.php' => "v2\n"], 'alice@example.com');

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-03T12:00:00+0000'),
            'feat: invoices init', ['/src/Invoices/Invoice.php' => "v1\n"], 'bob@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-04T12:00:00+0000'),
            'feat: invoices v2', ['/src/Invoices/Invoice.php' => "v2\n"], 'bob@example.com');

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: cross-team change', [
                '/src/Orders/Order.php' => "v3\n",
                '/src/Invoices/Invoice.php' => "v3\n",
            ], 'alice@example.com');

        $tmpDir = $this->makeTempDir();

        try {
            $tester = $this->executeCommand([
                'path' => $this->repoPath,
                '--teams' => $this->teamsFile,
                '--depth' => '2',
                '--format' => 'mermaid',
                '--output-dir' => $tmpDir,
            ]);

            self::assertSame(0, $tester->getStatusCode());
            self::assertFileExists($tmpDir.'/territory.mmd');
            self::assertFileDoesNotExist($tmpDir.'/territory.dot');

            $mmdContent = (string) file_get_contents($tmpDir.'/territory.mmd');
            self::assertStringContainsString('graph LR', $mmdContent);
        } finally {
            $this->cleanUpTestRepo($tmpDir);
        }
    }

    public function test_dot_format_is_the_default(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: orders init', ['/src/Orders/Order.php' => "v1\n"], 'alice@example.com');

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: invoices init', ['/src/Invoices/Invoice.php' => "v1\n"], 'bob@example.com');

        $tmpDir = $this->makeTempDir();

        try {
            $tester = $this->executeCommand([
                'path' => $this->repoPath,
                '--teams' => $this->teamsFile,
                '--depth' => '2',
                '--output-dir' => $tmpDir,
            ]);

            self::assertSame(0, $tester->getStatusCode());
            self::assertFileExists($tmpDir.'/territory.dot');

            $dotContent = (string) file_get_contents($tmpDir.'/territory.dot');
            self::assertStringContainsString('graph G', $dotContent);
        } finally {
            $this->cleanUpTestRepo($tmpDir);
        }
    }

    public function test_invalid_format_returns_failure(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
            ],
        ]);

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: init', ['/src/Orders/Order.php' => "v1\n"], 'alice@example.com');

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--format' => 'svg',
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Invalid --format value', $tester->getDisplay());
    }

    private function executeCommand(array $args): CommandTester
    {
        $app = new Application();
        $app->add(new TerritoryMap());
        $tester = new CommandTester($app->find('territory-map'));
        $tester->execute($args);

        return $tester;
    }

    /** @param array<string, mixed> $data */
    private function writeTeams(array $data): void
    {
        file_put_contents($this->teamsFile, json_encode($data));
    }
}
