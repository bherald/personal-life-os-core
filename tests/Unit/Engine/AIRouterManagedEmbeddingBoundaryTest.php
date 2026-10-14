<?php

namespace Tests\Unit\Engine;

use App\Engine\AIRouter;
use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\ModelHostControllerAutomaticSelectionService;
use App\Services\ModelHostControllerResourcePolicyService;
use App\Services\ModelHostControllerTransportSupervisor;
use App\Services\ModelHostControllerWaitService;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

class AIRouterManagedEmbeddingBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_unmanaged_legacy_caller_cannot_request_a_prefix_embedding(): void
    {
        $router = $this->unmanagedRouter();
        $input = '  '.str_repeat('Original complete input. ', 20)."\r\n日本語 CRITICAL TAIL  ";
        Http::fake(['unmanaged/*' => Http::response(['embeddings' => [[0.1, 0.2, 0.3]], 'embedding' => [0.1, 0.2, 0.3]])]);
        $this->assertSame([0.1, 0.2, 0.3], $router->generateEmbedding($input, 'http://unmanaged', 16, 10, 'embedding-fixture', false));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'http://unmanaged/api/embed'
            && $request['input'] === $input && $request['truncate'] === false && ! isset($request['prompt']));
    }

    public function test_unmanaged_invalid_utf8_is_refused_before_http(): void
    {
        Http::fake();
        $this->expectException(InferenceAdmissionException::class);
        try {
            $this->unmanagedRouter()->generateEmbedding("invalid\xff", 'http://unmanaged', 16, 10, 'embedding-fixture', false);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_unmanaged_overflow_is_not_retried_with_a_prefix(): void
    {
        $input = str_repeat('Original source. ', 100);
        Http::fake(['unmanaged/api/embed' => Http::response(['error' => 'context length exceeded'], 400)]);
        try {
            $this->unmanagedRouter()->generateEmbedding($input, 'http://unmanaged', 16, 10, 'embedding-fixture', false);
            $this->fail('Overflow was accepted.');
        } catch (\Exception $error) {
            $this->assertSame('Ollama embedding request failed.', $error->getMessage());
            Http::assertSentCount(1);
            Http::assertSent(fn ($request) => $request['input'] === $input && $request['truncate'] === false);
        }
    }

    private function unmanagedRouter(): AIRouter
    {
        $resources = Mockery::mock(ModelHostControllerResourcePolicyService::class);
        $resources->shouldReceive('managedHostForEndpoint')->andReturnNull();
        $resources->shouldReceive('assertUnmanagedTransportAllowed')->andReturnNull();
        app()->instance(ModelHostControllerResourcePolicyService::class, $resources);
        $supervisor = Mockery::mock(ModelHostControllerTransportSupervisor::class);
        $supervisor->shouldReceive('run')->andReturnUsing(fn ($wire, $endpoint, $payload, $send) => $send());
        app()->instance(ModelHostControllerTransportSupervisor::class, $supervisor);
        $router = (new ReflectionClass(AIRouter::class))->newInstanceWithoutConstructor();
        foreach (['embeddingCharLimit' => 16, 'embeddingModel' => 'embedding-fixture', 'ollamaUrl' => 'http://unmanaged'] as $key => $value) {
            (new ReflectionProperty(AIRouter::class, $key))->setValue($router, $value);
        }

        return $router;
    }

    public function test_default_managed_ollama_request_preserves_full_input_and_finishes_after_valid_vector(): void
    {
        $router = $this->router(true);
        $input = str_repeat('Long original input. ', 12)."\n日本語";
        Http::fake(['managed/api/embed' => Http::response(['embeddings' => [[0.1, 0.2, 0.3]]])]);
        $this->assertSame([0.1, 0.2, 0.3], $router->generateEmbedding($input, 'http://managed', 16, 10, 'embedding-fixture'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'http://managed/api/embed'
            && $request['input'] === $input && $request['truncate'] === false && $request['keep_alive'] === 0
            && $request['options'] === ['num_ctx' => 2048, 'num_gpu' => 0, 'num_thread' => 4]);
    }

    public static function badVectors(): array
    {
        return [[[1, 2]], [['1', 2, 3]], [[]], [['x' => 1, 'y' => 2, 'z' => 3]]];
    }

    #[DataProvider('badVectors')]
    public function test_invalid_vector_is_held_inside_custody_after_only_one_http_request(array $vector): void
    {
        $router = $this->router(false);
        Http::fake(['managed/api/embed' => Http::response(['embeddings' => [$vector]])]);
        try {
            $router->generateEmbedding('synthetic', 'http://managed', null, 10, 'embedding-fixture');
            $this->fail('Malformed managed vector was returned as success.');
        } catch (InferenceAdmissionException) {
            Http::assertSentCount(1);
        }
    }

    public function test_http_failure_is_typed_and_held_without_retry(): void
    {
        $router = $this->router(false);
        Http::fake(['managed/api/embed' => Http::response([], 503)]);
        try {
            $router->generateEmbedding('synthetic', 'http://managed', null, 10, 'embedding-fixture');
            $this->fail('Managed failure was not typed.');
        } catch (InferenceAdmissionException) {
            Http::assertSentCount(1);
        }
    }

    public function test_oversize_original_input_is_refused_before_http_instead_of_truncated(): void
    {
        $router = $this->router(null);
        Http::fake();
        try {
            $router->generateEmbedding(str_repeat('x', 1025), 'http://managed', 16, 10, 'embedding-fixture');
            $this->fail('Managed input was silently shortened.');
        } catch (InferenceAdmissionException) {
            Http::assertNothingSent();
        }
    }

    public function test_managed_invalid_utf8_refuses_before_http_in_both_embedding_transports(): void
    {
        $router = $this->router(null);
        Http::fake();
        foreach (['ollama', 'openai'] as $wire) {
            try {
                if ($wire === 'ollama') {
                    $router->generateEmbedding("invalid\xff", 'http://managed', null, 10, 'embedding-fixture');
                } else {
                    $router->generateExternalEmbedding("invalid\xff", ['base_url' => 'http://managed/v1', 'embedding_model' => 'embedding-fixture', 'embedding_dimensions' => 3]);
                }
                $this->fail('Invalid input was silently normalized.');
            } catch (InferenceAdmissionException) {
                $this->addToAssertionCount(1);
            }
        }
        Http::assertNothingSent();
    }

    public function test_managed_openai_embedding_uses_same_vector_and_custody_boundary(): void
    {
        $router = $this->router(true);
        Http::fake(['managed/v1/embeddings' => Http::response(['data' => [['embedding' => [1, 2, 3]]]])]);
        $this->assertSame([1, 2, 3], $router->generateExternalEmbedding('original bytes', ['base_url' => 'http://managed/v1',
            'embedding_model' => 'embedding-fixture', 'embedding_dimensions' => 3]));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['input'] === 'original bytes' && $request['dimensions'] === 3);
    }

    private function router(?bool $completed): AIRouter
    {
        $contract = ['model' => 'embedding-fixture', 'wire_api' => 'ollama_embeddings', 'context_window' => 2048,
            'dimensions' => 3, 'maximum_input_bytes' => 1024, 'ollama_options' => ['num_ctx' => 2048, 'num_gpu' => 0, 'num_thread' => 4]];
        $resources = Mockery::mock(ModelHostControllerResourcePolicyService::class);
        $resources->shouldReceive('managedHostForEndpoint')->andReturn('.87');
        $resources->shouldReceive('managedProvider')->andReturn((object) ['config' => json_encode([
            'controller_workloads' => ['ollama_embeddings' => ['embedding-fixture' => ['contract' => $contract]]]])]);
        app()->instance(ModelHostControllerResourcePolicyService::class, $resources);
        $selection = Mockery::mock(ModelHostControllerAutomaticSelectionService::class);
        if ($completed === null) {
            $selection->shouldNotReceive('dispatchExact');
            $selection->shouldNotReceive('finishExact');
        } else {
            $selection->shouldReceive('dispatchExact')->once()->andReturnUsing(function (array &$scope): void {
                $scope['dispatched'] = true;
            });
            $selection->shouldReceive('renewExact')->zeroOrMoreTimes();
            $selection->shouldReceive('finishExact')->once()->with(Mockery::any(), $completed);
        }
        $supervisor = new class($selection, $resources, Mockery::mock(ModelHostControllerWaitService::class)) extends ModelHostControllerTransportSupervisor
        {
            protected function open(string $wire, string $endpoint, array $payload, array $context = []): array
            {
                return ['dispatched' => false, 'request' => ['request_contract' => ['dimensions' => 3]]];
            }
        };
        app()->instance(ModelHostControllerTransportSupervisor::class, $supervisor);
        $router = (new ReflectionClass(AIRouter::class))->newInstanceWithoutConstructor();
        foreach (['embeddingCharLimit' => 16, 'embeddingModel' => 'embedding-fixture', 'ollamaUrl' => 'http://managed'] as $key => $value) {
            (new ReflectionProperty(AIRouter::class, $key))->setValue($router, $value);
        }

        return $router;
    }
}
