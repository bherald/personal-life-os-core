<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PATTERN_NAME = 'timeout_retry_or_stale_window';

    private const OLD_REGEX = '/\b(timeout_minutes|timeout|retry|cooldown|stale|stale_minutes|min_minutes_between_runs|expires_days)\b/i';

    private const NEW_REGEX = '/(?:\b(timeout_minutes|timeout|min_minutes_between_runs|expires_days)\b|[\'\"]retry[\'\"]\s*=>|\b((?:max_)?retries|retry_(?:attempts?|count|limit|delay|after|backoff|window)|cooldown_(?:seconds?|minutes?|hours?|days?)|stale_(?:seconds?|minutes?|hours?|days?|after|age|cutoff|threshold|max|min))\b|\b[A-Za-z0-9]*(Retries|RetryAttempts|RetryCount|RetryLimit|RetryDelay|RetryAfter(?:Seconds)?|RetryBackoff|RetryWindow|CooldownSeconds|CooldownMinutes|CooldownHours|CooldownDays|StaleSeconds|StaleMinutes|StaleHours|StaleDays|StaleAfter|StaleAge|StaleCutoff|StaleThreshold)\b)/i';

    public function up(): void
    {
        $this->setRegex(self::NEW_REGEX);
    }

    public function down(): void
    {
        $this->setRegex(self::OLD_REGEX, self::NEW_REGEX, false);
    }

    private function setRegex(string $regex, ?string $expectedCurrent = null, bool $recordPolicy = true): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->first(['config_value']);
        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;

        if (! is_array($policy) || ! is_array($policy['patterns'] ?? null)) {
            return;
        }

        $updated = false;
        foreach ($policy['patterns'] as &$pattern) {
            if (! is_array($pattern) || ($pattern['name'] ?? null) !== self::PATTERN_NAME) {
                continue;
            }
            if ($expectedCurrent !== null && ($pattern['regex'] ?? null) !== $expectedCurrent) {
                break;
            }

            $pattern['regex'] = $regex;
            $updated = true;
            break;
        }
        unset($pattern);

        if (! $updated) {
            return;
        }

        if ($recordPolicy) {
            $policy['classification_policy'][self::PATTERN_NAME] =
                'Matches timeout fields, literal retry-interval payloads, retry count/backoff identifiers, cooldown durations, and stale-age windows; bare retry methods, loop counters, cooldown state, and stale metrics are not runtime policy.';
        } elseif (is_array($policy['classification_policy'] ?? null)) {
            unset($policy['classification_policy'][self::PATTERN_NAME]);
        }

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
