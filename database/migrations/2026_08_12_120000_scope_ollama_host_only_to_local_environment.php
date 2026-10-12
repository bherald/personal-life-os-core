<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const HOST_ONLY = 'ollama_host_only';

    private const CORRECTION_NOTE = '2026-08-12 topology correction: the Hyper-V host-only serving URL is reachable from the .87 guest, not production .252. Production is inactive and blocked; local environments probe the real serving URL directly.';

    public function up(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $row = DB::table('llm_instances')->where('instance_id', self::HOST_ONLY)->first();
        if ($row === null) {
            return;
        }

        $config = $this->map($row->config ?? null);
        unset($config['health_base_urls'], $config['health_base_url']);

        $values = [
            'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'notes' => $this->appendNote($row->notes ?? null, self::CORRECTION_NOTE),
            'updated_at' => now(),
        ];

        if (app()->environment('production')) {
            $now = now();
            $values = [
                ...$values,
                'is_active' => 0,
                'routability' => 'blocked',
                'is_healthy' => 0,
                'health_score' => 0,
                'circuit_state' => 'open',
                'circuit_opened_at' => $now,
                'circuit_retry_at' => null,
                'consecutive_failures' => 0,
                'quarantine_status' => 'operator_network_scope_blocked',
                'quarantined_at' => $now,
                'quarantine_reason' => 'Production .252 has no route, VPN, or tether into the .87 Hyper-V host-only network.',
                'quarantine_source' => 'operator_topology_correction',
                'last_health_check' => $now,
            ];
        }

        DB::table('llm_instances')
            ->where('instance_id', self::HOST_ONLY)
            ->update($this->filterColumns($values));

        $this->clearCaches();
    }

    public function down(): void
    {
        if (! Schema::hasTable('llm_instances') || ! app()->environment('production')) {
            return;
        }

        $now = now();
        DB::table('llm_instances')
            ->where('instance_id', self::HOST_ONLY)
            ->update($this->filterColumns([
                'is_active' => 0,
                'routability' => 'blocked',
                'is_healthy' => 0,
                'health_score' => 0,
                'circuit_state' => 'open',
                'circuit_opened_at' => $now,
                'circuit_retry_at' => null,
                'quarantine_status' => 'operator_rollback_blocked',
                'quarantined_at' => $now,
                'quarantine_reason' => 'Rollback does not re-enable a serving URL that production cannot reach.',
                'quarantine_source' => 'migration_rollback',
                'updated_at' => $now,
            ]));

        $this->clearCaches();
    }

    /** @return array<string,mixed> */
    private function map(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private function filterColumns(array $values): array
    {
        return array_filter(
            $values,
            static fn (string $column): bool => Schema::hasColumn('llm_instances', $column),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function appendNote(mixed $existing, string $line): string
    {
        $existing = trim((string) ($existing ?? ''));
        if (str_contains($existing, $line)) {
            return $existing;
        }

        return $existing === '' ? $line : $existing."\n".$line;
    }

    private function clearCaches(): void
    {
        foreach (['llm_instances_all', 'llm_instances_healthy', 'external_api_providers'] as $key) {
            Cache::forget($key);
        }
    }
};
