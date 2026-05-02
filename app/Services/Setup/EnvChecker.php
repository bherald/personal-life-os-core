<?php

namespace App\Services\Setup;

use App\Support\Setup\CheckResult;

class EnvChecker
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return list<CheckResult>
     */
    public function run(string $profile, array $manifest): array
    {
        $results = [];
        $placeholders = (array) ($manifest['placeholders'] ?? []);

        foreach ($this->profilesFor($profile) as $profileKey) {
            $section = $manifest[$profileKey] ?? null;
            if (! is_array($section)) {
                continue;
            }

            foreach ((array) ($section['required'] ?? []) as $key) {
                $results[] = $this->checkKey($key, true, $placeholders);
            }

            foreach ((array) ($section['recommended'] ?? []) as $key) {
                $results[] = $this->checkKey($key, false, $placeholders);
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
     * @param  list<string>  $placeholders
     */
    private function checkKey(string $key, bool $required, array $placeholders): CheckResult
    {
        $value = env($key);
        $isMissing = $value === null || $value === '';
        $isPlaceholder = is_string($value) && in_array(trim($value), $placeholders, true);

        if ($isMissing) {
            return $required
                ? CheckResult::fail('env', $key, "missing required env {$key}")
                : CheckResult::warn('env', $key, "recommended env {$key} is unset");
        }

        if ($isPlaceholder) {
            return $required
                ? CheckResult::fail('env', $key, "{$key} still set to placeholder value")
                : CheckResult::warn('env', $key, "{$key} still set to placeholder value");
        }

        return CheckResult::pass('env', $key, "{$key} is set");
    }
}
