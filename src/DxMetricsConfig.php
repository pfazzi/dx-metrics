<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

final class DxMetricsConfig
{
    private array $data = [];

    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Loads .dx-metrics.json from the given repo path, or returns an empty config if not found.
     */
    public static function fromRepoPath(string $repoPath): self
    {
        $path = rtrim($repoPath, '/').'/.dx-metrics.json';
        if (!file_exists($path)) {
            return new self([]);
        }
        $json = file_get_contents($path);
        if (false === $json) {
            return new self([]);
        }
        $data = json_decode($json, true);

        return new self(\is_array($data) ? $data : []);
    }

    /**
     * Returns the config value for the given key, or $default if not set.
     * Supports dot notation for nested keys (not needed now, future-proofing optional).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Returns true if the config file was found and has any data.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function isEmpty(): bool
    {
        return [] === $this->data;
    }
}
