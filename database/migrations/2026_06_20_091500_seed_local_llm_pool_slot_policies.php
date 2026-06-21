<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NOTE = '2026-06-20 LOCAL-LLM-SLOTS: Local/Ollama pool lock runtime and slot settings are table-owned in llm_instances.config.runtime_policy and config.slot_policy; max_concurrent remains the slot count source.';

    public function up(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $rows = DB::table('llm_instances')
            ->whereIn('instance_type', ['ollama', 'local_llm'])
            ->get(['instance_id', 'config', 'notes']);

        foreach ($rows as $row) {
            $config = $this->decodeConfig($row->config ?? null);

            $existingRuntimePolicy = is_array($config['runtime_policy'] ?? null)
                ? $config['runtime_policy']
                : [];
            $existingSlotPolicy = is_array($config['slot_policy'] ?? null)
                ? $config['slot_policy']
                : [];

            $config['runtime_policy'] = array_replace($this->runtimePolicy(), $existingRuntimePolicy);
            $config['slot_policy'] = array_replace($this->slotPolicy(), $existingSlotPolicy);

            DB::table('llm_instances')
                ->where('instance_id', $row->instance_id)
                ->update([
                    'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                    'notes' => $this->appendNoteOnce((string) ($row->notes ?? ''), self::NOTE),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $rows = DB::table('llm_instances')
            ->whereIn('instance_type', ['ollama', 'local_llm'])
            ->get(['instance_id', 'config', 'notes']);

        foreach ($rows as $row) {
            $config = $this->decodeConfig($row->config ?? null);

            if (($config['runtime_policy']['policy_source'] ?? null) === 'llm_instances.config.runtime_policy') {
                unset($config['runtime_policy']);
            }

            if (($config['slot_policy']['policy_source'] ?? null) === 'llm_instances.config.slot_policy') {
                unset($config['slot_policy']);
            }

            DB::table('llm_instances')
                ->where('instance_id', $row->instance_id)
                ->update([
                    'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                    'notes' => trim(str_replace(self::NOTE, '', (string) ($row->notes ?? ''))),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimePolicy(): array
    {
        return [
            'policy_source' => 'llm_instances.config.runtime_policy',
            'slot_policy_source' => 'llm_instances.config.slot_policy',
            'route_policy_source' => 'llm_instances.config.route_policy',
            'model_policy_source' => 'llm_instances.config.models',
            'code_defaults_allowed' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function slotPolicy(): array
    {
        return [
            'policy_source' => 'llm_instances.config.slot_policy',
            'source' => 'llm_instances.max_concurrent',
            'request_ttl_seconds' => 150,
            'slot_cache_ttl_seconds' => 300,
            'lock_ttl_seconds' => 5,
            'busy_lock_key_prefix' => 'llm_busy_lock',
            'slot_cache_key_prefix' => 'llm_slots',
            'slot_lock_key_suffix' => '_lock',
            'capacity_error_type' => 'capacity',
            'health_failure_on_capacity_full' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeConfig(mixed $value): array
    {
        $decoded = json_decode((string) ($value ?? '{}'), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function appendNoteOnce(string $notes, string $note): string
    {
        if (str_contains($notes, $note)) {
            return $notes;
        }

        return trim($notes."\n".$note);
    }
};
