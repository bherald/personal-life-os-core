<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PATTERN_NAME = 'queue_or_backlog_threshold';

    private const OLD_REGEX = '/\b(queueLow|queue_low|queue-low|backlog|threshold|soft_cap|min_backlog|pending_min)\b/i';

    private const NEW_REGEX = '/\b(queueLow|queue_low|queue-low|(?:queue_)?backlog_(?:threshold|limit|cap|min|max|low|high)|(?:min|max)_backlog|threshold|soft_cap|pending_min)\b/i';

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
            $policy['classification_policy']['queue_or_backlog_threshold'] =
                'Matches explicit queue-low, threshold, backlog cap/limit/min/max, soft-cap, or pending-min policy identifiers; plain backlog metrics are observability data, not policy.';
        } elseif (is_array($policy['classification_policy'] ?? null)) {
            unset($policy['classification_policy']['queue_or_backlog_threshold']);
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
