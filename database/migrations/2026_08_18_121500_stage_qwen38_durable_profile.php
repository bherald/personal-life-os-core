<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSTANCE_ID = 'qwen38_87';

    private const MODEL = 'qwen3.8-27b-q4_k_m';

    private const PROFILE = 'deep_offline_qwen38';

    private const STAGE_MARKER = 'K3-ALT-06-staged';

    public function up(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }
        $instanceId = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->value('id');
        if ($instanceId === null) {
            throw new RuntimeException('The staged Qwen3.8 provider must exist before ALT-06 profile staging.');
        }

        $now = now();
        $notes = 'Inactive K3-ALT-06 Qwen3.8 durable-route profile. No route until guarded lifecycle, ingress, promotion, smoke, and rollback gates pass.';
        if (Schema::hasTable('llm_model_profiles')) {
            if (DB::table('llm_model_profiles')->where('profile_name', self::PROFILE)->exists()) {
                throw new RuntimeException('Refusing to overwrite an existing Qwen3.8 durable profile.');
            }
            DB::table('llm_model_profiles')->insert($this->filterColumns('llm_model_profiles', [
                'profile_name' => self::PROFILE,
                'model_name' => self::MODEL,
                'description' => 'Guarded local Qwen3.8-27B through the CPU-only .87 sidecar',
                'use_cases' => json_encode(['deep_offline_chat', 'patient_local_reasoning'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'enabled' => 0,
                'status' => 'staged',
                'quality_rating' => 86,
                'capabilities' => json_encode(['text_input', 'text_output', 'streaming', 'structured_output', 'local_candidate'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'requires_override' => 1,
                'metadata' => json_encode($this->stagedMetadata(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'notes' => $notes,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
        if (Schema::hasTable('ollama_models')) {
            $key = ['instance_id' => $instanceId, 'model_name' => self::MODEL];
            if (DB::table('ollama_models')->where($key)->exists()) {
                throw new RuntimeException('Refusing to overwrite an existing Qwen3.8 registry row.');
            }
            DB::table('ollama_models')->insert($this->filterColumns('ollama_models', [
                ...$key,
                'display_name' => 'Qwen3.8-27B Q4_K_M CPU',
                'profile' => self::PROFILE,
                'status' => 'testing',
                'is_available' => 0,
                'capabilities' => json_encode(['text_input', 'text_output', 'streaming', 'structured_output', 'local_candidate'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'use_cases' => json_encode(['deep_offline_chat', 'patient_local_reasoning'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'description' => $notes,
                'size_gb' => 17.67,
                'context_length' => 4096,
                'vram_required_mb' => 0,
                'quality_rating' => 86,
                'vetting_notes' => self::STAGE_MARKER,
                'vetted_by' => 'k3-alt-05-green-awaiting-alt-06',
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
        $this->clearCaches();
    }

    public function down(): void
    {
        if (Schema::hasTable('llm_model_profiles')) {
            $profile = DB::table('llm_model_profiles')->where('profile_name', self::PROFILE)->first(['metadata']);
            if ($profile !== null) {
                $metadata = json_decode((string) $profile->metadata, true, 512, JSON_THROW_ON_ERROR);
                if (($metadata['stage_marker'] ?? null) !== self::STAGE_MARKER) {
                    throw new RuntimeException('Refusing to delete a drifted Qwen3.8 durable profile.');
                }
                DB::table('llm_model_profiles')->where('profile_name', self::PROFILE)->delete();
            }
        }
        if (Schema::hasTable('ollama_models') && Schema::hasTable('llm_instances')) {
            $instanceId = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->value('id');
            if ($instanceId !== null) {
                $query = DB::table('ollama_models')->where('instance_id', $instanceId)->where('model_name', self::MODEL);
                $row = $query->first(['vetting_notes']);
                if ($row !== null && (string) $row->vetting_notes !== self::STAGE_MARKER) {
                    throw new RuntimeException('Refusing to delete a drifted Qwen3.8 registry row.');
                }
                $query->delete();
            }
        }
        $this->clearCaches();
    }

    /** @return array<string,mixed> */
    private function stagedMetadata(): array
    {
        return [
            'instance_id' => self::INSTANCE_ID,
            'required_provider' => true,
            'category' => 'Deep Offline',
            'route' => 'required_local_provider_no_fallback',
            'tools_enabled' => false,
            'fallback_enabled' => false,
            'runtime' => ['context_length' => 4096, 'max_tokens' => 128, 'backend' => 'cpu_only'],
            'ui' => ['sort_order' => 26],
            'admission_state' => 'staged',
            'stage_marker' => self::STAGE_MARKER,
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function filterColumns(string $table, array $payload): array
    {
        return array_filter($payload, static fn (string $column): bool => Schema::hasColumn($table, $column), ARRAY_FILTER_USE_KEY);
    }

    private function clearCaches(): void
    {
        foreach (['llm_instances_all', 'llm_instances_healthy', 'external_api_providers', 'llm_model_profiles', 'ollama_available_models', 'ollama_vetted_models'] as $key) {
            Cache::forget($key);
        }
    }
};
