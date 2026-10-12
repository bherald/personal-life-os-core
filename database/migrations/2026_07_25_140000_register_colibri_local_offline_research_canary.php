<?php

use App\Services\ColibriResearchPolicyService;
use App\Services\ColibriResearchSidecarService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSTANCE_ID = 'colibri_87';

    private const POLICY_SECTION = 'ai';

    private const POLICY_KEY = 'colibri_local_research_canary_policy';

    private const TOOL_NAME = 'colibri_local_research';

    private const DECISION = 'operator_selected_local_read_only_canary_2026_07_25';

    private const POLICY = [
        'version' => 1,
        'status' => 'operator_selected_canary',
        'execution_enabled' => true,
        'instance_id' => self::INSTANCE_ID,
        'model_role' => 'deep_offline',
        'capability_pack' => ColibriResearchPolicyService::LOCAL_OFFLINE_PACK,
        'requires_internet' => false,
        'requires_searxng_endpoint' => false,
        'local_offline_supported' => true,
        'offline_policy_profile' => 'offline_review',
        'allowed_tools' => [
            'rag.rag_search',
            'rag.rag_similar',
            'rag.rag_read',
        ],
        'max_tools_advertised' => 3,
        'max_iterations' => 4,
        'max_tool_calls' => 4,
        'max_result_bytes' => 12000,
        'max_total_result_bytes' => 32000,
        'max_search_query_chars' => 500,
        'max_search_results' => 10,
        'max_document_chars' => 6000,
        'max_prompt_chars' => 4000,
        'max_output_tokens' => 384,
        'max_sidecar_depth' => 0,
        'internet_requires_public_input' => false,
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

    private const INSTANCE_PACK = [
        'status' => 'operator_selected_canary',
        'decision' => self::DECISION,
        'capability_pack' => ColibriResearchPolicyService::LOCAL_OFFLINE_PACK,
        'requires_internet' => false,
        'requires_searxng_endpoint' => false,
        'local_offline_supported' => true,
        'offline_policy_profile' => 'offline_review',
        'tool_catalog' => 'exact_policy_allowlist_only',
        'writes_allowed' => false,
        'fallback_allowed' => false,
        'promotion_requires_measured_evidence' => true,
    ];

    public function up(): void
    {
        $this->seedPolicy();
        $this->admitInstancePack();
        $this->stageDisabledRegistryTool();
        $this->clearCaches();
    }

    public function down(): void
    {
        $this->removePolicyIfUnchanged();
        $this->removeInstancePackIfUnchanged();
        $this->removeRegistryToolIfUnchanged();
        $this->clearCaches();
    }

    private function seedPolicy(): void
    {
        if (! Schema::hasTable('system_configs')
            || DB::table('system_configs')
                ->where('section', self::POLICY_SECTION)
                ->where('config_key', self::POLICY_KEY)
                ->exists()
        ) {
            return;
        }

        DB::table('system_configs')->insert($this->filterColumns('system_configs', [
            'section' => self::POLICY_SECTION,
            'config_key' => self::POLICY_KEY,
            'config_value' => json_encode(self::POLICY, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'data_type' => 'json',
            'description' => 'Operator-selected Colibri local-offline RAG research canary; exact reads only, no writes, Internet, or fallback.',
            'is_public' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function admitInstancePack(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }
        $row = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->first();
        if ($row === null) {
            return;
        }
        $config = $this->decodeObject($row->config ?? null);
        $research = $this->decodeObject($config['research_sidecar'] ?? null);
        if (($research['broker'] ?? null) !== ColibriResearchSidecarService::class
            || ($research['provider_tools_remain_disabled'] ?? false) !== true
            || ($research['tools_enabled'] ?? false) !== true
        ) {
            return;
        }
        $packs = $this->decodeObject($research['capability_packs'] ?? null);
        if (isset($packs[ColibriResearchPolicyService::LOCAL_OFFLINE_PACK])) {
            return;
        }
        $packs[ColibriResearchPolicyService::LOCAL_OFFLINE_PACK] = self::INSTANCE_PACK;
        $research['capability_packs'] = $packs;
        $config['research_sidecar'] = $research;

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
            'method' => 'localTool',
            'description' => 'Ask the operator-selected Colibri sidecar to research local PLOS RAG documents through exact read-only tools. Disabled until measured promotion.',
            'parameters' => json_encode([
                'type' => 'object',
                'properties' => [
                    'question' => [
                        'type' => 'string',
                        'description' => 'Local/private research question, maximum 4000 characters.',
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
                'required' => ['question'],
                'additionalProperties' => false,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'returns_description' => 'A locally grounded answer plus content-free policy, tool, latency, and RAG citation receipts.',
            'permissions' => json_encode(['read'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'enabled' => 0,
            'risk_level' => 'read',
            'category' => 'research',
            'requires_confirmation' => 0,
            'max_calls_per_run' => 1,
            'max_tokens_per_call' => 512,
            'max_result_bytes' => 16000,
            'availability_status' => 'disabled',
            'last_error' => 'Measured local-offline canary promotion gate pending.',
            'schema_generation' => 1,
            'privacy_class' => 'internal_private',
            'allows_private_data' => 1,
            'source' => 'manual',
            'approved_by' => self::DECISION,
            'approved_at' => now(),
            'notes' => 'Staged disabled. Exact RAG reads only; no indexing, public web, SQL, filesystem, write, or fallback authority.',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function removePolicyIfUnchanged(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }
        DB::table('system_configs')
            ->where('section', self::POLICY_SECTION)
            ->where('config_key', self::POLICY_KEY)
            ->where('config_value', json_encode(self::POLICY, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            ->delete();
    }

    private function removeInstancePackIfUnchanged(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }
        $row = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID)->first();
        if ($row === null) {
            return;
        }
        $config = $this->decodeObject($row->config ?? null);
        $research = $this->decodeObject($config['research_sidecar'] ?? null);
        $packs = $this->decodeObject($research['capability_packs'] ?? null);
        $local = $this->decodeObject($packs[ColibriResearchPolicyService::LOCAL_OFFLINE_PACK] ?? null);
        if (($local['decision'] ?? null) !== self::DECISION) {
            return;
        }
        unset($packs[ColibriResearchPolicyService::LOCAL_OFFLINE_PACK]);
        if ($packs === []) {
            unset($research['capability_packs']);
        } else {
            $research['capability_packs'] = $packs;
        }
        $config['research_sidecar'] = $research;
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
            ->where('method', 'localTool')
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
        Cache::forget('syscfg:'.ColibriResearchPolicyService::LOCAL_POLICY_KEY);
        Cache::forget('external_api_providers');
        Cache::forget('llm_instances_healthy');
        Cache::forget('llm_instances_all');
        Cache::forget('agent_tool_registry');
    }
};
