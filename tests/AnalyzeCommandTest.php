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
    }

    protected function tearDown(): void
    {
        $this->cleanUpTestRepo($this->repoPath);
        $this->cleanUpTestRepo($this->outputDir);
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

    private function executeCommand(array $args): CommandTester
    {
        $app = new Application();
        $app->add(new Analyze());
        $tester = new CommandTester($app->find('analyze'));
        $tester->execute($args);

        return $tester;
    }
}
