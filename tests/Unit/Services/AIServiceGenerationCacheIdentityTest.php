<?php

namespace Tests\Unit\Services;

use App\DTOs\PolicyDecision;
use App\Engine\AIRouter;
use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\AIService;
use App\Services\LLMPoolManagerService;
use App\Services\ModelHardwareAdmissionService;
use App\Services\ModelHostControllerResourcePolicyService;
use App\Services\OfflinePolicyService;
use App\Services\SemanticCache;
use App\Services\SystemConfigService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesPoolWireCheckpoints;
use Tests\TestCase;

class AIServiceGenerationCacheIdentityTest extends TestCase
{
    use FakesPoolWireCheckpoints;

    private int $calls = 0;

    private ?object $instance = null;

    private ?array $profile = null;

    private function service(): AIService
    {
        $calls = &$this->calls;
        config(['services.ai.use_dynamic_pool' => true, 'services.ai.cache_enabled' => true, 'services.ai.semantic_cache' => false, 'app.key' => 'synthetic-test-key', 'cache.default' => 'array']);
        DB::shouldReceive('selectOne')->andReturn(null);
        DB::shouldReceive('select')->andReturn([]);
        $system = Mockery::mock(SystemConfigService::class);
        $system->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $default);
        $this->app->instance(SystemConfigService::class, $system);
        Http::preventStrayRequests();
        $instance = (object) ['id' => 1, 'instance_id' => 'denied_fixture', 'instance_name' => 'Denied fixture',
            'instance_type' => 'ollama', 'is_active' => 1, 'is_healthy' => 1, 'routability' => 'allowed',
            'compat_status' => null, 'quarantine_status' => 'none', 'base_url' => 'http://fixture.invalid',
            'capabilities' => '["text"]', 'cost_tier' => 'free', 'is_censored' => 1, 'avg_response_ms' => 100, 'success_rate' => 100,
            'priority' => 1, 'health_score' => 100, 'circuit_state' => 'closed', 'max_concurrent' => 1,
            'supported_models' => '["fixture-model"]', 'config' => '{"models":{"standard":"fixture-model"}}'];

        $this->instance = $instance;
        $policy = Mockery::mock(OfflinePolicyService::class);
        $policy->shouldReceive('evaluateProvider')->andReturn(PolicyDecision::allow('Synthetic local policy', 'default'));
        $this->app->instance(OfflinePolicyService::class, $policy);
        $hardware = Mockery::mock(ModelHardwareAdmissionService::class);
        $hardware->shouldReceive('assess')->andReturn(['route_allowed' => true]);
        $this->app->instance(ModelHardwareAdmissionService::class, $hardware);
        $pool = Mockery::mock(LLMPoolManagerService::class)->makePartial();
        $pool->shouldReceive('getInstances')->with(false)->andReturn([$instance]);
        $pool->shouldReceive('getInstance')->andReturn($instance);
        $pool->shouldReceive('isInstanceBusy')->andReturn(false);
        $this->fakePoolWireReceipts($pool, 'http://fixture.invalid');
        $pool->shouldReceive('acquireBusyLock')->andReturnTrue();
        $pool->shouldReceive('releaseBusyLock')->andReturnTrue();
        $pool->shouldReceive('recordSuccess')->andReturnNull();
        $resources = Mockery::mock(ModelHostControllerResourcePolicyService::class);
        $resources->shouldReceive('managedProvider')->andReturnNull();
        $this->app->instance(ModelHostControllerResourcePolicyService::class, $resources);
        $router = Mockery::mock(AIRouter::class);

        $router->shouldReceive('generationCacheDefaults')->andReturn(['system_prompt' => 'Synthetic default', 'max_tokens' => 4096]);
        $router->shouldReceive('processWithAI')->andReturnUsing(function ($prompt, $config) use (&$calls): string {
            $calls++;

            return $this->completePoolWireFixture($config, 'Synthetic transport response '.($config['system_prompt'] ?? $config['system'] ?? '').' '.$calls);
        });
        $service = Mockery::mock(AIService::class, [$router, $pool])->makePartial();
        // If a rejected instance leaks through, an already-loaded fake model
        // would let the real process() request path reach the mocked transport.
        $service->shouldReceive('getOllamaStatus')->andReturn(['available' => true, 'model_installed' => true,
            'model_loaded' => true, 'needs_swap' => false]);
        $service->shouldReceive('getActiveProfile')->andReturnUsing(fn () => $this->profile);
        $this->app->instance(AIService::class, $service);

        return $service;
    }

    private function request(AIService $service, array $extra = []): array
    {
        return $service->process('Synthetic bounded request', array_replace([
            'model_role' => 'standard', 'model' => 'fixture-model', 'use_cache' => true,
            'sensitive_data' => false, 'dedup' => false, 'disable_cascade' => true, 'suppressAlert' => true,
        ], $extra));
    }

    public function test_actual_process_system_a_then_b_does_not_reuse_generation(): void
    {
        $service = $this->service();
        $a = $this->request($service, ['system_prompt' => 'System A']);
        $b = $this->request($service, ['system_prompt' => 'System B']);
        $again = $this->request($service, ['system_prompt' => 'System A']);
        $this->assertNotSame($a['response'], $b['response']);
        $this->assertSame($a['response'], $again['response']);
        $this->assertTrue($again['from_cache']);
        $this->assertSame(2, $this->calls);
        Http::assertNothingSent();
    }

    public static function changedSettings(): array
    {
        return [[['system' => 'different']], [['sensitive_data' => true]], [['model_role' => 'fast']],
            [['response_format' => ['type' => 'json_object']]], [['tools' => [['name' => 'different']]]],
            [['num_ctx' => 2048]], [['max_tokens' => 100]], [['ollama_options' => ['seed' => 2]]],
            [['allowed_ollama_instance_ids' => ['denied_fixture']]], [['ollama_required_no_fallback' => true]]];
    }

    #[DataProvider('changedSettings')]
    public function test_generation_privacy_role_and_exact_choices_do_not_hit(array $change): void
    {
        $service = $this->service();
        $this->request($service);
        $result = $this->request($service, $change);
        $this->assertFalse($result['from_cache'] ?? false);
        Http::assertNothingSent();
    }

    public function test_actual_process_profile_change_misses_and_denied_cached_provider_is_not_reused(): void
    {
        $service = $this->service();
        $this->request($service);
        $this->profile = ['allowed_instance_types' => ['ollama'], 'llm_routing' => ['patient_local_capacity' => true]];
        $this->assertFalse($this->request($service)['from_cache']);
        $this->assertSame(2, $this->calls);
        // Even if a context fingerprint is deliberately kept identical, admission
        // must refuse a retained response whose provider/model is no longer eligible.
        $method = new \ReflectionMethod(AIService::class, 'cachedGenerationIsAdmitted');
        $this->instance->supported_models = '["different-model"]';
        $this->instance->config = '{"models":{"standard":"different-model"}}';
        $this->assertFalse($method->invoke($service, ['success' => true, 'provider' => 'denied_fixture', 'model' => 'fixture-model'], 'fixture-model', 'standard', []));
        $this->assertFalse($method->invoke($service, ['success' => true, 'provider' => 'external_fixture', 'model' => 'fixture-model'], 'fixture-model', 'standard', []));
    }

    public function test_table_endpoint_change_cannot_reuse_the_old_provider_generation(): void
    {
        $service = $this->service();
        $before = $this->request($service);
        $this->instance->base_url = 'http://replacement-fixture.invalid';
        try {
            $this->request($service);
            $this->fail('Changed endpoint must reach the new dispatch admission boundary.');
        } catch (InferenceAdmissionException $error) {
            $this->assertStringContainsString('dispatch held', $error->getMessage());
        }
        $this->assertSame(2, $this->calls);
        // The fixture receipt qualifies only the original endpoint. Its retained
        // output remains usable for the original request, without deletion.
        $this->instance->base_url = 'http://fixture.invalid';
        $again = $this->request($service);
        $this->assertTrue($again['from_cache']);
        $this->assertSame($before['response'], $again['response']);
        Http::assertNothingSent();
    }

    public function test_unbound_wire_option_cannot_bypass_transport_validation_with_a_warm_hit(): void
    {
        $service = $this->service();
        $this->request($service);
        $result = $this->request($service, ['ollama_keep_alive' => 'invalid']);
        // The fake transport does not validate settings; the actual request must
        // reach that boundary instead of authorizing the previously valid output.
        $this->assertFalse($result['from_cache'] ?? false);
        $this->assertSame(2, $this->calls);
        $this->expectException(\InvalidArgumentException::class);
        AIRouter::ollamaGenerationSettings('fixture-model', 0.1, 128, ['ollama_keep_alive' => 'invalid']);
    }

    public function test_complete_caller_evidence_including_future_options_is_bound(): void
    {
        $service = $this->service();
        $a = $this->request($service, ['source_binding' => ['source_id' => 1, 'body_hash' => 'synthetic-a']]);
        $b = $this->request($service, ['source_binding' => ['source_id' => 2, 'body_hash' => 'synthetic-b']]);
        $again = $this->request($service, ['source_binding' => ['body_hash' => 'synthetic-a', 'source_id' => 1]]);
        $this->assertFalse($b['from_cache'] ?? false);
        $this->assertSame($a['response'], $again['response']);
        $this->assertTrue($again['from_cache']);
        $this->assertSame(2, $this->calls);
        Http::assertNothingSent();
    }

    public function test_table_provider_contract_revision_cannot_reuse_a_prior_generation(): void
    {
        $service = $this->service();
        $this->request($service);
        $this->instance->config = '{"models":{"standard":"fixture-model"},"model_revision":"synthetic-revision-b"}';
        $this->assertFalse($this->request($service)['from_cache'] ?? false);
        $this->assertSame(2, $this->calls);
        Http::assertNothingSent();
    }

    public function test_table_runtime_changes_miss_an_existing_successful_generation(): void
    {
        $service = $this->service();
        $this->request($service);
        $this->instance->config = '{"models":{"standard":"fixture-model"},"role_runtime":{"standard":{"options":{"num_predict":64}}}}';
        $result = $this->request($service);
        $this->assertFalse($result['from_cache']);
        $this->assertSame(2, $this->calls);
    }

    public function test_v2_context_never_dispatches_unscoped_semantic_embedding(): void
    {
        config(['cache.default' => 'array']);
        $ai = Mockery::mock(AIService::class);
        $ai->shouldNotReceive('generateEmbedding');
        $cache = (new SemanticCache(['semantic_enabled' => true]))->setAIService($ai);
        $context = ['schema' => 'plos.ai_generation_cache.v2', 'identity' => 'synthetic'];
        $this->assertNull($cache->get('Synthetic request', $context));
        $cache->put('Synthetic request', ['success' => true], $context);
        $this->assertSame(['success' => true], $cache->get('Synthetic request', $context));
    }

    public function test_semantic_cache_logs_content_free_receipts(): void
    {
        config(['cache.default' => 'array', 'app.key' => 'synthetic-test-key']);
        Log::spy();
        $cache = new SemanticCache(['semantic_enabled' => false]);
        $cache->put('synthetic-private-text', ['success' => true], ['identity' => 'synthetic']);
        $cache->get('synthetic-private-text', ['identity' => 'synthetic']);
        Log::shouldHaveReceived('debug')->twice()->withArgs(function ($message, $context) {
            return ! array_key_exists('prompt', $context)
                && ($context['query_text_logged'] ?? null) === false
                && ! str_contains(json_encode($context), 'synthetic-private-text');
        });
        $this->addToAssertionCount(1);
    }

    public function test_exact_raw_prompt_and_long_source_tail_are_not_semantic_cache_matches(): void
    {
        $service = $this->service();
        $config = ['model' => 'fixture-model', 'model_role' => 'standard', 'dedup' => false,
            'use_cache' => true, 'sensitive_data' => true, 'disable_cascade' => true];
        $prefix = str_repeat('Synthetic shared transcript paragraph. ', 300);
        $a = $service->process($prefix.'Original tail A', $config);
        $b = $service->process($prefix.'Original tail B', $config);
        $again = $service->process($prefix.'Original tail A', $config);
        $this->assertFalse($b['from_cache'] ?? false);
        $this->assertSame($a['response'], $again['response']);
        $this->assertTrue($again['from_cache']);
        $this->assertSame(2, $this->calls);
        Http::assertNothingSent();
    }

    public function test_new_nested_credential_or_unrepresentable_evidence_disables_reuse(): void
    {
        $service = $this->service();
        $method = new \ReflectionMethod(AIService::class, 'generationCacheContext');
        $this->assertNull($method->invoke($service, ['source_binding' => ['cookie' => 'synthetic-private']], 'fixture-model', 'Synthetic bounded request'));
        $this->assertNull($method->invoke($service, ['future_option' => new \stdClass], 'fixture-model', 'Synthetic bounded request'));
        $this->assertNull($method->invoke($service, ['future_option' => INF], 'fixture-model', 'Synthetic bounded request'));
        $a = $method->invoke($service, [], 'fixture-model', 'Source A');
        $b = $method->invoke($service, [], 'fixture-model', 'Source B');
        $this->assertNotSame($a, $b);
        $this->assertStringNotContainsString('Source A', json_encode($a));
    }

    public function test_canonical_maps_hit_and_credentials_never_enter_context(): void
    {
        $service = $this->service();
        $method = new \ReflectionMethod(AIService::class, 'generationCacheContext');
        $a = $method->invoke($service, ['tools' => ['b' => 2, 'a' => 1]], 'fixture-model', 'Synthetic bounded request');
        $b = $method->invoke($service, ['tools' => ['a' => 1, 'b' => 2]], 'fixture-model', 'Synthetic bounded request');
        $this->assertSame($a, $b);
        $this->assertSame(['schema', 'identity'], array_keys($a));
        $this->assertStringNotContainsString('synthetic-credential', json_encode($a));
        $this->assertNull($method->invoke($service, ['api_key' => 'test-synthetic-credential'], 'fixture-model', 'Synthetic bounded request'));
        $this->assertNull($method->invoke($service, ['extra_headers' => ['X-Synthetic' => 'synthetic-credential']], 'fixture-model', 'Synthetic bounded request'));
        $this->assertNull($method->invoke($service, ['_mhc08_admission_token' => 'synthetic-credential'], 'fixture-model', 'Synthetic bounded request'));
        $this->assertNull($method->invoke($service, ['tools' => ['authorization' => 'synthetic-credential']], 'fixture-model', 'Synthetic bounded request'));
        $this->assertNull($method->invoke($service, ['temperature' => INF], 'fixture-model', 'Synthetic bounded request'));
        config(['app.key' => '']);
        $this->assertNull($method->invoke($service, [], 'fixture-model', 'Synthetic bounded request'));
        config(['app.key' => 'synthetic-test-key']);
        config(['services.ai.cache_identity_max_bytes' => 1]);
        $this->assertNull($method->invoke($service, [], 'fixture-model', 'Synthetic bounded request'));
    }
}
