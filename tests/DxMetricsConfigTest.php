<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\DxMetricsConfig;
use Pfazzi\DxMetrics\OwnershipHotspots;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class DxMetricsConfigTest extends TestCase
{
    use GitTestTrait;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dx-config-test-'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tempDir);
    }

    public function test_returns_empty_config_when_file_does_not_exist(): void
    {
        $config = DxMetricsConfig::fromRepoPath($this->tempDir);

        self::assertTrue($config->isEmpty());
        self::assertNull($config->get('teams'));
    }

    public function test_loads_string_value(): void
    {
        file_put_contents($this->tempDir.'/.dx-metrics.json', json_encode(['teams' => 'teams.json']));

        $config = DxMetricsConfig::fromRepoPath($this->tempDir);

        self::assertSame('teams.json', $config->get('teams'));
    }

    public function test_loads_integer_value(): void
    {
        file_put_contents($this->tempDir.'/.dx-metrics.json', json_encode(['depth' => 3]));

        $config = DxMetricsConfig::fromRepoPath($this->tempDir);

        self::assertSame(3, $config->get('depth'));
    }

    public function test_loads_array_value(): void
    {
        file_put_contents($this->tempDir.'/.dx-metrics.json', json_encode(['exclude' => ['*.lock']]));

        $config = DxMetricsConfig::fromRepoPath($this->tempDir);

        self::assertSame(['*.lock'], $config->get('exclude'));
    }

    public function test_returns_default_when_key_not_present(): void
    {
        file_put_contents($this->tempDir.'/.dx-metrics.json', json_encode([]));

        $config = DxMetricsConfig::fromRepoPath($this->tempDir);

        self::assertSame('fallback', $config->get('teams', 'fallback'));
    }

    public function test_cli_option_overrides_config(): void
    {
        $repoPath = $this->makeTempDir();
        $this->setUpTestRepo($repoPath);

        // Write a .dx-metrics.json with teams set to config-teams.json
        file_put_contents($repoPath.'/.dx-metrics.json', json_encode(['teams' => 'config-teams.json']));

        // Write a real teams file the CLI will use
        $teamsFile = $this->tempDir.'/cli-teams.json';
        file_put_contents($teamsFile, json_encode([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
            ],
        ]));

        $this->commit(
            $repoPath,
            new \DateTimeImmutable('2024-01-01T12:00:00+0000'),
            'feat: platform',
            ['/src/Shared.php' => "v1\n"],
            'alice@example.com',
        );
        $this->commit(
            $repoPath,
            new \DateTimeImmutable('2024-01-02T12:00:00+0000'),
            'feat: payments',
            ['/src/Shared.php' => "v2\n"],
            'bob@example.com',
        );

        $app = new Application();
        $app->add(new OwnershipHotspots());
        $tester = new CommandTester($app->find('ownership:hotspots'));
        // Pass --teams=cli-teams.json explicitly — this must override the config file value
        $tester->execute(['path' => $repoPath, '--teams' => $teamsFile]);

        // If CLI override works, the command must succeed (it can find the real teams file)
        self::assertSame(0, $tester->getStatusCode());

        $this->cleanUpTestRepo($repoPath);
    }
}
