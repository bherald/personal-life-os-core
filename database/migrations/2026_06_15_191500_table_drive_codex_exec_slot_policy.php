<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $this->updateCodexExec(function (array $config): array {
            $config['runtime_policy'] = [
                'timeout_min_seconds' => 1,
                'timeout_max_seconds' => 3600,
                'policy_source' => 'llm_instances.config.runtime_policy',
                'code_defaults_allowed' => false,
            ];

            $config['slot_policy'] = [
                'source' => 'llm_instances.max_concurrent',
                'cache_key' => 'codex_exec_slots',
                'slot_ttl_seconds' => 3900,
                'lock_ttl_seconds' => 10,
                'capacity_error_type' => 'capacity',
                'health_failure_on_capacity_full' => false,
            ];

            $config['route_policy'] = $this->recursiveMerge(
                is_array($config['route_policy'] ?? null) ? $config['route_policy'] : [],
                [
                    'require_model_role' => true,
                    'allow_generic_fallback' => true,
                    'allowed_model_roles' => ['fast', 'standard', 'quality', 'coding'],
                ]
            );

            return $config;
        }, [
            'is_active' => 1,
            'is_healthy' => 1,
            'health_score' => 100,
            'circuit_state' => 'closed',
            'circuit_opened_at' => null,
            'circuit_retry_at' => null,
            'consecutive_failures' => 0,
            'max_concurrent' => 4,
            'rate_limit_rpm' => 12,
        ], '2026-06-15 CODEX-SLOTS: Codex Exec capacity is enforced by connector slots sourced from llm_instances.max_concurrent; timeout and slot bounds are table-owned in config.runtime_policy and config.slot_policy.');
    }

    public function down(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $this->updateCodexExec(fn (array $config): array => $config, [
            'max_concurrent' => 2,
            'rate_limit_rpm' => 12,
        ], '2026-06-15 CODEX-SLOTS rollback: restored Codex Exec table capacity to the prior two-slot setting.');
    }

    /**
     * @param  callable(array<string,mixed>): array<string,mixed>  $mutator
     * @param  array<string,mixed>  $updates
     */
    private function updateCodexExec(callable $mutator, array $updates, string $note): void
    {
        $row = DB::table('llm_instances')
            ->where('instance_id', 'codex_exec')
            ->first(['config', 'notes']);

        if ($row === null) {
            return;
        }

        $config = json_decode((string) ($row->config ?? '{}'), true);
        $config = is_array($config) ? $config : [];
        $config = $mutator($config);

        $updates['config'] = json_encode($config, JSON_UNESCAPED_SLASHES);
        $updates['notes'] = trim((string) ($row->notes ?? '')."\n".$note);
        $updates['updated_at'] = now();

        DB::table('llm_instances')
            ->where('instance_id', 'codex_exec')
            ->update($updates);
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $overlay
     * @return array<string,mixed>
     */
    private function recursiveMerge(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (is_array($value) && is_array($base[$key] ?? null)) {
                $base[$key] = $this->recursiveMerge($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
};
