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

    public function test_get_commits__when_valid_repo_path__returns_commit_sha_list(): void
    {
        $git = new Git($this->repoPath);

        $shaList[] = $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: initial order+invoice',
            [
                '/src/Order.php' => "order v1\n",
                '/src/Invoice.php' => "invoice v1\n",
            ],
        );

        $shaList[] = $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: second change to order+invoice',
            [
                '/src/Order.php' => "order v2\n",
                '/src/Invoice.php' => "invoice v2\n",
            ],
        );

        $shaList = array_reverse($shaList); // Sorts from the newest to the oldest

        $result = $git->getCommitsSha();

        self::assertEquals($shaList, $result);
    }

    public function test_get_commits__when_date_interval_is_specified__filters_commit_sha_list(): void
    {
        $git = new Git($this->repoPath);

        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: initial order+invoice',
            [
                '/src/Order.php' => "order v1\n",
                '/src/Invoice.php' => "invoice v1\n",
            ],
        );

        $shaList[] = $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-10T12:00:00+0000'),
            'feat: second change to order+invoice',
            [
                '/src/Order.php' => "order v2\n",
                '/src/Invoice.php' => "invoice v2\n",
            ],
        );

        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-15T12:00:00+0000'),
            'feat: second change to order+invoice',
            [
                '/src/Order.php' => "order v3\n",
                '/src/Invoice.php' => "invoice v3\n",
            ],
        );

        $shaList = array_reverse($shaList); // Sorts from the newest to the oldest

        $result = $git->getCommitsSha(
            since: new \DateTimeImmutable('2024-01-10'),
            until: new \DateTimeImmutable('2024-01-10'),
        );

        self::assertEquals($shaList, $result);
    }
}
