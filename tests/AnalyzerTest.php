<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\AnalysisOutputItem;
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

    public function test_analyze__with_empty_repo__returns_empty_output(): void
    {
        $git = new Git($this->repoPath);
        $analyzer = new Analyzer($git);

        $result = $analyzer->analyze();

        self::assertCount(0, $result->items);
    }

    public function test_analyze__single_file_commit__produces_no_coupling(): void
    {
        $git = new Git($this->repoPath);
        $analyzer = new Analyzer($git);

        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: solo change',
            ['/src/Alone.php' => "content\n"],
        );

        $result = $analyzer->analyze();

        self::assertCount(0, $result->items);
    }

    public function test_analyze__with_since_filter__excludes_older_commits(): void
    {
        $git = new Git($this->repoPath);
        $analyzer = new Analyzer($git);

        // Old commit — should be excluded
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2023-06-01T12:00:00+0000'),
            'feat: old coupling',
            [
                '/src/Old.php' => "content\n",
                '/src/Legacy.php' => "content\n",
            ],
        );

        // Recent commit — should be included
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-03-01T12:00:00+0000'),
            'feat: new coupling',
            [
                '/src/New.php' => "content\n",
                '/src/Modern.php' => "content\n",
            ],
        );

        $result = $analyzer->analyze(since: new \DateTimeImmutable('2024-01-01'));

        $paths = array_map(static fn ($i) => $i->pathA, $result->items);
        self::assertNotContains('src/Old.php', $paths);
        self::assertContains('src/New.php', $paths);
    }

    public function test_analyze__with_until_filter__excludes_newer_commits(): void
    {
        $git = new Git($this->repoPath);
        $analyzer = new Analyzer($git);

        // Old commit — should be included
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2023-06-01T12:00:00+0000'),
            'feat: old coupling',
            [
                '/src/Old.php' => "content\n",
                '/src/Legacy.php' => "content\n",
            ],
        );

        // Recent commit — should be excluded
        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-03-01T12:00:00+0000'),
            'feat: new coupling',
            [
                '/src/New.php' => "content\n",
                '/src/Modern.php' => "content\n",
            ],
        );

        $result = $analyzer->analyze(until: new \DateTimeImmutable('2023-12-31'));

        $paths = array_map(static fn ($i) => $i->pathA, $result->items);
        self::assertContains('src/Old.php', $paths);
        self::assertNotContains('src/New.php', $paths);
    }

    public function test_analyze__three_files_in_commit__produces_all_pair_combinations(): void
    {
        $git = new Git($this->repoPath);
        $analyzer = new Analyzer($git);

        $this->commit(
            $this->repoPath,
            new \DateTimeImmutable('2024-01-05T12:00:00+0000'),
            'feat: three files',
            [
                '/src/A.php' => "content\n",
                '/src/B.php' => "content\n",
                '/src/C.php' => "content\n",
            ],
        );

        $items = $analyzer->analyze()->items;

        // 3 files → 3*(3-1) = 6 directed pairs
        self::assertCount(6, $items);
        $pairs = array_map(static fn ($i) => $i->pathA.'->'.$i->pathB, $items);
        self::assertContains('src/A.php->src/B.php', $pairs);
        self::assertContains('src/A.php->src/C.php', $pairs);
        self::assertContains('src/B.php->src/A.php', $pairs);
        self::assertContains('src/B.php->src/C.php', $pairs);
        self::assertContains('src/C.php->src/A.php', $pairs);
        self::assertContains('src/C.php->src/B.php', $pairs);
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

        $items = $analyzer->analyze()->items;
        usort($items, static fn ($a, $b) => ($a->pathA.$a->pathB) <=> ($b->pathA.$b->pathB));

        self::assertEquals([
            new AnalysisOutputItem('src/Invoice.php', 'src/Order.php', 2),
            new AnalysisOutputItem('src/Order.php', 'src/Invoice.php', 2),
            new AnalysisOutputItem('src/Order.php', 'src/User.php', 1),
            new AnalysisOutputItem('src/User.php', 'src/Order.php', 1),
        ], $items);
    }
}
