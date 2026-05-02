<?php

namespace App\Services\Setup;

use App\Support\Setup\CheckResult;

class PhpChecker
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return list<CheckResult>
     */
    public function run(string $profile, array $manifest): array
    {
        $results = [];

        $minVersion = (string) ($manifest['min_version'] ?? '8.2.0');
        $recommendedVersion = (string) ($manifest['recommended_version'] ?? $minVersion);
        $current = $this->phpVersion();

        if (version_compare($current, $minVersion, '<')) {
            $results[] = CheckResult::fail('php', 'version', "PHP {$current} below minimum {$minVersion}");
        } elseif (version_compare($current, $recommendedVersion, '<')) {
            $results[] = CheckResult::warn('php', 'version', "PHP {$current} below recommended {$recommendedVersion}");
        } else {
            $results[] = CheckResult::pass('php', 'version', "PHP {$current}");
        }

        $extensions = (array) ($manifest['extensions'] ?? []);
        $required = $this->extensionsForProfile($profile, $extensions, ['core']);
        $recommended = $this->extensionsForProfile($profile, $extensions, ['media', 'gpu', 'full']);

        foreach (array_unique($required) as $ext) {
            $results[] = $this->extensionLoaded($ext)
                ? CheckResult::pass('php', "ext.{$ext}", "ext-{$ext} loaded")
                : CheckResult::fail('php', "ext.{$ext}", "ext-{$ext} missing");
        }

        foreach (array_unique($recommended) as $ext) {
            if (in_array($ext, $required, true)) {
                continue;
            }
            $results[] = $this->extensionLoaded($ext)
                ? CheckResult::pass('php', "ext.{$ext}", "ext-{$ext} loaded")
                : CheckResult::warn('php', "ext.{$ext}", "ext-{$ext} not loaded (recommended for {$profile} profile)");
        }

        return $results;
    }

    /**
     * @param  array<string, list<string>>  $byProfile
     * @param  list<string>  $tiers
     * @return list<string>
     */
    private function extensionsForProfile(string $profile, array $byProfile, array $tiers): array
    {
        $result = [];
        $resolved = $this->profilesFor($profile);
        foreach ($tiers as $tier) {
            if (! in_array($tier, $resolved, true)) {
                continue;
            }
            foreach ((array) ($byProfile[$tier] ?? []) as $ext) {
                if (is_string($ext) && $ext !== '') {
                    $result[] = $ext;
                }
            }
        }

        return $result;
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

    protected function phpVersion(): string
    {
        return PHP_VERSION;
    }

    protected function extensionLoaded(string $name): bool
    {
        return extension_loaded($name);
    }
}
