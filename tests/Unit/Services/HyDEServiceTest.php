<?php

namespace Tests\Unit\Services;

use App\Services\AIService;
use App\Services\HyDEService;
use App\Services\SystemConfigService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class HyDEServiceTest extends TestCase
{
    private HyDEService $hydeService;

    private $mockAiService;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = Mockery::mock(SystemConfigService::class);
        $settings->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $default);
        $this->app->instance(SystemConfigService::class, $settings);

        $this->mockAiService = Mockery::mock(AIService::class);
        $this->hydeService = new HyDEService($this->mockAiService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ========================================
    // shouldUseHyde Tests
    // ========================================

    public function test_should_use_hyde_returns_true_for_what_questions(): void
    {
        $this->assertTrue($this->hydeService->shouldUseHyde('what is machine learning'));
        $this->assertTrue($this->hydeService->shouldUseHyde('What are the benefits of exercise'));
    }

    public function test_should_use_hyde_returns_true_for_why_questions(): void
    {
        $this->assertTrue($this->hydeService->shouldUseHyde('why does the sky appear blue'));
        $this->assertTrue($this->hydeService->shouldUseHyde('Why is Python popular'));
    }

    public function test_should_use_hyde_returns_true_for_how_questions(): void
    {
        $this->assertTrue($this->hydeService->shouldUseHyde('how does photosynthesis work'));
        $this->assertTrue($this->hydeService->shouldUseHyde('How to implement a binary search'));
    }

    public function test_should_use_hyde_returns_true_for_explain_questions(): void
    {
        $this->assertTrue($this->hydeService->shouldUseHyde('explain the theory of relativity'));
        $this->assertTrue($this->hydeService->shouldUseHyde('Explain how neural networks learn'));
    }

    public function test_should_use_hyde_returns_true_for_describe_questions(): void
    {
        $this->assertTrue($this->hydeService->shouldUseHyde('describe the process of mitosis'));
    }

    public function test_should_use_hyde_returns_true_for_compare_questions(): void
    {
        $this->assertTrue($this->hydeService->shouldUseHyde('compare SQL and NoSQL databases'));
    }

    public function test_should_use_hyde_returns_true_for_when_questions(): void
    {
        $this->assertTrue($this->hydeService->shouldUseHyde('when did World War II end'));
    }

    public function test_should_use_hyde_returns_true_for_where_questions(): void
    {
        $this->assertTrue($this->hydeService->shouldUseHyde('where is the Great Wall of China located'));
    }

    public function test_should_use_hyde_returns_true_for_which_questions(): void
    {
        $this->assertTrue($this->hydeService->shouldUseHyde('which programming language is best for beginners'));
    }

    public function test_should_use_hyde_returns_true_for_long_queries_with_five_plus_words(): void
    {
        // 5 words, length >= 20
        $this->assertTrue($this->hydeService->shouldUseHyde('best practices for database indexing optimization'));
        $this->assertTrue($this->hydeService->shouldUseHyde('machine learning algorithms for image recognition'));
    }

    public function test_should_use_hyde_returns_false_for_identifiers(): void
    {
        $this->assertFalse($this->hydeService->shouldUseHyde('ABC-123'));
        $this->assertFalse($this->hydeService->shouldUseHyde('Find ticket PROJ-456'));
        $this->assertFalse($this->hydeService->shouldUseHyde('What about issue BUG-789'));
        $this->assertFalse($this->hydeService->shouldUseHyde('REQ-001 status'));
    }

    public function test_should_use_hyde_returns_false_for_quoted_phrases(): void
    {
        $this->assertFalse($this->hydeService->shouldUseHyde('"exact match phrase"'));
        $this->assertFalse($this->hydeService->shouldUseHyde('find "John Smith" in records'));
        $this->assertFalse($this->hydeService->shouldUseHyde('search for "specific error message"'));
    }

    public function test_should_use_hyde_returns_false_for_very_short_queries(): void
    {
        $this->assertFalse($this->hydeService->shouldUseHyde('test'));
        $this->assertFalse($this->hydeService->shouldUseHyde('PHP'));
        $this->assertFalse($this->hydeService->shouldUseHyde('user login'));
        $this->assertFalse($this->hydeService->shouldUseHyde('api key'));
    }

    public function test_should_use_hyde_returns_false_for_short_non_question_queries(): void
    {
        // Less than 20 chars and not a question word
        $this->assertFalse($this->hydeService->shouldUseHyde('database'));
        $this->assertFalse($this->hydeService->shouldUseHyde('laravel vue'));
    }

    public function test_should_use_hyde_handles_whitespace(): void
    {
        $this->assertTrue($this->hydeService->shouldUseHyde('  what is machine learning  '));
        $this->assertFalse($this->hydeService->shouldUseHyde('  ABC-123  '));
    }

    public function test_should_use_hyde_case_insensitive_for_question_words(): void
    {
        $this->assertTrue($this->hydeService->shouldUseHyde('WHAT is this'));
        $this->assertTrue($this->hydeService->shouldUseHyde('WHY does this work'));
        $this->assertTrue($this->hydeService->shouldUseHyde('How Does This Work'));
    }

    // ========================================
    // generateHypotheticalDocument Tests
    // ========================================

    public function test_generate_hypothetical_document_returns_string_on_success(): void
    {
        $query = 'what is machine learning';
        $expectedResponse = 'Machine learning is a subset of artificial intelligence that enables systems to learn and improve from experience without being explicitly programmed.';

        $this->mockAiService->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => $expectedResponse,
            ]);

        $result = $this->hydeService->generateHypotheticalDocument($query);

        $this->assertEquals($expectedResponse, $result);
    }

    public function test_generate_hypothetical_document_trims_whitespace(): void
    {
        $query = 'test query';

        $this->mockAiService->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => '  response with whitespace  ',
            ]);

        $result = $this->hydeService->generateHypotheticalDocument($query);

        $this->assertEquals('response with whitespace', $result);
    }

    public function test_generate_hypothetical_document_returns_empty_on_ai_failure(): void
    {
        $query = 'test query';

        $this->mockAiService->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'AI service unavailable',
            ]);

        $result = $this->hydeService->generateHypotheticalDocument($query);

        $this->assertEquals('', $result);
    }

    public function test_generate_hypothetical_document_returns_empty_on_null_response(): void
    {
        $query = 'test query';

        $this->mockAiService->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => null,
            ]);

        $result = $this->hydeService->generateHypotheticalDocument($query);

        $this->assertEquals('', $result);
    }

    public function test_generate_hypothetical_document_passes_correct_options_to_ai(): void
    {
        $query = 'test query';
        $capturedPrompt = null;
        $capturedOptions = null;

        $this->mockAiService->shouldReceive('process')
            ->once()
            ->withArgs(function ($prompt, $options) use (&$capturedPrompt, &$capturedOptions) {
                $capturedPrompt = $prompt;
                $capturedOptions = $options;

                return true;
            })
            ->andReturn([
                'success' => true,
                'response' => 'test response',
            ]);

        $this->hydeService->generateHypotheticalDocument($query);

        $this->assertStringContainsString($query, $capturedPrompt);
        $this->assertEquals(0.3, $capturedOptions['temperature']);
        $this->assertEquals(300, $capturedOptions['max_tokens']);
        $this->assertTrue($capturedOptions['suppressAlert']);
    }

    // ========================================
    // search Tests
    // ========================================

    public function test_search_uses_hypothetical_document_embedding(): void
    {
        $query = 'what is machine learning';
        $hypotheticalDoc = 'Machine learning is an AI technique...';
        $embedding = array_fill(0, 768, 0.1);

        // Mock generateHypotheticalDocument (via process)
        $this->mockAiService->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => $hypotheticalDoc,
            ]);

        // Mock embedding generation for hypothetical document
        $this->mockAiService->shouldReceive('generateEmbedding')
            ->once()
            ->with($hypotheticalDoc)
            ->andReturn([
                'success' => true,
                'embedding' => $embedding,
            ]);

        // Mock database connection
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->andReturn([
                (object) [
                    'id' => 1,
                    'content' => 'Test document',
                    'similarity' => 0.95,
                    'distance' => 0.05,
                ],
            ]);

        $results = $this->hydeService->search($query, 5);

        $this->assertCount(1, $results);
        $this->assertEquals(0.95, $results[0]['similarity']);
    }

    public function test_search_falls_back_to_original_query_on_generation_failure(): void
    {
        $query = 'what is machine learning';
        $embedding = array_fill(0, 768, 0.1);

        // Mock failed hypothetical document generation
        $this->mockAiService->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'AI unavailable',
            ]);

        // Should embed original query as fallback
        $this->mockAiService->shouldReceive('generateEmbedding')
            ->once()
            ->with($query)
            ->andReturn([
                'success' => true,
                'embedding' => $embedding,
            ]);

        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->andReturn([
                (object) [
                    'id' => 1,
                    'content' => 'Test document',
                    'similarity' => 0.85,
                    'distance' => 0.15,
                ],
            ]);

        $results = $this->hydeService->search($query, 5);

        $this->assertCount(1, $results);
    }

    public function test_search_falls_back_when_hypothetical_document_is_empty(): void
    {
        $query = 'test query';
        $embedding = array_fill(0, 768, 0.1);

        // Mock empty hypothetical document
        $this->mockAiService->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => '',
            ]);

        // Should embed original query as fallback
        $this->mockAiService->shouldReceive('generateEmbedding')
            ->once()
            ->with($query)
            ->andReturn([
                'success' => true,
                'embedding' => $embedding,
            ]);

        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->andReturn([]);

        $results = $this->hydeService->search($query, 5);

        $this->assertCount(0, $results);
    }

    public function test_search_throws_exception_on_embedding_failure(): void
    {
        $query = 'test query';

        $this->mockAiService->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => 'hypothetical document',
            ]);

        $this->mockAiService->shouldReceive('generateEmbedding')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'Embedding service unavailable',
            ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('HyDE embedding failed');

        $this->hydeService->search($query, 5);
    }

    public function test_search_filters_by_document_type(): void
    {
        $query = 'test query';
        $documentType = 'youtube_transcript';
        $embedding = array_fill(0, 768, 0.1);

        $this->mockAiService->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => 'hypothetical document',
            ]);

        $this->mockAiService->shouldReceive('generateEmbedding')
            ->once()
            ->andReturn([
                'success' => true,
                'embedding' => $embedding,
            ]);

        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($sql, $params) use ($documentType) {
                return str_contains($sql, 'WHERE document_type = ?')
                    && $params[0] === $documentType;
            })
            ->andReturn([]);

        $results = $this->hydeService->search($query, 5, $documentType);

        $this->assertCount(0, $results);
    }

    public function test_search_respects_k_parameter(): void
    {
        $query = 'test query';
        $k = 10;
        $embedding = array_fill(0, 768, 0.1);
        $capturedParams = null;

        $this->mockAiService->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => 'hypothetical document',
            ]);

        $this->mockAiService->shouldReceive('generateEmbedding')
            ->once()
            ->andReturn([
                'success' => true,
                'embedding' => $embedding,
            ]);

        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($sql, $params) use (&$capturedParams) {
                $capturedParams = $params;

                return true;
            })
            ->andReturn([]);

        $this->hydeService->search($query, $k);

        // k should be the last param when no document_type
        $this->assertEquals($k, end($capturedParams));
    }

    public function test_search_returns_correct_result_structure(): void
    {
        $query = 'test query';
        $embedding = array_fill(0, 768, 0.1);

        $this->mockAiService->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => 'hypothetical document',
            ]);

        $this->mockAiService->shouldReceive('generateEmbedding')
            ->once()
            ->andReturn([
                'success' => true,
                'embedding' => $embedding,
            ]);

        $mockDoc = (object) [
            'id' => 1,
            'content' => 'Test document content',
            'document_type' => 'article',
            'similarity' => 0.92,
            'distance' => 0.08,
        ];

        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->andReturn([$mockDoc]);

        $results = $this->hydeService->search($query, 5);

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('document', $results[0]);
        $this->assertArrayHasKey('similarity', $results[0]);
        $this->assertEquals($mockDoc, $results[0]['document']);
        $this->assertIsFloat($results[0]['similarity']);
        $this->assertEquals(0.92, $results[0]['similarity']);
    }
}
