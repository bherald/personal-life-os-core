<?php

namespace Tests\Unit\Services;

use App\Services\AIService;
use App\Services\ResearchService;
use App\Services\SearXNGService;
use App\Services\WebResearchService;
use Illuminate\Support\Facades\Concurrency;
use Mockery;
use Tests\TestCase;

class ResearchOutcomePropagationTest extends TestCase
{
    private function invoke(string $class, string $method, array $args, array $properties = []): mixed
    {
        $service = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        foreach ($properties as $key => $value) {
            (new \ReflectionProperty($class, $key))->setValue($service, $value);
        }

        return (new \ReflectionMethod($class, $method))->invokeArgs($service, $args);
    }

    private function degradedSearch(): array
    {
        return ['success' => true, 'degraded' => true,
            'engine_health' => ['unresponsive_engine_count' => 1, 'reason_codes' => ['parser_error']],
            'engine_summary' => ['unique_engine_count' => 1],
            'results' => [['url' => 'https://example.org/report', 'title' => 'Report', 'snippet' => 'Source evidence', 'engines' => ['bing']]]];
    }

    public function test_searxng_wrapper_retains_partial_engine_failures_and_per_result_provenance(): void
    {
        $raw = $this->degradedSearch();
        $searx = Mockery::mock(SearXNGService::class);
        $searx->shouldReceive('isAvailable')->andReturnTrue();
        $searx->shouldReceive('search')->once()->andReturn($raw);
        $this->app->instance(SearXNGService::class, $searx);
        $result = $this->invoke(WebResearchService::class, 'searchWithSearXNG', ['test', 10]);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['degraded']);
        $this->assertSame($raw['engine_health'], $result['engine_health']);
        $this->assertSame(['bing'], $result['results'][0]['engines']);
    }

    public function test_empty_engine_failure_retains_its_reason(): void
    {
        $raw = [...$this->degradedSearch(), 'success' => false, 'results' => [], 'failure_reason' => 'unresponsive_engines_no_results'];
        $searx = Mockery::mock(SearXNGService::class);
        $searx->shouldReceive('isAvailable')->andReturnTrue();
        $searx->shouldReceive('search')->once()->andReturn($raw);
        $this->app->instance(SearXNGService::class, $searx);
        $result = $this->invoke(WebResearchService::class, 'searchWithSearXNG', ['test', 10]);
        $this->assertFalse($result['success']);
        $this->assertFalse($result['execution_success']);
        $this->assertSame($raw['failure_reason'], $result['failure_reason']);
        $this->assertSame($raw['engine_health'], $result['engine_health']);
    }

    public function test_parallel_search_reports_partial_coverage_with_usable_results(): void
    {
        Concurrency::shouldReceive('run')->once()->andReturn([
            'searxng' => $this->degradedSearch(),
            'wikipedia' => ['success' => false, 'error' => 'unavailable', 'results' => []],
        ]);
        $result = $this->invoke(WebResearchService::class, 'parallelSearch', ['test']);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['degraded']);
        $this->assertCount(1, $result['results']);
        $this->assertSame(['parser_error'], $result['source_health']['searxng']['engine_health']['reason_codes']);
        $this->assertFalse($result['source_health']['wikipedia']['success']);
    }

    public function test_research_source_and_aggregation_preserve_failure_metadata(): void
    {
        $web = Mockery::mock(WebResearchService::class);
        $web->shouldReceive('research')->once()->andReturn($this->degradedSearch());
        $source = $this->invoke(ResearchService::class, 'searchSource', ['searxng', 'test', 10], ['webResearchService' => $web]);
        $result = $this->invoke(ResearchService::class, 'aggregateResults', [[
            'searxng' => $source,
            'failed_without_error' => ['success' => false, 'results' => [['url' => 'https://example.org/failed', 'title' => 'Failed payload']]],
            'empty_success' => ['success' => true, 'results' => []],
            'unknown' => ['results' => []],
        ]]);
        $this->assertTrue($result['degraded']);
        $stats = $result['source_stats'];
        $this->assertTrue($stats['searxng']['success']);
        $this->assertSame(['parser_error'], $stats['searxng']['engine_health']['reason_codes']);
        $this->assertFalse($stats['failed_without_error']['success']);
        $this->assertTrue($stats['empty_success']['success']);
        $this->assertFalse($stats['empty_success']['has_results']);
        $this->assertFalse($stats['unknown']['success']);
        $this->assertNotContains('https://example.org/failed', array_column($result['articles'], 'url'));
    }

    public function test_synthesis_is_told_when_search_coverage_is_reduced(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('process')->once()->withArgs(fn ($prompt, $options) => str_contains($prompt, 'Search coverage is reduced'))
            ->andReturn(['success' => true, 'response' => 'Limited coverage.']);
        $result = $this->invoke(ResearchService::class, 'analyzeWithAI', ['test',
            ['degraded' => true, 'articles' => [['title' => 'Report', 'source_name' => 'Example', 'description' => 'Evidence']]]], ['aiService' => $ai]);
        $this->assertSame('Limited coverage.', $result);
    }

    public function test_empty_successful_search_is_distinguished_from_execution_failure(): void
    {
        $searx = Mockery::mock(SearXNGService::class);
        $searx->shouldReceive('isAvailable')->andReturnTrue();
        $searx->shouldReceive('search')->once()->andReturn(['success' => true, 'results' => [], 'degraded' => false]);
        $this->app->instance(SearXNGService::class, $searx);
        $result = $this->invoke(WebResearchService::class, 'searchWithSearXNG', ['test', 10]);
        $this->assertFalse($result['success']);
        $this->assertTrue($result['execution_success']);
        $this->assertSame('no_results', $result['failure_reason']);
        $this->assertFalse($result['degraded']);
    }
}
