<?php

namespace Tests\Unit\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\AIService;
use App\Services\KnowledgeGraphService;
use App\Services\SpacyNLPService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\UsesLlmRoutingBindings;
use Tests\TestCase;

/**
 * Unit tests for KnowledgeGraphService
 *
 * Tests knowledge graph functionality including:
 * - Entity extraction from text
 * - Triple management (add, find, delete)
 * - Graph traversal
 * - Entity merging
 * - Relationship search
 * - Statistics
 */
class KnowledgeGraphServiceTest extends TestCase
{
    use UsesLlmRoutingBindings;

    private KnowledgeGraphService $service;

    private $mockAIService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockAIService = Mockery::mock(AIService::class);
        $this->service = new KnowledgeGraphService($this->mockAIService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_scheduled_batch_refuses_oversized_source_before_dispatch(): void
    {
        $this->mockAIService->shouldNotReceive('process');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('source text was not shortened');
        $this->service->extractEntities(str_repeat('critical source detail. ', 1000), [
            'skip_recursive' => true, 'scheduled_batch' => true, 'persist' => false,
        ]);
    }

    // =========================================================================
    // extractEntities Tests
    // =========================================================================

    public function test_extract_entities_returns_entities_and_relationships(): void
    {
        $this->mockAIService
            ->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => json_encode([
                    'entities' => [
                        ['name' => 'Apple', 'type' => 'organization', 'aliases' => ['Apple Inc.'], 'properties' => []],
                        ['name' => 'Tim Cook', 'type' => 'person', 'aliases' => [], 'properties' => []],
                    ],
                    'relationships' => [
                        [
                            'subject' => 'Tim Cook',
                            'subject_type' => 'person',
                            'predicate' => 'works_at',
                            'object' => 'Apple',
                            'object_type' => 'organization',
                            'confidence' => 0.95,
                            'source_snippet' => 'Tim Cook is the CEO of Apple',
                        ],
                    ],
                ]),
            ]);

        $result = $this->service->extractEntities('Tim Cook is the CEO of Apple.', ['persist' => false]);

        $this->assertTrue($result['success'], $result['error'] ?? json_encode($result));
        $this->assertCount(2, $result['entities']);
        $this->assertCount(1, $result['relationships']);
        $this->assertEquals('Tim Cook', $result['relationships'][0]['subject']);
        $this->assertEquals('works_at', $result['relationships'][0]['predicate']);
    }

    public function test_spacy_typed_admission_stop_rethrows_original_wrapper_without_llm_fallback(): void
    {
        $admission = new InferenceAdmissionException('synthetic terminal admission stop');
        $wrapped = new \RuntimeException('ordinary wrapper', 0, $admission);
        $spacy = Mockery::mock(SpacyNLPService::class);
        $spacy->shouldReceive('isAvailable')->once()->andThrow($wrapped);
        $this->app->instance(SpacyNLPService::class, $spacy);
        $this->mockAIService->shouldNotReceive('process');

        try {
            $this->service->extractEntities('Source text', ['persist' => false, 'skip_recursive' => true]);
            $this->fail('Typed admission stop should escape both fallback catches.');
        } catch (\Throwable $caught) {
            $this->assertSame($wrapped, $caught);
        }
    }

    public function test_ordinary_spacy_exception_keeps_existing_llm_fallback(): void
    {
        $spacy = Mockery::mock(SpacyNLPService::class);
        $spacy->shouldReceive('isAvailable')->once()->andThrow(new \RuntimeException('ordinary spacy failure'));
        $this->app->instance(SpacyNLPService::class, $spacy);
        $this->mockAIService->shouldReceive('process')->once()->andReturn([
            'success' => false,
            'error' => 'LLM fallback was reached',
        ]);

        $result = $this->service->extractEntities('Source text', ['persist' => false, 'skip_recursive' => true]);

        $this->assertFalse($result['success']);
        $this->assertSame('LLM fallback was reached', $result['error']);
    }

    public function test_extract_entities_filters_by_min_confidence(): void
    {
        $this->mockAIService
            ->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => json_encode([
                    'entities' => [],
                    'relationships' => [
                        ['subject' => 'A', 'predicate' => 'related_to', 'object' => 'B', 'confidence' => 0.9],
                        ['subject' => 'C', 'predicate' => 'related_to', 'object' => 'D', 'confidence' => 0.3],
                    ],
                ]),
            ]);

        $result = $this->service->extractEntities('Test text', ['persist' => false, 'min_confidence' => 0.5]);

        $this->assertCount(1, $result['relationships']);
        $this->assertEquals('A', $result['relationships'][0]['subject']);
    }

    public function test_extract_entities_returns_error_on_ai_failure(): void
    {
        $this->mockAIService
            ->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => 'AI service unavailable',
            ]);

        $result = $this->service->extractEntities('Test text', ['persist' => false]);

        $this->assertFalse($result['success']);
        $this->assertEquals('AI service unavailable', $result['error']);
    }

    public function test_extract_entities_returns_error_on_invalid_json(): void
    {
        // With retry logic, process is called twice when first parse fails
        $this->mockAIService
            ->shouldReceive('process')
            ->twice()
            ->andReturn([
                'success' => true,
                'response' => 'This is not valid JSON',
            ]);

        $result = $this->service->extractEntities('Test text', ['persist' => false]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Failed to parse', $result['error']);
    }

    public function test_scheduled_batch_degrades_on_invalid_json(): void
    {
        $spacy = Mockery::mock(SpacyNLPService::class);
        $spacy->shouldReceive('isAvailable')->once()->andReturn(false);
        $this->app->instance(SpacyNLPService::class, $spacy);

        $this->mockAIService
            ->shouldReceive('process')
            ->twice()
            ->andReturn([
                'success' => true,
                'response' => '',
            ]);

        $result = $this->service->extractEntities('Test text', [
            'persist' => false,
            'skip_recursive' => true,
            'scheduled_batch' => true,
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? json_encode($result));
        $this->assertTrue($result['degraded']);
        $this->assertSame([], $result['entities']);
        $this->assertSame([], $result['relationships']);
    }

    public function test_scheduled_batch_retries_once_before_degrading(): void
    {
        $spacy = Mockery::mock(SpacyNLPService::class);
        $spacy->shouldReceive('isAvailable')->once()->andReturn(false);
        $this->app->instance(SpacyNLPService::class, $spacy);

        $this->mockAIService
            ->shouldReceive('process')
            ->twice()
            ->andReturn(
                [
                    'success' => true,
                    'response' => '',
                ],
                [
                    'success' => true,
                    'response' => json_encode([
                        'entities' => [
                            ['name' => 'Tim Cook', 'type' => 'person'],
                            ['name' => 'Apple', 'type' => 'organization'],
                        ],
                        'relationships' => [
                            [
                                'subject' => 'Tim Cook',
                                'subject_type' => 'person',
                                'predicate' => 'works_at',
                                'object' => 'Apple',
                                'object_type' => 'organization',
                                'confidence' => 0.95,
                                'source_snippet' => 'Tim Cook leads Apple',
                                'valid_from' => null,
                                'valid_until' => null,
                                'temporal_type' => 'unknown',
                            ],
                        ],
                    ]),
                ]
            );

        $result = $this->service->extractEntities('Tim Cook leads Apple.', [
            'persist' => false,
            'skip_recursive' => true,
            'scheduled_batch' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['degraded'] ?? false);
        $this->assertCount(2, $result['entities']);
        $this->assertCount(1, $result['relationships']);
    }

    public function test_scheduled_batch_uses_fast_local_model_settings(): void
    {
        $spacy = Mockery::mock(SpacyNLPService::class);
        $spacy->shouldReceive('isAvailable')->once()->andReturn(false);
        $this->app->instance(SpacyNLPService::class, $spacy);

        $this->mockAIService
            ->shouldReceive('process')
            ->once()
            ->withArgs(function (string $prompt, array $config): bool {
                return ($config['model_role'] ?? null) === 'fast'
                    && ($config['prefer_external'] ?? null) === false
                    && ($config['factual_mode'] ?? null) === true
                    && ($config['skip_if_busy'] ?? null) === false
                    && ($config['ollama_lock_wait_seconds'] ?? null) === 15
                    && ($config['allowed_ollama_instance_ids'] ?? null) === ['ollama_primary'];
            })
            ->andReturn([
                'success' => true,
                'response' => json_encode([
                    'entities' => [],
                    'relationships' => [],
                ]),
            ]);

        $result = $this->service->extractEntities('Local batch run', [
            'persist' => false,
            'skip_recursive' => true,
            'scheduled_batch' => true,
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_managed_secondary_batch_is_exact_and_disables_fallback(): void
    {
        $spacy = Mockery::mock(SpacyNLPService::class);
        $spacy->shouldReceive('isAvailable')->once()->andReturn(false);
        $this->app->instance(SpacyNLPService::class, $spacy);

        $this->mockAIService
            ->shouldReceive('process')
            ->once()
            ->withArgs(function (string $prompt, array $config): bool {
                return ($config['model'] ?? null) === 'qwen3:8b'
                    && ($config['model_override'] ?? null) === 'qwen3:8b'
                    && ($config['model_role'] ?? null) === 'standard'
                    && ($config['allowed_ollama_instance_ids'] ?? null) === ['ollama_secondary']
                    && ($config['preferred_ollama_instance_ids'] ?? null) === ['ollama_secondary']
                    && ($config['ollama_required_no_fallback'] ?? null) === true
                    && ($config['disable_cascade'] ?? null) === true
                    && ($config['use_cache'] ?? null) === false
                    && ($config['dedup'] ?? null) === false
                    && ($config['_skip_decompose'] ?? null) === true;
            })
            ->andReturn([
                'success' => true,
                'provider' => 'ollama_secondary',
                'model' => 'qwen3:8b',
                'response' => '{"entities":[],"relationships":[]}',
            ]);

        $result = $this->service->extractEntities('Managed batch run', [
            'persist' => false,
            'skip_recursive' => true,
            'scheduled_batch' => true,
            'preferred_instance' => 'secondary',
            'model_role_override' => 'standard',
            'managed_model_host' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['relationships']);
    }

    public function test_managed_batch_rejects_a_role_different_from_the_table_binding(): void
    {
        $this->mockAIService->shouldReceive('process')->never();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('role differs from its registered binding');
        $this->service->extractEntities('Complete source is preserved.', [
            'skip_recursive' => true, 'persist' => false, 'scheduled_batch' => true,
            'preferred_instance' => 'secondary', 'managed_model_host' => true,
            'model_role_override' => 'fast',
        ]);
    }

    public function test_managed_secondary_batch_rejects_wrong_provider_without_spacy_degradation(): void
    {
        $spacy = Mockery::mock(SpacyNLPService::class);
        $spacy->shouldReceive('isAvailable')->once()->andReturn(false);
        $this->app->instance(SpacyNLPService::class, $spacy);

        $this->mockAIService->shouldReceive('process')->once()->andReturn([
            'success' => true,
            'provider' => 'ollama_primary',
            'model' => 'qwen3:8b',
            'response' => '{"entities":[],"relationships":[]}',
        ]);

        $result = $this->service->extractEntities('Managed batch run', [
            'persist' => false,
            'skip_recursive' => true,
            'scheduled_batch' => true,
            'preferred_instance' => 'secondary',
            'managed_model_host' => true,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame([], $result['entities']);
        $this->assertSame([], $result['relationships']);
    }

    public function test_parse_diagnostics_hash_model_output_without_logging_a_preview(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/KnowledgeGraphService.php'));

        $this->assertStringNotContainsString("'response_preview'", $source);
        $this->assertSame(2, substr_count($source, "'response_sha256' => hash('sha256', \$response)"));
        $this->assertStringContainsString("'response_length' => strlen(\$response)", $source);
    }

    public function test_scheduled_batch_drops_malformed_graph_lists_before_limits(): void
    {
        $spacy = Mockery::mock(SpacyNLPService::class);
        $spacy->shouldReceive('isAvailable')->once()->andReturn(false);
        $this->app->instance(SpacyNLPService::class, $spacy);

        $this->mockAIService
            ->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => json_encode([
                    'entities' => 'none',
                    'relationships' => 'none',
                ]),
            ]);

        $result = $this->service->extractEntities('Local batch run', [
            'persist' => false,
            'skip_recursive' => true,
            'scheduled_batch' => true,
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? json_encode($result));
        $this->assertSame([], $result['entities']);
        $this->assertSame([], $result['relationships']);
        $this->assertTrue($result['degraded']);
    }

    public function test_extract_entities_wraps_single_graph_items(): void
    {
        $this->mockAIService
            ->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => json_encode([
                    'entities' => ['name' => 'Alice', 'type' => 'person'],
                    'relationships' => [
                        'subject' => 'Alice',
                        'subject_type' => 'person',
                        'predicate' => 'mentioned_in',
                        'object' => 'Document',
                        'object_type' => 'document',
                        'confidence' => 0.9,
                    ],
                ]),
            ]);

        $result = $this->service->extractEntities('Alice appears in the document.', [
            'persist' => false,
            'skip_recursive' => true,
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? json_encode($result));
        $this->assertCount(1, $result['entities']);
        $this->assertSame('Alice', $result['entities'][0]['name']);
        $this->assertCount(1, $result['relationships']);
        $this->assertSame('mentioned_in', $result['relationships'][0]['predicate']);
    }

    public function test_extract_entities_coerces_malformed_relationship_confidence(): void
    {
        $this->mockAIService
            ->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => json_encode([
                    'entities' => [],
                    'relationships' => [
                        [
                            'subject' => 'Alice',
                            'predicate' => 'related_to',
                            'object' => 'Bob',
                            'confidence' => 'float',
                        ],
                        [
                            'subject' => 'Carol',
                            'predicate' => 'related_to',
                            'object' => 'Dave',
                            'confidence' => '0.91',
                        ],
                    ],
                ]),
            ]);

        $result = $this->service->extractEntities('Alice and Carol relationships.', [
            'persist' => false,
            'skip_recursive' => true,
            'min_confidence' => 0.5,
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? json_encode($result));
        $this->assertCount(2, $result['relationships']);
        $this->assertSame(0.8, $result['relationships'][0]['confidence']);
        $this->assertSame(0.91, $result['relationships'][1]['confidence']);
    }

    public function test_extract_entities_parses_markdown_wrapped_json(): void
    {
        $this->mockAIService
            ->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => <<<'TEXT'
```json
{
  "entities": [{"name": "Alice", "type": "person"}],
  "relationships": [{"subject": "Alice", "subject_type": "person", "predicate": "related_to", "object": "Bob", "object_type": "person", "confidence": 0.9, "source_snippet": "Alice and Bob"}]
}
```
TEXT,
            ]);

        $result = $this->service->extractEntities('Alice and Bob', ['persist' => false]);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['entities']);
        $this->assertCount(1, $result['relationships']);
    }

    public function test_extract_entities_parses_json_with_surrounding_prose(): void
    {
        $this->mockAIService
            ->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => <<<'TEXT'
Here is the extracted graph:

{
  "entities": [{"name": "Springfield", "type": "location"}],
  "relationships": []
}

This output is complete.
TEXT,
            ]);

        $result = $this->service->extractEntities('Springfield', ['persist' => false]);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['entities']);
        $this->assertCount(0, $result['relationships']);
    }

    public function test_extract_entities_persists_when_requested(): void
    {
        $this->mockAIService
            ->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => json_encode([
                    'entities' => [
                        ['name' => 'TestEntity', 'type' => 'person', 'aliases' => [], 'properties' => []],
                    ],
                    'relationships' => [
                        [
                            'subject' => 'TestEntity',
                            'subject_type' => 'person',
                            'predicate' => 'lives_in',
                            'object' => 'City',
                            'object_type' => 'location',
                            'confidence' => 0.8,
                            'source_snippet' => 'Test lives in City',
                        ],
                    ],
                ]),
            ]);

        // Entity creation
        DB::shouldReceive('connection')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->andReturnUsing(function ($query) {
                if (str_contains($query, 'INSERT INTO knowledge_graph_entities')) {
                    return [(object) ['id' => 1]];
                }

                if (str_contains($query, 'INSERT INTO knowledge_graph')) {
                    return [(object) ['id' => 2]];
                }

                return [];
            });

        DB::shouldReceive('insert')
            ->once()
            ->withArgs(fn ($query, $params) => str_contains($query, 'knowledge_graph_edge_history') && $params[0] === 2)
            ->andReturn(true);

        DB::shouldReceive('statement')
            ->andReturn(true);

        $result = $this->service->extractEntities('Test text', ['persist' => true]);

        $this->assertTrue($result['success'], $result['error'] ?? json_encode($result));
    }

    public function test_extract_entities_links_relationship_endpoints_missing_from_entity_list(): void
    {
        $this->mockAIService
            ->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => json_encode([
                    'entities' => [
                        ['name' => 'TestEntity', 'type' => 'person', 'aliases' => [], 'properties' => []],
                    ],
                    'relationships' => [[
                        'subject' => 'TestEntity',
                        'subject_type' => 'person',
                        'predicate' => 'lives_in',
                        'object' => 'City',
                        'object_type' => 'location',
                        'confidence' => 0.8,
                        'source_snippet' => 'Test lives in City',
                    ]],
                ]),
            ]);

        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->andReturnUsing(function (string $query, array $params = []) {
                if (str_contains($query, 'SELECT id FROM knowledge_graph_entities')) {
                    return [];
                }

                if (str_contains($query, 'INSERT INTO knowledge_graph_entities')) {
                    $ids = [
                        'TestEntity' => 10,
                        'City' => 20,
                    ];

                    return [(object) ['id' => $ids[$params[0]] ?? 99]];
                }

                if (str_contains($query, 'INSERT INTO knowledge_graph')) {
                    $this->assertSame(10, $params[3]);
                    $this->assertSame(20, $params[7]);

                    return [(object) ['id' => 30]];
                }

                if (str_contains($query, 'LOWER(subject) = LOWER(?)')) {
                    return [];
                }

                return [];
            });

        DB::shouldReceive('insert')
            ->once()
            ->withArgs(fn ($query, $params) => str_contains($query, 'knowledge_graph_edge_history') && $params[0] === 30)
            ->andReturn(true);

        $result = $this->service->extractEntities('Test text', [
            'persist' => true,
            'skip_recursive' => true,
        ]);

        $this->assertTrue($result['success'], $result['error'] ?? json_encode($result));
        $this->assertSame([10, 20], array_values($result['saved_entity_ids']));
        $this->assertSame([30], $result['saved_triple_ids']);
    }

    // =========================================================================
    // addTriple Tests
    // =========================================================================

    public function test_add_triple_creates_triple_with_metadata(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($query, $params) {
                return str_contains($query, 'INSERT INTO knowledge_graph')
                    && $params[1] === 'John Doe'
                    && $params[2] === 'person'
                    && $params[4] === 'works_at'
                    && $params[5] === 'Acme Corp'
                    && $params[6] === 'organization';
            })
            ->andReturn([(object) ['id' => 42]]);

        DB::shouldReceive('insert')
            ->once()
            ->withArgs(fn ($query, $params) => str_contains($query, 'knowledge_graph_edge_history') && $params[0] === 42)
            ->andReturn(true);

        DB::shouldReceive('select')
            ->once()
            ->withArgs(fn ($query) => str_contains($query, 'LOWER(subject) = LOWER(?)'))
            ->andReturn([]);

        $tripleId = $this->service->addTriple('John Doe', 'works_at', 'Acme Corp', [
            'subject_type' => 'person',
            'object_type' => 'organization',
            'confidence' => 0.9,
        ]);

        $this->assertEquals(42, $tripleId);
    }

    public function test_add_triple_normalizes_predicate(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($query, $params) {
                return $params[4] === 'works_at'; // normalized from "Works At"
            })
            ->andReturn([(object) ['id' => 1]]);

        DB::shouldReceive('insert')
            ->once()
            ->withArgs(fn ($query, $params) => str_contains($query, 'knowledge_graph_edge_history') && $params[0] === 1)
            ->andReturn(true);

        DB::shouldReceive('select')
            ->once()
            ->withArgs(fn ($query) => str_contains($query, 'LOWER(subject) = LOWER(?)'))
            ->andReturn([]);

        $tripleId = $this->service->addTriple('A', 'Works At', 'B', []);

        $this->assertEquals(1, $tripleId);
    }

    public function test_add_triple_validates_entity_type(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($query, $params) {
                return $params[2] === 'other'; // invalid type becomes 'other'
            })
            ->andReturn([(object) ['id' => 1]]);

        DB::shouldReceive('insert')
            ->once()
            ->withArgs(fn ($query, $params) => str_contains($query, 'knowledge_graph_edge_history') && $params[0] === 1)
            ->andReturn(true);

        DB::shouldReceive('select')
            ->once()
            ->withArgs(fn ($query) => str_contains($query, 'LOWER(subject) = LOWER(?)'))
            ->andReturn([]);

        $tripleId = $this->service->addTriple('A', 'related_to', 'B', [
            'subject_type' => 'invalid_type',
        ]);

        $this->assertEquals(1, $tripleId);
    }

    // =========================================================================
    // findRelationships Tests
    // =========================================================================

    public function test_find_relationships_finds_outgoing_relationships(): void
    {
        $results = [
            (object) [
                'id' => 1,
                'subject' => 'John',
                'subject_type' => 'person',
                'predicate' => 'works_at',
                'object' => 'Acme',
                'object_type' => 'organization',
                'confidence' => 0.9,
                'extracted_from' => 'source text',
                'source_document_id' => 100,
                'created_at' => '2026-02-01 10:00:00',
            ],
        ];

        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($query, $params) {
                return str_contains($query, 'LOWER(subject) = LOWER(?)')
                    && ! str_contains($query, 'LOWER(object) = LOWER(?)')
                    && $params[0] === 'John';
            })
            ->andReturn($results);

        $relationships = $this->service->findRelationships('John', ['direction' => 'outgoing']);

        $this->assertCount(1, $relationships);
        $this->assertEquals('outgoing', $relationships[0]['direction']);
    }

    public function test_find_relationships_finds_incoming_relationships(): void
    {
        $results = [
            (object) [
                'id' => 1,
                'subject' => 'Manager',
                'subject_type' => 'person',
                'predicate' => 'manages',
                'object' => 'John',
                'object_type' => 'person',
                'confidence' => 0.85,
                'extracted_from' => null,
                'source_document_id' => null,
                'created_at' => '2026-02-01 10:00:00',
            ],
        ];

        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($query, $params) {
                return str_contains($query, 'LOWER(object) = LOWER(?)')
                    && $params[0] === 'John';
            })
            ->andReturn($results);

        $relationships = $this->service->findRelationships('John', ['direction' => 'incoming']);

        $this->assertCount(1, $relationships);
        $this->assertEquals('incoming', $relationships[0]['direction']);
    }

    public function test_find_relationships_filters_by_predicates(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($query, $params) {
                return str_contains($query, 'predicate IN')
                    && in_array('works_at', $params)
                    && in_array('employed_by', $params);
            })
            ->andReturn([]);

        $result = $this->service->findRelationships('John', [
            'predicates' => ['works_at', 'employed_by'],
        ]);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function test_find_relationships_filters_by_min_confidence(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($query, $params) {
                return str_contains($query, 'confidence >= ?')
                    && in_array(0.8, $params);
            })
            ->andReturn([]);

        $result = $this->service->findRelationships('John', ['min_confidence' => 0.8]);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    // =========================================================================
    // getEntityGraph Tests
    // =========================================================================

    public function test_get_entity_graph_returns_graph_structure(): void
    {
        $relationships = [
            (object) [
                'id' => 1,
                'subject' => 'John',
                'subject_type' => 'person',
                'predicate' => 'works_at',
                'object' => 'Acme',
                'object_type' => 'organization',
                'confidence' => 0.9,
                'extracted_from' => null,
                'source_document_id' => null,
                'valid_from' => null,
                'valid_until' => null,
                'temporal_type' => 'unknown',
                'created_at' => '2026-02-01 10:00:00',
            ],
        ];

        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        // findRelationships for 'John'
        DB::shouldReceive('select')
            ->andReturn($relationships, [], []);

        $graph = $this->service->getEntityGraph('John', 1);

        $this->assertEquals('John', $graph['root']);
        $this->assertArrayHasKey('nodes', $graph);
        $this->assertArrayHasKey('edges', $graph);
        $this->assertArrayHasKey('total_nodes', $graph);
        $this->assertArrayHasKey('total_edges', $graph);
    }

    public function test_get_entity_graph_respects_max_nodes(): void
    {
        // Create relationships - note that max_nodes is checked before processing
        // but nodes can be added inside the loop, so we test that the limit eventually stops
        $relationships = [
            (object) [
                'id' => 1,
                'subject' => 'Root',
                'subject_type' => 'person',
                'predicate' => 'knows',
                'object' => 'Person1',
                'object_type' => 'person',
                'confidence' => 0.9,
                'extracted_from' => null,
                'source_document_id' => null,
                'valid_from' => null,
                'valid_until' => null,
                'temporal_type' => 'unknown',
                'created_at' => '2026-02-01 10:00:00',
            ],
        ];

        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->andReturn($relationships, []);

        // Test with small max_nodes - service stops when limit reached in while loop
        $graph = $this->service->getEntityGraph('Root', 1, ['max_nodes' => 5]);

        // Graph should have nodes (exact count depends on traversal depth)
        $this->assertGreaterThan(0, $graph['total_nodes']);
        $this->assertArrayHasKey('nodes', $graph);
    }

    // =========================================================================
    // mergeEntities Tests
    // =========================================================================

    public function test_merge_entities_transfers_relationships(): void
    {
        $sourceEntity = (object) [
            'canonical_name' => 'John Smith',
            'aliases' => '["Johnny", "J. Smith"]',
            'properties' => '{"role": "developer"}',
        ];

        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('beginTransaction')
            ->once();

        DB::shouldReceive('select')
            ->once()
            ->andReturn([$sourceEntity]);

        DB::shouldReceive('statement')
            ->times(4); // Update aliases, update subjects, update objects, delete source

        DB::shouldReceive('commit')
            ->once();

        $result = $this->service->mergeEntities(1, 2);

        $this->assertTrue($result);
    }

    public function test_merge_entities_returns_false_for_nonexistent_source(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('beginTransaction')
            ->once();

        DB::shouldReceive('select')
            ->once()
            ->andReturn([]);

        DB::shouldReceive('rollBack')
            ->once();

        $result = $this->service->mergeEntities(999, 2);

        $this->assertFalse($result);
    }

    // =========================================================================
    // searchByRelationship Tests
    // =========================================================================

    public function test_search_by_relationship_finds_by_predicate(): void
    {
        $results = [
            (object) [
                'id' => 1,
                'subject' => 'John',
                'subject_type' => 'person',
                'predicate' => 'works_at',
                'object' => 'Acme',
                'object_type' => 'organization',
                'confidence' => 0.9,
                'extracted_from' => 'text',
                'source_document_id' => 100,
                'created_at' => '2026-02-01 10:00:00',
            ],
        ];

        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($query, $params) {
                return str_contains($query, 'predicate = ?')
                    && $params[0] === 'works_at';
            })
            ->andReturn($results);

        $found = $this->service->searchByRelationship('works_at');

        $this->assertCount(1, $found);
        $this->assertEquals('John', $found[0]['subject']);
    }

    public function test_search_by_relationship_filters_by_object(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($query, $params) {
                return str_contains($query, 'LOWER(object) = LOWER(?)')
                    && in_array('Acme', $params);
            })
            ->andReturn([]);

        $result = $this->service->searchByRelationship('works_at', 'Acme');

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function test_search_by_relationship_filters_by_types(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($query, $params) {
                return str_contains($query, 'subject_type = ?')
                    && str_contains($query, 'object_type = ?');
            })
            ->andReturn([]);

        $result = $this->service->searchByRelationship('works_at', null, [
            'subject_type' => 'person',
            'object_type' => 'organization',
        ]);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    // =========================================================================
    // getOrCreateEntity Tests
    // =========================================================================

    public function test_get_or_create_entity_returns_existing_entity(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->andReturn([(object) ['id' => 42]]);

        $entityId = $this->service->getOrCreateEntity('John Doe', 'person');

        $this->assertEquals(42, $entityId);
    }

    public function test_get_or_create_entity_creates_new_entity(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->andReturn([]);

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($query, $params) {
                return str_contains($query, 'INSERT INTO knowledge_graph_entities')
                    && $params[0] === 'New Entity'
                    && $params[1] === 'concept';
            })
            ->andReturn([(object) ['id' => 100]]);

        $entityId = $this->service->getOrCreateEntity('New Entity', 'concept', ['alias1'], ['key' => 'value']);

        $this->assertEquals(100, $entityId);
    }

    public function test_get_or_create_entity_updates_aliases_for_existing(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->andReturn([(object) ['id' => 42]]);

        DB::shouldReceive('statement')
            ->once()
            ->withArgs(function ($query, $params) {
                return str_contains($query, 'UPDATE knowledge_graph_entities')
                    && str_contains($query, 'aliases');
            });

        $entityId = $this->service->getOrCreateEntity('John Doe', 'person', ['Johnny']);

        $this->assertEquals(42, $entityId);
    }

    // =========================================================================
    // searchEntities Tests
    // =========================================================================

    public function test_search_entities_searches_by_name(): void
    {
        $results = [
            (object) [
                'id' => 1,
                'canonical_name' => 'John Doe',
                'entity_type' => 'person',
                'aliases' => '["Johnny"]',
                'properties' => '{}',
                'created_at' => '2026-02-01 10:00:00',
            ],
        ];

        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($query, $params) {
                return str_contains($query, 'canonical_name ILIKE ?')
                    && $params[0] === '%John%';
            })
            ->andReturn($results);

        $found = $this->service->searchEntities('John');

        $this->assertCount(1, $found);
        $this->assertEquals('John Doe', $found[0]['canonical_name']);
        $this->assertEquals(['Johnny'], $found[0]['aliases']);
    }

    public function test_search_entities_filters_by_types(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function ($query, $params) {
                return str_contains($query, 'entity_type IN')
                    && in_array('person', $params)
                    && in_array('organization', $params);
            })
            ->andReturn([]);

        $result = $this->service->searchEntities('test', ['types' => ['person', 'organization']]);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    // =========================================================================
    // getStatistics Tests
    // =========================================================================

    public function test_get_statistics_returns_complete_stats(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('selectOne')
            ->once()
            ->andReturn((object) ['total' => 1000, 'active' => 900, 'expired' => 100]);

        DB::shouldReceive('select')
            ->andReturn(
                [(object) ['count' => 200]],   // entity count
                [                              // predicate stats
                    (object) ['predicate' => 'works_at', 'count' => 100],
                    (object) ['predicate' => 'located_in', 'count' => 80],
                ],
                [                              // type stats
                    (object) ['entity_type' => 'person', 'count' => 100],
                    (object) ['entity_type' => 'organization', 'count' => 50],
                ],
                [(object) ['avg' => 0.85]]     // avg confidence
            );

        $stats = $this->service->getStatistics();

        $this->assertEquals(1000, $stats['total_triples']);
        $this->assertEquals(200, $stats['total_entities']);
        $this->assertEquals(0.85, $stats['average_confidence']);
        $this->assertCount(2, $stats['top_predicates']);
        $this->assertCount(2, $stats['entity_types']);
    }

    // =========================================================================
    // deleteTriple Tests
    // =========================================================================

    public function test_delete_triple_returns_true_on_success(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('selectOne')
            ->once()
            ->with('SELECT t_expired, superseded_by FROM knowledge_graph WHERE id = ?', [1])
            ->andReturn((object) ['t_expired' => null, 'superseded_by' => null]);

        DB::shouldReceive('beginTransaction')->once();
        DB::shouldReceive('statement')->once()->andReturn(true);
        DB::shouldReceive('insert')->once()->andReturn(true);
        DB::shouldReceive('commit')->once();

        $result = $this->service->deleteTriple(1);

        $this->assertTrue($result);
    }

    public function test_delete_triple_returns_false_when_not_found(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('selectOne')
            ->once()
            ->andReturn(null);

        $result = $this->service->deleteTriple(999);

        $this->assertFalse($result);
    }

    // =========================================================================
    // deleteEntity Tests
    // =========================================================================

    public function test_delete_entity_deletes_entity_and_triples(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('beginTransaction')
            ->once();

        DB::shouldReceive('statement')
            ->once()
            ->andReturn(true);

        DB::shouldReceive('select')
            ->once()
            ->andReturn([(object) ['id' => 10], (object) ['id' => 11]]);

        DB::shouldReceive('insert')
            ->twice()
            ->andReturn(true);

        DB::shouldReceive('delete')
            ->once()
            ->with('DELETE FROM knowledge_graph_entities WHERE id = ?', [1])
            ->andReturn(1);

        DB::shouldReceive('commit')
            ->once();

        $result = $this->service->deleteEntity(1);

        $this->assertTrue($result);
    }

    public function test_delete_entity_rolls_back_on_error(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('beginTransaction')
            ->once();

        DB::shouldReceive('statement')
            ->once()
            ->andThrow(new \Exception('Database error'));

        DB::shouldReceive('rollBack')
            ->once();

        $result = $this->service->deleteEntity(1);

        $this->assertFalse($result);
    }

    // =========================================================================
    // getQualityMetrics Tests
    // =========================================================================

    public function test_get_quality_metrics_returns_all_dimensions(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        // measureAccuracy: sample query
        DB::shouldReceive('select')
            ->andReturn(
                // accuracy samples
                [
                    (object) ['id' => 1, 'subject' => 'Acme', 'object' => 'New York', 'predicate' => 'located_in', 'confidence' => 0.9, 'content' => 'Acme Corp is based in New York', 'title' => 'Company Info'],
                ],
                // freshness age_buckets
                [
                    (object) ['bucket' => '0-7d', 'count' => 50],
                ]
            );

        // measureFreshness: selectOne
        DB::shouldReceive('selectOne')
            ->andReturn(
                (object) ['linked' => 100, 'stale' => 10],     // freshness stats
                (object) ['eligible' => 200, 'extracted' => 150], // coverage doc stats
                (object) ['count' => 5],                         // orphan count
                (object) ['avg_triples' => 3.5],                 // avg triples/doc
            );

        $result = $this->service->getQualityMetrics(['sample_size' => 1]);

        $this->assertArrayHasKey('accuracy', $result);
        $this->assertArrayHasKey('freshness', $result);
        $this->assertArrayHasKey('coverage', $result);
        $this->assertArrayHasKey('composite_score', $result);
        $this->assertArrayHasKey('duration_ms', $result);
    }

    public function test_get_quality_metrics_accuracy_calculates_correctly(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->andReturn(
                // 2 samples: one verified (both found), one not
                [
                    (object) ['id' => 1, 'subject' => 'Alice', 'object' => 'Boston', 'predicate' => 'lives_in', 'confidence' => 0.9, 'content' => 'Alice lives in Boston', 'title' => 'People'],
                    (object) ['id' => 2, 'subject' => 'Xyz', 'object' => 'Nowhere', 'predicate' => 'located_in', 'confidence' => 0.8, 'content' => 'Unrelated text here', 'title' => 'Other'],
                ],
                // freshness age_buckets
                [(object) ['bucket' => '0-7d', 'count' => 10]]
            );

        DB::shouldReceive('selectOne')
            ->andReturn(
                (object) ['linked' => 50, 'stale' => 0],
                (object) ['eligible' => 100, 'extracted' => 80],
                (object) ['count' => 0],
                (object) ['avg_triples' => 2.0],
            );

        $result = $this->service->getQualityMetrics(['sample_size' => 2]);

        $this->assertEquals(0.5, $result['accuracy']['score']); // 1 of 2 verified
        $this->assertEquals(2, $result['accuracy']['sampled']);
        $this->assertEquals(1, $result['accuracy']['verified']);
    }

    public function test_get_quality_metrics_freshness_handles_no_linked_triples(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        // accuracy: no linked triples returned
        DB::shouldReceive('select')
            ->andReturn([]);

        // freshness: 0 linked
        DB::shouldReceive('selectOne')
            ->andReturn(
                (object) ['linked' => 0, 'stale' => 0],
                (object) ['eligible' => 10, 'extracted' => 5],
                (object) ['count' => 0],
                (object) ['avg_triples' => 0],
            );

        $result = $this->service->getQualityMetrics();

        $this->assertEquals(0, $result['accuracy']['score']);
        $this->assertEquals(1.0, $result['freshness']['score']); // 0 linked = perfect freshness
    }

    public function test_get_quality_metrics_coverage_with_zero_docs(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->andReturn([]);

        DB::shouldReceive('selectOne')
            ->andReturn(
                (object) ['linked' => 0, 'stale' => 0],
                (object) ['eligible' => 0, 'extracted' => 0],
                (object) ['count' => 0],
                (object) ['avg_triples' => 0],
            );

        $result = $this->service->getQualityMetrics();

        $this->assertEquals(0, $result['coverage']['score']);
        $this->assertEquals(0, $result['coverage']['eligible_documents']);
    }

    public function test_get_quality_metrics_persist_inserts_to_kg_quality_runs(): void
    {
        DB::shouldReceive('connection')
            ->with('pgsql_rag')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->andReturn(
                [(object) ['id' => 1, 'subject' => 'Test', 'object' => 'Data', 'predicate' => 'is', 'confidence' => 0.9, 'content' => 'Test Data here', 'title' => 'Doc']],
                [(object) ['bucket' => '0-7d', 'count' => 5]]
            );

        DB::shouldReceive('selectOne')
            ->andReturn(
                (object) ['linked' => 100, 'stale' => 20],
                (object) ['active_total' => 100, 'with_temporal' => 25, 'stale_valid_time' => 2, 'expired_total' => 10, 'avg_temporal_confidence' => 0.7],
                (object) ['eligible' => 50, 'extracted' => 40],
                (object) ['count' => 3],
                (object) ['avg_triples' => 4.0],
                (object) ['total' => 100, 'active' => 90, 'expired' => 10],
                (object) ['c' => 50],   // total_entities count
            );

        DB::shouldReceive('insert')
            ->once()
            ->withArgs(function ($sql) {
                return str_contains($sql, 'kg_quality_runs');
            })
            ->andReturn(true);

        $result = $this->service->getQualityMetrics(['persist' => true]);

        $this->assertArrayHasKey('composite_score', $result);
    }

    // =========================================================================
    // Entity Name Array Coercion Tests (N143b)
    // =========================================================================

    public function test_extract_entities_coerces_array_name_to_string(): void
    {
        $this->mockAIService
            ->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => json_encode([
                    'entities' => [
                        ['name' => ['John Smith'], 'type' => 'person'],
                    ],
                    'relationships' => [],
                ]),
            ]);

        $result = $this->service->extractEntities('Some text about John Smith', ['persist' => false]);

        $this->assertCount(1, $result['entities']);
        $this->assertEquals('John Smith', $result['entities'][0]['name']);
    }

    public function test_extract_entities_coerces_associative_array_name(): void
    {
        $this->mockAIService
            ->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => json_encode([
                    'entities' => [
                        ['name' => ['primary' => 'Apple Inc', 'aliases' => ['AAPL']], 'type' => 'organization'],
                    ],
                    'relationships' => [],
                ]),
            ]);

        $result = $this->service->extractEntities('Text about Apple Inc', ['persist' => false]);

        $this->assertCount(1, $result['entities']);
        $this->assertEquals('Apple Inc', $result['entities'][0]['name']);
    }

    public function test_extract_entities_skips_empty_array_name(): void
    {
        $this->mockAIService
            ->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => json_encode([
                    'entities' => [
                        ['name' => [], 'type' => 'person'],
                        ['name' => 'Valid Entity', 'type' => 'thing'],
                    ],
                    'relationships' => [],
                ]),
            ]);

        $result = $this->service->extractEntities('Text', ['persist' => false]);

        // Empty array name should be filtered out, only Valid Entity remains
        $this->assertCount(1, $result['entities']);
        $this->assertEquals('Valid Entity', $result['entities'][0]['name']);
    }

    public function test_extract_entities_coerces_nested_name_object(): void
    {
        $this->mockAIService
            ->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => true,
                'response' => json_encode([
                    'entities' => [
                        ['name' => ['name' => 'Nested Name'], 'type' => 'person'],
                    ],
                    'relationships' => [],
                ]),
            ]);

        $result = $this->service->extractEntities('Text about Nested Name', ['persist' => false]);

        $this->assertCount(1, $result['entities']);
        $this->assertEquals('Nested Name', $result['entities'][0]['name']);
    }
}
