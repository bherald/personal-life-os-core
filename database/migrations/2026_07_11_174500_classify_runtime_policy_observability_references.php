<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OBSERVABILITY_REGEXES = [
        '/^\s*(?:protected\s+\$description\s*=|\$this->(?:info|line|warn|error|comment|newLine)\s*\(|Log::(?:debug|info|notice|warning|error|critical|alert|emergency)\s*\()/i',
        '/^\s*[\'\"](?:message|label|title|description)[\'\"]\s*=>\s*[\'\"]/i',
        '/^\s*sprintf\(\s*[\'\"][^\'\"]*\b(?:timeout|stale|backlog|threshold|limit|retry|cooldown|concurren)/i',
        '/^\s*\[(?:\s*[\'\"][^\'\"]*[\'\"]\s*,?)+\]\s*,?$/',
    ];

    public function up(): void
    {
        $this->updatePolicy(true);
    }

    public function down(): void
    {
        $this->updatePolicy(false);
    }

    private function updatePolicy(bool $add): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->first(['config_value']);

        $policy = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;

        if (! is_array($policy) || ! is_array($policy['classifier'] ?? null)) {
            return;
        }

        $current = $this->stringList($policy['classifier']['line_observability_reference_regexes'] ?? []);
        $policy['classifier']['line_observability_reference_regexes'] = $add
            ? array_values(array_unique(array_merge($current, self::OBSERVABILITY_REGEXES)))
            : array_values(array_diff($current, self::OBSERVABILITY_REGEXES));

        if ($add) {
            $policy['classification_policy']['observability_reference'] =
                'Output-only message, log, description, formatted metric, or literal report header; it reports policy-related terms but does not define runtime policy.';
        } elseif (is_array($policy['classification_policy'] ?? null)) {
            unset($policy['classification_policy']['observability_reference']);
        }

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    /** @return list<string> */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== ''
        ));
    }
};
