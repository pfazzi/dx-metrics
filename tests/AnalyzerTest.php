<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\Analyzer;
use Pfazzi\DxMetrics\Git;
use PHPUnit\Framework\TestCase;

class AnalyzerTest extends TestCase
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

    public function test_coupling__with_valid_repo__returns_list_of_files_by_changes_in_the_same_commit(): void
    {
        $git = new Git($this->repoPath);
        $analyzer = new Analyzer($git);

        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: order+invoice',
            [
                '/src/Order.php' => "random content\n",
                '/src/Invoice.php' => "random content\n",
            ],
        );

        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: order+invoice v2',
            [
                '/src/Order.php' => "random content\n",
                '/src/Invoice.php' => "random content\n",
            ],
        );

        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: order+user',
            [
                '/src/Order.php' => "random content\n",
                '/src/User.php' => "random content\n",
            ],
        );

        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: just invoice',
            [
                '/src/Invoice.php' => "random content\n",
            ],
        );

        $coupling = $analyzer->computeCoupling();

        self::assertEqualsCanonicalizing([
            [
                'files' => ['src/Order.php', 'src/Invoice.php'],
                'changes' => 2,
            ],
            [
                'files' => ['src/Order.php', 'src/User.php'],
                'changes' => 1,
            ],
            [
                'files' => ['src/Invoice.php', 'src/Order.php'],
                'changes' => 2,
            ],
            [
                'files' => ['src/User.php', 'src/Order.php'],
                'changes' => 1,
            ],
        ], $coupling);
    }
}
