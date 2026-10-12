<?php

namespace Tests\Unit\Services;

use App\Engine\AIRouter;
use App\Services\AIService;
use App\Services\CircuitBreaker;
use App\Services\CodexExecConnectorService;
use App\Services\SystemConfigService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Actual process preference branch, table policy, isolated SQLite; no inference. */
class AIServicePreferredCodexPrivacyAdmissionTest extends TestCase
{
    private string $priorConnection;

    private array $policy = [];

    private array $dispatches = [];

    private AIService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->priorConnection = DB::getDefaultConnection();
        config(['database.connections.codex_privacy_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array', 'services.ai.cache_enabled' => false, 'services.ai.use_dynamic_pool' => false]);
        DB::setDefaultConnection('codex_privacy_test');
        Schema::create('llm_instances', function (Blueprint $table): void {
            $table->id();
            foreach (['instance_id', 'instance_name', 'instance_type', 'base_url', 'routability', 'quarantine_status',
                'capabilities', 'config', 'data_privacy_scope', 'circuit_state'] as $name) {
                $table->text($name)->nullable();
            }
            foreach (['is_active', 'is_healthy', 'allows_private_data', 'priority', 'max_concurrent', 'consecutive_failures',
                'rate_limit_rpm', 'rate_limit_tpm', 'avg_response_ms', 'success_rate'] as $name) {
                $table->integer($name)->nullable();
            }
        });
        DB::table('llm_instances')->insert(['instance_id' => 'codex_exec', 'instance_name' => 'Synthetic Codex',
            'instance_type' => 'codex_cli', 'is_active' => 1, 'is_healthy' => 1, 'routability' => 'allowed',
            'quarantine_status' => 'none', 'allows_private_data' => 0, 'data_privacy_scope' => 'public_only',
            'capabilities' => '["text","sensitive_safe"]', 'priority' => 1, 'circuit_state' => 'closed', 'max_concurrent' => 1,
            'config' => json_encode(['models' => ['standard' => 'synthetic-model'], 'sensitive_safe' => true,
                'route_policy' => ['allowed_model_roles' => ['standard'], 'require_model_role' => true]])]);
        Http::preventStrayRequests();
        $system = Mockery::mock(SystemConfigService::class);
        $system->shouldReceive('get')->andReturnUsing(function ($key, $default = null) {
            if (($this->policy[$key] ?? null) instanceof \Throwable) {
                throw $this->policy[$key];
            }

            return array_key_exists($key, $this->policy) ? $this->policy[$key] : $default;
        });
        $this->app->instance(SystemConfigService::class, $system);
        $connector = Mockery::mock(CodexExecConnectorService::class);
        $connector->shouldReceive('execute')->andReturnUsing(function ($prompt, $config): array {
            $this->dispatches[] = [$prompt, $config];

            return ['success' => true, 'response' => 'Synthetic permitted result', 'provider' => 'codex_exec', 'model' => 'synthetic-model'];
        });
        $this->app->instance(CodexExecConnectorService::class, $connector);
        $router = Mockery::mock(AIRouter::class);
        $router->shouldNotReceive('processWithAI');
        $breaker = Mockery::mock(CircuitBreaker::class);
        $breaker->shouldReceive('isAvailable')->andReturnTrue();
        $breaker->shouldReceive('recordSuccess')->andReturnNull();
        $breaker->shouldReceive('getState')->andReturn('closed');
        $this->service = Mockery::mock(AIService::class, [$router, null, $breaker])->makePartial();
        $this->service->shouldReceive('getHealthyOllamaInstances')->andReturn([]);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        DB::purge('codex_privacy_test');
        DB::setDefaultConnection($this->priorConnection);
        parent::tearDown();
    }

    private function request(array $config = []): array
    {
        return $this->service->process('<|original-source|>'.str_repeat('Complete source ', 1000).'UNCHANGED TAIL', array_replace([
            'prefer_codex' => true, 'codex_required' => true, 'sensitive_data' => false, 'use_cache' => false,
            'model' => 'synthetic-model', 'model_role' => 'standard', 'dedup' => false, 'suppressAlert' => true,
            'source_binding' => ['video' => 'synthetic-video', 'sha256' => str_repeat('a', 64)],
        ], $config));
    }

    private function assertHeld(array $config = []): void
    {
        try {
            $this->request($config);
        } catch (\Exception $error) {
            $this->assertStringStartsWith('Codex Exec failed:', $error->getMessage());
            $this->assertSame([], $this->dispatches);
            Http::assertNothingSent();

            return;
        }
        $this->fail('Required preferred Codex dispatched despite the request/provider restriction.');
    }

    public static function localRestrictions(): array
    {
        return [[['local_only' => true]], [['local_only' => 'true']], [['disable_external_fallback' => 1]],
            [['disable_external_fallback' => 'true']], [['ai_mode' => 'codex_exec', 'prefer_codex' => false, 'local_only' => true]]];
    }

    #[DataProvider('localRestrictions')]
    public function test_explicit_local_constraints_hold_without_any_dispatch(array $config): void
    {
        $this->assertHeld($config);
    }

    public function test_offline_enabled_and_lookup_failure_hold(): void
    {
        $this->policy['routing.offline_mode'] = 'enabled';
        $this->assertHeld();
        $this->policy['routing.offline_mode'] = new \RuntimeException('Synthetic unavailable policy');
        $this->assertHeld();
    }

    public function test_table_denies_sensitive_input_even_with_legacy_safe_claims(): void
    {
        $this->assertHeld(['sensitive_data' => true]);
        $this->assertHeld(['sensitive_data' => 'true']);
    }

    public function test_inferred_private_context_cannot_dispatch_to_public_only_provider(): void
    {
        $constant = (new \ReflectionClass(AIService::class))->getConstant('RUNTIME_POLICY_CONFIG_KEY');
        $this->policy[$constant] = ['sensitive_context_terms' => ['photo']];
        try {
            $this->service->process('Exact synthetic photo evidence', ['prefer_codex' => true, 'codex_required' => true,
                'task_type' => 'photo', 'use_cache' => false, 'model' => 'synthetic-model', 'model_role' => 'standard']);
            $this->fail('Unclassified photo context must not reach a public-only connector.');
        } catch (\Exception $error) {
            $this->assertStringStartsWith('Codex Exec failed:', $error->getMessage());
        }
        $this->assertSame([], $this->dispatches);
    }

    public static function deniedProfiles(): array
    {
        return [
            ['offline_review', null],
            ['unknown_fixture_profile', null],
            ['fixture', ['allowed_instance_types' => ['ollama']]],
            ['fixture', ['allowed_instance_types' => ['codex_cli'], 'allowed_provider_classes' => ['cloud_sensitive_safe']]],
            ['fixture', ['allowed_instance_types' => ['codex_cli'], 'llm_routing' => ['allowed_external_fallback_instance_ids' => []]]],
        ];
    }

    #[DataProvider('deniedProfiles')]
    public function test_preference_cannot_override_profile_admission(string $name, ?array $profile): void
    {
        $this->policy['routing.active_profile'] = $name;
        if ($profile !== null) {
            $this->policy['routing.profile.'.$name] = $profile;
        }
        $this->assertHeld();
    }

    public function test_profile_lookup_failure_holds_before_connector(): void
    {
        $this->policy['routing.active_profile'] = new \RuntimeException('Synthetic profile lookup failure');
        $this->assertHeld();
    }

    public static function deniedRoutes(): array
    {
        return [[['allowed_model_roles' => ['quality']]], [['require_redacted_input' => true]],
            [['require_explicit_instance' => true]], [['max_prompt_chars' => 10]]];
    }

    #[DataProvider('deniedRoutes')]
    public function test_preference_cannot_override_table_route_policy(array $route): void
    {
        DB::table('llm_instances')->update(['config' => json_encode(['models' => ['standard' => 'synthetic-model'], 'route_policy' => $route])]);
        $this->assertHeld();
    }

    public function test_rate_hold_and_table_deactivation_do_not_dispatch(): void
    {
        Cache::put('rate_limit_codex_exec', true, 60);
        $this->assertHeld();
        Cache::forget('rate_limit_codex_exec');
        DB::table('llm_instances')->update(['is_active' => 0]);
        $this->assertHeld();
    }

    public function test_public_control_preserves_complete_original_source_and_false_constraints(): void
    {
        $result = $this->request(['local_only' => 'false', 'disable_external_fallback' => false]);
        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->dispatches);
        $this->assertSame('<|original-source|>'.str_repeat('Complete source ', 1000).'UNCHANGED TAIL', $this->dispatches[0][0]);
        $this->assertSame(['video' => 'synthetic-video', 'sha256' => str_repeat('a', 64)], $this->dispatches[0][1]['source_binding']);
    }

    public static function malformedProfiles(): array
    {
        return [[false], [0], [1], [12.5], [[]], [['name' => 'default']], [(object) ['name' => 'default']], [null]];
    }

    #[DataProvider('malformedProfiles')]
    public function test_malformed_profile_holds_preference(mixed $profile): void
    {
        $this->policy['routing.active_profile'] = $profile;
        $this->assertHeld();
    }

    #[DataProvider('malformedProfiles')]
    public function test_malformed_profile_holds_ordinary_fallback(mixed $profile): void
    {
        $this->policy['routing.active_profile'] = $profile;
        $result = $this->request(['prefer_codex' => false, 'codex_required' => false]);
        $this->assertFalse($result['success']);
        $this->assertSame([], $this->dispatches);
    }

    public static function inactiveProfiles(): array
    {
        return [['default'], [''], ['  ']];
    }

    #[DataProvider('inactiveProfiles')]
    public function test_existing_default_and_empty_profile_contract_remains_admitted(string $profile): void
    {
        $this->policy['routing.active_profile'] = $profile;
        $this->assertTrue($this->request()['success']);
        $this->assertTrue($this->request(['prefer_codex' => false, 'codex_required' => false])['success']);
        $this->assertCount(2, $this->dispatches);
    }

    public function test_missing_profile_row_uses_real_system_config_default(): void
    {
        Schema::create('system_configs', function (Blueprint $table): void {
            $table->id();
            $table->text('section');
            $table->text('config_key');
            $table->text('config_value')->nullable();
            $table->text('data_type');
        });
        $this->app->instance(SystemConfigService::class, new SystemConfigService);
        $this->assertTrue($this->request()['success']);
        $this->assertTrue($this->request(['prefer_codex' => false, 'codex_required' => false])['success']);
        $this->assertCount(2, $this->dispatches);
        $this->assertSame(0, DB::table('system_configs')->count());
    }

    public function test_required_foreign_provider_is_not_admitted_by_existing_codex_boundary(): void
    {
        $config = ['required_instance_id' => 'other_fixture', 'sensitive_data' => false, 'model_role' => 'standard'];
        // Existing process() already rejects this unavailable selected provider.
        $result = $this->request($config);
        $this->assertFalse($result['success']);
        $this->assertTrue($result['fallback_suppressed']);
        $this->assertSame([], $this->dispatches);
        // Exercise its actual existing connector boundary, with the same real row
        // (which deliberately does not require explicit-instance policy).
        $result = (new \ReflectionMethod(AIService::class, 'tryCodexExec'))->invoke($this->service, 'Exact original source', $config);
        $this->assertFalse($result['success']);
        $this->assertSame([], $this->dispatches);
        // The shared chain itself must not grant Codex eligibility for that ID.
        $chain = (new \ReflectionMethod(AIService::class, 'buildFallbackChain'))->invoke($this->service, false, false, $config);
        $this->assertNotContains('codex_exec', array_column($chain, 'id'));
    }

    public function test_matching_required_codex_remains_admitted_in_actual_process(): void
    {
        $result = $this->request(['required_instance_id' => 'codex_exec']);
        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->dispatches);
        $this->assertSame('codex_exec', $this->dispatches[0][1]['required_instance_id']);
    }

    public function test_operator_approved_private_provider_under_sensitive_safe_profile_remains_admitted(): void
    {
        DB::table('llm_instances')->update(['allows_private_data' => 1, 'data_privacy_scope' => 'private_allowed']);
        $this->policy['routing.active_profile'] = 'hybrid_review';
        $result = $this->request(['sensitive_data' => true]);
        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->dispatches);
        $this->assertTrue($this->dispatches[0][1]['sensitive_data']);
        // Fresh table refusal must win on the next exact same request.
        DB::table('llm_instances')->update(['allows_private_data' => 0, 'data_privacy_scope' => 'public_only']);
        $this->dispatches = [];
        $this->assertHeld(['sensitive_data' => true]);
    }
}
