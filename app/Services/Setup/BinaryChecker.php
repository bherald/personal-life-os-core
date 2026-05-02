<?php

namespace App\Services\Setup;

use App\Support\Setup\CheckResult;
use Symfony\Component\Process\Process;
use Throwable;

class BinaryChecker
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return list<CheckResult>
     */
    public function run(string $profile, array $manifest): array
    {
        $results = [];
        $seen = [];

        foreach ($this->profilesFor($profile) as $tier) {
            $section = $manifest[$tier] ?? null;
            if (! is_array($section)) {
                continue;
            }

            foreach ((array) ($section['required'] ?? []) as $entry) {
                $spec = $this->normalizeSpec($entry);
                if ($spec === null) {
                    continue;
                }

                $bin = $spec['name'];
                $key = "required:{$bin}";
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $results[] = $this->checkBinary($spec, true);
            }

            foreach ((array) ($section['recommended'] ?? []) as $entry) {
                $spec = $this->normalizeSpec($entry);
                if ($spec === null) {
                    continue;
                }

                $bin = $spec['name'];
                $requiredKey = "required:{$bin}";
                $recKey = "recommended:{$bin}";
                if (isset($seen[$requiredKey]) || isset($seen[$recKey])) {
                    continue;
                }
                $seen[$recKey] = true;
                $results[] = $this->checkBinary($spec, false);
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

    /**
     * @return array{name:string,min_version?:string,version_args?:list<string>,version_regex?:string}|null
     */
    private function normalizeSpec(mixed $entry): ?array
    {
        if (is_string($entry)) {
            return ['name' => $entry];
        }

        if (! is_array($entry)) {
            return null;
        }

        $name = (string) ($entry['name'] ?? '');
        if ($name === '') {
            return null;
        }

        $spec = ['name' => $name];
        foreach (['min_version', 'version_regex'] as $key) {
            if (isset($entry[$key]) && is_string($entry[$key]) && $entry[$key] !== '') {
                $spec[$key] = $entry[$key];
            }
        }

        if (isset($entry['version_args']) && is_array($entry['version_args'])) {
            $spec['version_args'] = array_values(array_map('strval', $entry['version_args']));
        }

        return $spec;
    }

    /**
     * @param  array{name:string,min_version?:string,version_args?:list<string>,version_regex?:string}  $spec
     */
    private function checkBinary(array $spec, bool $required): CheckResult
    {
        $bin = $spec['name'];
        $path = $this->resolve($bin);
        if ($path === null) {
            return $required
                ? CheckResult::fail('binaries', $bin, "binary '{$bin}' not found on PATH")
                : CheckResult::warn('binaries', $bin, "binary '{$bin}' not found on PATH (recommended)");
        }

        $context = ['path' => $path];
        $minVersion = $spec['min_version'] ?? null;
        if ($minVersion !== null) {
            $version = $this->binaryVersion($path, $spec);
            if ($version === null) {
                return CheckResult::warn('binaries', $bin, "{$bin} found but version could not be parsed", $context);
            }

            $context['version'] = $version;
            $context['min_version'] = $minVersion;
            if (version_compare($version, $minVersion, '<')) {
                return $required
                    ? CheckResult::fail('binaries', $bin, "{$bin} {$version} below required {$minVersion}", $context)
                    : CheckResult::warn('binaries', $bin, "{$bin} {$version} below recommended {$minVersion}", $context);
            }

            return CheckResult::pass('binaries', $bin, "{$bin} {$version} found", $context);
        }

        return CheckResult::pass('binaries', $bin, "{$bin} found", $context);
    }

    /**
     * Resolve a binary against the current PATH. Returns null when not found.
     */
    protected function resolve(string $bin): ?string
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
     * @param  array{name:string,min_version?:string,version_args?:list<string>,version_regex?:string}  $spec
     */
    protected function binaryVersion(string $path, array $spec): ?string
    {
        $args = $spec['version_args'] ?? ['--version'];
        $output = $this->runBinary(array_merge([$path], $args));
        if ($output === null || $output === '') {
            return null;
        }

        $regex = $spec['version_regex'] ?? '/(\d+(?:\.\d+){0,3})/';
        if (! preg_match($regex, $output, $matches)) {
            return null;
        }

        return $matches[1] ?? null;
    }

    /**
     * @param  list<string>  $command
     */
    protected function runBinary(array $command): ?string
    {
        try {
            $process = new Process($command);
            $process->setTimeout(3);
            $process->run();

            return trim($process->getOutput()."\n".$process->getErrorOutput());
        } catch (Throwable) {
            return null;
        }
    }
}
