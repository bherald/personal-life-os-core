<?php

namespace Tests\Unit\Services;

use App\Services\AIService;
use App\Services\RAGService;
use App\Services\SemDeDupService;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class RAGServiceTest extends TestCase
{
    private RAGService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RAGService(Mockery::mock(AIService::class));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function callPrivate(string $method, ...$args): mixed
    {
        $ref = new ReflectionMethod($this->service, $method);
        $ref->setAccessible(true);

        return $ref->invoke($this->service, ...$args);
    }

    public function test_to_vector_literal_rounds_to_four_decimals(): void
    {
        $literal = $this->callPrivate('toVectorLiteral', [1.23456789, -0.3333333, 0.1000001]);

        $this->assertSame('[1.2346,-0.3333,0.1]', $literal);
    }

    public function test_to_vector_literal_normalizes_negative_zero_and_trims_trailing_zeroes(): void
    {
        $literal = $this->callPrivate('toVectorLiteral', [-0.00001, 0.5000, 2.0000, 0.0000001]);

        $this->assertSame('[0,0.5,2,0]', $literal);
    }

    public function test_source_identity_upsert_updates_latest_existing_document_without_inserting(): void
    {
        $ai = Mockery::mock(AIService::class);
        $dedup = Mockery::mock(SemDeDupService::class);
        $connection = Mockery::mock();

        DB::shouldReceive('connection')->with('pgsql_rag')->andReturn($connection);
        $connection->shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'pg_advisory_lock')
                && $bindings === ["file_catalog\x1ffile_registry\x1f41"])
            ->andReturn((object) ['locked' => null]);
        $connection->shouldReceive('selectOne')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                return str_contains($sql, 'ORDER BY updated_at DESC NULLS LAST, id DESC')
                    && $bindings === ['file_catalog', 'file_registry', '41'];
            })
            ->andReturn((object) ['id' => 77]);
        $dedup->shouldReceive('updateExisting')
            ->once()
            ->with(77, 'current content', 'Current title', ['revision' => 2], true)
            ->andReturnTrue();
        $connection->shouldReceive('select')
            ->once()
            ->with('SELECT * FROM rag_documents WHERE id = ? LIMIT 1', [77])
            ->andReturn([(object) ['id' => 77]]);
        $connection->shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'pg_advisory_unlock')
                && $bindings === ["file_catalog\x1ffile_registry\x1f41"])
            ->andReturn((object) ['unlocked' => true]);
        $ai->shouldReceive('generateEmbedding')->never();

        $service = new RAGService($ai, null, null, $dedup);
        $document = $service->indexDocument(
            'file_catalog',
            'current content',
            'Current title',
            ['revision' => 2],
            41,
            'file_registry',
            options: ['source_identity_upsert' => true],
        );

        $this->assertSame(77, $document?->id);
    }

    public function test_source_identity_upsert_requires_complete_identity_before_embedding(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateEmbedding')->never();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('requires source_type and source_id');

        (new RAGService($ai))->indexDocument(
            'file_catalog',
            'current content',
            options: ['source_identity_upsert' => true],
        );
    }

    public function test_unbound_legacy_youtube_projection_holds_before_embedding_or_duplicate_insert(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldNotReceive('generateEmbedding');
        $connection = Mockery::mock();
        DB::shouldReceive('connection')->with('pgsql_rag')->andReturn($connection);
        $connection->shouldReceive('selectOne')->once()->withArgs(fn ($sql) => str_contains($sql, 'pg_advisory_lock'))->andReturn((object) []);
        $connection->shouldReceive('selectOne')->once()->withArgs(fn ($sql) => str_contains($sql, 'ORDER BY updated_at DESC'))->andReturnNull();
        $connection->shouldReceive('selectOne')->once()->withArgs(fn ($sql, $args) => str_contains($sql, 'IS DISTINCT FROM')
            && $args === ['youtube_video', '797', 'abcdefghijk', 'youtube_transcript', '797'])->andReturn((object) ['id' => 19]);
        $connection->shouldReceive('selectOne')->once()->withArgs(fn ($sql) => str_contains($sql, 'pg_advisory_unlock'))->andReturn((object) []);
        $connection->shouldNotReceive('select');
        $this->expectExceptionMessage('Legacy YouTube source identity requires review');
        (new RAGService($ai))->indexDocument('youtube_video', 'Exact original', metadata: ['domain' => 'youtube', 'video_id' => 'abcdefghijk'],
            sourceId: 797, sourceType: 'youtube_transcript', options: ['preserve_content' => true, 'source_identity_upsert' => true]);
    }

    public function test_source_identity_upsert_holds_lock_through_new_insert(): void
    {
        $ai = Mockery::mock(AIService::class);
        $dedup = Mockery::mock(SemDeDupService::class);
        $connection = Mockery::mock();
        $identity = "file_catalog\x1ffile_registry\x1f42";

        DB::shouldReceive('connection')->with('pgsql_rag')->andReturn($connection);
        $connection->shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'pg_advisory_lock')
                && $bindings === [$identity])
            ->andReturn((object) ['locked' => null]);
        $connection->shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'ORDER BY updated_at DESC')
                && $bindings === ['file_catalog', 'file_registry', '42'])
            ->andReturnNull();
        $dedup->shouldReceive('checkDuplicate')
            ->once()
            ->withArgs(fn (string $content, ?string $title, ?string $sourceType, string|int|null $sourceId, array $options): bool => $content === 'new content'
                && $title === 'New title'
                && $sourceType === 'file_registry'
                && $sourceId === 42
                && ($options['skip_dedup'] ?? false) === true
                && ! array_key_exists('source_identity_upsert', $options))
            ->andReturn([
                'is_duplicate' => false,
                'action' => 'allow',
                'strategy' => null,
                'matched_document_id' => null,
                'similarity' => null,
                'content_hash' => hash('sha256', 'new content'),
            ]);
        $ai->shouldReceive('generateEmbedding')->once()->with('new content', [])->andReturn([
            'success' => true,
            'embedding' => [0.1, 0.2],
            'provider' => 'ollama',
            'model' => 'nomic-embed-text',
            'chunked' => false,
        ]);
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'INSERT INTO rag_documents')
                && in_array('42', array_map('strval', $bindings), true))
            ->andReturn([(object) ['id' => 88]]);
        $connection->shouldReceive('select')
            ->once()
            ->with('SELECT * FROM rag_documents WHERE id = ? LIMIT 1', [88])
            ->andReturn([(object) ['id' => 88]]);
        $connection->shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'pg_advisory_unlock')
                && $bindings === [$identity])
            ->andReturn((object) ['unlocked' => true]);

        $document = (new RAGService($ai, null, null, $dedup))->indexDocument(
            'file_catalog',
            'new content',
            'New title',
            sourceId: 42,
            sourceType: 'file_registry',
            options: ['source_identity_upsert' => true],
        );

        $this->assertSame(88, $document->id);
    }
}
