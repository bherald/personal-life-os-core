<?php

namespace Tests\Unit\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\AIService;
use App\Services\RagEmbeddingProvenanceService;
use App\Services\RAGService;
use App\Services\SemDeDupService;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RagEmbeddingProvenanceServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_attach_preserves_metadata_and_records_content_free_embedding_contract(): void
    {
        $service = new RagEmbeddingProvenanceService;

        $metadata = $service->attach(
            ['source' => 'fixture'],
            [
                'embedding' => [0.1, 0.2, 0.3],
                'provider' => 'ollama',
                'instance_id' => 'jetson',
                'model' => 'nomic-embed-text',
                'chunked' => true,
                'chunk_count' => 3,
            ],
            str_repeat('a', 64)
        );

        $record = $metadata[RagEmbeddingProvenanceService::METADATA_KEY];
        $this->assertSame('fixture', $metadata['source']);
        $this->assertSame(RagEmbeddingProvenanceService::SCHEMA, $record['schema']);
        $this->assertSame('ollama', $record['provider']);
        $this->assertSame('jetson', $record['instance_id']);
        $this->assertSame('nomic-embed-text', $record['model']);
        $this->assertSame(3, $record['dimensions']);
        $this->assertTrue($record['chunked']);
        $this->assertSame(3, $record['chunk_count']);
        $this->assertSame('arithmetic_mean_v1', $record['pooling']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $record['contract_fingerprint']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $record['document_fingerprint']);
        $this->assertArrayNotHasKey('content', $record);
        $this->assertArrayNotHasKey('embedding', $record);
    }

    public function test_available_revision_is_preserved_and_changes_contract_identity(): void
    {
        $service = new RagEmbeddingProvenanceService;
        $base = ['embedding' => [0.1, 0.2], 'provider' => 'ollama', 'instance_id' => 'selected', 'model' => 'model'];
        $first = $service->attach(null, $base + ['revision' => 'sha256:Original'], str_repeat('b', 64))[RagEmbeddingProvenanceService::METADATA_KEY];
        $second = $service->attach(null, $base + ['revision' => 'sha256:changed'], str_repeat('b', 64))[RagEmbeddingProvenanceService::METADATA_KEY];
        $this->assertSame('sha256:Original', $first['revision']);
        $this->assertNotSame($first['contract_fingerprint'], $second['contract_fingerprint']);
        $this->assertNotSame($first['document_fingerprint'], $second['document_fingerprint']);
    }

    public function test_missing_revision_remains_unknown_with_compatible_legacy_contract(): void
    {
        $base = ['embedding' => [0.1, 0.2], 'provider' => 'ollama', 'instance_id' => 'selected', 'model' => 'model'];
        $service = new RagEmbeddingProvenanceService;
        $missing = $service->attach(null, $base, str_repeat('b', 64))[RagEmbeddingProvenanceService::METADATA_KEY];
        $null = $service->attach(null, $base + ['revision' => null], str_repeat('b', 64))[RagEmbeddingProvenanceService::METADATA_KEY];
        $contract = ['schema' => RagEmbeddingProvenanceService::SCHEMA, 'contract' => 'document-vector-v1',
            'provider' => 'ollama', 'instance_id' => 'selected', 'model' => 'model', 'dimensions' => 2,
            'chunked' => false, 'chunk_count' => 1, 'pooling' => 'none'];
        $this->assertArrayHasKey('revision', $missing);
        $this->assertNull($missing['revision']);
        $this->assertSame(hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR)), $missing['contract_fingerprint']);
        $this->assertSame($missing['contract_fingerprint'], $null['contract_fingerprint']);
    }

    #[DataProvider('malformedRevisions')]
    public function test_malformed_revision_is_not_coerced_into_an_artifact(mixed $revision): void
    {
        $this->expectException(InferenceAdmissionException::class);
        (new RagEmbeddingProvenanceService)->attach(null, ['embedding' => [0.1], 'revision' => $revision], str_repeat('b', 64));
    }

    public static function malformedRevisions(): array
    {
        return [[false], [123], [1.5], [[]], [(object) ['digest' => 'x']], [''], [' ']];
    }

    #[DataProvider('invalidStrictProof')]
    public function test_invalid_strict_partition_is_held_before_storage(array $override): void
    {
        $source = 'Full source';
        $result = ['embedding' => [0.1, 0.2], 'provider' => 'ollama', 'instance_id' => 'selected', 'model' => 'selected-model',
            'chunked' => true, 'chunk_count' => 2, 'embedding_input_hash' => hash('sha256', $source),
            'embedding_input_bytes' => strlen($source), 'preserved_input_chunks' => [
                ['sha256' => hash('sha256', 'Full '), 'bytes' => 5], ['sha256' => hash('sha256', 'source'), 'bytes' => 6],
            ]];
        DB::shouldReceive('connection')->never();
        $this->expectException(InferenceAdmissionException::class);
        (new RagEmbeddingProvenanceService)->attach(null, array_replace($result, $override), hash('sha256', $source));
    }

    public static function invalidStrictProof(): array
    {
        return ['wrong input hash' => [['embedding_input_hash' => str_repeat('0', 64)]],
            'wrong source bytes' => [['embedding_input_bytes' => 12]],
            'wrong chunk count' => [['chunk_count' => 1]],
            'missing chunk hash' => [['preserved_input_chunks' => [['bytes' => 5], ['sha256' => str_repeat('a', 64), 'bytes' => 6]]]],
            'extra chunk fields' => [['preserved_input_chunks' => [['sha256' => str_repeat('a', 64), 'bytes' => 5, 'content' => 'private'], ['sha256' => str_repeat('b', 64), 'bytes' => 6]]]],
            'fraction bytes' => [['embedding_input_bytes' => 11.0]]];
    }

    public function test_contract_fingerprint_changes_when_the_embedding_model_changes(): void
    {
        $service = new RagEmbeddingProvenanceService;
        $base = [
            'embedding' => [0.1, 0.2],
            'provider' => 'ollama',
            'model' => 'model-a',
            'chunked' => false,
        ];

        $first = $service->attach(null, $base, str_repeat('b', 64));
        $second = $service->attach(null, [...$base, 'model' => 'model-b'], str_repeat('b', 64));

        $this->assertNotSame(
            $first[RagEmbeddingProvenanceService::METADATA_KEY]['contract_fingerprint'],
            $second[RagEmbeddingProvenanceService::METADATA_KEY]['contract_fingerprint']
        );
    }

    public function test_audit_reports_legacy_coverage_and_distinct_contracts_without_document_content(): void
    {
        DB::shouldReceive('connection')->twice()->with('pgsql_rag')->andReturnSelf();
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $params): bool => str_contains($sql, 'jsonb_exists(metadata')
                && in_array(RagEmbeddingProvenanceService::SCHEMA, $params, true))
            ->andReturn((object) [
                'total' => 100,
                'captured' => 12,
                'current_schema' => 10,
                'incomplete' => 1,
            ]);
        DB::shouldReceive('select')
            ->once()
            ->withArgs(fn (string $sql, array $params): bool => str_contains($sql, 'GROUP BY 1, 2, 3, 4, 5, 6')
                && end($params) === 5)
            ->andReturn([
                (object) [
                    'contract_fingerprint' => str_repeat('c', 64),
                    'provider' => 'ollama',
                    'instance_id' => 'jetson',
                    'model' => 'nomic-embed-text',
                    'dimensions' => 768,
                    'chunked' => false,
                    'documents' => 10,
                    'first_document_at' => '2026-07-13 10:00:00',
                    'last_document_at' => '2026-07-13 11:00:00',
                ],
            ]);

        $payload = (new RagEmbeddingProvenanceService)->audit(5);

        $this->assertSame('observe_debt', $payload['status']);
        $this->assertTrue($payload['read_only']);
        $this->assertSame(88, $payload['summary']['legacy_or_missing']);
        $this->assertSame(2, $payload['summary']['old_schema']);
        $this->assertSame(10.0, $payload['summary']['coverage_percent']);
        $this->assertSame('nomic-embed-text', $payload['contracts'][0]['model']);
        $this->assertSame('jetson', $payload['contracts'][0]['instance_id']);
        $this->assertArrayNotHasKey('content', $payload['contracts'][0]);
    }

    public function test_new_rag_document_persists_actual_embedding_provenance(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateEmbedding')
            ->once()
            ->with('indexed text', [])
            ->andReturn([
                'success' => true,
                'embedding' => [0.1, 0.2, 0.3],
                'provider' => 'jetson',
                'model' => 'nomic-embed-text',
                'chunked' => false,
            ]);

        DB::shouldReceive('connection')->twice()->with('pgsql_rag')->andReturnSelf();
        DB::shouldReceive('select')
            ->once()
            ->withArgs(function (string $sql, array $params): bool {
                if (! str_contains($sql, 'INSERT INTO rag_documents')) {
                    return false;
                }

                $metadata = json_decode((string) $params[4], true);
                $record = $metadata[RagEmbeddingProvenanceService::METADATA_KEY] ?? [];

                return ($metadata['source'] ?? null) === 'unit'
                    && ($record['provider'] ?? null) === 'jetson'
                    && ($record['model'] ?? null) === 'nomic-embed-text'
                    && ($record['dimensions'] ?? null) === 3;
            })
            ->andReturn([(object) ['id' => 42]]);
        DB::shouldReceive('select')
            ->once()
            ->withArgs(fn (string $sql, array $params): bool => str_contains($sql, 'SELECT * FROM rag_documents')
                && $params === [42])
            ->andReturn([(object) ['id' => 42]]);

        $document = (new RAGService($ai))->indexDocument(
            'unit',
            'indexed text',
            title: 'Indexed fixture',
            metadata: ['source' => 'unit'],
            options: ['skip_dedup' => true]
        );

        $this->assertSame(42, $document?->id);
    }

    public function test_document_update_merges_provenance_into_existing_metadata_when_none_is_supplied(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateEmbedding')
            ->once()
            ->with('updated text')
            ->andReturn([
                'success' => true,
                'embedding' => [0.4, 0.5],
                'provider' => 'ollama',
                'model' => 'nomic-embed-text',
                'chunked' => false,
            ]);

        DB::shouldReceive('connection')->once()->with('pgsql_rag')->andReturnSelf();
        DB::shouldReceive('update')
            ->once()
            ->withArgs(function (string $sql, array $params): bool {
                $metadata = json_decode((string) $params[2], true);

                return str_contains($sql, "metadata = COALESCE(metadata, '{}'::jsonb) || ?::jsonb")
                    && ($metadata[RagEmbeddingProvenanceService::METADATA_KEY]['dimensions'] ?? null) === 2
                    && end($params) === 9;
            })
            ->andReturn(1);

        $updated = (new SemDeDupService($ai))->updateExisting(9, ' updated text ', null, null);

        $this->assertTrue($updated);
    }
}
