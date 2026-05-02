<?php

namespace App\Services\Setup;

use App\Support\Setup\CheckResult;

class BrowserChecker
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
            $entries = (array) ($manifest[$tier] ?? []);
            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $name = (string) ($entry['name'] ?? '');
                if ($name === '' || isset($seen[$name])) {
                    continue;
                }

                $seen[$name] = true;
                $results[] = $this->checkBrowser($entry);
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
     * @param  array<string, mixed>  $entry
     */
    private function checkBrowser(array $entry): CheckResult
    {
        $engine = (string) ($entry['engine'] ?? '');

        return match ($engine) {
            'playwright' => $this->checkPlaywright($entry),
            'puppeteer' => $this->checkPuppeteer($entry),
            default => CheckResult::warn('browser', (string) ($entry['name'] ?? 'unknown'), "unknown browser engine '{$engine}'"),
        };
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function checkPlaywright(array $entry): CheckResult
    {
        $name = (string) ($entry['name'] ?? 'playwright.chromium');
        $required = (bool) ($entry['required'] ?? false);
        $hint = (string) ($entry['install_hint'] ?? 'npx playwright install chromium');
        $expected = $this->playwrightChromiumPath();

        if ($expected !== null && $this->isExecutablePath($expected)) {
            return CheckResult::pass('browser', $name, 'Playwright Chromium executable found', ['path' => $expected]);
        }

        $message = $expected === null
            ? "could not resolve Playwright Chromium executable; run {$hint}"
            : "Playwright Chromium missing at {$expected}; run {$hint}";
        $context = $expected === null ? ['hint' => $hint] : ['path' => $expected, 'hint' => $hint];

        return $required
            ? CheckResult::fail('browser', $name, $message, $context)
            : CheckResult::warn('browser', $name, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function checkPuppeteer(array $entry): CheckResult
    {
        $name = (string) ($entry['name'] ?? 'puppeteer.chrome');
        $required = (bool) ($entry['required'] ?? false);
        $hint = (string) ($entry['install_hint'] ?? 'set PLOS_PUPPETEER_CHROME or install Chrome/Chromium');

        foreach ($this->puppeteerCandidates($entry) as $candidate) {
            if ($this->isExecutablePath($candidate)) {
                return CheckResult::pass('browser', $name, 'Puppeteer Chrome executable found', ['path' => $candidate]);
            }
        }

        $message = "Puppeteer Chrome executable not found; {$hint}";
        $context = ['hint' => $hint];

        return $required
            ? CheckResult::fail('browser', $name, $message, $context)
            : CheckResult::warn('browser', $name, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    protected function puppeteerCandidates(array $entry): array
    {
        $candidates = [];

        foreach ((array) ($entry['env_keys'] ?? []) as $key) {
            $value = $this->envValue((string) $key);
            if ($value !== null) {
                $candidates[] = $this->expandPath($value);
            }
        }

        $configPath = (string) ($entry['config_path'] ?? '');
        if ($configPath !== '') {
            $value = config($configPath);
            if (is_string($value) && trim($value) !== '') {
                $candidates[] = $this->expandPath($value);
            }
        }

        foreach ((array) ($entry['cache_globs'] ?? []) as $pattern) {
            foreach ($this->globPaths($this->expandPath((string) $pattern)) as $path) {
                $candidates[] = $path;
            }
        }

        foreach ((array) ($entry['fallback_paths'] ?? []) as $path) {
            $candidates[] = $this->expandPath((string) $path);
        }

        foreach ((array) ($entry['fallback_bins'] ?? []) as $bin) {
            $path = $this->resolveBinary((string) $bin);
            if ($path !== null) {
                $candidates[] = $path;
            }
        }

        return array_values(array_unique(array_filter($candidates, static fn ($path) => $path !== '')));
    }

    protected function playwrightChromiumPath(): ?string
    {
        $project = escapeshellarg(rtrim($this->basePath(''), DIRECTORY_SEPARATOR));
        $script = "const { chromium } = require('playwright'); process.stdout.write(chromium.executablePath());";
        $output = @shell_exec('cd '.$project.' && node -e '.escapeshellarg($script).' 2>/dev/null');
        if (! is_string($output)) {
            return null;
        }

        $output = trim($output);

        return $output === '' ? null : $output;
    }

    protected function isExecutablePath(string $path): bool
    {
        return is_file($path) && is_executable($path);
    }

    /**
     * @return list<string>
     */
    protected function globPaths(string $pattern): array
    {
        $matches = glob($pattern);
        if ($matches === false) {
            return [];
        }

        rsort($matches);

        return array_values($matches);
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
            if ($this->isExecutablePath($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function envValue(string $key): ?string
    {
        if ($key === '') {
            return null;
        }

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
