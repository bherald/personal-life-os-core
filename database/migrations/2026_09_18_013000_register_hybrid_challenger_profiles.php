<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('llm_model_profiles')) {
            return;
        }
        $profiles = [
            'hybrid_native_qwen3_coder_30b' => [
                'model' => 'qwen3-coder:30b',
                'description' => 'Measured native llama.cpp challenger; no production admission.',
                'metadata' => [
                    'engine' => 'llama.cpp', 'runtime_version' => 'b10964-cuda12.4',
                    'runtime_sha256' => 'aa2e1f5c67be55f11be26ae58d643a545ca07c6de498f6f870330ac2f4dfec73',
                    'artifact_sha256' => '1194192cf2a187eb02722edcc3f77b11d21f537048ce04b67ccf8ba78863006a',
                    'artifact_bytes' => 18556688736,
                    'probe_settings' => ['context_tokens' => 2048, 'max_output_tokens' => 256, 'parallel_requests' => 1, 'cpu_moe' => true, 'gpu_layers' => 99, 'fit' => false, 'temperature' => 0.1],
                    'observations' => ['load_seconds' => 11.2485074, 'short_task_seconds' => [10.0527093, 5.081899, 5.8267154],
                        'decode_tokens_per_second' => [22.6078426793, 25.6268461445, 26.059778943],
                        'minimum_observed_available_ram_bytes' => 24007905280, 'task_fixture_passes' => 1, 'task_fixture_count' => 3],
                    'limitations' => ['Short prompts only; not full-context qualification.', 'Genealogy and injection checks failed; no automated task promotion.', 'Raw loopback timing excludes PLOS/controller overhead.'],
                    'next_gates' => ['role-specific quality', 'full-context capacity', 'controller target and request binding', 'production canary'],
                    'source_url' => 'https://github.com/ggml-org/llama.cpp/releases/tag/b10964',
                ],
            ],
            'hybrid_qwen38_flash_next' => [
                'model' => 'Qwen3.8-Flash-Next',
                'description' => 'Active RAM and placement evaluation; runtime and artifact remain unqualified.',
                'metadata' => [
                    'engine_candidates' => ['ik_llama.cpp', 'colibri'],
                    'capacity_scenario' => ['host_expert_gib' => 50.39, 'post_load_reserve_gib' => 8, 'minimum_available_gib_estimate' => 58.39,
                        'kind' => 'published IK IQ3 expert placement estimate; not a measured admission threshold'],
                    'observations' => ['visible_ram_bytes' => 67847610368, 'available_ram_bytes' => 39889846272,
                        'active_dev_vm_assigned_bytes' => 12079595520, 'archive_drive_free_bytes' => 496761831424],
                    'limitations' => ['Dynamic availability is required; snapshot shortage does not retire the model.', 'Reclaiming the active DEV VM is insufficient for this scenario and may disrupt work.', 'Storage availability does not establish RAM or throughput fit.'],
                    'next_gates' => ['exact artifact and placement selection', 'resource allocation or lower-memory placement', 'measured full-context admission'],
                    'source_url' => 'https://huggingface.co/ji-farthing/Qwen3.8-Flash-Next-ik-llama-GGUF/blob/main/README.md',
                ],
            ],
            'hybrid_freetoken_qwen36_35b' => [
                'model' => 'nvidia/Qwen3.6-35B-A3B-NVFP4',
                'description' => 'Native Windows FreeToken CLI and CUDA verified; model inference remains unqualified.',
                'metadata' => [
                    'engine' => 'FreeToken', 'runtime_version' => '0.1.3+g52322e984', 'platform' => 'win_amd64', 'python_abi' => 'cp312', 'cuda' => 'cu130',
                    'platform_checks' => ['dependency_check' => 'passed', 'cli_help' => 'passed', 'serve_help' => 'passed', 'torch' => '2.11.0+cu130', 'cuda_available' => true, 'gpu_capability' => [8, 9]],
                    'artifact_revision' => '1355db6a052410cfd62085d94b58866fd0f2c3c5', 'artifact_total_bytes' => 23462477857,
                    'runtime_wheel_sha256' => '3967ad1c9fcf4660251e7f6f2bd24bd02dbc8aee04a4140e6a8dd395c9f428dd',
                    'kernel_cache_wheel_sha256' => '115bad034b6d1e8f21355d112fb537331b99de47c25f6329268928ae6b240d3c',
                    'limitations' => ['Beta Windows wheel comes from official desktop release channel; Linux-only install guide is incomplete for this channel.', 'Existing Qwen3-Coder GGUF cannot be reused by the current gemma4-only GGUF loader.', 'No model throughput result or Jetson ARM support is established.'],
                    'next_gates' => ['verified compatible artifact', 'bounded model and lifecycle qualification', 'exact PLOS controller/profile integration'],
                    'source_url' => 'https://github.com/FlashML-org/FreeToken-Web/releases/tag/beta',
                ],
            ],
            'hybrid_colibri_qwen36_35b' => [
                'model' => 'Kreuzzelg/qwen36-35b-a3b-colibri-i4-gs64',
                'description' => 'Smaller Colibri model path retained for a justified comparison.',
                'metadata' => [
                    'engine' => 'colibri', 'source_commit' => 'a90bed95ca14c82e24c5ef98eaac1e509da7064a',
                    'published_artifact_gb_estimate' => 20, 'published_comfortable_ram_gb_estimate' => 30,
                    'limitations' => ['Published sizing is not local measurement.', 'Qwen3.6 does not honor the GLM RAM budget flag; CUDA expert tier keeps experts in host RAM.', 'Do not repeat prolonged GLM/K3 runs without a specific hypothesis.'],
                    'next_gates' => ['exact converted artifact and runtime identity', 'local capacity and useful-task comparison'],
                    'source_url' => 'https://github.com/JustVugg/colibri/blob/a90bed95ca14c82e24c5ef98eaac1e509da7064a/docs/qwen36.md',
                ],
            ],
        ];
        DB::transaction(function () use ($profiles): void {
            foreach ($profiles as $name => $profile) {
                if (DB::table('llm_model_profiles')->where('profile_name', $name)->exists()) {
                    continue;
                }
                DB::table('llm_model_profiles')->insert([
                    'profile_name' => $name, 'model_name' => $profile['model'], 'description' => $profile['description'],
                    'enabled' => 0, 'status' => 'staged', 'requires_override' => 1,
                    'use_cases' => json_encode(['hybrid_qualification'], JSON_THROW_ON_ERROR),
                    'capabilities' => json_encode(['local_candidate'], JSON_THROW_ON_ERROR),
                    'metadata' => json_encode(['schema' => 'plos.hybrid_candidate_evidence.v1', 'host_id' => '.87',
                        'qualified' => false, 'full_context_qualified' => false, 'temperature_mode' => 'observation_only',
                        'evidence_date_utc' => '2026-09-18', ...$profile['metadata']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'notes' => 'Evidence inventory only. Does not register an active provider, controller target, resource binding or route. Preserve later operator qualification.',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        Cache::forget('llm_model_profiles');
    }

    public function down(): void
    {
        // Retain additive evidence and any later operator decisions; no route was enabled.
    }
};
