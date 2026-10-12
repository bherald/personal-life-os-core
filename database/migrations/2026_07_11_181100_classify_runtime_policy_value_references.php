<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VALUE_REFERENCE_REGEXES = [
        '/^\s*[\'\"][a-z0-9_]*(?:limit|timeout|retry|stale|cooldown|threshold|capacity|concurren|batch|max_parallel)[a-z0-9_]*[\'\"]\s*=>\s*(?:\([^)]*\)\s*)?\$[A-Za-z_][A-Za-z0-9_]*(?:\[[^\]]+\])?(?:\s*\?\?\s*(?:null|false|true|-?\d+(?:\.\d+)?|[\'\"][^\'\"]*[\'\"]))?\s*,?\s*$/i',
        '/^\s*[A-Za-z0-9_]*(?:Limit|Timeout|Retries?|Stale|Cooldown|Threshold|Capacity|Concurrency|Batch|MaxParallel)[A-Za-z0-9_]*\s*:\s*\$[A-Za-z_][A-Za-z0-9_]*\s*,?\s*$/i',
        '/^\s*(?:\??(?:int|float|string|array|bool|mixed)\s+)?\$[A-Za-z0-9_]*(?:Limit|Timeout|Retries?|Stale|Cooldown|Threshold|Capacity|Concurrency|Batch|MaxParallel)[A-Za-z0-9_]*\s*,\s*$/i',
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
        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;

        if (! is_array($policy) || ! is_array($policy['classifier'] ?? null)) {
            return;
        }

        $current = $this->stringList($policy['classifier']['line_runtime_value_reference_regexes'] ?? []);
        $policy['classifier']['line_runtime_value_reference_regexes'] = $add
            ? array_values(array_unique(array_merge($current, self::VALUE_REFERENCE_REGEXES)))
            : array_values(array_diff($current, self::VALUE_REFERENCE_REGEXES));

        if ($add) {
            $policy['classification_policy']['runtime_value_reference'] =
                'Downstream payload, named-argument, or typed-parameter use of an already-selected runtime value; inspect the value origin rather than counting every use as a separate policy leak.';
        } elseif (is_array($policy['classification_policy'] ?? null)) {
            unset($policy['classification_policy']['runtime_value_reference']);
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
