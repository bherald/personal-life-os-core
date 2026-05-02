<?php

namespace App\Services\Setup;

use App\Support\Setup\CheckResult;
use Symfony\Component\Process\Process;
use Throwable;

class DockerChecker
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return list<CheckResult>
     */
    public function run(string $profile, array $manifest): array
    {
        $results = [];

        foreach ($this->profilesFor($profile) as $tier) {
            $section = $manifest[$tier] ?? null;
            if (! is_array($section)) {
                continue;
            }

            $engine = $section['engine'] ?? null;
            if (is_array($engine)) {
                foreach ($this->checkEngine($engine) as $result) {
                    $results[] = $result;
                }
            }

            foreach ((array) ($section['compose_files'] ?? []) as $file) {
                $results[] = $this->checkFile('compose', (string) $file, false);
            }
            foreach ((array) ($section['required_files'] ?? []) as $file) {
                $results[] = $this->checkFile('file', (string) $file, true);
            }
            foreach ((array) ($section['required_dirs'] ?? []) as $dir) {
                $results[] = $this->checkDir((string) $dir);
            }
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function profilesFor(string $profile): array
    {
        return match ($profile) {
            'core' => ['core'],
            'media' => ['core', 'media'],
            'gpu' => ['core', 'gpu'],
            'full' => ['core', 'media', 'gpu', 'full'],
            'personal' => ['core', 'media', 'gpu', 'full', 'personal'],
            default => ['core'],
        };
    }

    private function checkFile(string $kind, string $relative, bool $required): CheckResult
    {
        $name = $relative;
        $absolute = $this->basePath($relative);

        if (is_file($absolute)) {
            return CheckResult::pass('docker', $name, "{$kind} present");
        }

        return $required
            ? CheckResult::fail('docker', $name, "{$kind} missing at {$relative}")
            : CheckResult::warn('docker', $name, "{$kind} missing at {$relative}");
    }

    private function checkDir(string $relative): CheckResult
    {
        $absolute = $this->basePath($relative);

        return is_dir($absolute)
            ? CheckResult::pass('docker', $relative, 'directory present')
            : CheckResult::warn('docker', $relative, "directory missing at {$relative}");
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<CheckResult>
     */
    private function checkEngine(array $config): array
    {
        $required = (bool) ($config['required'] ?? false);
        $docker = $this->resolveBinary('docker');
        if ($docker === null) {
            return [
                $required
                    ? CheckResult::fail('docker', 'docker.binary', 'docker binary not found on PATH')
                    : CheckResult::warn('docker', 'docker.binary', 'docker binary not found on PATH (recommended)'),
            ];
        }

        $results = [
            CheckResult::pass('docker', 'docker.binary', 'docker binary found', ['path' => $docker]),
        ];

        if ((bool) ($config['compose'] ?? false)) {
            $results[] = $this->commandSucceeds([$docker, 'compose', 'version'])
                ? CheckResult::pass('docker', 'docker.compose', 'docker compose is available')
                : CheckResult::warn('docker', 'docker.compose', 'docker compose is not available');
        }

        if ((bool) ($config['daemon'] ?? false)) {
            $results[] = $this->commandSucceeds([$docker, 'info', '--format', '{{.ServerVersion}}'])
                ? CheckResult::pass('docker', 'docker.daemon', 'docker daemon reachable')
                : CheckResult::warn('docker', 'docker.daemon', 'docker daemon not reachable');
        }

        return $results;
    }

    protected function resolveBinary(string $bin): ?string
    {
        $path = getenv('PATH') ?: '';
        if ($path === '') {
            return null;
        }

        $separator = PHP_OS_FAMILY === 'Windows' ? ';' : ':';
        foreach (explode($separator, $path) as $dir) {
            if ($dir === '') {
                continue;
            }

            $candidate = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$bin;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $command
     */
    protected function commandSucceeds(array $command): bool
    {
        try {
            $process = new Process($command, $this->basePath(''));
            $process->setTimeout(3);
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    protected function basePath(string $relative): string
    {
        if (function_exists('base_path')) {
            return base_path($relative);
        }

        return getcwd().DIRECTORY_SEPARATOR.$relative;
    }
}
