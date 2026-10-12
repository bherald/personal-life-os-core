<?php

namespace Tests\Unit\Engine;

use App\Engine\MCPRouter;
use App\Services\RAGService;
use Mockery;
use ReflectionMethod;
use stdClass;
use Tests\TestCase;

class MCPRouterHierarchicalRagToolTest extends TestCase
{
    public function test_rag_catalog_exposes_exact_read_contract(): void
    {
        $router = app(MCPRouter::class);
        $method = new ReflectionMethod($router, 'discoverInternalTools');
        $method->setAccessible(true);
        $tools = $method->invoke($router, 'rag', config('mcp.servers.rag'));

        $this->assertCount(4, $tools);
        $read = collect($tools)->firstWhere('name', 'rag_read');
        $this->assertNotNull($read);
        $this->assertSame(['document_id'], $read['parameters']['required']);
        $this->assertSame(1, $read['parameters']['properties']->document_id['minimum']);
        $this->assertSame(0, $read['parameters']['properties']->offset['minimum']);
        $this->assertSame(20000, $read['parameters']['properties']->max_chars['maximum']);
    }

    public function test_search_result_exposes_stable_identity_and_unicode_safe_preview(): void
    {
        $document = new stdClass;
        $document->id = 73;
        $document->title = 'Evidence';
        $document->document_type = 'note';
        $document->content = str_repeat('β', 1501);

        $router = app(MCPRouter::class);
        $method = new ReflectionMethod($router, 'formatRAGSearchResults');
        $method->setAccessible(true);
        $result = $method->invoke($router, [[
            'document' => $document,
            'similarity' => 0.876,
        ]]);

        $formatted = $result['documents'][0];
        $this->assertSame('RAG-1', $formatted['ref']);
        $this->assertSame(73, $formatted['document_id']);
        $this->assertSame('RAG-DOC-73', $formatted['document_ref']);
        $this->assertSame('87.6%', $formatted['relevance']);
        $this->assertStringEndsWith('... [truncated]', $formatted['content']);
        $this->assertSame(1515, mb_strlen($formatted['content'], 'UTF-8'));
    }

    public function test_rag_read_dispatches_exact_window_parameters(): void
    {
        $service = Mockery::mock(RAGService::class);
        $service->shouldReceive('readDocumentWindow')
            ->once()
            ->with(73, 1500, 5000)
            ->andReturn([
                'document_id' => 73,
                'document_ref' => 'RAG-DOC-73',
                'offset' => 1500,
                'end_offset' => 6500,
                'total_chars' => 9000,
                'has_more' => true,
                'next_offset' => 6500,
                'content' => 'exact window',
            ]);
        $this->app->instance(RAGService::class, $service);

        $router = app(MCPRouter::class);
        $method = new ReflectionMethod($router, 'callRAGTool');
        $method->setAccessible(true);
        $result = $method->invoke($router, 'rag_read', [
            'document_id' => 73,
            'offset' => 1500,
            'max_chars' => 5000,
        ]);

        $this->assertTrue($result['found']);
        $this->assertSame('exact window', $result['document']['content']);
        $this->assertSame(6500, $result['document']['next_offset']);
    }
}
