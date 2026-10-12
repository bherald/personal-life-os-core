<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['llm_instances', 'ollama_models', 'system_configs', 'llm_model_profiles'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Jetson text candidate registration requires the existing inventory tables.');
            }
        }
        DB::transaction(function (): void {
            $instance = DB::table('llm_instances')->where('instance_id', 'ollama_jetson')->lockForUpdate()->first();
            if ($instance === null) {
                return;
            }
            $config = json_decode((string) $instance->config, true, flags: JSON_THROW_ON_ERROR);
            $row = DB::table('system_configs')->where('section', 'llm')->where('config_key', 'model_hardware_evidence_policy')->lockForUpdate()->first();
            $policy = json_decode((string) ($row->config_value ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
            if (($config['model_admission']['enforce'] ?? null) !== true
                || ($config['model_admission']['hardware_class'] ?? null) !== 'jetson_orin_8gb'
                || ! is_array($policy['hardware_classes']['jetson_orin_8gb']['models'] ?? null)) {
                throw new RuntimeException('Jetson text candidate requires its existing hardware admission policy.');
            }
            $model = 'plos-qwen35-4b-text-q4km:20260919';
            $profile = 'hybrid_jetson_qwen35_text_q4km';
            $source = 'https://huggingface.co/unsloth/Qwen3.5-4B-GGUF/blob/e87f176479d0855a907a41277aca2f8ee7a09523/Qwen3.5-4B-Q4_K_M.gguf';
            $evidence = [
                'schema' => 'plos.hybrid_candidate_evidence.v1', 'instance_id' => 'ollama_jetson',
                'engine' => 'ollama', 'runtime_version' => '0.30.8', 'quantization' => 'Q4_K_M',
                'runtime_sha256' => 'f2472f3399959ea1ff104e6c714cdbe3ff7925cc104355688f7b7b23b81b8240',
                'artifact_sha256' => '00fe7986ff5f6b463e62455821146049db6f9313603938a70800d1fb69ef11a4',
                'artifact_bytes' => 2740937888,
                'ollama_manifest_digest' => '361823c09f0cc2125d6844672dc2c1063b4f95b80c54db04c2c09fcf7884f077',
                'source_revision' => 'e87f176479d0855a907a41277aca2f8ee7a09523', 'source_url' => $source,
                'license' => 'Apache-2.0', 'evidence_date_utc' => '2026-09-19',
                'qualified' => false, 'full_context_qualified' => false, 'temperature_mode' => 'observation_only',
                'observations' => ['import_passed' => true, 'model_requests' => 0, 'tensor_count' => 426,
                    'vision_tensor_count' => 0, 'mtp_tensor_count' => 0, 'service_pid_preserved' => true,
                    'existing_tags_preserved' => true, 'embedding_residency_preserved' => true],
                'advertised_capabilities' => ['completion', 'tools', 'thinking'],
                'qualified_capabilities' => [],
                'limitations' => ['Import is not inference or role qualification.',
                    'The language-only file also omits 15 MTP tensors; it is not byte-identical to the bundled artifact.',
                    'Published MAXN_SUPER results do not predict local 15W throughput by a fixed ratio.',
                    'File-size reduction is not a measured reduction in working RAM.'],
                'next_gates' => ['short exact-role and template check', 'useful-context capacity with embeddings preserved',
                    'production canary and explicit qualification migration before routing'],
            ];
            if (! isset($policy['hardware_classes']['jetson_orin_8gb']['models'][$model])) {
                $policy['hardware_classes']['jetson_orin_8gb']['models'][$model] = [
                    'hardware_fit' => 'feasible', 'deployment_state' => 'candidate', 'roles' => [],
                    'artifact_size_gb' => $evidence['artifact_bytes'] / 1_000_000_000, 'parameters_b' => 4.2,
                    'artifact_digest' => 'sha256:'.$evidence['ollama_manifest_digest'], 'source_url' => $source,
                    'license' => 'Apache-2.0', 'reasons' => ['Text-only GGUF imported on existing Jetson Ollama; inference remains unqualified.'],
                ];
                DB::table('system_configs')->where('id', $row->id)->update([
                    'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            }
            if (! DB::table('ollama_models')->where('instance_id', $instance->id)->where('model_name', $model)->exists()) {
                DB::table('ollama_models')->insert([
                    'instance_id' => $instance->id, 'model_name' => $model, 'display_name' => 'Qwen3.5 4B text-only Q4_K_M',
                    'profile' => 'hybrid_candidate', 'status' => 'testing', 'is_available' => true,
                    'capabilities' => json_encode(['completion'], JSON_THROW_ON_ERROR),
                    'use_cases' => json_encode(['hybrid_qualification', 'local_private'], JSON_THROW_ON_ERROR),
                    'description' => 'Separate imported text candidate; no production role is admitted.',
                    'size_gb' => round($evidence['artifact_bytes'] / (1024 ** 3), 2),
                    'vetting_notes' => json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $supported = json_decode((string) $instance->supported_models, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($supported) || ! array_is_list($supported)) {
                throw new RuntimeException('Jetson supported-model inventory is not a list.');
            }
            if (! in_array($model, $supported, true)) {
                $supported[] = $model;
                DB::table('llm_instances')->where('id', $instance->id)->update([
                    'supported_models' => json_encode($supported, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            }
            if (! DB::table('llm_model_profiles')->where('profile_name', $profile)->exists()) {
                DB::table('llm_model_profiles')->insert([
                    'profile_name' => $profile, 'model_name' => $model, 'description' => 'Imported text-only Jetson candidate; no routing promotion.',
                    'enabled' => 0, 'status' => 'staged', 'requires_override' => 1,
                    'use_cases' => json_encode(['hybrid_qualification'], JSON_THROW_ON_ERROR),
                    'capabilities' => json_encode(['local_candidate'], JSON_THROW_ON_ERROR),
                    'metadata' => json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'notes' => 'Preserve qualified Qwen3.5 and embeddings; register exact measured settings only after qualification.',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            // Correct a specific observation classifier; preserve all qualification and routing fields.
            $existing = DB::table('llm_model_profiles')->where('profile_name', 'hybrid_jetson_offline_manual')->lockForUpdate()->first();
            if ($existing !== null) {
                $metadata = json_decode((string) $existing->metadata, true, flags: JSON_THROW_ON_ERROR);
                $show = $metadata['benchmark_first_followup']['installed_metadata']['show'] ?? [];
                if (($metadata['qualification']['artifact_digest'] ?? null) === 'sha256:2a654d98e6fba55d452b7043684e9b57a947e393bbffa62485a7aac05ee4eefd'
                    && ($show['tensor_count'] ?? null) === 834 && ($show['vision_tensor_count'] ?? null) === 426) {
                    $metadata['benchmark_first_followup']['installed_metadata']['show']['vision_tensor_count'] = 393;
                    $metadata['benchmark_first_followup']['installed_metadata']['show']['mtp_tensor_count'] = 15;
                    $metadata['benchmark_first_followup']['tensor_classification_correction'] =
                        '2026-09-19: exact v. prefix counts 393 vision tensors; substring matching had also counted 33 language/MTP attention tensors. No quality or routing change.';
                    DB::table('llm_model_profiles')->where('id', $existing->id)->update([
                        'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'updated_at' => now(),
                    ]);
                }
            }
        });
        foreach (['llm_model_hardware_evidence_policy', 'llm_instances_all', 'llm_instances_healthy', 'llm_model_profiles'] as $key) {
            Cache::forget($key);
        }
    }

    public function down(): void
    {
        // Retain installed-artifact evidence and later decisions; registration enables no route.
    }
};
