<?php

namespace App\Services\Setup;

use App\Support\Setup\CheckResult;

class AssetChecker
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

            foreach ((array) ($section['required_files'] ?? []) as $file) {
                $this->addFileResult($results, $seen, (string) $file, true);
            }
            foreach ((array) ($section['recommended_files'] ?? []) as $file) {
                $this->addFileResult($results, $seen, (string) $file, false);
            }
            foreach ((array) ($section['required_dirs'] ?? []) as $dir) {
                $this->addDirResult($results, $seen, (string) $dir, true);
            }
            foreach ((array) ($section['recommended_dirs'] ?? []) as $dir) {
                $this->addDirResult($results, $seen, (string) $dir, false);
            }
            foreach ((array) ($section['required_writable_dirs'] ?? []) as $dir) {
                $this->addWritableDirResult($results, $seen, (string) $dir, true);
            }
            foreach ((array) ($section['recommended_writable_dirs'] ?? []) as $dir) {
                $this->addWritableDirResult($results, $seen, (string) $dir, false);
            }
            foreach ((array) ($section['env_dirs'] ?? []) as $entry) {
                if (is_array($entry)) {
                    $this->addEnvDirResult($results, $seen, $entry);
                }
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
     * @param  list<CheckResult>  $results
     * @param  array<string, bool>  $seen
     */
    private function addFileResult(array &$results, array &$seen, string $relative, bool $required): void
    {
        $key = "file:{$relative}";
        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $absolute = $this->basePath($relative);

        if (is_file($absolute)) {
            $results[] = CheckResult::pass('assets', $relative, 'file present', ['path' => $absolute]);

            return;
        }

        $results[] = $required
            ? CheckResult::fail('assets', $relative, "required file missing at {$relative}", ['path' => $absolute])
            : CheckResult::warn('assets', $relative, "recommended file missing at {$relative}", ['path' => $absolute]);
    }

    /**
     * @param  list<CheckResult>  $results
     * @param  array<string, bool>  $seen
     */
    private function addDirResult(array &$results, array &$seen, string $relative, bool $required): void
    {
        $key = "dir:{$relative}";
        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $absolute = $this->basePath($relative);

        if (is_dir($absolute)) {
            $results[] = CheckResult::pass('assets', $relative, 'directory present', ['path' => $absolute]);

            return;
        }

        $results[] = $required
            ? CheckResult::fail('assets', $relative, "required directory missing at {$relative}", ['path' => $absolute])
            : CheckResult::warn('assets', $relative, "recommended directory missing at {$relative}", ['path' => $absolute]);
    }

    /**
     * @param  list<CheckResult>  $results
     * @param  array<string, bool>  $seen
     */
    private function addWritableDirResult(array &$results, array &$seen, string $relative, bool $required): void
    {
        $key = "writable-dir:{$relative}";
        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $absolute = $this->basePath($relative);

        if (! is_dir($absolute)) {
            $results[] = $required
                ? CheckResult::fail('assets', $relative, "required writable directory missing at {$relative}", ['path' => $absolute])
                : CheckResult::warn('assets', $relative, "recommended writable directory missing at {$relative}", ['path' => $absolute]);

            return;
        }

        if (! is_writable($absolute)) {
            $results[] = $required
                ? CheckResult::fail('assets', $relative, "required directory is not writable at {$relative}", ['path' => $absolute])
                : CheckResult::warn('assets', $relative, "recommended directory is not writable at {$relative}", ['path' => $absolute]);

            return;
        }

        $results[] = CheckResult::pass('assets', $relative, 'directory writable', ['path' => $absolute]);
    }

    /**
     * @param  list<CheckResult>  $results
     * @param  array<string, bool>  $seen
     * @param  array<string, mixed>  $entry
     */
    private function addEnvDirResult(array &$results, array &$seen, array $entry): void
    {
        $envKey = (string) ($entry['env'] ?? '');
        if ($envKey === '') {
            return;
        }

        $name = (string) ($entry['name'] ?? "env.{$envKey}");
        $key = "env-dir:{$envKey}";
        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $value = $this->envValue($envKey);
        if ($value === null) {
            $results[] = CheckResult::skip('assets', $name, "{$envKey} is unset; directory probe skipped");

            return;
        }

        $path = $this->expandPath($value);
        $failWhenSet = (bool) ($entry['fail_when_set'] ?? false);
        $readable = (bool) ($entry['readable'] ?? false);
        $writable = (bool) ($entry['writable'] ?? false);

        if (! is_dir($path)) {
            $message = "{$envKey} points to missing directory";
            $results[] = $failWhenSet
                ? CheckResult::fail('assets', $name, $message, ['path' => $path, 'env' => $envKey])
                : CheckResult::warn('assets', $name, $message, ['path' => $path, 'env' => $envKey]);

            return;
        }

        if ($readable && ! is_readable($path)) {
            $message = "{$envKey} directory is not readable";
            $results[] = $failWhenSet
                ? CheckResult::fail('assets', $name, $message, ['path' => $path, 'env' => $envKey])
                : CheckResult::warn('assets', $name, $message, ['path' => $path, 'env' => $envKey]);

            return;
        }

        if ($writable && ! is_writable($path)) {
            $message = "{$envKey} directory is not writable";
            $results[] = $failWhenSet
                ? CheckResult::fail('assets', $name, $message, ['path' => $path, 'env' => $envKey])
                : CheckResult::warn('assets', $name, $message, ['path' => $path, 'env' => $envKey]);

            return;
        }

        $results[] = CheckResult::pass('assets', $name, "{$envKey} directory present", ['path' => $path, 'env' => $envKey]);
    }

    protected function envValue(string $key): ?string
    {
        $value = env($key);
        if ($value === null) {
            $value = getenv($key);
        }

        if ($value === false || $value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function expandPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $home = $this->envValue('HOME') ?? '';
        if ($home !== '') {
            if (str_starts_with($path, '~/')) {
                $path = $home.substr($path, 1);
            }
            $path = str_replace('$HOME', $home, $path);
        }

        return $path;
    }

    protected function basePath(string $relative): string
    {
        if (function_exists('base_path')) {
            return base_path($relative);
        }

        return getcwd().DIRECTORY_SEPARATOR.$relative;
    }
}
