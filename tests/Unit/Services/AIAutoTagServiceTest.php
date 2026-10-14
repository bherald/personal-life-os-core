<?php

namespace Tests\Unit\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\AIAutoTagService;
use App\Services\AIService;
use App\Services\ContentExtractionProvenanceService;
use App\Services\ContentExtractionService;
use App\Services\NextcloudFileApiService;
use App\Services\SystemConfigService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for AIAutoTagService
 *
 * Tests AI-powered automatic tagging functionality:
 * - File analysis and tag generation
 * - Image analysis with vision AI
 * - Document analysis and classification
 * - Batch analysis operations
 * - Tag-based search
 */
class AIAutoTagServiceTest extends TestCase
{
    private AIAutoTagService $service;

    private $mockAIService;

    private $mockContentExtraction;

    private $mockNextcloudApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockAIService = Mockery::mock(AIService::class);
        $this->mockContentExtraction = Mockery::mock(ContentExtractionService::class);
        $this->mockNextcloudApi = Mockery::mock(NextcloudFileApiService::class);

        $this->service = Mockery::mock(AIAutoTagService::class, [$this->mockAIService])->makePartial();
        $this->service->setContentExtractionService($this->mockContentExtraction);
        $this->service->setNextcloudApi($this->mockNextcloudApi);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // analyzeFile() Tests
    // =========================================================================

    public function test_analyze_file_returns_error_for_missing_file(): void
    {
        $fileRegistryId = 999;

        DB::shouldReceive('selectOne')
            ->withArgs(function ($query, $params) use ($fileRegistryId) {
                return str_contains($query, 'SELECT') && $params[0] === $fileRegistryId;
            })
            ->once()
            ->andReturn(null);

        $result = $this->service->analyzeFile($fileRegistryId);

        $this->assertFalse($result['success']);
        $this->assertEquals('File not found in registry', $result['error']);
    }

    public function test_analyze_file_returns_error_for_inactive_file(): void
    {
        $fileRegistryId = 1;

        DB::shouldReceive('selectOne')
            ->once()
            ->andReturn((object) [
                'id' => 1,
                'status' => 'deleted',
                'asset_uuid' => 'abc123',
            ]);

        $result = $this->service->analyzeFile($fileRegistryId);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not active', $result['error']);
    }

    public function test_analyze_file_skips_already_analyzed(): void
    {
        $fileRegistryId = 1;

        DB::shouldReceive('selectOne')
            ->once()
            ->andReturn((object) [
                'id' => 1,
                'status' => 'active',
                'asset_uuid' => 'abc123',
                'extension' => 'pdf',
                'ai_analyzed_at' => '2026-02-04 10:00:00',
                'ai_analysis_version' => 'v1.0',
            ]);

        $result = $this->service->analyzeFile($fileRegistryId);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['skipped']);
    }

    public function test_analyze_file_reanalyzes_with_force_refresh(): void
    {
        $fileRegistryId = 1;

        DB::shouldReceive('selectOne')
            ->once()
            ->andReturn((object) [
                'id' => 1,
                'status' => 'active',
                'asset_uuid' => 'abc123',
                'extension' => 'jpg',
                'current_path' => '/files/photo.jpg',
                'filename' => 'photo.jpg',
                'mime_type' => 'image/jpeg',
                'ai_analyzed_at' => '2026-02-04 10:00:00',
                'ai_analysis_version' => 'v1.0',
            ]);

        // Mock download
        $this->mockNextcloudApi->shouldReceive('localPath')
            ->once()
            ->with('/files/photo.jpg')
            ->andReturn(null);

        $this->mockNextcloudApi->shouldReceive('downloadFile')
            ->once()
            ->andReturn(['success' => true, 'content' => 'image data']);

        // Mock analyzeImage
        $this->service->shouldReceive('analyzeImage')
            ->once()
            ->andReturn([
                'success' => true,
                'tags' => [['tag' => 'photo', 'confidence' => 0.9]],
                'description' => 'A test photo',
                'document_type' => 'photo',
                'input_source_sha256' => hash('sha256', 'image data'),
            ]);

        DB::shouldReceive('update')->once()
            ->withArgs(fn (string $sql, array $params) => str_contains($sql, "SET ai_analysis_version = 'stale'") && $params[0] === 1)
            ->andReturn(1);
        DB::shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());
        DB::shouldReceive('selectOne')->once()
            ->with('SELECT * FROM file_registry WHERE id = ? FOR UPDATE', [1])
            ->andReturn((object) [
                'id' => 1, 'status' => 'active', 'extension' => 'jpg',
                'current_path' => '/files/photo.jpg',
                'ai_analyzed_at' => '2026-02-04 10:00:00', 'ai_analysis_version' => 'stale',
            ]);
        DB::shouldReceive('selectOne')->once()
            ->with('SELECT metadata FROM file_content_extraction_provenance WHERE file_registry_id = ? FOR UPDATE', [1])
            ->andReturn(null);
        $this->mockNextcloudApi->shouldReceive('computeFileHash')->once()
            ->with('/files/photo.jpg')->andReturn(['success' => true, 'hash' => hash('sha256', 'image data')]);
        DB::shouldReceive('statement')->once()->andReturn(true);
        DB::shouldReceive('update')->once()
            ->with('UPDATE file_registry SET content_hash = ?, content_hash_verified_at = NOW() WHERE id = ?', [hash('sha256', 'image data'), 1])
            ->andReturn(1);

        // Mock analysis update and RAG invalidation
        DB::shouldReceive('update')
            ->once()
            ->withArgs(function (string $query, array $params) use ($fileRegistryId): bool {
                return str_contains($query, 'SET ai_tags = ?')
                    && str_contains($query, 'ai_analysis_version = ?')
                    && $params[5] === $fileRegistryId;
            })
            ->andReturn(1);

        DB::shouldReceive('update')
            ->once()
            ->with('UPDATE file_registry SET rag_indexed_at = NULL WHERE id = ?', [$fileRegistryId])
            ->andReturn(1);

        $result = $this->service->analyzeFile($fileRegistryId, true);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('tags', $result);
    }

    public function test_analyze_file_returns_error_for_unsupported_type(): void
    {
        $fileRegistryId = 1;

        DB::shouldReceive('selectOne')
            ->once()
            ->andReturn((object) [
                'id' => 1,
                'status' => 'active',
                'asset_uuid' => 'abc123',
                'extension' => 'exe',
                'ai_analyzed_at' => null,
            ]);

        DB::shouldReceive('update')
            ->once()
            ->withArgs(function (string $query, array $params) use ($fileRegistryId): bool {
                return str_contains($query, 'UPDATE file_registry')
                    && str_contains($query, "ai_analysis_version = 'skipped'")
                    && $params === ['[skipped] unsupported_type:exe', $fileRegistryId];
            })
            ->andReturn(1);

        $result = $this->service->analyzeFile($fileRegistryId);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unsupported file type', $result['error']);
    }

    // =========================================================================
    // analyzeImage() Tests
    // =========================================================================

    public function test_analyze_image_returns_error_for_missing_file(): void
    {
        $result = $this->service->analyzeImage('/nonexistent/path.jpg');

        $this->assertFalse($result['success']);
        $this->assertEquals('Image file not found', $result['error']);
    }

    public function test_analyze_image_calls_vision_ai(): void
    {
        $this->bindExactVisionFixture();
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, 'fake image data');

        try {
            $this->mockAIService->shouldReceive('processImage')
                ->once()
                ->withArgs(fn (string $imageContent): bool => $imageContent === 'fake image data')
                ->andReturn([
                    'success' => true,
                    'response' => json_encode([
                        'document_type' => 'photo',
                        'description' => 'A landscape photo',
                        'tags' => [
                            ['tag' => 'landscape', 'confidence' => 0.9],
                            ['tag' => 'nature', 'confidence' => 0.85],
                        ],
                        'objects' => ['tree', 'mountain'],
                        'scenes' => ['outdoor'],
                        'colors' => ['green', 'blue'],
                        'detected_text' => '',
                        'key_info' => [],
                        'confidence' => 'high',
                    ]),
                    'provider' => 'ollama',
                ]);

            $result = $this->service->analyzeImage($tempFile, 'landscape.jpg');

            $this->assertTrue($result['success']);
            $this->assertArrayHasKey('tags', $result);
            $this->assertEquals('photo', $result['document_type']);
            $this->assertSame(hash('sha256', 'fake image data'), $result['input_source_sha256']);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_analyze_image_handles_vision_failure(): void
    {
        $this->bindExactVisionFixture();
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, 'fake image data');

        try {
            $this->mockAIService->shouldReceive('processImage')
                ->once()
                ->andReturn([
                    'success' => false,
                    'error' => 'Vision model unavailable',
                ]);

            $result = $this->service->analyzeImage($tempFile);

            $this->assertFalse($result['success']);
            $this->assertStringContainsString('Vision analysis failed', $result['error']);
        } finally {
            unlink($tempFile);
        }
    }

    private function bindExactVisionFixture(): void
    {
        $settings = Mockery::mock(SystemConfigService::class);
        $settings->shouldReceive('get')->with('files.ai_auto_tag_vision_model_selection', null, true)
            ->andReturn('vision-fixture::synthetic-vision-model');
        $this->app->instance(SystemConfigService::class, $settings);
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('where')->with('instance_id', 'vision-fixture')->andReturnSelf();
        $query->shouldReceive('first')->andReturn((object) ['instance_id' => 'vision-fixture', 'instance_type' => 'ollama',
            'is_active' => true, 'is_healthy' => true, 'routability' => 'allowed',
            'supported_models' => '["synthetic-vision-model"]',
            'config' => json_encode(['models' => ['vision' => 'synthetic-vision-model'],
                'route_policy' => ['require_explicit_instance' => true, 'require_model_role' => true,
                    'allowed_model_roles' => ['vision'], 'allow_generic_fallback' => false]])]);
        DB::shouldReceive('table')->with('llm_instances')->andReturn($query);
    }

    // =========================================================================
    // analyzeDocument() Tests
    // =========================================================================

    public function test_analyze_document_extracts_and_analyzes_content(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, 'PDF content');

        try {
            $this->mockContentExtraction->shouldReceive('extract')
                ->once()
                ->andReturn([
                    'text' => 'Invoice #12345\nAmount Due: $500\nDate: 2026-02-04',
                    'method' => 'tika',
                    'provenance' => (new ContentExtractionProvenanceService)->build([
                        'success' => true,
                        'text' => 'Invoice #12345\nAmount Due: $500\nDate: 2026-02-04',
                        'method' => 'tika',
                        'pipeline' => 'aiservice',
                    ], ['extension' => 'pdf']),
                ]);

            $this->mockAIService->shouldReceive('process')
                ->once()
                ->andReturn([
                    'success' => true,
                    'response' => json_encode([
                        'document_type' => 'invoice',
                        'description' => 'Invoice document for $500',
                        'tags' => [
                            ['tag' => 'invoice', 'confidence' => 0.95],
                            ['tag' => 'financial', 'confidence' => 0.85],
                        ],
                        'key_info' => [
                            'amounts' => ['$500'],
                            'dates' => ['2026-02-04'],
                        ],
                        'entities' => [],
                        'summary' => 'Invoice for $500 due on 2026-02-04',
                        'confidence' => 'high',
                    ]),
                    'provider' => 'ollama',
                ]);

            $result = $this->service->analyzeDocument($tempFile, 'invoice.pdf');

            $this->assertTrue($result['success']);
            $this->assertEquals('invoice', $result['document_type']);
            $this->assertNotEmpty($result['tags']);
            $this->assertSame(ContentExtractionProvenanceService::SCHEMA, $result['extraction_provenance']['schema']);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_analyze_document_handles_empty_extraction(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, 'empty');

        try {
            $this->mockContentExtraction->shouldReceive('extract')
                ->once()
                ->andReturn([
                    'text' => '',
                    'method' => 'tika',
                ]);

            $result = $this->service->analyzeDocument($tempFile);

            $this->assertFalse($result['success']);
            $this->assertStringContainsString('No text could be extracted', $result['error']);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_update_persists_valid_extraction_provenance_without_raw_content(): void
    {
        $provenance = (new ContentExtractionProvenanceService)->build([
            'success' => true,
            'text' => 'private extracted content',
            'method' => 'tika',
            'pipeline' => 'aiservice',
        ], ['extension' => 'txt']);

        DB::shouldReceive('update')
            ->once()
            ->withArgs(fn (string $sql, array $params): bool => str_contains($sql, 'SET ai_tags = ?')
                && $params[5] === 42)
            ->andReturn(1);
        DB::shouldReceive('statement')
            ->once()
            ->withArgs(function (string $sql, array $params): bool {
                $stored = json_decode((string) $params[1], true);

                return str_contains($sql, 'file_content_extraction_provenance')
                    && $params[0] === 42
                    && $params[1] === $params[2]
                    && ($stored['schema'] ?? null) === ContentExtractionProvenanceService::SCHEMA
                    && ! str_contains((string) $params[1], 'private extracted content');
            })
            ->andReturn(true);

        $method = new \ReflectionMethod(AIAutoTagService::class, 'updateFileWithAnalysis');
        $method->setAccessible(true);
        $this->assertTrue($method->invoke($this->service, 42, [
            'tags' => [],
            'description' => 'fixture',
            'document_type' => 'record',
            'detected_text' => 'private extracted content',
            'extraction_provenance' => $provenance,
        ]));
    }

    // =========================================================================
    // batchAnalyze() Tests
    // =========================================================================

    public function test_batch_analyze_processes_multiple_files(): void
    {
        DB::shouldReceive('select')->once()->andReturn([]);
        DB::shouldReceive('selectOne')->times(3)->andReturnUsing(fn ($sql, $params) => (object) [
            'id' => $params[0], 'status' => 'active', 'ai_analysis_version' => null,
            'claim_worker' => 'test-worker', 'claim_expires_at' => now()->addMinutes(10)->toDateTimeString(),
        ]);
        DB::shouldReceive('update')->times(4)->andReturn(1);
        $this->service->shouldReceive('analyzeFile')->with(1, false, 'test-worker')->once()->andReturn(['success' => true, 'tags' => []]);
        $this->service->shouldReceive('analyzeFile')->with(2, false, 'test-worker')->once()->andReturn(['success' => true, 'skipped' => true]);
        $this->service->shouldReceive('analyzeFile')->with(3, false, 'test-worker')->once()->andReturn(['success' => false, 'error' => 'Failed']);
        $results = $this->service->batchAnalyze([1, 2, 3], 10, false, 'test-worker');
        $this->assertEquals(3, $results['total']);
        $this->assertEquals(1, $results['processed']);
        $this->assertEquals(1, $results['skipped']);
        $this->assertEquals(1, $results['failed']);
    }

    public function test_batch_analyze_handles_exceptions(): void
    {
        DB::shouldReceive('select')->once()->andReturn([]);
        DB::shouldReceive('selectOne')->once()->andReturn((object) [
            'id' => 1, 'status' => 'active', 'ai_analysis_version' => null,
            'claim_worker' => 'test-worker', 'claim_expires_at' => now()->addMinutes(10)->toDateTimeString(),
        ]);
        DB::shouldReceive('update')->once()->withArgs(fn ($sql, $params) => $params[0] === 'processing:1')->andReturn(1);
        DB::shouldReceive('update')->once()->withArgs(fn ($sql, $params) => $params[0] === 'fail:1' && $params[2] === 'processing:1')->andReturn(1);
        $this->service->shouldReceive('analyzeFile')->with(1, false, 'test-worker')->once()->andThrow(new \Exception('Unexpected error'));
        $results = $this->service->batchAnalyze([1], 10, false, 'test-worker');
        $this->assertEquals(1, $results['failed']);
        $this->assertArrayHasKey(1, $results['errors']);
    }

    public function test_batch_preserves_wrapped_unknown_without_failure_bookkeeping_or_second_file(): void
    {
        DB::shouldReceive('select')->once()->andReturn([]);
        DB::shouldReceive('selectOne')->once()->andReturn((object) [
            'id' => 1, 'status' => 'active', 'ai_analysis_version' => null,
            'claim_worker' => 'test-worker', 'claim_expires_at' => now()->addMinutes(10)->toDateTimeString(),
        ]);
        DB::shouldReceive('update')->once()->withArgs(fn ($sql, $params) => $params[0] === 'processing:1')->andReturn(1);
        $stop = new \RuntimeException('wrapped unknown', 0, new InferenceAdmissionException('unknown'));
        $this->service->shouldReceive('analyzeFile')->with(1, false, 'test-worker')->once()->andThrow($stop);
        $this->service->shouldNotReceive('analyzeFile')->with(2, false, 'test-worker');
        try {
            $this->service->batchAnalyze([1, 2], 10, false, 'test-worker');
            $this->fail('Unknown execution was turned into a retryable file failure.');
        } catch (\RuntimeException $observed) {
            $this->assertSame($stop, $observed);
        }
    }

    // =========================================================================
    // getUnanalyzedFiles() Tests
    // =========================================================================

    public function test_get_unanalyzed_files_returns_pending_files(): void
    {
        DB::shouldReceive('select')
            ->withArgs(function ($query, $params) {
                return str_contains($query, 'ai_analyzed_at IS NULL')
                    && str_contains($query, 'LIMIT');
            })
            ->once()
            ->andReturn([
                (object) ['id' => 1, 'filename' => 'doc1.pdf'],
                (object) ['id' => 2, 'filename' => 'photo1.jpg'],
            ]);

        $result = $this->service->getUnanalyzedFiles(100);

        $this->assertCount(2, $result);
    }

    // =========================================================================
    // searchByTags() Tests
    // =========================================================================

    public function test_search_by_tags_returns_matching_files(): void
    {
        $tags = ['financial', 'invoice'];

        DB::shouldReceive('select')
            ->withArgs(function ($query, $params) {
                return str_contains($query, 'JSON_SEARCH')
                    && str_contains($query, 'ai_tags')
                    && $params[0] === 'financial'
                    && $params[1] === 'invoice';
            })
            ->once()
            ->andReturn([
                (object) [
                    'id' => 1,
                    'asset_uuid' => 'abc123',
                    'filename' => 'invoice.pdf',
                    'ai_tags' => '[{"tag":"financial","confidence":0.9}]',
                    'ai_document_type' => 'invoice',
                ],
            ]);

        $results = $this->service->searchByTags($tags);

        $this->assertCount(1, $results);
        $this->assertIsArray($results[0]->ai_tags);
    }

    public function test_search_by_tags_returns_empty_for_no_tags(): void
    {
        $result = $this->service->searchByTags([]);

        $this->assertEmpty($result);
    }

    // =========================================================================
    // searchByDocumentType() Tests
    // =========================================================================

    public function test_search_by_document_type_returns_matching_files(): void
    {
        $documentType = 'invoice';

        DB::shouldReceive('select')
            ->withArgs(function ($query, $params) use ($documentType) {
                return str_contains($query, 'ai_document_type = ?')
                    && $params[0] === $documentType;
            })
            ->once()
            ->andReturn([
                (object) [
                    'id' => 1,
                    'filename' => 'invoice1.pdf',
                    'ai_document_type' => 'invoice',
                    'ai_tags' => '[]',
                ],
            ]);

        $results = $this->service->searchByDocumentType($documentType);

        $this->assertCount(1, $results);
    }

    // =========================================================================
    // searchByContent() Tests
    // =========================================================================

    public function test_search_by_content_searches_descriptions_and_text(): void
    {
        $query = 'specific phrase';

        DB::shouldReceive('select')
            ->withArgs(function ($sql, $params) {
                return str_contains($sql, 'ai_description LIKE')
                    && str_contains($sql, 'ai_detected_text LIKE')
                    && $params[0] === '%specific phrase%';
            })
            ->once()
            ->andReturn([
                (object) [
                    'id' => 1,
                    'filename' => 'document.pdf',
                    'ai_description' => 'Contains specific phrase',
                    'ai_detected_text' => 'More text with specific phrase here',
                    'ai_tags' => '[]',
                ],
            ]);

        $results = $this->service->searchByContent($query);

        $this->assertCount(1, $results);
    }

    // =========================================================================
    // getStats() Tests
    // =========================================================================

    public function test_get_stats_returns_analysis_statistics(): void
    {
        DB::shouldReceive('selectOne')
            ->withArgs(function ($query, $bindings) {
                return str_contains($query, 'COUNT(*)') && str_contains($query, 'file_registry')
                    && str_contains($query, AIAutoTagService::pendingAnalysisPredicate()['sql'])
                    && str_contains($query, 'EXISTS') && count($bindings) > 0;
            })
            ->once()
            ->andReturn((object) [
                'total_files' => 1000,
                'analyzed_count' => 750,
                'pending_count' => 250,
                'source_bound_image_count' => 200,
                'current_version_document_count' => 100,
            ]);

        DB::shouldReceive('select')
            ->withArgs(function ($query) {
                return str_contains($query, 'ai_document_type') && str_contains($query, 'GROUP BY');
            })
            ->once()
            ->andReturn([
                (object) ['ai_document_type' => 'invoice', 'count' => 100],
                (object) ['ai_document_type' => 'photo', 'count' => 500],
            ]);

        DB::shouldReceive('select')
            ->withArgs(function ($query) {
                return str_contains($query, 'JSON_TABLE');
            })
            ->once()
            ->andReturn([
                (object) ['tag_name' => 'financial', 'count' => 150],
                (object) ['tag_name' => 'document', 'count' => 200],
            ]);

        $stats = $this->service->getStats();

        $this->assertArrayHasKey('total_files', $stats);
        $this->assertArrayHasKey('analyzed_count', $stats);
        $this->assertArrayHasKey('document_types', $stats);
        $this->assertArrayHasKey('top_tags', $stats);
        $this->assertEquals(1000, $stats['total_files']);
        $this->assertEquals(750, $stats['analyzed_count']);
        $this->assertSame(250, $stats['pending_count']);
        $this->assertSame(200, $stats['source_bound_image_count']);
        $this->assertSame(100, $stats['current_version_document_count']);
    }
}
