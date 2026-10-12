<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSTANCE_ID = 'ollama_jetson';

    private const RETIRED_MODEL = 'qwen3-coder:30b';

    public function up(): void
    {
        if (Schema::hasTable('system_configs')) {
            $this->upsertPolicy($this->evidencePolicy());
        }

        if (Schema::hasTable('llm_instances')) {
            $this->updateJetsonInstance();
            $this->markRetiredModelUnavailable();
        }

        $this->clearCaches();
    }

    public function down(): void
    {
        if (Schema::hasTable('system_configs')) {
            $this->upsertPolicy($this->legacyEvidencePolicy());
        }

        if (Schema::hasTable('llm_instances')) {
            $this->restorePriorJetsonFallback();
        }

        $this->clearCaches();
    }

    /** @return array<string,mixed> */
    private function evidencePolicy(): array
    {
        $candidateGates = [
            'sync exact Ollama artifact metadata',
            'run one bounded PLOS role/tool/schema contract check',
            'run a time-boxed canary with rollback',
        ];

        return [
            'version' => 2,
            'reviewed_at' => '2026-07-10',
            'evidence_posture' => 'published_fit_first_then_minimal_plos_confirmation',
            'decision_contract' => [
                'published_evidence' => 'Official hardware specifications and exact model artifact metadata decide initial feasibility without repeating broad local bakeoffs.',
                'local_confirmation' => 'PLOS testing is limited to runtime reachability plus the exact role/tool/schema contract before promotion.',
                'telemetry' => 'A bounded canary ranks or demotes an admitted host/model pair; telemetry does not make an infeasible artifact feasible.',
                'fail_closed' => 'An enforced host blocks unknown, candidate-only, rejected, retired, or hardware-infeasible artifacts from live routing.',
            ],
            'sources' => [
                [
                    'name' => 'NVIDIA Jetson Orin Nano Developer Kit User Guide',
                    'url' => 'https://docs.nvidia.com/jetson/orin-nano-devkit/user-guide/index.html',
                    'use' => 'Authoritative 8GB unified-memory hardware class.',
                    'reviewed_at' => '2026-07-10',
                ],
                [
                    'name' => 'NVIDIA Jetson Orin Nano Super Developer Kit',
                    'url' => 'https://developer.nvidia.com/blog/?p=93942',
                    'use' => 'Vendor positioning for local generative AI up to the 8B class.',
                    'reviewed_at' => '2026-07-10',
                ],
                [
                    'name' => 'Ollama Qwen3-Coder library',
                    'url' => 'https://registry.ollama.com/library/qwen3-coder',
                    'use' => 'Exact 30B artifact is about 19GB even though only 3.3B parameters are active per token.',
                    'reviewed_at' => '2026-07-10',
                ],
                [
                    'name' => 'Ollama EmbeddingGemma library',
                    'url' => 'https://ollama.com/library/embeddinggemma',
                    'use' => 'Exact 300M embedding artifact size and context prior.',
                    'reviewed_at' => '2026-07-10',
                ],
                [
                    'name' => 'Ollama SmolLM2 library',
                    'url' => 'https://ollama.com/library/smollm2',
                    'use' => 'Exact 135M health-probe artifact size and context prior.',
                    'reviewed_at' => '2026-07-10',
                ],
                [
                    'name' => 'Ollama Qwen3 library',
                    'url' => 'https://ollama.com/library/qwen3',
                    'use' => 'Exact 4B and 8B candidate artifact sizes.',
                    'reviewed_at' => '2026-07-10',
                ],
                [
                    'name' => 'Ollama Llama 3.2 library',
                    'url' => 'https://ollama.com/library/llama3.2',
                    'use' => 'Exact 3B candidate artifact size.',
                    'reviewed_at' => '2026-07-10',
                ],
                [
                    'name' => 'Ollama Gemma 3 library',
                    'url' => 'https://ollama.com/library/gemma3',
                    'use' => 'Exact 4B multimodal candidate artifact size.',
                    'reviewed_at' => '2026-07-10',
                ],
                [
                    'name' => 'BitNet b1.58 2B4T technical report',
                    'url' => 'https://arxiv.org/abs/2504.12285',
                    'use' => 'Preserves the existing low-bit CPU prior for the narrow privacy prefilter lane.',
                    'reviewed_at' => '2026-07-10',
                ],
            ],
            'hardware_classes' => [
                'jetson_orin_8gb' => [
                    'memory_gb' => 8.0,
                    'memory_type' => 'unified',
                    'normal_model_class_max_params_b' => 4.0,
                    'conditional_model_class_max_params_b' => 8.0,
                    'normal_context_max_tokens' => 8192,
                    'conditional_context_max_tokens' => 4096,
                    'models' => [
                        'embeddinggemma:latest' => [
                            'hardware_fit' => 'feasible',
                            'deployment_state' => 'production',
                            'artifact_size_gb' => 0.622,
                            'parameters_b' => 0.3,
                            'context_length' => 2048,
                            'roles' => ['embedding'],
                            'reasons' => ['Official artifact fits comfortably and current PLOS 768-dimensional embedding telemetry is healthy.'],
                            'required_gates' => [],
                        ],
                        'smollm2:135m' => [
                            'hardware_fit' => 'feasible',
                            'deployment_state' => 'production',
                            'artifact_size_gb' => 0.271,
                            'parameters_b' => 0.135,
                            'context_length' => 8192,
                            'roles' => ['health'],
                            'reasons' => ['Official artifact fits comfortably and is restricted to the health probe role.'],
                            'required_gates' => [],
                        ],
                        'qwen3:4b' => [
                            'hardware_fit' => 'feasible',
                            'deployment_state' => 'candidate',
                            'artifact_size_gb' => 2.5,
                            'parameters_b' => 4.0,
                            'roles' => ['fast', 'standard', 'coding'],
                            'reasons' => ['Official artifact is in the normal Jetson class; prior PLOS runs require exact no-think/tool-contract confirmation before promotion.'],
                            'required_gates' => $candidateGates,
                        ],
                        'qwen3:8b' => [
                            'hardware_fit' => 'conditional',
                            'deployment_state' => 'candidate',
                            'artifact_size_gb' => 5.2,
                            'parameters_b' => 8.0,
                            'roles' => ['standard', 'coding'],
                            'reasons' => ['Official artifact is at the vendor-stated upper model class; context and concurrency must remain constrained.'],
                            'required_gates' => $candidateGates,
                        ],
                        'llama3.2:3b' => [
                            'hardware_fit' => 'feasible',
                            'deployment_state' => 'rejected_role_contract',
                            'artifact_size_gb' => 2.0,
                            'parameters_b' => 3.0,
                            'roles' => ['fast', 'standard'],
                            'reasons' => ['Official evidence proves hardware fit, but the 2026-07-02 PLOS contract eval failed 0/9 cases.'],
                            'required_gates' => ['new model version or material prompt/runtime contract change', ...$candidateGates],
                        ],
                        'gemma3:4b' => [
                            'hardware_fit' => 'feasible',
                            'deployment_state' => 'candidate',
                            'artifact_size_gb' => 3.3,
                            'parameters_b' => 4.0,
                            'roles' => ['vision'],
                            'reasons' => ['Official artifact fits the normal class; promote only when the PLOS vision lane has an exact bounded need.'],
                            'required_gates' => $candidateGates,
                        ],
                        self::RETIRED_MODEL => [
                            'hardware_fit' => 'infeasible',
                            'deployment_state' => 'retired',
                            'artifact_size_gb' => 19.0,
                            'parameters_b' => 30.5,
                            'active_parameters_b' => 3.3,
                            'roles' => [],
                            'reasons' => [
                                'The official 19GB artifact exceeds the 8GB unified-memory device before KV cache and runtime overhead.',
                                'Mixture-of-experts active parameters reduce compute per token, not the stored/resident weight requirement.',
                            ],
                            'required_gates' => ['use a host with a published memory envelope that fits the exact artifact'],
                        ],
                    ],
                    'promotion_rule' => 'Published fit creates a candidate; only an exact bounded PLOS role contract plus canary may set deployment_state=production.',
                ],
                'bitnet_cpu_low_bit' => [
                    'initial_roles' => [
                        'privacy_deny_prefilter' => ['bitnet-b1.58-2b-4t'],
                    ],
                    'promotion_rule' => 'Keep narrow unless redacted-input eval and latency telemetry justify additional roles.',
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function legacyEvidencePolicy(): array
    {
        return [
            'version' => 1,
            'evidence_posture' => 'industry_prior_plus_local_telemetry',
            'sources' => [],
            'hardware_classes' => [
                'jetson_orin_8gb' => [
                    'initial_roles' => [
                        'fast' => ['qwen3:4b'],
                        'standard' => ['qwen3:4b'],
                        'quality_candidate' => ['qwen3:4b'],
                        'coding' => ['qwen3:4b'],
                        'embedding' => ['embeddinggemma:latest'],
                    ],
                    'promotion_rule' => 'Industry prior may seed eligibility; local eval and telemetry control promotion.',
                ],
            ],
        ];
    }

    private function updateJetsonInstance(): void
    {
        $row = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->first();
        if (! $row) {
            return;
        }

        $config = $this->decodeMap($row->config ?? []);
        $models = $this->decodeMap($config['models'] ?? []);
        foreach ($models as $role => $model) {
            if ($model === self::RETIRED_MODEL) {
                unset($models[$role]);
            }
        }
        $config['models'] = $models;
        if (($config['default_model'] ?? null) === self::RETIRED_MODEL) {
            unset($config['default_model']);
        }

        $routePolicy = $this->decodeMap($config['route_policy'] ?? []);
        $routePolicy['allowed_model_roles'] = ['embedding', 'health'];
        $routePolicy['require_model_role'] = true;
        $routePolicy['allow_generic_fallback'] = false;
        $config['route_policy'] = $routePolicy;
        $config['model_admission'] = [
            'enforce' => true,
            'hardware_class' => 'jetson_orin_8gb',
            'policy_source' => 'system_configs.llm.model_hardware_evidence_policy',
            'fail_closed' => true,
        ];

        $jetson = $this->decodeMap($config['jetson'] ?? []);
        unset($jetson['explicit_offline_fallback']);
        $jetson['retired_offline_fallback'] = [
            'profile' => 'plos-offline-jetson',
            'model' => self::RETIRED_MODEL,
            'status' => 'retired_hardware_infeasible',
            'retired_at' => '2026-07-10',
            'replacement_gate' => 'No Jetson Codex route until a hardware-feasible candidate passes the minimal PLOS command/tool/schema contract and canary.',
        ];
        $jetson['replacement_candidates'] = ['qwen3:4b', 'qwen3:8b', 'gemma3:4b'];
        $config['jetson'] = $jetson;

        $supported = array_values(array_filter(
            $this->decodeList($row->supported_models ?? []),
            static fn (string $model): bool => $model !== self::RETIRED_MODEL
        ));

        DB::table('llm_instances')->where('id', $row->id)->update([
            'supported_models' => json_encode($supported, JSON_UNESCAPED_SLASHES),
            'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
            'notes' => $this->appendNoteOnce(
                (string) ($row->notes ?? ''),
                '2026-07-10 RTC: Enforced published-evidence-first Jetson admission, retired the infeasible 19GB qwen3-coder:30b fallback, and kept embedding/health as the only production roles.'
            ),
            'updated_at' => now(),
        ]);
    }

    private function markRetiredModelUnavailable(): void
    {
        if (! Schema::hasTable('ollama_models')) {
            return;
        }

        $dbInstanceId = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->value('id');
        if (! $dbInstanceId) {
            return;
        }

        DB::table('ollama_models')
            ->where('instance_id', $dbInstanceId)
            ->where('model_name', self::RETIRED_MODEL)
            ->update([
                'status' => 'unavailable',
                'is_available' => 0,
                'description' => 'Retired on Jetson: the official 19GB artifact cannot fit the 8GB unified-memory hardware envelope.',
                'vetting_notes' => 'Hardware admission block. Active MoE parameters reduce compute, not the 19GB artifact memory requirement.',
                'vetted_by' => 'codex-rtc',
                'updated_at' => now(),
            ]);
    }

    private function restorePriorJetsonFallback(): void
    {
        $row = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->first();
        if (! $row) {
            return;
        }

        $config = $this->decodeMap($row->config ?? []);
        unset($config['model_admission']);
        $jetson = $this->decodeMap($config['jetson'] ?? []);
        unset($jetson['retired_offline_fallback'], $jetson['replacement_candidates']);
        $jetson['explicit_offline_fallback'] = [
            'profile' => 'plos-offline-jetson',
            'model' => self::RETIRED_MODEL,
            'normal_pool_routing' => false,
        ];
        $config['jetson'] = $jetson;

        $supported = $this->decodeList($row->supported_models ?? []);
        if (! in_array(self::RETIRED_MODEL, $supported, true)) {
            $supported[] = self::RETIRED_MODEL;
        }

        DB::table('llm_instances')->where('id', $row->id)->update([
            'supported_models' => json_encode($supported, JSON_UNESCAPED_SLASHES),
            'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $policy */
    private function upsertPolicy(array $policy): void
    {
        DB::table('system_configs')->updateOrInsert(
            ['section' => 'llm', 'config_key' => 'model_hardware_evidence_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Published-evidence-first hardware feasibility and deployment admission policy for exact local model artifacts.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /** @return array<string,mixed> */
    private function decodeMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<int,string> */
    private function decodeList(mixed $value): array
    {
        return array_values(array_unique(array_map('strval', $this->decodeMap($value))));
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
            'llm_instances_all',
            'llm_instances_healthy',
            'llm_model_hardware_evidence_policy',
            'ollama_available_models',
            'ollama_vetted_models',
        ] as $key) {
            Cache::forget($key);
        }
    }
};
