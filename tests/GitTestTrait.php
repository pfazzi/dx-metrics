<?php

declare(strict_types=1);

namespace Pfazzi\DxMetrics\Tests;

use Pfazzi\DxMetrics\CommandLineTrait;

trait GitTestTrait
{
    use CommandLineTrait;

    private string $repoPath;

    protected function commit(string $repoPath, \DateTimeImmutable $date, string $message, array $files): string
    {
        foreach ($files as $path => $content) {
            $this->append($repoPath.$path, $content);
        }

        $this->append($repoPath.'/src/Invoice.php', "invoice v2\n");
        $this->runCommand('git add -A', $repoPath);

        $message = escapeshellarg($message);
        $this->runWithEnv(
            "git commit -qm \"{$message}\"",
            $repoPath,
            ['GIT_AUTHOR_DATE' => $date->format(\DATE_ATOM), 'GIT_COMMITTER_DATE' => $date->format(\DATE_ATOM)],
        );

        $commitSha = $this->runCommand('git rev-parse HEAD', $repoPath);

        return $commitSha[0];
    }

    protected function makeTempDir(): string
    {
        $tempRepoDir = sys_get_temp_dir().'/git-repo-'.bin2hex(random_bytes(6));

        mkdir($tempRepoDir, 0777, true);

        return $tempRepoDir;
    }

    protected function initRepo(string $path): void
    {
        $this->runCommand('git init -q', $path);
        $this->runCommand('git config user.name "Test Bot"', $path);
        $this->runCommand('git config user.email "test@example.com"', $path);
        $this->runCommand('git config commit.gpgsign false', $path);
        $this->runCommand('git checkout -q -b main', $path);
    }

    protected function setUpTestRepo(string $repoPath): void
    {
        $this->initRepo($repoPath);
    }

    protected function cleanUpTestRepo(string $repoPath): void
    {
        $this->rrmdir($repoPath);
    }

    public function getCommitShaList(string $repoPath): array
    {
        return $this->runCommand('git rev-parse HEAD', $repoPath);
    }

    private function runWithEnv(string $cmd, string $cwd, array $env): void
    {
        $envPairs = array_map(fn ($k, $v) => "$k=$v", array_keys($env), array_values($env));
        $full = implode(' ', array_map('escapeshellarg', $envPairs)).' '.$cmd;
        // più portabile: setta env per il process
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptorspec, $pipes, $cwd, $env + $_ENV);
        if (!\is_resource($process)) {
            throw new \RuntimeException("Cannot start process: $cmd");
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if (0 !== $code) {
            throw new \RuntimeException("Command failed ($code): $cmd\n$err");
        }
    }

    private function append(string $path, string $content): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $result = file_put_contents($path, $content, \FILE_APPEND);

        if (false === $result) {
            throw new \RuntimeException("Cannot write file: $path");
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
