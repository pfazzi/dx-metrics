<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\TeamConfig;
use PHPUnit\Framework\TestCase;

class TeamConfigTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir().'/teams-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function test_get_team__returns_correct_team_for_known_email(): void
    {
        $this->writeTeamsFile([
            'teams' => [
                'platform' => ['alice@example.com', 'bob@example.com'],
                'payments' => ['charlie@example.com'],
            ],
        ]);

        $config = TeamConfig::fromFile($this->tempFile);

        self::assertSame('platform', $config->getTeam('alice@example.com'));
        self::assertSame('platform', $config->getTeam('bob@example.com'));
        self::assertSame('payments', $config->getTeam('charlie@example.com'));
    }

    public function test_get_team__email_matching_is_case_insensitive(): void
    {
        $this->writeTeamsFile([
            'teams' => ['platform' => ['Alice@Example.COM']],
        ]);

        $config = TeamConfig::fromFile($this->tempFile);

        self::assertSame('platform', $config->getTeam('alice@example.com'));
        self::assertSame('platform', $config->getTeam('ALICE@EXAMPLE.COM'));
    }

    public function test_get_team__unknown_email_returns_unknown(): void
    {
        $this->writeTeamsFile([
            'teams' => ['platform' => ['alice@example.com']],
        ]);

        $config = TeamConfig::fromFile($this->tempFile);

        self::assertSame('unknown', $config->getTeam('stranger@example.com'));
    }

    public function test_get_team_names__returns_all_configured_teams(): void
    {
        $this->writeTeamsFile([
            'teams' => [
                'platform' => ['alice@example.com'],
                'payments' => ['bob@example.com'],
                'identity' => ['charlie@example.com'],
            ],
        ]);

        $config = TeamConfig::fromFile($this->tempFile);
        $teams = $config->getTeamNames();

        self::assertCount(3, $teams);
        self::assertContains('platform', $teams);
        self::assertContains('payments', $teams);
        self::assertContains('identity', $teams);
    }

    public function test_from_file__throws_when_file_not_found(): void
    {
        $this->expectException(\RuntimeException::class);

        TeamConfig::fromFile('/nonexistent/path/teams.json');
    }

    public function test_from_file__throws_when_json_missing_teams_key(): void
    {
        file_put_contents($this->tempFile, '{"not_teams": {}}');

        $this->expectException(\InvalidArgumentException::class);

        TeamConfig::fromFile($this->tempFile);
    }

    public function test_from_file__throws_when_emails_not_an_array(): void
    {
        $this->writeTeamsFile(['teams' => ['platform' => 'alice@example.com']]);

        $this->expectException(\InvalidArgumentException::class);

        TeamConfig::fromFile($this->tempFile);
    }

    private function writeTeamsFile(array $data): void
    {
        file_put_contents($this->tempFile, json_encode($data));
    }
}
