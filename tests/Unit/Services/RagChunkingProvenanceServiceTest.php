<?php

namespace Tests\Unit\Services;

use App\Services\RagChunkingProvenanceService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RagChunkingProvenanceServiceTest extends TestCase
{
    public function test_record_normalizes_effective_options_without_retaining_content_or_chunk_hashes(): void
    {
        $service = new RagChunkingProvenanceService;
        $record = $service->record(
            str_repeat('a', 64),
            ['private first chunk', 'private second chunk'],
            [
                'breakpoint_method' => ' Gradient ',
                'breakpoint_percentile' => '85',
                'max_chunk_size' => 1500,
                'ignored_option' => 'not part of the runtime contract',
            ]
        );

        $this->assertSame(RagChunkingProvenanceService::SCHEMA, $record['schema']);
        $this->assertSame('gradient', $record['options']['breakpoint_method']);
        $this->assertSame(85, $record['options']['breakpoint_percentile']);
        $this->assertSame(1500, $record['options']['max_chunk_size']);
        $this->assertSame(2, $record['output_chunk_count']);
        $this->assertArrayNotHasKey('ignored_option', $record['options']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $record['contract_fingerprint']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $record['chunk_set_fingerprint']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $record['document_fingerprint']);
        $this->assertStringNotContainsString('private', json_encode($record));
        $this->assertArrayNotHasKey('chunk_hashes', $record);
    }

    public function test_contract_and_document_fingerprints_change_for_configuration_and_output_drift(): void
    {
        $service = new RagChunkingProvenanceService;
        $inputHash = str_repeat('b', 64);
        $base = $service->record($inputHash, ['one', 'two']);
        $configChanged = $service->record($inputHash, ['one', 'two'], ['overlap_percent' => 20]);
        $outputChanged = $service->record($inputHash, ['one changed', 'two']);

        $this->assertNotSame($base['contract_fingerprint'], $configChanged['contract_fingerprint']);
        $this->assertSame($base['contract_fingerprint'], $outputChanged['contract_fingerprint']);
        $this->assertNotSame($base['chunk_set_fingerprint'], $outputChanged['chunk_set_fingerprint']);
        $this->assertNotSame($base['document_fingerprint'], $configChanged['document_fingerprint']);
        $this->assertNotSame($base['document_fingerprint'], $outputChanged['document_fingerprint']);
    }

    public function test_attach_record_overwrites_untrusted_metadata_value(): void
    {
        $service = new RagChunkingProvenanceService;
        $record = $service->record(str_repeat('c', 64), ['one']);
        $metadata = $service->attachRecord([
            'source' => 'fixture',
            RagChunkingProvenanceService::METADATA_KEY => ['schema' => 'spoofed'],
        ], $record);

        $this->assertSame('fixture', $metadata['source']);
        $this->assertSame($record, $metadata[RagChunkingProvenanceService::METADATA_KEY]);
    }

    public function test_audit_reports_semantic_chunk_coverage_without_chunk_content(): void
    {
        DB::shouldReceive('connection')->twice()->with('pgsql_rag')->andReturnSelf();
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $params): bool => str_contains($sql, "metadata->>'chunking_method' = 'semantic'")
                && in_array(RagChunkingProvenanceService::SCHEMA, $params, true))
            ->andReturn((object) [
                'total' => 100,
                'captured' => 25,
                'current_schema' => 24,
                'incomplete' => 1,
            ]);
        DB::shouldReceive('select')
            ->once()
            ->withArgs(fn (string $sql, array $params): bool => str_contains($sql, 'GROUP BY 1, 2, 3, 4')
                && end($params) === 5)
            ->andReturn([
                (object) [
                    'contract_fingerprint' => str_repeat('d', 64),
                    'implementation' => 'semantic-chunker-service-v1',
                    'strategy' => 'semantic_topic_shift_with_fixed_size_fallback_v1',
                    'options' => json_encode(['max_chunk_size' => 2000]),
                    'chunks' => 24,
                    'first_chunk_at' => '2026-07-15 10:00:00',
                    'last_chunk_at' => '2026-07-15 11:00:00',
                ],
            ]);

        $payload = (new RagChunkingProvenanceService)->audit(5);

        $this->assertSame('observe_debt', $payload['status']);
        $this->assertSame(75, $payload['summary']['legacy_or_missing']);
        $this->assertSame(1, $payload['summary']['old_schema']);
        $this->assertSame(24.0, $payload['summary']['coverage_percent']);
        $this->assertSame(2000, $payload['contracts'][0]['options']['max_chunk_size']);
        $this->assertArrayNotHasKey('content', $payload['contracts'][0]);
    }

    public function test_audit_caps_contract_detail_query_at_observability_ceiling(): void
    {
        DB::shouldReceive('connection')->twice()->with('pgsql_rag')->andReturnSelf();
        DB::shouldReceive('selectOne')->once()->andReturn((object) [
            'total' => 0,
            'captured' => 0,
            'current_schema' => 0,
            'incomplete' => 0,
        ]);
        DB::shouldReceive('select')
            ->once()
            ->withArgs(fn (string $sql, array $params): bool => str_contains($sql, 'LIMIT ?')
                && end($params) === 100)
            ->andReturn([]);

        $payload = (new RagChunkingProvenanceService)->audit(1000);

        $this->assertSame('observe_ok', $payload['status']);
        $this->assertSame([], $payload['contracts']);
    }
}
