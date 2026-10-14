<?php

namespace Tests\Unit\Services;

use App\Engine\AIRouter;
use App\Services\AIService;
use App\Services\LLMPoolManagerService;
use App\Services\ModelHostControllerClient;
use App\Services\ModelHostControllerPolicyService;
use App\Services\OllamaModelRegistryService;
use App\Services\SystemConfigService;
use App\Services\SystemHealthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionProperty;
use Tests\TestCase;

class TableOnlyLlmRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'llm_route_test');
        config()->set('database.connections.llm_route_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('llm_route_test');
        config()->set('cache.default', 'array');
        config()->set('services.ai.cache_enabled', false);
        config()->set('services.ollama.api_url', 'http://retired-provider.test:11434');
        config()->set('services.ollama.model', 'retired-model');
        config()->set('services.ollama.embedding_model', 'retired-embedding');
        config()->set('services.ollama.vision_model', 'retired-vision');
        Schema::create('llm_instances', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('instance_id');
            $table->string('instance_name');
            $table->string('instance_type');
            $table->string('base_url');
            $table->integer('priority');
            $table->boolean('is_active');
            $table->boolean('is_healthy');
            $table->integer('max_concurrent');
            $table->text('config');
            $table->integer('port')->nullable();
            $table->string('host_affinity')->nullable();
        });
        Schema::create('system_configs', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('section');
            $table->string('config_key');
            $table->text('config_value');
            $table->string('data_type');
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_empty_registry_does_not_resurrect_environment_endpoint_or_models(): void
    {
        $router = new AIRouter;
        $pool = Mockery::mock(LLMPoolManagerService::class);
        $pool->shouldReceive('getInstances')->andReturn([]);
        $service = new AIService($router, $pool);

        $this->assertSame('', $this->property($router, 'ollamaUrl'));
        $this->assertSame('', $this->property($router, 'ollamaModel'));
        $this->assertSame('', $this->property($service, 'embeddingModel'));
        $this->assertSame([], $this->property($service, 'ollamaInstances'));
        $this->assertFalse($service->getOllamaStatus()['available']);
        $this->assertFalse($service->isOllamaBusy());
        Http::assertNothingSent();
    }

    public function test_disabled_provider_is_not_a_default_or_discovery_target(): void
    {
        $this->provider('old-local', false);
        $router = new AIRouter;
        $service = new AIService($router, Mockery::mock(LLMPoolManagerService::class));
        $pool = Mockery::mock(LLMPoolManagerService::class);
        $pool->shouldReceive('getInstances')->andReturn([]);
        $registry = new OllamaModelRegistryService($service, $pool);

        $this->assertSame('', $this->property($router, 'ollamaUrl'));
        $this->assertSame([], $registry->syncModels()['by_instance']);
        $this->assertSame('not_configured', app(SystemHealthService::class)->checkOllama()['status']);
        Http::assertNothingSent();
    }

    public function test_registry_failure_never_creates_a_synthetic_provider(): void
    {
        DB::shouldReceive('selectOne')->andThrow(new \RuntimeException('fixture registry unavailable'));
        DB::shouldReceive('select')->andThrow(new \RuntimeException('fixture registry unavailable'));
        $router = new AIRouter;
        $service = new AIService($router, Mockery::mock(LLMPoolManagerService::class));

        $this->assertSame('', $this->property($router, 'ollamaUrl'));
        $this->assertSame([], $this->property($service, 'ollamaInstances'));
        Http::assertNothingSent();
    }

    public function test_arbitrary_registered_identity_supplies_url_and_role_models(): void
    {
        $this->provider('replacement-without-legacy-name', true);
        $router = new AIRouter;
        $service = new AIService($router, Mockery::mock(LLMPoolManagerService::class));

        $this->assertSame('http://replacement.test:12345', $this->property($router, 'ollamaUrl'));
        $this->assertSame('registered-text', $this->property($router, 'ollamaModel'));
        $this->assertSame('registered-embedding', $this->property($service, 'embeddingModel'));
        $this->assertSame('replacement-without-legacy-name', $this->property($service, 'ollamaInstances')[0]['id']);
        Http::assertNothingSent();
    }

    public function test_binding_requires_explicit_table_authority_even_with_stale_cache(): void
    {
        Cache::put('syscfg:routing.llm_bindings', ['sample' => ['instance_id' => 'stale']], 300);
        $this->expectException(\RuntimeException::class);
        (new SystemConfigService)->llmRoutingBinding('sample');
    }

    public function test_binding_reads_changed_table_values_instead_of_fixed_identity(): void
    {
        $id = DB::table('system_configs')->insertGetId(['section' => 'routing', 'config_key' => 'llm_bindings', 'data_type' => 'json', 'config_value' => json_encode(['sample' => ['instance_id' => 'first-registered', 'host' => 'one.test', 'port' => 12345]])]);
        $config = new SystemConfigService;
        $this->assertSame('first-registered', $config->llmRoutingValue('sample', 'instance_id'));
        DB::table('system_configs')->where('id', $id)->update(['config_value' => json_encode(['sample' => ['instance_id' => 'second-registered', 'host' => 'two.test', 'port' => 23456]])]);
        $this->assertSame('second-registered', $config->llmRoutingValue('sample', 'instance_id'));
        $this->assertSame(23456, $config->llmRoutingValue('sample', 'port'));
    }

    public function test_missing_dedicated_policy_does_not_block_an_unrelated_provider(): void
    {
        $system = new SystemConfigService;
        $this->assertFalse($system->isDedicatedLlmTarget('replacement-provider'));
        DB::table('system_configs')->insert(['section' => 'ai', 'config_key' => 'model_host_controller_mhc08_k3_policy', 'data_type' => 'json', 'config_value' => json_encode(['target' => ['target_id' => 'renamed-dedicated-provider']])]);
        $this->assertTrue($system->isDedicatedLlmTarget('renamed-dedicated-provider'));
        $this->assertFalse($system->isDedicatedLlmTarget('replacement-provider'));
    }

    public function test_controller_transport_uses_registered_table_allowlist(): void
    {
        DB::table('system_configs')->insert(['section' => 'routing', 'config_key' => 'llm_bindings', 'data_type' => 'json', 'config_value' => json_encode(['controller_transport' => ['base_url' => 'http://replacement-controller.test:12346', 'allowed_hosts' => ['replacement-controller.test'], 'port' => 12346]])]);
        config()->set('model_host_controller.url', 'http://retired-controller.test:11440');
        $client = new ModelHostControllerClient(new ModelHostControllerPolicyService);
        $method = new \ReflectionMethod($client, 'baseUrl');
        $this->assertSame('http://replacement-controller.test:12346', $method->invoke($client));
        Http::assertNothingSent();
    }

    public function test_binding_rejects_string_port_instead_of_coercing_it(): void
    {
        DB::table('system_configs')->insert(['section' => 'routing', 'config_key' => 'llm_bindings', 'data_type' => 'json', 'config_value' => json_encode(['sample' => ['port' => '12345']])]);
        $this->expectException(\RuntimeException::class);
        (new SystemConfigService)->llmRoutingValue('sample', 'port');
    }

    public function test_registration_migration_preserves_existing_operator_bindings(): void
    {
        $this->provider('operator-replacement', true);
        $binding = ['sample' => ['instance_id' => 'operator-replacement']];
        DB::table('system_configs')->insert(['section' => 'routing', 'config_key' => 'llm_bindings', 'data_type' => 'json', 'config_value' => json_encode($binding)]);
        $before = (array) DB::table('llm_instances')->first();
        $migration = require database_path('migrations/2026_10_13_232300_register_table_owned_llm_bindings.php');
        $migration->up();
        $migration->up();
        $this->assertSame($binding['sample'], (new SystemConfigService)->llmRoutingBinding('sample'));
        $this->assertSame($before, (array) DB::table('llm_instances')->first());
    }

    public function test_registration_migration_imports_only_existing_routes_without_activating_them(): void
    {
        $this->provider('ollama_primary', false);
        $this->provider('ollama_secondary', false);
        DB::table('llm_instances')->where('instance_id', 'ollama_secondary')->update(['config' => json_encode(['models' => ['standard' => 'operator-text-model'], 'slot_policy' => ['admission_group' => 'operator-group']])]);
        foreach (['model_host_controller_policy' => ['host_id' => 'operator-host', 'targets' => ['operator-target' => ['engine' => 'ollama', 'model' => 'operator-text-model']]], 'service_runtime_policy' => []] as $key => $value) {
            DB::table('system_configs')->insert(['section' => 'ai', 'config_key' => $key, 'data_type' => 'json', 'config_value' => json_encode($value)]);
        }
        $before = DB::table('llm_instances')->get()->toJson();
        $migration = require database_path('migrations/2026_10_13_232300_register_table_owned_llm_bindings.php');
        $migration->up();
        $migration->up();
        $system = new SystemConfigService;
        $this->assertSame('operator-text-model', $system->llmRoutingBinding('knowledge_graph')['managed']['model']);
        $this->assertSame('operator-target', $system->llmRoutingBinding('knowledge_graph')['managed']['target_id']);
        $this->assertSame('operator-host', $system->llmRoutingValue('controller_transport', 'expected_host_id'));
        $this->assertSame($before, DB::table('llm_instances')->get()->toJson());
        $this->assertSame(1, DB::table('system_configs')->where('section', 'routing')->where('config_key', 'llm_bindings')->count());
        Http::assertNothingSent();
    }

    public function test_retirement_migration_disables_only_compressor_and_never_reenables_on_rollback(): void
    {
        foreach (['agent_tool_registry' => 'mcp_server', 'mcp_server_definitions' => 'server_name'] as $table => $column) {
            Schema::create($table, function (Blueprint $table) use ($column): void {
                $table->id();
                $table->string($column);
                $table->boolean('enabled');
            });
            DB::table($table)->insert([[$column => 'prompt-compressor', 'enabled' => true], [$column => 'retained-server', 'enabled' => true]]);
        }
        $policy = ['tool_timeouts_seconds' => ['prompt-compressor.compress' => 90, 'retained-server.read' => 30]];
        DB::table('system_configs')->insert(['section' => 'mcp', 'config_key' => 'router_runtime_policy', 'data_type' => 'json', 'config_value' => json_encode($policy)]);
        $migration = require database_path('migrations/2026_10_13_232200_retire_prompt_compressor_integration.php');
        $migration->up();
        $migration->up();
        $migration->down();
        foreach (['agent_tool_registry' => 'mcp_server', 'mcp_server_definitions' => 'server_name'] as $table => $column) {
            $this->assertSame(0, DB::table($table)->where($column, 'prompt-compressor')->value('enabled'));
            $this->assertSame(1, DB::table($table)->where($column, 'retained-server')->value('enabled'));
        }
        $this->assertSame(['retained-server.read' => 30], (new SystemConfigService)->get('mcp.router_runtime_policy', null, true)['tool_timeouts_seconds']);
        Http::assertNothingSent();
    }

    private function provider(string $id, bool $active): void
    {
        DB::table('llm_instances')->insert(['instance_id' => $id, 'instance_name' => $id, 'instance_type' => 'ollama', 'base_url' => 'http://replacement.test:12345', 'priority' => 1, 'is_active' => $active, 'is_healthy' => true, 'max_concurrent' => 1, 'config' => json_encode(['models' => ['standard' => 'registered-text', 'embedding' => 'registered-embedding', 'vision' => 'registered-vision']])]);
    }

    private function property(object $object, string $name): mixed
    {
        return (new ReflectionProperty($object, $name))->getValue($object);
    }
}
