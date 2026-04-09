<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

readonly class TeamConfig
{
    /** @param array<string, string> $emailToTeam */
    private function __construct(private array $emailToTeam)
    {
    }

    public static function fromFile(string $path): self
    {
        $json = @file_get_contents($path);
        if (false === $json) {
            throw new \RuntimeException("Cannot read teams file: {$path}");
        }

        $data = json_decode($json, true);
        if (!\is_array($data) || !isset($data['teams']) || !\is_array($data['teams'])) {
            throw new \InvalidArgumentException("Invalid teams JSON: missing or invalid 'teams' key");
        }

        $emailToTeam = [];
        foreach ($data['teams'] as $team => $emails) {
            if (!\is_array($emails)) {
                throw new \InvalidArgumentException("Invalid teams JSON: emails for team '{$team}' must be an array");
            }
            foreach ($emails as $email) {
                $emailToTeam[strtolower((string) $email)] = (string) $team;
            }
        }

        return new self($emailToTeam);
    }

    public function getTeam(string $email): string
    {
        return $this->emailToTeam[strtolower($email)] ?? 'unknown';
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @return string[]
     */
    public function getTeamNames(): array
    {
        return array_values(array_unique(array_values($this->emailToTeam)));
    }
}
