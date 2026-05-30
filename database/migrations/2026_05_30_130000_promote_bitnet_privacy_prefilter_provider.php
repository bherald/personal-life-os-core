<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSTANCE_ID = 'bitnet_87';

    private const MODEL = 'bitnet-b1.58-2b-4t-i2s';

    public function up(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $now = now();
        $healthBaseUrl = rtrim((string) env('PLOS_BITNET_PRIVACY_PREFILTER_HEALTH_BASE_URL', 'http://127.0.0.1:11435'), '/');
        $baseUrl = rtrim((string) env('PLOS_BITNET_PRIVACY_PREFILTER_BASE_URL', "{$healthBaseUrl}/v1"), '/');
        $config = [
            'adapter' => 'openai_compatible',
            'health_base_url' => $healthBaseUrl,
            'health_path' => '/health',
            'default_model' => self::MODEL,
            'models' => [
                'privacy_deny_prefilter' => self::MODEL,
            ],
            'route_policy' => [
                'mode' => 'narrow',
                'allowed_model_roles' => ['privacy_deny_prefilter'],
                'allow_generic_fallback' => false,
                'require_model_role' => true,
                'require_redacted_input' => true,
                'allowed_decisions' => ['deny', 'allow'],
                'max_prompt_chars' => 4000,
                'prefilter_deny_rules' => [
                    [
                        'id' => 'outbound_sensitive_to_external',
                        'all_groups' => [
                            ['send', 'share', 'upload', 'forward', 'submit', 'post', 'route'],
                            [
                                'private',
                                'secret',
                                'unredacted',
                                'token',
                                'credential',
                                'environment variables',
                                'living relatives',
                                'living family',
                                'diary',
                                'face-cluster',
                                'person names',
                                'email content',
                                'genealogy research',
                                'nextcloud file paths',
                                'private filenames',
                                'agent memory',
                                'source-media ocr',
                                'contact details',
                                'calendar notes',
                                'laravel logs containing identifiers',
                            ],
                            [
                                'mistral_free',
                                'openrouter_free',
                                'cerebras_free',
                                'groq_free',
                                'deepinfra_free',
                                'gemini_free',
                                'sambanova_free',
                                'public web chatbot',
                                'hosted summarization api',
                                'unauthenticated external demo endpoint',
                                'external',
                                'hosted',
                                'free llm',
                            ],
                        ],
                    ],
                ],
            ],
            'timeout' => 20,
            'max_tokens' => 4,
            'temperature' => 0,
            'notes' => 'Allowed only through the bitllm privacy_deny_prefilter role; not a generic text fallback.',
        ];

        $payload = $this->filterColumns('llm_instances', [
            'instance_name' => '.87 BitNet b1.58 2B privacy prefilter',
            'instance_type' => 'local_llm',
            'base_url' => $baseUrl,
            'port' => 11435,
            'api_key_env' => null,
            'api_key' => null,
            'priority' => 32,
            'is_active' => 1,
            'routability' => 'allowed',
            'is_healthy' => 1,
            'health_score' => 85,
            'gpu_target' => 'none',
            'host_affinity' => 'win87',
            'compat_runtime_family' => 'bitnet.cpp',
            'compat_backend' => 'openai_compat',
            'compat_status' => 'authoritative',
            'capabilities' => json_encode([
                'text',
                'chat',
                'classification',
                'structured_output',
                'privacy_deny_prefilter',
                'local_llm',
            ], JSON_UNESCAPED_SLASHES),
            'is_censored' => 1,
            'supported_models' => json_encode([self::MODEL], JSON_UNESCAPED_SLASHES),
            'context_length' => 4096,
            'embedding_context_length' => null,
            'avg_response_ms' => 282,
            'p95_response_ms' => 307,
            'total_requests' => 0,
            'total_failures' => 0,
            'consecutive_failures' => 0,
            'success_rate' => 100,
            'circuit_state' => 'closed',
            'circuit_opened_at' => null,
            'circuit_retry_at' => null,
            'max_concurrent' => 1,
            'rate_limit_rpm' => 240,
            'rate_limit_tpm' => null,
            'cost_per_1k_input' => 0,
            'cost_per_1k_output' => 0,
            'cost_tier' => 'free',
            'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
            'allows_private_data' => 1,
            'data_privacy_scope' => 'local_private',
            'privacy_reviewed_at' => $now,
            'privacy_notes' => 'Local LAN BitNet sidecar vetted for redacted privacy deny/allow prefilter only. Generic fallback and private payload use remain blocked by route_policy.',
            'quarantine_status' => 'none',
            'quarantined_at' => null,
            'quarantine_reason' => null,
            'quarantine_source' => null,
            'notes' => 'Promoted from bench_only to allowed for privacy_deny_prefilter only after 120-case eval and stop/restart circuit proof on 2026-05-30.',
            'last_health_check' => $now,
            'last_success_at' => $now,
            'last_failure_at' => null,
            'updated_at' => $now,
        ]);

        if (! DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->exists()) {
            $payload['instance_id'] = self::INSTANCE_ID;
            $payload['created_at'] = $now;
        }

        DB::table('llm_instances')->updateOrInsert(
            ['instance_id' => self::INSTANCE_ID],
            $payload
        );

        $this->upsertModelRegistry($now);
        $this->clearCaches();
    }

    public function down(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        DB::table('llm_instances')
            ->where('instance_id', self::INSTANCE_ID)
            ->update($this->filterColumns('llm_instances', [
                'routability' => 'bench_only',
                'compat_status' => 'provisional',
                'allows_private_data' => 0,
                'privacy_notes' => 'Rolled back to bench_only.',
                'updated_at' => now(),
            ]));

        $this->clearCaches();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function filterColumns(string $table, array $payload): array
    {
        return array_filter(
            $payload,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function upsertModelRegistry(Carbon $now): void
    {
        if (! Schema::hasTable('ollama_models')) {
            return;
        }

        $instance = DB::table('llm_instances')
            ->where('instance_id', self::INSTANCE_ID)
            ->first();

        if (! $instance) {
            return;
        }

        $payload = $this->filterColumns('ollama_models', [
            'instance_id' => $instance->id,
            'model_name' => self::MODEL,
            'display_name' => 'BitNet b1.58 2B privacy prefilter',
            'profile' => 'privacy_deny_prefilter',
            'status' => 'vetted',
            'is_available' => 1,
            'capabilities' => json_encode(['text', 'classification', 'privacy_deny_prefilter'], JSON_UNESCAPED_SLASHES),
            'use_cases' => json_encode(['redacted_privacy_gate', 'deny_prefilter'], JSON_UNESCAPED_SLASHES),
            'description' => 'Local BitNet sidecar model vetted only for redacted deny/allow privacy prefilter decisions.',
            'size_gb' => 1.11,
            'context_length' => 4096,
            'vram_required_mb' => 0,
            'avg_tokens_per_second' => 37,
            'avg_response_time_ms' => 282,
            'total_requests' => 0,
            'total_failures' => 0,
            'success_rate' => 100,
            'quality_rating' => 6,
            'vetting_notes' => '2026-05-30 RTS: routed through deterministic table prefilter plus BitNet gate; 120/120 expanded privacy eval, 0 dangerous false-allows; not for general text, coding, Genea facts, summaries, or writeback.',
            'vetted_at' => $now,
            'vetted_by' => 'codex-rts',
            'last_seen_at' => $now,
            'updated_at' => $now,
        ]);

        if (! DB::table('ollama_models')->where('model_name', self::MODEL)->exists()) {
            $payload['created_at'] = $now;
            if (Schema::hasColumn('ollama_models', 'first_seen_at')) {
                $payload['first_seen_at'] = $now;
            }
        }

        DB::table('ollama_models')->updateOrInsert(
            ['model_name' => self::MODEL],
            $payload
        );
    }

    private function clearCaches(): void
    {
        foreach ([
            'llm_instances_all',
            'llm_instances_healthy',
            'external_api_providers',
            'llm_model_profiles',
        ] as $key) {
            Cache::forget($key);
        }
    }
};
