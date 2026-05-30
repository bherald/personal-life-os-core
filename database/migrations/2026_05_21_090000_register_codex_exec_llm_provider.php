<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->addCodexCliEnumValue();
        $this->upsertCodexProvider();

        Cache::forget('llm_instances_all');
        Cache::forget('llm_instances_healthy');
        Cache::forget('external_api_providers');
    }

    public function down(): void
    {
        DB::table('llm_instances')
            ->where('instance_id', 'codex_exec')
            ->delete();

        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE llm_instances
                MODIFY instance_type ENUM(
                    'ollama',
                    'claude_cli',
                    'anthropic_api',
                    'openai',
                    'azure_openai',
                    'google_gemini',
                    'local_llm',
                    'custom'
                ) NOT NULL COMMENT 'Provider type for adapter selection'
            ");
        }
    }

    private function addCodexCliEnumValue(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("
            ALTER TABLE llm_instances
            MODIFY instance_type ENUM(
                'ollama',
                'claude_cli',
                'codex_cli',
                'anthropic_api',
                'openai',
                'azure_openai',
                'google_gemini',
                'local_llm',
                'custom'
            ) NOT NULL COMMENT 'Provider type for adapter selection'
        ");
    }

    private function upsertCodexProvider(): void
    {
        $now = now();
        $exists = DB::table('llm_instances')
            ->where('instance_id', 'codex_exec')
            ->exists();
        $models = [
            'gpt-5.5',
            'gpt-5.4',
            'gpt-5.4-mini',
            'gpt-5.3-codex',
            'gpt-5.2',
        ];

        $config = [
            'adapter' => 'codex_exec',
            'executable' => 'codex',
            'default_profile' => null,
            'default_cwd' => base_path(),
            'default_sandbox' => 'read-only',
            'default_approval_policy' => 'never',
            'default_timeout_seconds' => 900,
            'max_prompt_bytes' => 200000,
            'output_last_message' => true,
            'json_events' => true,
            'ephemeral' => true,
            'skip_git_repo_check' => true,
            'allow_live_extra_models' => true,
            'cwd_roots' => [
                base_path(),
            ],
            'models' => [
                'standard' => 'gpt-5.5',
                'quality' => 'gpt-5.5',
                'coding' => 'gpt-5.5',
                'fast' => 'gpt-5.4-mini',
            ],
            'reasoning_effort' => [
                'standard' => 'medium',
                'quality' => 'high',
                'coding' => 'high',
                'fast' => 'low',
            ],
            'supported_reasoning_efforts' => [
                'low',
                'medium',
                'high',
                'xhigh',
            ],
            'sandbox_by_role' => [
                'standard' => 'read-only',
                'quality' => 'read-only',
                'coding' => 'workspace-write',
                'fast' => 'read-only',
            ],
            'structured_output' => [
                'enabled' => true,
                'schema_file_roots' => [
                    storage_path('app/codex-schemas'),
                ],
            ],
        ];

        DB::table('llm_instances')->updateOrInsert(
            ['instance_id' => 'codex_exec'],
            [
                'instance_name' => 'OpenAI Codex Exec',
                'instance_type' => 'codex_cli',
                'base_url' => null,
                'api_key_env' => 'OPENAI_API_KEY',
                'api_key' => null,
                'priority' => 18,
                'is_active' => 1,
                'is_healthy' => 1,
                'health_score' => 100,
                'routability' => 'allowed',
                'gpu_target' => 'none',
                'host_affinity' => 'prod',
                'compat_runtime_family' => 'codex-cli',
                'compat_backend' => 'openai',
                'compat_status' => 'authoritative',
                'capabilities' => json_encode([
                    'text',
                    'code',
                    'tools',
                    'repository',
                    'jsonl',
                    'structured_output',
                ], JSON_UNESCAPED_SLASHES),
                'is_censored' => 1,
                'supported_models' => json_encode($models, JSON_UNESCAPED_SLASHES),
                'context_length' => 128000,
                'avg_response_ms' => 30000,
                'p95_response_ms' => 90000,
                'total_requests' => 0,
                'total_failures' => 0,
                'consecutive_failures' => 0,
                'success_rate' => 100,
                'circuit_state' => 'closed',
                'max_concurrent' => 1,
                'rate_limit_rpm' => 6,
                'rate_limit_tpm' => null,
                'cost_tier' => 'premium',
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'notes' => 'Codex Exec is a bounded online external LLM partner for PLOS/Genea/dev pipeline tasks. Model and reasoning effort are resolved from llm_instances.config; approval_policy must remain never for pipeline execution.',
                'last_health_check' => $now,
                'last_success_at' => null,
                'last_failure_at' => null,
                'updated_at' => $now,
            ] + ($exists ? [] : ['created_at' => $now])
        );
    }
};
