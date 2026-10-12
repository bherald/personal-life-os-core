<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RagEmbeddingProvenanceService
{
    public const METADATA_KEY = '_plos_embedding';

    public const SCHEMA = 'plos_rag_embedding_provenance.v1';

    private const EMBEDDING_CONTRACT = 'document-vector-v1';

    /**
     * @param  array<string, mixed>|null  $metadata
     * @param  array<string, mixed>  $embeddingResult
     * @return array<string, mixed>
     */
    public function attach(?array $metadata, array $embeddingResult, string $inputHash): array
    {
        $embedding = is_array($embeddingResult['embedding'] ?? null)
            ? $embeddingResult['embedding']
            : [];
        $provider = $this->nullableString($embeddingResult['provider'] ?? null);
        $instanceId = $this->nullableString($embeddingResult['instance_id'] ?? null) ?? $provider;
        $model = $this->nullableString($embeddingResult['model'] ?? null);
        $chunked = (bool) ($embeddingResult['chunked'] ?? false);
        $chunkCount = $chunked ? max(1, (int) ($embeddingResult['chunk_count'] ?? 1)) : 1;

        $contract = [
            'schema' => self::SCHEMA,
            'contract' => self::EMBEDDING_CONTRACT,
            'provider' => $provider,
            'instance_id' => $instanceId,
            'model' => $model,
            'dimensions' => count($embedding),
            'chunked' => $chunked,
            'chunk_count' => $chunkCount,
            'pooling' => $chunked ? 'arithmetic_mean_v1' : 'none',
        ];
        $contractFingerprint = hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR));

        $metadata ??= [];
        $metadata[self::METADATA_KEY] = [
            ...$contract,
            'contract_fingerprint' => $contractFingerprint,
            'document_fingerprint' => hash('sha256', $inputHash.'|'.$contractFingerprint),
            'input_hash' => $inputHash,
            'recorded_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];

        return $metadata;
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
                            NULLIF(metadata->?->>'provider', '') IS NULL
                            OR NULLIF(metadata->?->>'instance_id', '') IS NULL
                            OR NULLIF(metadata->?->>'model', '') IS NULL
                            OR COALESCE(metadata->?->>'dimensions', '') !~ '^[1-9][0-9]*$'
                            OR NULLIF(metadata->?->>'contract_fingerprint', '') IS NULL
                          )
                    ) AS incomplete
                 FROM rag_documents",
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
                    metadata->?->>'provider' AS provider,
                    metadata->?->>'instance_id' AS instance_id,
                    metadata->?->>'model' AS model,
                    CASE
                        WHEN COALESCE(metadata->?->>'dimensions', '') ~ '^[1-9][0-9]*$'
                        THEN (metadata->?->>'dimensions')::integer
                        ELSE 0
                    END AS dimensions,
                    metadata->?->>'chunked' = 'true' AS chunked,
                    COUNT(*) AS documents,
                    MIN(created_at) AS first_document_at,
                    MAX(updated_at) AS last_document_at
                 FROM rag_documents
                 WHERE jsonb_exists(metadata, ?)
                   AND metadata->?->>'schema' = ?
                 GROUP BY 1, 2, 3, 4, 5, 6
                 ORDER BY documents DESC, contract_fingerprint
                 LIMIT ?",
                [
                    self::METADATA_KEY,
                    self::METADATA_KEY,
                    self::METADATA_KEY,
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
            Log::warning('RAG embedding provenance audit failed', [
                'exception' => $e::class,
            ]);

            return [
                'schema' => 'plos_rag_embedding_provenance_audit.v1',
                'mode' => 'observe',
                'read_only' => true,
                'status' => 'unavailable',
                'evidence_error' => 'rag_embedding_provenance_query_failed',
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
            'schema' => 'plos_rag_embedding_provenance_audit.v1',
            'mode' => 'observe',
            'read_only' => true,
            'status' => ($legacyOrMissing === 0 && $oldSchema === 0 && $incomplete === 0)
                ? 'observe_ok'
                : 'observe_debt',
            'evidence_error' => null,
            'summary' => [
                'documents' => $total,
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
                    'provider' => $this->nullableString($row->provider ?? null),
                    'instance_id' => $this->nullableString($row->instance_id ?? null),
                    'model' => $this->nullableString($row->model ?? null),
                    'dimensions' => (int) ($row->dimensions ?? 0),
                    'chunked' => (bool) ($row->chunked ?? false),
                    'documents' => (int) ($row->documents ?? 0),
                    'first_document_at' => $this->nullableString($row->first_document_at ?? null),
                    'last_document_at' => $this->nullableString($row->last_document_at ?? null),
                ],
                $contracts
            ),
        ];
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
