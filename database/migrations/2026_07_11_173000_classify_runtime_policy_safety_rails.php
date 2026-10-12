<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LINE_TABLE_MARKERS = [
        '->max_parallel',
        '->timeout_minutes',
    ];

    private const SAFETY_RAIL_REGEXES = [
        '/\b(?:capped at|hard maximum|safety (?:cap|rail)|absolute (?:cap|maximum))\b/i',
        '/\bmax\(\s*\d[\d_]*\s*,\s*min\(\s*\d[\d_]*\s*,/i',
        '/\bmin\(\s*\d[\d_]*\s*,\s*max\(\s*\d[\d_]*\s*,/i',
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

        $lineMarkers = $this->stringList($policy['classifier']['line_table_driven_markers'] ?? []);
        $safetyRegexes = $this->stringList($policy['classifier']['line_safety_rail_regexes'] ?? []);

        if ($add) {
            $lineMarkers = $this->appendUnique($lineMarkers, self::LINE_TABLE_MARKERS);
            $safetyRegexes = $this->appendUnique($safetyRegexes, self::SAFETY_RAIL_REGEXES);
            $policy['classification_policy']['intentional_safety_rail'] =
                'Explicit bounded clamp or documented hard maximum retained as a safety rail, not runtime throughput policy.';
        } else {
            $lineMarkers = array_values(array_diff($lineMarkers, self::LINE_TABLE_MARKERS));
            $safetyRegexes = array_values(array_diff($safetyRegexes, self::SAFETY_RAIL_REGEXES));
            if (is_array($policy['classification_policy'] ?? null)) {
                unset($policy['classification_policy']['intentional_safety_rail']);
            }
        }

        $policy['classifier']['line_table_driven_markers'] = $lineMarkers;
        $policy['classifier']['line_safety_rail_regexes'] = $safetyRegexes;

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

    /**
     * @param  list<string>  $current
     * @param  list<string>  $additions
     * @return list<string>
     */
    private function appendUnique(array $current, array $additions): array
    {
        return array_values(array_unique(array_merge($current, $additions)));
    }
};
