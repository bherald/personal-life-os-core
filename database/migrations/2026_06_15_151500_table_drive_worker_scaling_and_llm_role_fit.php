<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('system_configs')) {
            $this->upsertSystemConfig(
                'scheduler',
                'worker_scaling_policy',
                [
                    'cpu_cores_per_worker' => 2.0,
                    'io_target_workers' => 3,
                    'io_low_memory_gb' => 8.0,
                    'io_low_memory_workers' => 2,
                    'gpu_single_worker_util_pct' => 70,
                    'gpu_limited_worker_util_pct' => 40,
                    'gpu_limited_workers' => 2,
                    'load_ratio_single_worker_threshold' => 0.80,
                    'min_memory_gb_single_worker' => 4.0,
                    'large_backlog_threshold' => 10000,
                    'large_backlog_bonus_workers' => 1,
                    'total_running_core_ratio_limit' => 1.0,
                    'total_running_core_ratio_conservative' => 0.60,
                    'total_running_conservative_workers' => 2,
                    'off_hours_start_hour' => 21,
                    'off_hours_end_hour' => 6,
                    'off_hours_bonus_workers' => 1,
                    'genealogy_parallel_min_backlog' => 2,
                    'default_parallel_min_backlog' => 100,
                    'provider_capacity_error_default' => 2,
                ],
                'Table-owned scheduler worker scaling thresholds. Runtime policy lives here; code fallbacks are bootstrap-only.'
            );

            $this->upsertSystemConfig(
                'llm',
                'role_fit_policy',
                [
                    'version' => 1,
                    'roles' => [
                        'quality' => ['direction' => 'larger', 'min_params_b' => 2, 'scale' => 1.5, 'max_bonus' => 20],
                        'coding' => ['direction' => 'larger', 'min_params_b' => 2, 'scale' => 1.5, 'max_bonus' => 20],
                        'standard' => ['direction' => 'larger', 'min_params_b' => 2, 'scale' => 1.5, 'max_bonus' => 20],
                        'vision' => ['direction' => 'larger', 'min_params_b' => 2, 'scale' => 1.5, 'max_bonus' => 20],
                        'uncensored' => ['direction' => 'larger', 'min_params_b' => 2, 'scale' => 1.5, 'max_bonus' => 20],
                        'fast' => ['direction' => 'smaller', 'target_params_b' => 2, 'base_bonus' => 20, 'scale' => 2.0, 'max_bonus' => 20],
                        'embedding' => ['direction' => 'smaller', 'target_params_b' => 2, 'base_bonus' => 20, 'scale' => 2.0, 'max_bonus' => 20],
                    ],
                    'default' => ['bonus' => 10, 'max_bonus' => 10],
                    'notes' => 'Model role fitness is model-level. Identical model tags receive identical role-fit credit across hosts; host ordering comes from llm_instances telemetry.',
                ],
                'Table-owned LLM role-fit scoring policy for model-level capability priors.'
            );

            $this->upsertSystemConfig(
                'llm',
                'model_hardware_evidence_policy',
                [
                    'version' => 1,
                    'evidence_posture' => 'industry_prior_plus_local_telemetry',
                    'sources' => [
                        [
                            'name' => 'Jetson AI Lab supported models',
                            'url' => 'https://www.jetson-ai-lab.com/models/',
                            'use' => 'Initial Jetson model-fit prior for Orin/Thor device classes.',
                        ],
                        [
                            'name' => 'jetson-containers Ollama package',
                            'url' => 'https://github.com/dusty-nv/jetson-containers/tree/master/packages/llm/ollama',
                            'use' => 'Runtime/package evidence that Ollama is a supported Jetson LLM path.',
                        ],
                        [
                            'name' => 'BitNet b1.58 2B4T technical report',
                            'url' => 'https://arxiv.org/abs/2504.12285',
                            'use' => 'CPU/edge prior for keeping BitNet in narrow low-bit privacy-prefilter duty unless PLOS eval promotes it.',
                        ],
                    ],
                    'hardware_classes' => [
                        'jetson_orin_8gb' => [
                            'initial_roles' => [
                                'fast' => ['qwen3:4b'],
                                'standard' => ['qwen3:4b'],
                                'quality_candidate' => ['qwen3:4b'],
                                'coding' => ['qwen3:4b'],
                                'embedding' => ['embeddinggemma:latest'],
                            ],
                            'promotion_rule' => 'Industry prior may seed eligibility; llm_model_request_logs and eval results rank, demote, or quarantine per host.',
                        ],
                        'bitnet_cpu_low_bit' => [
                            'initial_roles' => [
                                'privacy_deny_prefilter' => ['bitnet-b1.58-2b-4t'],
                            ],
                            'promotion_rule' => 'Keep narrow unless redacted-input eval and latency telemetry justify additional roles.',
                        ],
                    ],
                ],
                'Evidence-backed model/hardware prior used to guide table-owned LLM promotion decisions.'
            );
        }

        if (Schema::hasTable('llm_instances')) {
            foreach (['ollama_jetson', 'ollama_primary'] as $instanceId) {
                $this->updateInstanceConfig($instanceId, function (array $config): array {
                    $config['models'] = is_array($config['models'] ?? null) ? $config['models'] : [];
                    $config['models']['standard'] = $config['models']['standard'] ?? 'qwen3:4b';
                    $config['models']['quality'] = $config['models']['quality'] ?? 'qwen3:4b';

                    $config['route_policy'] = is_array($config['route_policy'] ?? null) ? $config['route_policy'] : [];
                    $config['route_policy']['require_model_role'] = true;
                    $config['route_policy']['allow_generic_fallback'] = false;
                    $config['route_policy']['allowed_model_roles'] = array_values(array_unique(array_merge(
                        is_array($config['route_policy']['allowed_model_roles'] ?? null)
                            ? $config['route_policy']['allowed_model_roles']
                            : [],
                        ['fast', 'embedding', 'health', 'coding', 'standard', 'quality']
                    )));

                    $config['role_runtime'] = is_array($config['role_runtime'] ?? null) ? $config['role_runtime'] : [];
                    foreach (['fast', 'standard', 'quality', 'coding'] as $role) {
                        $config['role_runtime'][$role] = array_merge([
                            'think' => false,
                            'prompt_prefix' => '/no_think',
                            'options' => [
                                'num_ctx' => 4096,
                            ],
                        ], is_array($config['role_runtime'][$role] ?? null) ? $config['role_runtime'][$role] : []);
                    }

                    return $config;
                }, '2026-06-15 TABLE-DRIVEN-RUNTIME: Standard/quality eligibility restored from model-level evidence; host ordering remains telemetry-driven and Qwen3 no-think runtime options are table-owned.');
            }
        }

        $this->clearCaches();
    }

    public function down(): void
    {
        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')
                ->where('section', 'scheduler')
                ->where('config_key', 'worker_scaling_policy')
                ->delete();

            DB::table('system_configs')
                ->where('section', 'llm')
                ->whereIn('config_key', ['role_fit_policy', 'model_hardware_evidence_policy'])
                ->delete();
        }

        $this->clearCaches();
    }

    /**
     * @param  array<string,mixed>  $value
     */
    private function upsertSystemConfig(string $section, string $key, array $value, string $description): void
    {
        DB::table('system_configs')->updateOrInsert(
            ['section' => $section, 'config_key' => $key],
            [
                'config_value' => json_encode($value, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => $description,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /**
     * @param  callable(array<string,mixed>): array<string,mixed>  $mutator
     */
    private function updateInstanceConfig(string $instanceId, callable $mutator, string $note): void
    {
        $row = DB::table('llm_instances')
            ->where('instance_id', $instanceId)
            ->first(['config', 'notes']);

        if (! $row) {
            return;
        }

        $config = json_decode((string) ($row->config ?? '{}'), true);
        if (! is_array($config)) {
            $config = [];
        }

        DB::table('llm_instances')
            ->where('instance_id', $instanceId)
            ->update([
                'config' => json_encode($mutator($config), JSON_UNESCAPED_SLASHES),
                'notes' => $this->appendNoteOnce((string) ($row->notes ?? ''), $note),
                'updated_at' => now(),
            ]);
    }

    private function appendNoteOnce(string $notes, string $note): string
    {
        if (str_contains($notes, $note)) {
            return $notes;
        }

        return trim($notes) === '' ? $note : rtrim($notes)."\n".$note;
    }

    private function clearCaches(): void
    {
        foreach ([
            'scheduler_resource_profiles',
            'scheduler_worker_scaling_policy',
            'llm_role_fit_policy',
            'llm_instances_all',
            'llm_instances_healthy',
            'ollama_available_models',
            'ollama_vetted_models',
        ] as $key) {
            Cache::forget($key);
        }
    }
};
