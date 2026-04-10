<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\CodeownersSuggest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Documents codeowners:suggest behavior: generating a CODEOWNERS draft from commit history.
 *
 * The command assigns each module (defined by path depth) to its dominant team. The generated
 * file uses one line per module in the format:
 *   path/  @team-slug
 *
 * Key behaviors under test:
 *   - Module paths are sorted alphabetically for a clean, stable output
 *   - Team names are slugified (lowercase, non-alphanum replaced by "-")
 *   - With --github-org, owners are formatted as @org/team-slug
 *   - Modules with dominance < 50% carry a (!) marker — ownership is contested
 *   - With --output, content is written to a file instead of stdout
 *
 * Note: all executeCommand calls pass '--since' => '2020-01-01' because the command defaults to
 * '-6 months' and test commits use fixed historical dates.
 */
class CodeownersSuggestCommandTest extends TestCase
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
        $tester = $this->executeCommand(['path' => $this->repoPath]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--teams', $tester->getDisplay());
    }

    public function test_dominant_team_is_assigned_to_module(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: platform work', [
                '/src/Orders/Order.php' => "v1\n",
                '/src/Orders/OrderItem.php' => "v1\n",
            ], 'alice@example.com');

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--depth' => '2',
            '--since' => '2020-01-01',
        ]);

        $output = $tester->getDisplay();
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('src/Orders/', $output);
        self::assertStringContainsString('@platform', $output);
    }

    public function test_module_paths_are_sorted_alphabetically_in_generated_file(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: multi module', [
                '/src/Zebra/Z.php' => "v1\n",
                '/src/Alpha/A.php' => "v1\n",
                '/src/Mango/M.php' => "v1\n",
            ], 'alice@example.com');

        $outputFile = sys_get_temp_dir().'/CODEOWNERS-test-'.bin2hex(random_bytes(4));

        try {
            $this->executeCommand([
                'path' => $this->repoPath,
                '--teams' => $this->teamsFile,
                '--depth' => '2',
                '--output' => $outputFile,
                '--since' => '2020-01-01',
            ]);

            $content = file_get_contents($outputFile);
            self::assertNotFalse($content);

            $alphaPos = strpos($content, 'src/Alpha/');
            $mangoPos = strpos($content, 'src/Mango/');
            $zebraPos = strpos($content, 'src/Zebra/');

            self::assertLessThan($mangoPos, $alphaPos, 'Alpha should come before Mango');
            self::assertLessThan($zebraPos, $mangoPos, 'Mango should come before Zebra');
        } finally {
            if (file_exists($outputFile)) {
                unlink($outputFile);
            }
        }
    }

    public function test_github_org_option_formats_owner_as_at_org_slash_slug(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: init', ['/src/Orders/Order.php' => "v1\n"], 'alice@example.com');

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--depth' => '2',
            '--github-org' => 'mycompany',
            '--since' => '2020-01-01',
        ]);

        self::assertStringContainsString('@mycompany/platform', $tester->getDisplay());
    }

    public function test_team_name_is_slugified_in_output(): void
    {
        // Team names with spaces or uppercase should be normalized to lowercase slug
        $this->writeTeams(['teams' => ['Platform Core' => ['alice@example.com']]]);

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: init', ['/src/Orders/Order.php' => "v1\n"], 'alice@example.com');

        $tester = $this->executeCommand([
            'path' => $this->repoPath,
            '--teams' => $this->teamsFile,
            '--depth' => '2',
            '--since' => '2020-01-01',
        ]);

        // "Platform Core" → slug "platform-core"
        self::assertStringContainsString('@platform-core', $tester->getDisplay());
    }

    public function test_output_option_writes_codeowners_to_file(): void
    {
        $this->writeTeams(['teams' => ['platform' => ['alice@example.com']]]);

        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: init', ['/src/Orders/Order.php' => "v1\n"], 'alice@example.com');

        $outputFile = sys_get_temp_dir().'/CODEOWNERS-test-'.bin2hex(random_bytes(4));

        try {
            $this->executeCommand([
                'path' => $this->repoPath,
                '--teams' => $this->teamsFile,
                '--depth' => '2',
                '--output' => $outputFile,
                '--since' => '2020-01-01',
            ]);

            self::assertFileExists($outputFile);
            $content = (string) file_get_contents($outputFile);
            self::assertStringContainsString('src/Orders/', $content);
            self::assertStringContainsString('@platform', $content);
            self::assertStringContainsString('Generated by dx-metrics', $content);
        } finally {
            if (file_exists($outputFile)) {
                unlink($outputFile);
            }
        }
    }

    public function test_contested_module_is_marked_with_exclamation(): void
    {
        $this->writeTeams([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]);

        // alice and bob each commit one file in the module → 50% dominance, contested
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: alice', ['/src/Orders/Order.php' => "v1\n"], 'alice@example.com');
        $this->commit($this->repoPath, new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: bob', ['/src/Orders/OrderItem.php' => "v1\n"], 'bob@example.com');

        $outputFile = sys_get_temp_dir().'/CODEOWNERS-test-'.bin2hex(random_bytes(4));

        try {
            $this->executeCommand([
                'path' => $this->repoPath,
                '--teams' => $this->teamsFile,
                '--depth' => '2',
                '--output' => $outputFile,
                '--since' => '2020-01-01',
            ]);

            $content = (string) file_get_contents($outputFile);
            // Module with 50% dominance must carry the (!) marker
            self::assertStringContainsString('(!)', $content);
        } finally {
            if (file_exists($outputFile)) {
                unlink($outputFile);
            }
        }
    }

    private function executeCommand(array $args): CommandTester
    {
        $app = new Application();
        $app->add(new CodeownersSuggest());
        $tester = new CommandTester($app->find('codeowners:suggest'));
        $tester->execute($args);

        return $tester;
    }

    /** @param array<string, mixed> $data */
    private function writeTeams(array $data): void
    {
        file_put_contents($this->teamsFile, json_encode($data));
    }
}
