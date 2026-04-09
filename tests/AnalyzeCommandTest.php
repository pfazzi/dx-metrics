<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\Analyze;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class AnalyzeCommandTest extends TestCase
{
    use GitTestTrait;

    private string $repoPath;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->repoPath = $this->makeTempDir();
        $this->outputDir = $this->makeTempDir();
        $this->setUpTestRepo($this->repoPath);
        $this->removeCwdCouplingFiles();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTestRepo($this->repoPath);
        $this->cleanUpTestRepo($this->outputDir);
        $this->removeCwdCouplingFiles();
    }

    public function test_plot_writes_dot_file_to_specified_output_dir(): void
    {
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: order+invoice',
            [
                '/src/Order.php' => "content\n",
                '/src/Invoice.php' => "content\n",
            ],
        );

        $this->executeCommand(['path' => $this->repoPath, '--output-dir' => $this->outputDir]);

        self::assertFileExists($this->outputDir.'/coupling.dot');
        self::assertFileDoesNotExist(getcwd().'/coupling.dot');
    }

    public function test_plot_defaults_to_current_working_directory(): void
    {
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: order+invoice',
            [
                '/src/Order.php' => "content\n",
                '/src/Invoice.php' => "content\n",
            ],
        );

        $dotFile = (string) getcwd().'/coupling.dot';

        try {
            $this->executeCommand(['path' => $this->repoPath]);

            self::assertFileExists($dotFile);
        } finally {
            if (file_exists($dotFile)) {
                unlink($dotFile);
            }
        }
    }

    public function test_command_outputs_table_with_file_pairs(): void
    {
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: order+invoice',
            [
                '/src/Order.php' => "content\n",
                '/src/Invoice.php' => "content\n",
            ],
        );

        $tester = $this->executeCommand(['path' => $this->repoPath]);

        self::assertStringContainsString('Order.php', $tester->getDisplay());
        self::assertStringContainsString('Invoice.php', $tester->getDisplay());
    }

    public function test_threshold_option_filters_low_coupling_pairs(): void
    {
        // 2 co-changes for Order+Invoice
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: order+invoice',
            ['/src/Order.php' => "v1\n", '/src/Invoice.php' => "v1\n"],
        );
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-06T12:00:00+0000'),
            'feat: order+invoice v2',
            ['/src/Order.php' => "v2\n", '/src/Invoice.php' => "v2\n"],
        );
        // 1 co-change for Order+User
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-07T12:00:00+0000'),
            'feat: order+user',
            ['/src/Order.php' => "v3\n", '/src/User.php' => "v1\n"],
        );

        $tester = $this->executeCommand(['path' => $this->repoPath, '--threshold' => 2]);
        $output = $tester->getDisplay();

        self::assertStringContainsString('Order.php', $output);
        self::assertStringContainsString('Invoice.php', $output);
        self::assertStringNotContainsString('User.php', $output);
    }

    public function test_filter_option_limits_output_to_matching_path_prefix(): void
    {
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: mixed coupling',
            [
                '/src/Order.php' => "content\n",
                '/src/Invoice.php' => "content\n",
                '/config/Config.php' => "content\n",
            ],
        );

        $tester = $this->executeCommand(['path' => $this->repoPath, '--filter' => 'src/']);
        $output = $tester->getDisplay();

        self::assertStringContainsString('Order.php', $output);
        self::assertStringContainsString('Invoice.php', $output);
        self::assertStringNotContainsString('Config.php', $output);
    }

    public function test_output_dir_with_spaces_in_path_works_correctly(): void
    {
        $dirWithSpaces = sys_get_temp_dir().'/dx metrics output '.bin2hex(random_bytes(4));
        mkdir($dirWithSpaces, 0777, true);

        try {
            $this->commit(
                $this->repoPath,
                new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
                'feat: order+invoice',
                ['/src/Order.php' => "content\n", '/src/Invoice.php' => "content\n"],
            );

            $this->executeCommand(['path' => $this->repoPath, '--output-dir' => $dirWithSpaces]);

            self::assertFileExists($dirWithSpaces.'/coupling.dot');
        } finally {
            foreach (['coupling.dot', 'coupling.png'] as $file) {
                $path = $dirWithSpaces.'/'.$file;
                if (file_exists($path)) {
                    unlink($path);
                }
            }
            rmdir($dirWithSpaces);
        }
    }

    public function test_no_dot_file_generated_when_no_coupling_found(): void
    {
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: solo file',
            ['/src/Alone.php' => "content\n"],
        );

        $this->executeCommand(['path' => $this->repoPath, '--output-dir' => $this->outputDir]);

        self::assertFileDoesNotExist($this->outputDir.'/coupling.dot');
    }

    public function test_command_exits_with_success_code(): void
    {
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: order+invoice',
            ['/src/Order.php' => "content\n", '/src/Invoice.php' => "content\n"],
        );

        $tester = $this->executeCommand(['path' => $this->repoPath, '--output-dir' => $this->outputDir]);

        self::assertSame(0, $tester->getStatusCode());
    }

    public function test_dot_file_contains_valid_graphviz_syntax(): void
    {
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: order+invoice',
            ['/src/Order.php' => "content\n", '/src/Invoice.php' => "content\n"],
        );

        $this->executeCommand(['path' => $this->repoPath, '--output-dir' => $this->outputDir]);

        $dotContent = (string) file_get_contents($this->outputDir.'/coupling.dot');
        self::assertStringStartsWith('graph G {', $dotContent);
        self::assertStringEndsWith("}\n", $dotContent);
        self::assertStringContainsString('Order.php', $dotContent);
        self::assertStringContainsString('Invoice.php', $dotContent);
        self::assertStringContainsString('penwidth=', $dotContent);
        self::assertStringContainsString('label=', $dotContent);
    }

    public function test_since_option_excludes_commits_before_date(): void
    {
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2023-06-01T12:00:00+0000'),
            'feat: old coupling',
            ['/src/Old.php' => "content\n", '/src/Legacy.php' => "content\n"],
        );
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-03-01T12:00:00+0000'),
            'feat: new coupling',
            ['/src/New.php' => "content\n", '/src/Modern.php' => "content\n"],
        );

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--output-dir' => $this->outputDir,
            '--since' => '2024-01-01',
        ]);
        $output = $tester->getDisplay();

        self::assertStringNotContainsString('Old.php', $output);
        self::assertStringContainsString('New.php', $output);
    }

    public function test_until_option_excludes_commits_after_date(): void
    {
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2023-06-01T12:00:00+0000'),
            'feat: old coupling',
            ['/src/Old.php' => "content\n", '/src/Legacy.php' => "content\n"],
        );
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-03-01T12:00:00+0000'),
            'feat: new coupling',
            ['/src/New.php' => "content\n", '/src/Modern.php' => "content\n"],
        );

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--output-dir' => $this->outputDir,
            '--until' => '2023-12-31',
        ]);
        $output = $tester->getDisplay();

        self::assertStringContainsString('Old.php', $output);
        self::assertStringNotContainsString('New.php', $output);
    }

    public function test_since_and_until_together_restrict_to_date_window(): void
    {
        // Before window
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2023-01-01T12:00:00+0000'),
            'feat: before window',
            ['/src/Before.php' => "content\n", '/src/BeforeB.php' => "content\n"],
        );
        // Inside window
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-06-01T12:00:00+0000'),
            'feat: inside window',
            ['/src/Inside.php' => "content\n", '/src/InsideB.php' => "content\n"],
        );
        // After window
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2025-01-01T12:00:00+0000'),
            'feat: after window',
            ['/src/After.php' => "content\n", '/src/AfterB.php' => "content\n"],
        );

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--output-dir' => $this->outputDir,
            '--since' => '2024-01-01',
            '--until' => '2024-12-31',
        ]);
        $output = $tester->getDisplay();

        self::assertStringNotContainsString('Before.php', $output);
        self::assertStringContainsString('Inside.php', $output);
        self::assertStringNotContainsString('After.php', $output);
    }

    private function removeCwdCouplingFiles(): void
    {
        foreach (['coupling.dot', 'coupling.png'] as $file) {
            $path = (string) getcwd().'/'.$file;
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    private function executeCommand(array $args): CommandTester
    {
        $app = new Application();
        $app->add(new Analyze());
        $tester = new CommandTester($app->find('analyze'));
        $tester->execute($args);

        return $tester;
    }
}
