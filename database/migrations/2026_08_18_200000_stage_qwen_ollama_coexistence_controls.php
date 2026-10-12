<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const QWEN = 'qwen38_87';

    private const OLLAMA = 'ollama_secondary';

    private const ADMISSION_GROUP = 'win87_qwen_ollama';

    public function up(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        DB::transaction(function (): void {
            $qwen = DB::table('llm_instances')->where('instance_id', self::QWEN)->lockForUpdate()->first();
            $ollama = DB::table('llm_instances')->where('instance_id', self::OLLAMA)->lockForUpdate()->first();
            if ($qwen === null || $ollama === null) {
                throw new RuntimeException('Qwen/Ollama coexistence controls require both exact .87 provider rows.');
            }

            $qwenConfig = $this->map($qwen->config ?? null);
            $qwenConfig['slot_policy'] = [
                ...$this->map($qwenConfig['slot_policy'] ?? null),
                'admission_group' => self::ADMISSION_GROUP,
                'request_ttl_seconds' => 1200,
                'slot_cache_ttl_seconds' => 2400,
                'lock_ttl_seconds' => 5,
            ];
            $qwenConfig['coexistence'] = [
                'phase' => 'K3-COEX-01-staged',
                'host' => 'win87',
                'mode' => 'serialized',
                'ollama_route_block_required' => true,
                'public_write' => false,
            ];

            $ollamaConfig = $this->map($ollama->config ?? null);
            $ollamaConfig['slot_policy'] = [
                ...$this->map($ollamaConfig['slot_policy'] ?? null),
                'admission_group' => self::ADMISSION_GROUP,
                'request_ttl_seconds' => 1200,
                'slot_cache_ttl_seconds' => 2400,
                'lock_ttl_seconds' => 5,
            ];
            $ollamaConfig['route_policy'] = [
                ...$this->map($ollamaConfig['route_policy'] ?? null),
                'operator_route_block' => true,
                'operator_route_block_phase' => 'K3-COEX-01',
            ];
            $ollamaConfig['coexistence'] = [
                'phase' => 'K3-COEX-01-staged',
                'host' => 'win87',
                'mode' => 'serialized',
                'allowed_models' => ['qwen3:8b', 'qwen3:4b'],
                'gpu_only_required' => true,
                'cpu_spill_max_bytes' => 0,
                'model_size_max_bytes' => 6442450944,
                'normal_routing' => false,
                'tools_enabled' => false,
                'fallback_enabled' => false,
            ];

            DB::table('llm_instances')->where('instance_id', self::QWEN)->update($this->filterColumns([
                'config' => json_encode($qwenConfig, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => $this->appendNote($qwen->notes ?? null, 'K3-COEX-01: shared .87 admission group staged; durable Qwen route otherwise unchanged.'),
                'updated_at' => now(),
            ]));

            $now = now();
            DB::table('llm_instances')->where('instance_id', self::OLLAMA)->update($this->filterColumns([
                'is_active' => 0,
                'is_healthy' => 0,
                'health_score' => 0,
                'routability' => 'blocked',
                'circuit_state' => 'open',
                'circuit_opened_at' => $now,
                'circuit_retry_at' => null,
                'quarantine_status' => 'operator_coexistence_blocked',
                'quarantined_at' => $now,
                'quarantine_reason' => 'K3-COEX operator hold: service canary does not authorize PLOS routing.',
                'quarantine_source' => 'K3-COEX-01',
                'config' => json_encode($ollamaConfig, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => $this->appendNote($ollama->notes ?? null, 'K3-COEX-01: operator route hold plus shared .87 admission group; no Ollama service start.'),
                'updated_at' => $now,
            ]));
        });

        $this->clearCaches();
    }

    public function down(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $now = now();
        DB::table('llm_instances')->where('instance_id', self::OLLAMA)->update($this->filterColumns([
            'is_active' => 0,
            'is_healthy' => 0,
            'health_score' => 0,
            'routability' => 'blocked',
            'circuit_state' => 'open',
            'circuit_opened_at' => $now,
            'circuit_retry_at' => null,
            'quarantine_status' => 'operator_rollback_blocked',
            'quarantined_at' => $now,
            'quarantine_reason' => 'Rollback of coexistence controls fails closed; separate operator action is required to restore Ollama routing.',
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
