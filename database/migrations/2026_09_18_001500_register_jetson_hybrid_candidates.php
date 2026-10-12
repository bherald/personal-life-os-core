<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('llm_instances') || ! Schema::hasTable('ollama_models') || ! Schema::hasTable('system_configs')) {
            return;
        }

        DB::transaction(function (): void {
            $instance = DB::table('llm_instances')->where('instance_id', 'ollama_jetson')->lockForUpdate()->first();
            if ($instance === null) {
                return;
            }
            $config = json_decode((string) $instance->config, true, flags: JSON_THROW_ON_ERROR);
            $row = DB::table('system_configs')->where('section', 'llm')
                ->where('config_key', 'model_hardware_evidence_policy')->lockForUpdate()->first();
            $policy = json_decode((string) ($row->config_value ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
            if (($config['model_admission']['enforce'] ?? null) !== true
                || ($config['model_admission']['hardware_class'] ?? null) !== 'jetson_orin_8gb'
                || ! is_array($policy['hardware_classes']['jetson_orin_8gb']['models'] ?? null)) {
                throw new RuntimeException('Jetson candidate inventory requires its existing hardware admission policy.');
            }

            $candidates = [
                'qwen3.5:2b' => [
                    'display' => 'Qwen3.5 2B Q8_0', 'bytes' => 2741192820, 'parameters_b' => 2.3,
                    'digest' => '324d162be6ca5629ae4517c8710434d0bd2d665bc94dbad46e9af8fbf8a2f0df',
                    'resident_bytes' => 2159489842,
                    'evidence' => 'Short text checks passed. Registered task probes passed 0/3 on 0.30.8 chat; 0.34.1 corrected schemas but still passed 0/3 factual checks. No role promotion.',
                ],
                'qwen3.5:4b' => [
                    'display' => 'Qwen3.5 4B Q4_K_M', 'bytes' => 3389983735, 'parameters_b' => 4.7,
                    'digest' => '2a654d98e6fba55d452b7043684e9b57a947e393bbffa62485a7aac05ee4eefd',
                    'resident_bytes' => 2899323125,
                    'evidence' => '0.30.8 generation with PLOS factual settings passed 1/3 task fixtures at 2048 context and 256 output tokens; embeddings remained valid. Four short manual offline checks and embeddings passed on home/alternate LAN addresses with daemon WAN/DNS egress denied. Full context is unqualified. Separate 0.34.1 partial-offload trial timed out. No role promotion.',
                ],
            ];
            $supported = json_decode((string) $instance->supported_models, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($supported) || ! array_is_list($supported)) {
                throw new RuntimeException('Jetson supported-model inventory is not a list.');
            }
            foreach ($candidates as $name => $candidate) {
                if (! isset($policy['hardware_classes']['jetson_orin_8gb']['models'][$name])) {
                    $policy['hardware_classes']['jetson_orin_8gb']['models'][$name] = [
                        'hardware_fit' => 'feasible', 'deployment_state' => 'candidate',
                        'artifact_size_gb' => $candidate['bytes'] / 1_000_000_000,
                        'parameters_b' => $candidate['parameters_b'], 'context_length' => 2048,
                        'roles' => ['standard'], 'artifact_digest' => 'sha256:'.$candidate['digest'],
                        'observed_resident_bytes' => $candidate['resident_bytes'],
                        'observed_runtime' => '0.30.8', 'full_context_qualified' => false,
                        'source_url' => 'https://ollama.com/library/'.$name, 'license' => 'Apache-2.0',
                        'reasons' => [$candidate['evidence']],
                        'required_gates' => ['exact task and transport qualification', 'full-context and offline LAN checks', 'production canary and explicit role migration'],
                    ];
                }
                if (! DB::table('ollama_models')->where('instance_id', $instance->id)->where('model_name', $name)->exists()) {
                    DB::table('ollama_models')->insert([
                        'instance_id' => $instance->id, 'model_name' => $name, 'display_name' => $candidate['display'],
                        'profile' => 'hybrid_candidate', 'status' => 'testing', 'is_available' => true,
                        'capabilities' => json_encode(['completion'], JSON_THROW_ON_ERROR),
                        'use_cases' => json_encode(['hybrid_qualification', 'local_private'], JSON_THROW_ON_ERROR),
                        'description' => 'Installed candidate only; existing Jetson routing and embedding contract remain unchanged.',
                        'size_gb' => round($candidate['bytes'] / (1024 ** 3), 2), 'context_length' => 2048,
                        'vram_required_mb' => (int) ceil($candidate['resident_bytes'] / (1024 ** 2)),
                        'vetting_notes' => $candidate['evidence'].' Artifact sha256:'.$candidate['digest'],
                        'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                if (! in_array($name, $supported, true)) {
                    $supported[] = $name;
                }
            }
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
            DB::table('llm_instances')->where('id', $instance->id)->update([
                'supported_models' => json_encode($supported, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
        });
        foreach (['llm_model_hardware_evidence_policy', 'llm_instances_all', 'llm_instances_healthy'] as $key) {
            Cache::forget($key);
        }
    }

    public function down(): void
    {
        // Retain evidence for installed artifacts and any later operator decisions.
        // This registration never changes runtime, model-role routing or admission.
    }
};
