<?php

use App\Services\ColibriResearchSidecarService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSTANCE_ID = 'colibri_87';

    private const POLICY_SECTION = 'ai';

    private const POLICY_KEY = 'colibri_research_canary_policy';

    private const TOOL_NAME = 'colibri_deep_research';

    private const DECISION = 'operator_selected_read_only_canary_2026_07_24';

    private const POLICY = [
        'version' => 1,
        'status' => 'operator_selected_canary',
        'execution_enabled' => true,
        'instance_id' => self::INSTANCE_ID,
        'model_role' => 'deep_offline',
        'capability_pack' => 'public_web_read_only',
        'requires_internet' => true,
        'requires_searxng_endpoint' => true,
        'local_offline_supported' => false,
        'offline_policy_profile' => 'hybrid_review',
        'allowed_tools' => [
            'searxng.searxng_search',
            'searxng.searxng_news',
            'searxng.searxng_status',
        ],
        'max_tools_advertised' => 3,
        'max_iterations' => 4,
        'max_tool_calls' => 4,
        'max_result_bytes' => 12000,
        'max_total_result_bytes' => 32000,
        'max_search_query_chars' => 500,
        'max_search_results' => 10,
        'max_prompt_chars' => 4000,
        'max_output_tokens' => 384,
        'max_sidecar_depth' => 0,
        'internet_requires_public_input' => true,
        'writes_allowed' => false,
        'fallback_allowed' => false,
        'promotion_gate' => [
            'model_tool_contract_cases_minimum' => 20,
            'model_tool_contract_success_minimum' => 0.95,
            'research_quality_cases_minimum' => 10,
            'audited_canary_runs_minimum' => 25,
            'unauthorized_accepts_maximum' => 0,
            'write_accepts_maximum' => 0,
            'operator_review_required' => true,
        ],
    ];

    public function up(): void
    {
        $this->seedPolicy();
        $this->admitInstanceCanary();
        $this->stageDisabledRegistryTool();
        $this->clearCaches();
    }

    public function down(): void
    {
        $this->removePolicyIfUnchanged();
        $this->removeInstanceCanaryIfUnchanged();
        $this->removeRegistryToolIfUnchanged();
        $this->clearCaches();
    }

    private function seedPolicy(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }
        $existing = DB::table('system_configs')
            ->where('section', self::POLICY_SECTION)
            ->where('config_key', self::POLICY_KEY)
            ->exists();
        if ($existing) {
            return;
        }

        DB::table('system_configs')->insert($this->filterColumns('system_configs', [
            'section' => self::POLICY_SECTION,
            'config_key' => self::POLICY_KEY,
            'config_value' => json_encode(self::POLICY, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'data_type' => 'json',
            'description' => 'Operator-selected Colibri read-only public-web research canary policy; no writes and no fallback.',
            'is_public' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function admitInstanceCanary(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }
        $row = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->first();
        if ($row === null) {
            return;
        }
        $config = $this->decodeObject($row->config ?? null);
        if (isset($config['research_sidecar'])) {
            return;
        }
        $config['research_sidecar'] = [
            'status' => 'operator_selected_canary',
            'decision' => self::DECISION,
            'broker' => ColibriResearchSidecarService::class,
            'provider_tools_remain_disabled' => true,
            'tools_enabled' => true,
            'tool_catalog' => 'exact_policy_allowlist_only',
            'writes_allowed' => false,
            'fallback_allowed' => false,
            'internet_input_class' => 'public_confirmed_only',
            'capability_pack' => 'public_web_read_only',
            'requires_internet' => true,
            'requires_searxng_endpoint' => true,
            'local_offline_supported' => false,
            'promotion_requires_measured_evidence' => true,
        ];

        DB::table('llm_instances')
            ->where('instance_id', self::INSTANCE_ID)
            ->update([
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    private function stageDisabledRegistryTool(): void
    {
        if (! Schema::hasTable('agent_tool_registry')
            || DB::table('agent_tool_registry')->where('name', self::TOOL_NAME)->exists()
        ) {
            return;
        }

        DB::table('agent_tool_registry')->insert($this->filterColumns('agent_tool_registry', [
            'name' => self::TOOL_NAME,
            'service_class' => ColibriResearchSidecarService::class,
            'method' => 'tool',
            'description' => 'Ask the operator-selected Colibri sidecar to research a confirmed-public question using only the exact read-only PLOS search allowlist. Disabled until measured canary promotion.',
            'parameters' => json_encode([
                'type' => 'object',
                'properties' => [
                    'question' => [
                        'type' => 'string',
                        'description' => 'Public-information research question, maximum 4000 characters.',
                    ],
                    'public_web_confirmed' => [
                        'type' => 'boolean',
                        'description' => 'Must be true only when the question may be sent to public search engines.',
                    ],
                    'max_iterations' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 4,
                    ],
                    'max_tool_calls' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 4,
                    ],
                ],
                'required' => ['question', 'public_web_confirmed'],
                'additionalProperties' => false,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'returns_description' => 'A cited research answer plus content-free policy, tool, latency, and citation receipts.',
            'permissions' => json_encode(['read'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'enabled' => 0,
            'risk_level' => 'read',
            'category' => 'research',
            'requires_confirmation' => 0,
            'max_calls_per_run' => 1,
            'max_tokens_per_call' => 512,
            'max_result_bytes' => 16000,
            'availability_status' => 'disabled',
            'last_error' => 'Measured canary promotion gate pending.',
            'schema_generation' => 1,
            'privacy_class' => 'external_no_private',
            'allows_private_data' => 0,
            'source' => 'manual',
            'approved_by' => self::DECISION,
            'approved_at' => now(),
            'notes' => 'Staged disabled. Enabling requires model tool-contract, injection, research-quality, citation, and audited-run evidence plus operator review.',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function removePolicyIfUnchanged(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }
        $encoded = json_encode(self::POLICY, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        DB::table('system_configs')
            ->where('section', self::POLICY_SECTION)
            ->where('config_key', self::POLICY_KEY)
            ->where('config_value', $encoded)
            ->delete();
    }

    private function removeInstanceCanaryIfUnchanged(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }
        $row = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->first();
        if ($row === null) {
            return;
        }
        $config = $this->decodeObject($row->config ?? null);
        if (($config['research_sidecar']['decision'] ?? null) !== self::DECISION) {
            return;
        }
        unset($config['research_sidecar']);
        DB::table('llm_instances')
            ->where('instance_id', self::INSTANCE_ID)
            ->update([
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    private function removeRegistryToolIfUnchanged(): void
    {
        if (! Schema::hasTable('agent_tool_registry')) {
            return;
        }
        DB::table('agent_tool_registry')
            ->where('name', self::TOOL_NAME)
            ->where('service_class', ColibriResearchSidecarService::class)
            ->where('enabled', 0)
            ->where('approved_by', self::DECISION)
            ->delete();
    }

    /** @return array<string,mixed> */
    private function decodeObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $values */
    private function filterColumns(string $table, array $values): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }
        $columns = array_flip(Schema::getColumnListing($table));

        return array_intersect_key($values, $columns);
    }

    private function clearCaches(): void
    {
        Cache::forget('syscfg:ai.colibri_research_canary_policy');
        Cache::forget('external_api_providers');
        Cache::forget('llm_instances_healthy');
        Cache::forget('llm_instances_all');
        Cache::forget('agent_tool_registry');
    }
};
