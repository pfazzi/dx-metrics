<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\Git;
use PHPUnit\Framework\TestCase;

class GitTest extends TestCase
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

    public function test_get_changes_from_commit__when_commit_sha_is_valid__returns_files_list()
    {
        $git = new Git($this->repoPath);

        $sha = $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: initial order+invoice',
            [
                '/src/Order.php' => "order v1\n",
                '/src/Invoice.php' => "invoice v1\n",
            ],
        );

        $files = $git->getChangesFromCommit($sha);

        self::assertEqualsCanonicalizing([
            'src/Order.php',
            'src/Invoice.php',
        ], $files);
    }
}
