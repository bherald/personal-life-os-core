<?php

namespace Tests\Unit\Services;

use App\Services\AIService;
use App\Services\RAGService;
use App\Services\RAGTracingService;
use App\Services\SystemConfigService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class RAGSearchTracingIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $settings = Mockery::mock(SystemConfigService::class);
        $settings->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $default);
        $this->app->instance(SystemConfigService::class, $settings);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_successful_search_measures_before_writing_one_complete_trace(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateEmbedding')
            ->once()
            ->with('four word private query')
            ->andReturn(['success' => true, 'embedding' => [0.1, 0.2]]);

        $document = (object) ['id' => 7, 'similarity' => 0.9, 'created_at' => now()->toDateTimeString()];
        $pgsql = Mockery::mock();
        $pgsql->shouldReceive('select')->once()->andReturn([$document]);
        DB::shouldReceive('connection')->once()->with('pgsql_rag')->andReturn($pgsql);

        $tracing = $this->tracingMock();
        $tracing->shouldReceive('recordSearch')
            ->once()
            ->withArgs(fn (...$args): bool => $args[0] === 'four word private query'
                && $args[1] === 'vector'
                && isset($args[2]['embedding'], $args[2]['vector'])
                && $args[4] === 1
                && $args[10] === 'success');

        $service = new RAGService($ai);
        $this->setPrivate($service, 'tracingService', $tracing);

        $results = $service->search('four word private query', 1);

        $this->assertCount(1, $results);
        $this->assertSame(0.9, $results[0]['similarity']);
    }

    public function test_embedding_failure_writes_failed_trace_without_breaking_exception_contract(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateEmbedding')
            ->once()
            ->andReturn(['success' => false, 'error' => 'private provider error']);

        $tracing = $this->tracingMock();
        $tracing->shouldReceive('recordSearch')
            ->once()
            ->withArgs(fn (...$args): bool => $args[1] === 'error_embedding'
                && isset($args[2]['embedding'])
                && $args[4] === 0
                && $args[10] === 'failure'
                && $args[11] === 'embedding');

        $service = new RAGService($ai);
        $this->setPrivate($service, 'tracingService', $tracing);

        $this->expectException(\Exception::class);
        $service->search('private failed query');
    }

    private function tracingMock(): RAGTracingService
    {
        $tracing = Mockery::mock(RAGTracingService::class)->makePartial();
        $tracing->shouldReceive('measure')
            ->andReturnUsing(function (string $phase, callable $callback, array &$phases): mixed {
                try {
                    return $callback();
                } finally {
                    $phases[$phase] = 1;
                }
            });

        return $tracing;
    }

    private function setPrivate(object $object, string $property, object $value): void
    {
        $property = new \ReflectionProperty($object, $property);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
