<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics;

trait CommandLineTrait
{
    private function runCommand(string $cmd, string $cwd): array
    {
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptorspec, $pipes, $cwd);
        if (!\is_resource($process)) {
            throw new \RuntimeException("Cannot start process: $cmd");
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if (0 !== $code) {
            throw new \RuntimeException("Command failed ($code): $cmd\n$err");
        }

        return explode(\PHP_EOL, trim($out));
    }
}
