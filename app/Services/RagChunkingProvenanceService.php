<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class RagChunkingProvenanceService
{
    public const METADATA_KEY = '_plos_chunking';

    public const SCHEMA = 'plos_rag_chunking_provenance.v1';

    private const CONTRACT = 'semantic-chunking-v1';

    private const IMPLEMENTATION = 'semantic-chunker-service-v1';

    /**
     * @param  array<int, string>  $chunks
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function record(string $inputHash, array $chunks, array $options = []): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $inputHash)) {
            throw new InvalidArgumentException('Chunking provenance requires a lowercase SHA-256 input hash.');
        }

        if ($chunks === [] || array_filter($chunks, static fn (mixed $chunk): bool => ! is_string($chunk)) !== []) {
            throw new InvalidArgumentException('Chunking provenance requires one or more string chunks.');
        }

        $contract = [
            'schema' => self::SCHEMA,
            'contract' => self::CONTRACT,
            'implementation' => self::IMPLEMENTATION,
            'strategy' => 'semantic_topic_shift_with_fixed_size_fallback_v1',
            'options' => $this->normalizeOptions($options),
        ];
        $contractFingerprint = hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR));
        $chunkSetFingerprint = hash('sha256', json_encode(
            array_map(static fn (string $chunk): string => hash('sha256', $chunk), $chunks),
            JSON_THROW_ON_ERROR
        ));

        return [
            ...$contract,
            'contract_fingerprint' => $contractFingerprint,
            'input_hash' => $inputHash,
            'output_chunk_count' => count($chunks),
            'chunk_set_fingerprint' => $chunkSetFingerprint,
            'document_fingerprint' => hash('sha256', $inputHash.'|'.$contractFingerprint.'|'.$chunkSetFingerprint),
            'recorded_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function attachRecord(?array $metadata, array $record): array
    {
        if (($record['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException('Chunking provenance record schema is invalid.');
        }

        $metadata ??= [];
        $metadata[self::METADATA_KEY] = $record;

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, int|string>
     */
    public function normalizeOptions(array $options): array
    {
        return [
            'min_content_for_chunking' => (int) ($options['min_content_for_chunking'] ?? 1000),
            'breakpoint_percentile' => (int) ($options['breakpoint_percentile'] ?? 90),
            'breakpoint_method' => strtolower(trim((string) ($options['breakpoint_method'] ?? 'percentile'))),
            'min_chunk_size' => (int) ($options['min_chunk_size'] ?? 100),
            'max_chunk_size' => (int) ($options['max_chunk_size'] ?? 2000),
            'overlap_percent' => (int) ($options['overlap_percent'] ?? 15),
            'min_sentences_per_chunk' => (int) ($options['min_sentences_per_chunk'] ?? 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function audit(int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));

        try {
            $summary = DB::connection('pgsql_rag')->selectOne(
                "SELECT
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE jsonb_exists(metadata, ?)) AS captured,
                    COUNT(*) FILTER (
                        WHERE jsonb_exists(metadata, ?)
                          AND metadata->?->>'schema' = ?
                    ) AS current_schema,
                    COUNT(*) FILTER (
                        WHERE jsonb_exists(metadata, ?)
                          AND (
                            COALESCE(metadata->?->>'contract_fingerprint', '') !~ '^[a-f0-9]{64}$'
                            OR COALESCE(metadata->?->>'document_fingerprint', '') !~ '^[a-f0-9]{64}$'
                            OR COALESCE(metadata->?->>'chunk_set_fingerprint', '') !~ '^[a-f0-9]{64}$'
                            OR COALESCE(metadata->?->>'input_hash', '') !~ '^[a-f0-9]{64}$'
                            OR COALESCE(metadata->?->>'output_chunk_count', '') !~ '^[1-9][0-9]*$'
                          )
                    ) AS incomplete
                 FROM rag_documents
                 WHERE metadata->>'chunking_method' = 'semantic'",
                [
                    self::METADATA_KEY,
                    self::METADATA_KEY,
                    self::METADATA_KEY,
                    self::SCHEMA,
                    self::METADATA_KEY,
                    self::METADATA_KEY,
                    self::METADATA_KEY,
                    self::METADATA_KEY,
                    self::METADATA_KEY,
                    self::METADATA_KEY,
                ]
            );

            $contracts = DB::connection('pgsql_rag')->select(
                "SELECT
                    metadata->?->>'contract_fingerprint' AS contract_fingerprint,
                    metadata->?->>'implementation' AS implementation,
                    metadata->?->>'strategy' AS strategy,
                    metadata->?->'options' AS options,
                    COUNT(*) AS chunks,
                    MIN(created_at) AS first_chunk_at,
                    MAX(updated_at) AS last_chunk_at
                 FROM rag_documents
                 WHERE metadata->>'chunking_method' = 'semantic'
                   AND jsonb_exists(metadata, ?)
                   AND metadata->?->>'schema' = ?
                 GROUP BY 1, 2, 3, 4
                 ORDER BY chunks DESC, contract_fingerprint
                 LIMIT ?",
                [
                    self::METADATA_KEY,
                    self::METADATA_KEY,
                    self::METADATA_KEY,
                    self::METADATA_KEY,
                    self::METADATA_KEY,
                    self::METADATA_KEY,
                    self::SCHEMA,
                    $limit,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('RAG chunking provenance audit failed', [
                'exception' => $e::class,
            ]);

            return [
                'schema' => 'plos_rag_chunking_provenance_audit.v1',
                'mode' => 'observe',
                'read_only' => true,
                'status' => 'unavailable',
                'evidence_error' => 'rag_chunking_provenance_query_failed',
                'summary' => null,
                'contracts' => [],
            ];
        }

        $total = max(0, (int) ($summary->total ?? 0));
        $captured = max(0, (int) ($summary->captured ?? 0));
        $currentSchema = max(0, (int) ($summary->current_schema ?? 0));
        $incomplete = max(0, (int) ($summary->incomplete ?? 0));
        $legacyOrMissing = max(0, $total - $captured);
        $oldSchema = max(0, $captured - $currentSchema);

        return [
            'schema' => 'plos_rag_chunking_provenance_audit.v1',
            'mode' => 'observe',
            'read_only' => true,
            'status' => ($legacyOrMissing === 0 && $oldSchema === 0 && $incomplete === 0)
                ? 'observe_ok'
                : 'observe_debt',
            'evidence_error' => null,
            'summary' => [
                'semantic_chunks' => $total,
                'captured' => $captured,
                'current_schema' => $currentSchema,
                'legacy_or_missing' => $legacyOrMissing,
                'old_schema' => $oldSchema,
                'incomplete' => $incomplete,
                'coverage_percent' => $total === 0 ? 100.0 : round(($currentSchema / $total) * 100, 2),
            ],
            'contracts' => array_map(
                fn (object $row): array => [
                    'contract_fingerprint' => (string) ($row->contract_fingerprint ?? ''),
                    'implementation' => $this->nullableString($row->implementation ?? null),
                    'strategy' => $this->nullableString($row->strategy ?? null),
                    'options' => $this->decodeOptions($row->options ?? null),
                    'chunks' => (int) ($row->chunks ?? 0),
                    'first_chunk_at' => $this->nullableString($row->first_chunk_at ?? null),
                    'last_chunk_at' => $this->nullableString($row->last_chunk_at ?? null),
                ],
                $contracts
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeOptions(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
