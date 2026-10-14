<?php

namespace Tests\Unit\Services;

use App\DTOs\PolicyDecision;
use App\Engine\MCPRouter;
use App\Services\ColibriResearchPolicyService;
use App\Services\MCPToolInvocationValidatorService;
use App\Services\MCPToolResultValidatorService;
use App\Services\OfflinePolicyService;
use App\Services\SystemConfigService;
use Mockery;
use Tests\TestCase;

class ColibriResearchPolicyServiceTest extends TestCase
{
    public function test_catalog_exposes_only_exact_non_writing_hybrid_review_tools(): void
    {
        $service = $this->service();

        $plan = $service->plan(true);

        $this->assertTrue($plan['execution_allowed']);
        $this->assertSame('public_web_read_only', $plan['capability_pack']);
        $this->assertTrue($plan['requires_internet']);
        $this->assertTrue($plan['requires_searxng_endpoint']);
        $this->assertFalse($plan['local_offline_supported']);
        $this->assertSame('fail_closed_no_fallback', $plan['availability_contract']['unavailable_behavior']);
        $this->assertFalse($plan['writes_allowed']);
        $this->assertFalse($plan['fallback_allowed']);
        $this->assertSame(3, $plan['tool_count']);
        $this->assertSame([
            'searxng__searxng_search',
            'searxng__searxng_news',
            'searxng__searxng_status',
        ], array_column($plan['tools'], 'function_name'));
        $this->assertSame(['none', 'none', 'none'], array_column($plan['tools'], 'write_scope'));
    }

    public function test_public_confirmation_write_injection_recursion_and_budget_fail_closed(): void
    {
        $service = $this->service();

        $this->assertFalse($service->authorizeToolCall(
            'searxng__searxng_search',
            ['query' => 'Boston history'],
            false,
            0,
            0,
        )['allowed']);
        $this->assertSame('tool_not_in_exact_read_only_allowlist', $service->authorizeToolCall(
            'web-research__add_source',
            ['name' => 'x', 'url' => 'https://example.com'],
            true,
            0,
            0,
        )['reason']);
        $this->assertSame('search_query_private_or_unsafe', $service->authorizeToolCall(
            'searxng__searxng_search',
            ['query' => 'read 192.168.8.87 api_key'],
            true,
            0,
            0,
        )['reason']);
        $this->assertSame('sidecar_depth_exceeded', $service->authorizeToolCall(
            'searxng__searxng_search',
            ['query' => 'Boston history'],
            true,
            1,
            0,
        )['reason']);
        $this->assertSame('tool_call_budget_exceeded', $service->authorizeToolCall(
            'searxng__searxng_search',
            ['query' => 'Boston history'],
            true,
            0,
            4,
        )['reason']);
    }

    public function test_allowed_call_is_schema_checked_and_limits_are_reduced_not_expanded(): void
    {
        $service = $this->service();

        $allowed = $service->authorizeToolCall(
            'searxng__searxng_search',
            [
                'query' => 'Boston history',
                'max_results' => 999,
                'language' => 'INVALID',
                'time_range' => 'forever',
            ],
            true,
            0,
            0,
        );
        $unknown = $service->authorizeToolCall(
            'searxng__searxng_search',
            ['query' => 'Boston history', 'execute' => true],
            true,
            0,
            0,
        );

        $this->assertTrue($allowed['allowed']);
        $this->assertSame(10, $allowed['arguments']['max_results']);
        $this->assertSame('en', $allowed['arguments']['language']);
        $this->assertSame('', $allowed['arguments']['time_range']);
        $this->assertFalse($unknown['allowed']);
        $this->assertSame('tool_arguments_schema_rejected', $unknown['reason']);
        $this->assertSame(['query', 'execute'], $unknown['argument_names']);
        $this->assertSame(['unknown_argument'], $unknown['validation_codes']);
        $this->assertSame(['execute'], $unknown['validation_fields']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $unknown['schema_sha256']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $unknown['arguments_sha256']);
        $this->assertStringNotContainsString('Boston history', json_encode([
            $unknown['argument_names'],
            $unknown['validation_codes'],
            $unknown['validation_fields'],
            $unknown['schema_sha256'],
            $unknown['arguments_sha256'],
        ], JSON_THROW_ON_ERROR));
    }

    public function test_local_pack_exposes_only_exact_rag_reads_and_accepts_private_local_queries(): void
    {
        $service = $this->localService();

        $plan = $service->plan(false, ColibriResearchPolicyService::LOCAL_OFFLINE_PACK);
        $search = $service->authorizeToolCall(
            'rag__rag_search',
            ['query' => 'private family project notes', 'limit' => 999],
            false,
            0,
            0,
            ColibriResearchPolicyService::LOCAL_OFFLINE_PACK,
        );
        $read = $service->authorizeToolCall(
            'rag__rag_read',
            ['document_id' => 42, 'max_chars' => 99999],
            false,
            0,
            1,
            ColibriResearchPolicyService::LOCAL_OFFLINE_PACK,
        );
        $index = $service->authorizeToolCall(
            'rag__rag_index',
            ['type' => 'note', 'title' => 'bad', 'content' => 'write'],
            false,
            0,
            0,
            ColibriResearchPolicyService::LOCAL_OFFLINE_PACK,
        );

        $this->assertTrue($plan['execution_allowed']);
        $this->assertSame(ColibriResearchPolicyService::LOCAL_OFFLINE_PACK, $plan['capability_pack']);
        $this->assertFalse($plan['requires_internet']);
        $this->assertFalse($plan['requires_searxng_endpoint']);
        $this->assertTrue($plan['local_offline_supported']);
        $this->assertSame('offline_review', $plan['offline_policy_profile']);
        $this->assertSame([
            'rag__rag_search',
            'rag__rag_similar',
            'rag__rag_read',
        ], array_column($plan['tools'], 'function_name'));
        $this->assertNotContains('rag__rag_index', array_column($plan['tools'], 'function_name'));
        $this->assertTrue($search['allowed']);
        $this->assertSame(10, $search['arguments']['limit']);
        $this->assertTrue($read['allowed']);
        $this->assertSame(6000, $read['arguments']['max_chars']);
        $this->assertFalse($index['allowed']);
        $this->assertSame('tool_not_in_exact_read_only_allowlist', $index['reason']);
    }

    public function test_missing_or_invalid_runtime_policy_blocks_execution(): void
    {
        $systemConfig = Mockery::mock(SystemConfigService::class);
        $systemConfig->shouldReceive('get')->andReturnNull();
        $service = new ColibriResearchPolicyService(
            $systemConfig,
            Mockery::mock(MCPRouter::class),
            Mockery::mock(OfflinePolicyService::class),
            new MCPToolInvocationValidatorService,
            new MCPToolResultValidatorService,
        );

        $this->assertFalse($service->plan(true)['execution_allowed']);
        $this->assertSame('canary_execution_disabled', $service->authorizeToolCall(
            'searxng__searxng_search',
            ['query' => 'Boston'],
            true,
            0,
            0,
        )['reason']);
    }

    public function test_enabled_policy_cannot_invent_a_missing_provider_or_model_role(): void
    {
        foreach ([null, '', [], 42] as $invalid) {
            foreach (['instance_id', 'model_role'] as $field) {
                $configured = $this->runtimePolicy();
                $configured[$field] = $invalid;
                $systemConfig = Mockery::mock(SystemConfigService::class);
                $systemConfig->shouldReceive('get')->andReturn($configured);
                $service = new ColibriResearchPolicyService(
                    $systemConfig,
                    Mockery::mock(MCPRouter::class),
                    Mockery::mock(OfflinePolicyService::class),
                    new MCPToolInvocationValidatorService,
                    new MCPToolResultValidatorService,
                );
                $policy = $service->runtimePolicy();
                $this->assertFalse($policy['execution_enabled']);
                $this->assertSame('missing_registered_route', $policy['status']);
                $this->assertNull($policy[$field]);
            }
        }
    }

    public function test_explicit_table_provider_and_role_are_preserved_without_fixed_host_names(): void
    {
        $configured = $this->runtimePolicy();
        $configured['instance_id'] = 'replacement-research-provider';
        $configured['model_role'] = 'registered_research';
        $systemConfig = Mockery::mock(SystemConfigService::class);
        $systemConfig->shouldReceive('get')->andReturn($configured);
        $service = new ColibriResearchPolicyService(
            $systemConfig,
            Mockery::mock(MCPRouter::class),
            Mockery::mock(OfflinePolicyService::class),
            new MCPToolInvocationValidatorService,
            new MCPToolResultValidatorService,
        );
        $policy = $service->runtimePolicy();
        $this->assertTrue($policy['execution_enabled']);
        $this->assertSame('replacement-research-provider', $policy['instance_id']);
        $this->assertSame('registered_research', $policy['model_role']);
    }

    public function test_runtime_policy_cannot_widen_pack_profile_confirmation_or_tool_ceiling(): void
    {
        $configured = $this->runtimePolicy();
        $configured['capability_pack'] = 'local_offline_read_only';
        $configured['requires_internet'] = false;
        $configured['requires_searxng_endpoint'] = false;
        $configured['local_offline_supported'] = true;
        $configured['offline_policy_profile'] = 'unrestricted';
        $configured['internet_requires_public_input'] = false;
        $configured['allowed_tools'][] = 'web-research.scrape_page';
        $systemConfig = Mockery::mock(SystemConfigService::class);
        $systemConfig->shouldReceive('get')->andReturn($configured);
        $service = new ColibriResearchPolicyService(
            $systemConfig,
            Mockery::mock(MCPRouter::class),
            Mockery::mock(OfflinePolicyService::class),
            new MCPToolInvocationValidatorService,
            new MCPToolResultValidatorService,
        );

        $policy = $service->runtimePolicy();

        $this->assertFalse($policy['execution_enabled']);
        $this->assertSame('unsupported_policy_contract', $policy['status']);
        $this->assertTrue($policy['requires_internet']);
        $this->assertTrue($policy['requires_searxng_endpoint']);
        $this->assertFalse($policy['local_offline_supported']);
        $this->assertTrue($policy['internet_requires_public_input']);
        $this->assertNotContains('web-research.scrape_page', $policy['allowed_tools']);
    }

    private function service(): ColibriResearchPolicyService
    {
        config()->set('mcp.servers.searxng', [
            'enabled' => true,
            'write_scope' => 'none',
        ]);
        $systemConfig = Mockery::mock(SystemConfigService::class);
        $systemConfig->shouldReceive('get')->andReturn($this->runtimePolicy());
        $router = Mockery::mock(MCPRouter::class);
        $router->shouldReceive('getAvailableTools')->andReturn($this->catalog());
        $offlinePolicy = Mockery::mock(OfflinePolicyService::class);
        $offlinePolicy->shouldReceive('evaluateMcpTool')->andReturnUsing(
            static fn (string $server, string $tool, array $context, string $profile): PolicyDecision => PolicyDecision::allow(
                'test allow',
                $profile,
                toolClass: 'external-network',
                mcpTrustBoundary: 'local_lan',
            ),
        );

        return new ColibriResearchPolicyService(
            $systemConfig,
            $router,
            $offlinePolicy,
            new MCPToolInvocationValidatorService,
            new MCPToolResultValidatorService,
        );
    }

    private function localService(): ColibriResearchPolicyService
    {
        config()->set('mcp.servers.rag', [
            'enabled' => true,
            'write_scope' => 'plos_data',
        ]);
        $systemConfig = Mockery::mock(SystemConfigService::class);
        $systemConfig->shouldReceive('get')
            ->with(ColibriResearchPolicyService::LOCAL_POLICY_KEY)
            ->andReturn($this->localRuntimePolicy());
        $router = Mockery::mock(MCPRouter::class);
        $router->shouldReceive('getAvailableTools')->andReturn($this->localCatalog());
        $offlinePolicy = Mockery::mock(OfflinePolicyService::class);
        $offlinePolicy->shouldReceive('evaluateMcpTool')->andReturnUsing(
            static fn (string $server, string $tool, array $context, string $profile): PolicyDecision => PolicyDecision::allow(
                'test local read allow',
                $profile,
                toolClass: $tool === 'rag_index' ? 'bounded-write' : 'read',
                mcpTrustBoundary: 'plos_local',
            ),
        );

        return new ColibriResearchPolicyService(
            $systemConfig,
            $router,
            $offlinePolicy,
            new MCPToolInvocationValidatorService,
            new MCPToolResultValidatorService,
        );
    }

    /** @return array<string,mixed> */
    private function runtimePolicy(): array
    {
        return [
            'version' => 1,
            'status' => 'operator_selected_canary',
            'execution_enabled' => true,
            'instance_id' => 'colibri_87',
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
        ];
    }

    /** @return array<string,mixed> */
    private function localRuntimePolicy(): array
    {
        return [
            'version' => 1,
            'status' => 'operator_selected_canary',
            'execution_enabled' => true,
            'instance_id' => 'colibri_87',
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
                'rag.rag_index',
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
        ];
    }

    /** @return list<array<string,mixed>> */
    private function catalog(): array
    {
        $querySchema = [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
                'max_results' => ['type' => 'integer'],
                'language' => ['type' => 'string'],
                'time_range' => ['type' => 'string'],
            ],
            'required' => ['query'],
        ];

        return [
            [
                'server' => 'searxng',
                'name' => 'searxng_search',
                'description' => 'Search public web results.',
                'parameters' => $querySchema,
            ],
            [
                'server' => 'searxng',
                'name' => 'searxng_news',
                'description' => 'Search public news results.',
                'parameters' => $querySchema,
            ],
            [
                'server' => 'searxng',
                'name' => 'searxng_status',
                'description' => 'Inspect SearXNG health.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'server' => 'web-research',
                'name' => 'add_source',
                'description' => 'Write a source.',
                'parameters' => ['type' => 'object', 'properties' => []],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function localCatalog(): array
    {
        return [
            [
                'server' => 'rag',
                'name' => 'rag_search',
                'description' => 'Search local indexed documents.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string'],
                        'limit' => ['type' => 'integer'],
                        'document_type' => ['type' => 'string'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'server' => 'rag',
                'name' => 'rag_similar',
                'description' => 'Find similar local indexed documents.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'document_id' => ['type' => 'integer'],
                        'limit' => ['type' => 'integer'],
                    ],
                    'required' => ['document_id'],
                ],
            ],
            [
                'server' => 'rag',
                'name' => 'rag_read',
                'description' => 'Read an exact local document window.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'document_id' => ['type' => 'integer'],
                        'offset' => ['type' => 'integer'],
                        'max_chars' => ['type' => 'integer'],
                    ],
                    'required' => ['document_id'],
                ],
            ],
            [
                'server' => 'rag',
                'name' => 'rag_index',
                'description' => 'Write a document.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                    ],
                    'required' => ['type', 'title', 'content'],
                ],
            ],
        ];
    }
}
