<?php

namespace Tests\Unit\Services;

use App\Engine\AIRouter;
use App\Exceptions\AI\ContextLengthException;
use App\Exceptions\AI\InferenceAdmissionException;
use App\Exceptions\ModelHostControllerUnavailableException;
use App\Services\AIService;
use App\Services\CircuitBreaker;
use App\Services\LlmCircuitBreakerPolicyService;
use App\Services\LLMPoolManagerService;
use App\Services\SystemConfigService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

class AIServiceManagedEmbeddingFailureTest extends TestCase
{
    public function test_ordinary_caller_cannot_disable_complete_chunk_coverage(): void
    {
        $source = "  Original\r\n\r\n".str_repeat("日本語\tComplete source. ", 40)."\r\nCritical tail  ";
        $seen = [];
        $router = Mockery::mock(AIRouter::class);
        $router->shouldReceive('generateEmbedding')->andReturnUsing(function (string $chunk) use (&$seen) {
            $seen[] = $chunk;

            return [1.0, 0.0];
        });
        $router->shouldNotReceive('generateExternalEmbedding');
        $service = $this->service($router, [['id' => 'first', 'url' => 'http://first', 'embedding_context_length' => 128]]);
        $result = $service->generateEmbedding($source, ['preserve_embedding_input' => false, 'allow_cpu_fallback' => false]);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['chunked']);
        $this->assertGreaterThan(1, count($seen));
        $this->assertSame($source, implode('', $seen));
        foreach ($seen as $chunk) {
            $this->assertTrue(mb_check_encoding($chunk, 'UTF-8'));
        }
    }

    public function test_managed_ollama_unknown_stops_retry_provider_and_cpu_fallback(): void
    {
        $router = Mockery::mock(AIRouter::class);
        $stop = new InferenceAdmissionException('context length outcome unknown');
        $router->shouldReceive('generateEmbedding')->once()->andThrow($stop);
        $router->shouldNotReceive('generateExternalEmbedding');
        $service = $this->service($router);
        Process::fake();
        $this->assertOriginalStop($stop, fn () => $service->generateEmbedding('synthetic text', ['preserve_embedding_input' => true]));
        Process::assertNothingRan();
    }

    public function test_managed_external_unknown_stops_retry_next_provider_and_cpu(): void
    {
        $router = Mockery::mock(AIRouter::class);
        $router->shouldNotReceive('generateEmbedding');
        $router->shouldReceive('generateExternalEmbedding')->once()->with('synthetic text', Mockery::on(fn ($v) => $v['instance_id'] === 'external-first'))
            ->andThrow($stop = new InferenceAdmissionException('timeout outcome unknown'));
        $service = $this->service($router, []);
        Process::fake();
        $this->assertOriginalStop($stop, fn () => $service->generateEmbedding('synthetic text', ['preserve_embedding_input' => true]));
        Process::assertNothingRan();
    }

    public function test_prechunked_managed_failure_preserves_stop_flag_and_does_not_send_second_chunk(): void
    {
        $router = Mockery::mock(AIRouter::class);
        $stop = new InferenceAdmissionException('unknown after dispatch');
        $router->shouldReceive('generateEmbedding')->once()->andThrow($stop);
        $router->shouldNotReceive('generateExternalEmbedding');
        $service = $this->service($router, [['id' => 'first', 'url' => 'http://first', 'embedding_context_length' => 128]]);
        $this->assertOriginalStop($stop, fn () => $service->generateEmbedding(str_repeat('Synthetic sentence. ', 400), ['allow_cpu_fallback' => false]));
    }

    public function test_context_recovery_chunk_failure_stops_all_further_work(): void
    {
        $router = Mockery::mock(AIRouter::class);
        $router->shouldReceive('generateEmbedding')->once()->ordered()->andThrow(new ContextLengthException('context length exceeded'));
        $stop = new InferenceAdmissionException('unknown chunk result');
        $router->shouldReceive('generateEmbedding')->once()->ordered()->andThrow($stop);
        $router->shouldNotReceive('generateExternalEmbedding');
        $service = $this->service($router);
        $this->assertOriginalStop($stop, fn () => $service->generateEmbedding('short synthetic text', ['allow_cpu_fallback' => false]));
    }

    public function test_managed_context_message_never_triggers_chunk_recovery(): void
    {
        $router = Mockery::mock(AIRouter::class);
        $stop = new InferenceAdmissionException('context length outcome unknown');
        $router->shouldReceive('generateEmbedding')->once()->andThrow($stop);
        $router->shouldNotReceive('generateExternalEmbedding');
        $this->assertOriginalStop($stop, fn () => $this->service($router)->generateEmbedding('short text', ['allow_cpu_fallback' => false]));
    }

    public function test_ordinary_context_failure_still_uses_existing_chunk_recovery(): void
    {
        $router = Mockery::mock(AIRouter::class);
        $router->shouldReceive('generateEmbedding')->once()->ordered()->andThrow(new ContextLengthException('context length exceeded'));
        $router->shouldReceive('generateEmbedding')->once()->ordered()->andReturn([1.0, 0.0]);
        $router->shouldNotReceive('generateExternalEmbedding');
        $result = $this->service($router)->generateEmbedding('short text', ['allow_cpu_fallback' => false]);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['chunked']);
        $this->assertSame([1.0, 0.0], $result['embedding']);
    }

    public function test_ordinary_embedding_dimension_failures_keep_existing_error_array(): void
    {
        $router = Mockery::mock(AIRouter::class);
        $router->shouldNotReceive('generateEmbedding');
        $router->shouldReceive('generateExternalEmbedding')->twice()->andReturn([1.0]);
        $result = $this->service($router, [])->generateEmbedding('synthetic text', ['allow_cpu_fallback' => false]);
        $this->assertFalse($result['success']);
        $this->assertNull($result['embedding']);
        $this->assertFalse($result['fallback_suppressed']);
        $this->assertStringContainsString('CPU fallback disabled', $result['error']);
    }

    public function test_wrapped_typed_embedding_resolver_stop_survives_before_provider_selection(): void
    {
        $router = Mockery::mock(AIRouter::class);
        $router->shouldNotReceive('generateEmbedding', 'generateExternalEmbedding');
        $service = $this->service($router, providerSelection: false);
        $stop = new \RuntimeException('synthetic wrapper', 0, new InferenceAdmissionException('held'));
        $this->assertOriginalStop($stop, fn () => AIService::withWorkflowModelRole(null,
            fn () => $service->generateEmbedding('synthetic text'), resolveEmbeddingModel: fn () => throw $stop));
    }

    public function test_ordinary_embedding_resolver_failure_keeps_existing_error_array(): void
    {
        $router = Mockery::mock(AIRouter::class);
        $router->shouldNotReceive('generateEmbedding', 'generateExternalEmbedding');
        $service = $this->service($router, providerSelection: false);
        $result = AIService::withWorkflowModelRole(null, fn () => $service->generateEmbedding('synthetic text'),
            resolveEmbeddingModel: fn () => throw new \RuntimeException('ordinary resolver unavailable'));
        $this->assertFalse($result['success']);
        $this->assertSame('ordinary resolver unavailable', $result['error']);
        $this->assertTrue($result['fallback_suppressed']);
    }

    #[DataProvider('malformedSuccessfulChunks')]
    public function test_malformed_successful_chunk_holds_before_next_provider_call(mixed $vector): void
    {
        $router = Mockery::mock(AIRouter::class);
        $router->shouldReceive('generateEmbedding')->once()->andReturn($vector);
        $router->shouldNotReceive('generateExternalEmbedding');
        $service = $this->service($router, [['id' => 'first', 'url' => 'http://first', 'embedding_context_length' => 128]]);
        Process::fake();
        $this->expectException(InferenceAdmissionException::class);
        try {
            $service->generateEmbedding(str_repeat('Synthetic source. ', 400), ['allow_cpu_fallback' => false]);
        } finally {
            Process::assertNothingRan();
        }
    }

    public static function malformedSuccessfulChunks(): array
    {
        return ['empty' => [[]], 'not list' => [[1 => 0.25]], 'numeric string' => [['0.25']],
            'nonfinite' => [[INF]], 'nan' => [[NAN]]];
    }

    #[DataProvider('revisionDrift')]
    public function test_chunk_revision_drift_holds_without_third_chunk_alternate_or_cpu(mixed $first, mixed $second): void
    {
        $router = Mockery::mock(AIRouter::class);
        $router->shouldReceive('generateEmbedding')->twice()->andReturn([1.0, 0.0]);
        $router->shouldNotReceive('generateExternalEmbedding');
        $service = $this->service($router, [['id' => 'first', 'url' => 'http://first', 'embedding_context_length' => 128]]);
        $service->testRevisions = [$first, $second];
        Process::fake();
        $this->expectException(InferenceAdmissionException::class);
        try {
            $service->generateEmbedding(str_repeat('Synthetic source. ', 400), ['allow_cpu_fallback' => false]);
        } finally {
            Process::assertNothingRan();
        }
    }

    public static function revisionDrift(): array
    {
        return ['changed' => ['sha256:first', 'sha256:second'], 'unknown to known' => [null, 'sha256:known'],
            'known to unknown' => ['sha256:known', null], 'wrong type' => ['sha256:known', 123],
            'empty' => ['sha256:known', ''], 'whitespace' => ['sha256:known', ' ']];
    }

    public function test_chunk_model_drift_holds_before_third_chunk(): void
    {
        $router = Mockery::mock(AIRouter::class);
        $service = $this->service($router, [['id' => 'first', 'url' => 'http://first', 'embedding_context_length' => 128]]);
        $router->shouldReceive('generateEmbedding')->twice()->andReturnUsing(function () use ($service) {
            (new ReflectionProperty(AIService::class, 'embeddingModel'))->setValue($service, 'changed-model');

            return [1.0, 0.0];
        });
        $router->shouldNotReceive('generateExternalEmbedding');
        $this->expectException(InferenceAdmissionException::class);
        $service->generateEmbedding(str_repeat('Synthetic source. ', 400), ['allow_cpu_fallback' => false]);
    }

    #[DataProvider('stableRevisions')]
    public function test_consistent_chunks_preserve_original_revision_and_input(?string $revision): void
    {
        $source = "  Full synthetic source\r\n".str_repeat('Complete source. ', 30).'尾部  ';
        $router = Mockery::mock(AIRouter::class);
        $seen = [];
        $router->shouldReceive('generateEmbedding')->andReturnUsing(function (string $chunk) use (&$seen) {
            $seen[] = $chunk;

            return [1.0, 0.0];
        });
        $router->shouldNotReceive('generateExternalEmbedding');
        $service = $this->service($router, [['id' => 'first', 'url' => 'http://first', 'embedding_context_length' => 128]], providerSelection: false);
        $service->testRevisions = array_fill(0, 100, $revision);
        $method = new \ReflectionMethod(AIService::class, 'generateChunkedEmbeddingForProvider');
        $result = $method->invoke($service, $source, 128, ['id' => 'first', 'url' => 'http://first', 'instance_type' => 'ollama'],
            ['preserve_embedding_input' => true, 'local_only' => true, 'allow_cpu_fallback' => false]);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertGreaterThan(2, $result['chunk_count']);
        $this->assertSame($source, implode('', $seen));
        $this->assertSame($revision, $result['revision']);
        $this->assertSame('first', $result['instance_id']);
        $this->assertSame('fixture-embedding', $result['model']);
        $this->assertSame('ollama', $result['provider']);
        $this->assertSame(hash('sha256', $source), $result['embedding_input_hash']);
    }

    #[DataProvider('malformedChunkIdentities')]
    public function test_actual_external_chunk_rejects_untyped_provider_instance_or_model(array $override): void
    {
        $router = Mockery::mock(AIRouter::class);
        $router->shouldReceive('generateExternalEmbedding')->once()->andReturn(array_fill(0, 768, 0.5));
        $router->shouldNotReceive('generateEmbedding');
        $service = $this->service($router, [], providerSelection: false);
        $provider = $override + ['id' => 'fixture', 'instance_type' => 'custom', 'embedding_model' => 'model',
            'base_url' => 'http://fixture', 'api_key' => 'test-fixture-key', 'config' => [], 'rate_limit_rpm' => null];
        $this->expectException(InferenceAdmissionException::class);
        (new \ReflectionMethod(AIService::class, 'generateChunkedEmbeddingForProvider'))->invoke($service,
            str_repeat('Synthetic source. ', 100), 128, $provider);
    }

    public static function malformedChunkIdentities(): array
    {
        return ['provider and instance integer' => [['id' => 123]], 'model integer' => [['embedding_model' => 123]],
            'model empty' => [['embedding_model' => '']], 'model whitespace' => [['embedding_model' => ' ']]];
    }

    public static function stableRevisions(): array
    {
        return ['legacy unknown' => [null], 'known original' => ['sha256:Exact-Original']];
    }

    public function test_auto_selected_ollama_preserves_every_original_byte_across_context_chunks(): void
    {
        $source = "  Exact José source\r\n\t".str_repeat('尾 complete original ', 60).' final tail  ';
        $router = Mockery::mock(AIRouter::class);
        $chunks = [];
        $router->shouldReceive('generateEmbedding')->andReturnUsing(function (string $input, $url, $third, $timeout, $model, bool $preserve) use (&$chunks): array {
            $this->assertTrue($preserve);
            $chunks[] = $input;

            return [1.0, 0.0];
        });
        $router->shouldNotReceive('generateExternalEmbedding');
        $service = $this->service($router, [['id' => 'first', 'url' => 'http://first', 'embedding_context_length' => 128]]);
        $result = $service->generateEmbedding($source, ['preserve_embedding_input' => true, 'allow_cpu_fallback' => false]);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertTrue($result['chunked'] ?? false, 'Automatic registered routes must preserve long original input too.');
        $this->assertGreaterThan(2, count($chunks));
        $this->assertSame($source, implode('', $chunks));
        foreach ($chunks as $chunk) {
            $this->assertTrue(mb_check_encoding($chunk, 'UTF-8'));
        }
        $this->assertSame(hash('sha256', $source), $result['embedding_input_hash']);
        $this->assertSame(strlen($source), $result['embedding_input_bytes']);
        $this->assertSame(count($chunks), $result['chunk_count']);
    }

    public function test_auto_selected_lossless_second_chunk_unknown_stops_all_later_dispatch(): void
    {
        $router = Mockery::mock(AIRouter::class);
        $router->shouldReceive('generateEmbedding')->once()->ordered()->andReturn([1.0, 0.0]);
        $router->shouldReceive('generateEmbedding')->once()->ordered()->andThrow($stop = new InferenceAdmissionException('second chunk outcome unknown'));
        $router->shouldNotReceive('generateExternalEmbedding');
        $service = $this->service($router, [['id' => 'first', 'url' => 'http://first', 'embedding_context_length' => 128]]);
        Process::fake();
        $this->assertOriginalStop($stop, fn () => $service->generateEmbedding(str_repeat('Complete original. ', 60),
            ['preserve_embedding_input' => true, 'allow_cpu_fallback' => false]));
        Process::assertNothingRan();
    }

    private function assertOriginalStop(\Throwable $stop, \Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Actual typed admission stop must escape unchanged.');
        } catch (\Throwable $observed) {
            $this->assertSame($stop, $observed);
        }
    }

    #[DataProvider('typedStops')]
    public function test_original_and_wrapped_stops_do_not_become_ordinary_embedding_failures(bool $external, bool $wrapped, bool $controller): void
    {
        $cause = $controller ? new ModelHostControllerUnavailableException('wait_deadline', dispatched: true)
            : new InferenceAdmissionException('synthetic typed stop');
        $stop = $wrapped ? new \RuntimeException('wrapper with no diagnostic clue', 0, $cause) : $cause;
        $router = Mockery::mock(AIRouter::class);
        $router->shouldReceive($external ? 'generateExternalEmbedding' : 'generateEmbedding')->once()->andThrow($stop);
        $router->shouldNotReceive($external ? 'generateEmbedding' : 'generateExternalEmbedding');
        Process::fake();
        $service = $this->service($router, $external ? [] : null);
        $this->assertOriginalStop($stop, fn () => $service->generateEmbedding('synthetic text', ['allow_cpu_fallback' => false]));
        Process::assertNothingRan();
    }

    public static function typedStops(): array
    {
        return ['Ollama raw' => [false, false, false], 'Ollama wrapped' => [false, true, false],
            'external raw' => [true, false, false], 'external wrapped' => [true, true, false],
            'Ollama dispatched controller' => [false, false, true], 'Ollama wrapped dispatched controller' => [false, true, true],
            'external dispatched controller' => [true, false, true], 'external wrapped dispatched controller' => [true, true, true]];
    }

    public function test_exact_trial_embedding_retains_table_role_full_input_and_new_dimensions(): void
    {
        $source = "  Original José\r\nCritical tail  ";
        $vector = array_fill(0, 1024, 0.5);
        $router = Mockery::mock(AIRouter::class);
        $router->shouldReceive('generateEmbedding')->once()->with($source, 'http://fixture-trial', null,
            Mockery::type('int'), 'fixture-trial-model', true)->andReturn($vector);
        $router->shouldNotReceive('generateExternalEmbedding');
        $service = $this->trialService($router, 2048);
        $result = AIService::withWorkflowModelRole(null, fn () => $service->generateEmbedding($source),
            resolveEmbeddingModel: fn () => $this->trialOptions());
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame($vector, $result['embedding']);
        $this->assertSame('fixture-trial-model', $result['model']);
        $this->assertSame('trial-fixture', $result['instance_id']);
        $this->assertFalse($result['chunked']);
    }

    public function test_exact_trial_embedding_missing_registered_context_refuses_send(): void
    {
        $router = Mockery::mock(AIRouter::class);
        $router->shouldNotReceive('generateEmbedding', 'generateExternalEmbedding');
        $result = $this->trialService($router, null)->generateEmbedding('original', $this->trialOptions());
        $this->assertFalse($result['success']);
        $this->assertTrue($result['fallback_suppressed']);
    }

    public function test_alternate_embedding_role_without_exact_binding_refuses_send(): void
    {
        $router = Mockery::mock(AIRouter::class);
        $router->shouldNotReceive('generateEmbedding', 'generateExternalEmbedding');
        $service = $this->service($router, providerSelection: false);
        $result = $service->generateEmbedding('original', ['model_role' => 'embedding_trial']);
        $this->assertFalse($result['success']);
        $this->assertTrue($result['fallback_suppressed']);
    }

    private function trialOptions(): array
    {
        return ['model_role' => 'embedding_trial', 'required_instance_id' => 'trial-fixture',
            'ollama_required_no_fallback' => true, 'model_override' => 'fixture-trial-model',
            'preferred_ollama_instance_ids' => ['trial-fixture'], 'preserve_embedding_input' => true];
    }

    private function trialService(AIRouter $router, ?int $context): AIService
    {
        $service = $this->service($router, providerSelection: false);
        $pool = Mockery::mock(LLMPoolManagerService::class);
        $pool->shouldReceive('selectInstance')->once()->with(Mockery::on(fn ($selection) => $selection['role'] === 'embedding_trial' && $selection['capabilities'] === ['embedding']
            && $selection['model_capability_groups'] === [['embedding']]
            && $selection['model'] === 'fixture-trial-model'
            && $selection['prefer_instance'] === 'trial-fixture'
            && $selection['explicit_instance_selection'] === true))->andReturn(['instance' => (object) [
                'instance_type' => 'ollama', 'instance_id' => 'trial-fixture', 'base_url' => 'http://fixture-trial',
                'priority' => 2, 'embedding_context_length' => $context,
            ]]);
        $pool->shouldReceive('recordSuccess')->andReturnNull();
        foreach (['useDynamicPool' => true, 'poolManager' => $pool] as $name => $value) {
            (new ReflectionProperty(AIService::class, $name))->setValue($service, $value);
        }

        return $service;
    }

    private function service(AIRouter $router, ?array $instances = null, bool $providerSelection = true): AIService
    {
        $service = new class extends AIService
        {
            public array $testInstances = [];

            public array $testRevisions = [];

            public int $statusCalls = 0;

            public function __construct() {}

            public function getHealthyOllamaInstances(): array
            {
                return $this->testInstances;
            }

            public function getOllamaStatus(?string $targetModel = null, ?string $url = null): array
            {
                return ['available' => true, 'model_loaded' => true, 'model_digest' => $this->testRevisions[$this->statusCalls++] ?? null];
            }
        };
        $service->testInstances = $instances ?? [['id' => 'first', 'url' => 'http://first'], ['id' => 'second', 'url' => 'http://second']];
        $config = Mockery::mock(SystemConfigService::class);
        config(['cache.default' => 'array']);
        Cache::flush();
        $config->shouldReceive('get')->andReturnUsing(static fn ($key, $default = null) => $key === 'routing.offline_mode' ? 'disabled' : $default);
        app()->instance(SystemConfigService::class, $config);
        $circuit = Mockery::mock(CircuitBreaker::class);
        $circuit->shouldReceive('isAvailable')->andReturn(true);
        $circuit->shouldReceive('recordSuccess')->andReturnNull();
        $circuit->shouldReceive('getState')->andReturn('closed');
        $circuit->shouldNotReceive('recordFailure');
        foreach (['aiRouter' => $router, 'embeddingModel' => 'fixture-embedding', 'useDynamicPool' => false,
            'circuitBreaker' => $circuit, 'circuitBreakerPolicy' => new LlmCircuitBreakerPolicyService($config)] as $key => $value) {
            (new ReflectionProperty(AIService::class, $key))->setValue($service, $value);
        }
        $rows = [];
        foreach (['external-first', 'external-second'] as $id) {
            $rows[] = (object) ['routability' => 'allowed', 'compat_status' => 'authoritative', 'quarantine_status' => 'none',
                'is_healthy' => 1, 'allows_private_data' => 1, 'rate_limit_tpm' => 0, 'max_concurrent' => 1, 'circuit_state' => 'closed',
                'supported_models' => '["fixture-embedding"]', 'instance_id' => $id, 'instance_name' => $id, 'instance_type' => 'custom',
                'base_url' => 'http://'.$id.'/v1', 'api_key' => 'test-fixture-key', 'api_key_env' => null,
                'priority' => count($rows), 'capabilities' => '["embedding"]', 'config' => '{"embedding_model":"fixture-embedding"}',
                'rate_limit_rpm' => null, 'embedding_context_length' => 8192];
        }
        if ($providerSelection) {
            DB::shouldReceive('select')->once()->andReturn($rows);
        } else {
            DB::shouldReceive('select')->never();
        }
        DB::shouldReceive('selectOne')->andReturnNull();

        return $service;
    }
}
