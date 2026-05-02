<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RAG-3 — SPLADE Sparse Vector Service
 *
 * Provides learned sparse encoding via SPLADE model (naver/splade-cocondenser-ensembledistil).
 * Encodes text → sparse vector (30522-dim BERT vocab), stores in pgvector sparsevec column.
 *
 * Three integration points:
 * 1. encode() — Convert text to sparse vector via Python script
 * 2. search() — Find documents by sparse vector inner product similarity
 * 3. batchIndex() — Encode and store sparse vectors for multiple documents
 *
 * Uses ComputeRouterService for Python execution with circuit breaker.
 */
class SPLADEService
{
    private ?ComputeRouterService $computeRouter = null;
    private ?bool $available = null;

    private const VOCAB_SIZE = 30522; // BERT vocab size
    private const BATCH_SIZE = 10;    // Documents per Python call

    private function getComputeRouter(): ComputeRouterService
    {
        if ($this->computeRouter === null) {
            $this->computeRouter = app(ComputeRouterService::class);
        }
        return $this->computeRouter;
    }

    /**
     * Check if SPLADE encoding is available (compute instance with capability).
     */
    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        // SPLADE runs on CPU — check if any compute instance can run Python scripts
        // Use 'nlp' capability as proxy (same Python env)
        $this->available = $this->getComputeRouter()->route('nlp') !== null;
        return $this->available;
    }

    /**
     * Encode text(s) to SPLADE sparse vectors.
     *
     * @param string|string[] $texts Single text or array of texts
     * @return array Array of ['indices' => int[], 'weights' => float[]] per text, or empty on failure
     */
    public function encode(string|array $texts): array
    {
        if (is_string($texts)) {
            $texts = [$texts];
        }

        if (empty($texts)) {
            return [];
        }

        try {
            $input = json_encode(['texts' => $texts], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
            $result = $this->getComputeRouter()->executeScript(
                'nlp', // Use NLP compute instance (CPU, has transformers installed)
                'splade_encode.py',
                $input,
                [],
                30 // 30s timeout for batch
            );

            if (!$result['success'] || empty($result['output'])) {
                Log::warning('SPLADEService: encode failed', [
                    'error' => $result['error'] ?? 'no output',
                    'text_count' => count($texts),
                ]);
                return [];
            }

            $output = $this->extractJsonObject($result['output']);
            $parsed = json_decode($output, true);
            if (!is_array($parsed) || !isset($parsed['vectors'])) {
                Log::warning('SPLADEService: invalid output format', [
                    'output_preview' => mb_substr($result['output'] ?? '', 0, 200),
                    'json_error' => json_last_error_msg(),
                ]);
                return [];
            }

            if (!empty($parsed['error'])) {
                Log::warning('SPLADEService: script returned error', ['error' => $parsed['error']]);
                return [];
            }

            return $parsed['vectors'];

        } catch (\Exception $e) {
            Log::warning('SPLADEService: encode exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function extractJsonObject(string $output): string
    {
        $trimmed = trim($output);
        if (str_starts_with($trimmed, '{')) {
            return $trimmed;
        }

        $start = mb_strpos($trimmed, '{');
        if ($start === false) {
            return $trimmed;
        }

        return mb_substr($trimmed, $start);
    }

    /**
     * Search documents by SPLADE sparse vector similarity.
     * Uses inner product between query sparse vector and stored sparse vectors.
     *
     * @param string $query Query text
     * @param int $limit Max results
     * @param string|null $documentType Filter by document_type
     * @return array Search results: [['document' => object, 'similarity' => float], ...]
     */
    public function search(string $query, int $limit = 10, ?string $documentType = null): array
    {
        $vectors = $this->encode($query);
        if (empty($vectors)) {
            return [];
        }

        $sparseVec = $vectors[0];
        if (empty($sparseVec['indices'])) {
            return [];
        }

        // Build pgvector sparsevec literal: {idx1:weight1,idx2:weight2,...}/dimension
        $sparseLiteral = $this->toSparsevecLiteral($sparseVec);

        $params = [];
        $whereClauses = ['sparse_embedding IS NOT NULL'];

        if ($documentType) {
            $whereClauses[] = 'document_type = ?';
            $params[] = $documentType;
        }

        $where = implode(' AND ', $whereClauses);
        $params[] = $limit;

        // Inner product similarity (higher = more similar for SPLADE)
        $sql = "SELECT id, title, content, document_type, metadata, created_at, media_url,
                       (sparse_embedding <#> '{$sparseLiteral}'::sparsevec) * -1 AS sparse_similarity
                FROM rag_documents
                WHERE {$where}
                ORDER BY sparse_embedding <#> '{$sparseLiteral}'::sparsevec ASC
                LIMIT ?";

        try {
            $results = DB::connection('pgsql_rag')->select($sql, $params);

            return array_map(fn($doc) => [
                'document' => $doc,
                'similarity' => (float) ($doc->sparse_similarity ?? 0),
            ], $results);

        } catch (\Exception $e) {
            Log::warning('SPLADEService: search failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Batch encode and store sparse vectors for documents.
     *
     * @param int $limit Max documents to process
     * @return array ['indexed' => int, 'failed' => int, 'skipped' => int]
     */
    public function batchIndex(int $limit = 50): array
    {
        // Find documents without sparse embeddings
        $docs = DB::connection('pgsql_rag')->select("
            SELECT id, title, content
            FROM rag_documents
            WHERE sparse_embedding IS NULL AND content IS NOT NULL AND LENGTH(content) > 50
            ORDER BY created_at DESC
            LIMIT ?
        ", [$limit]);

        if (empty($docs)) {
            return ['indexed' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $indexed = 0;
        $failed = 0;

        // Process in batches
        $batches = array_chunk($docs, self::BATCH_SIZE);
        foreach ($batches as $batch) {
            $texts = array_map(fn($doc) => mb_substr($doc->title . "\n" . $doc->content, 0, 2000), $batch);
            $vectors = $this->encode($texts);

            if (empty($vectors) || count($vectors) !== count($batch)) {
                $failed += count($batch);
                continue;
            }

            foreach ($batch as $i => $doc) {
                $vec = $vectors[$i] ?? null;
                if (!$vec || empty($vec['indices'])) {
                    $failed++;
                    continue;
                }

                try {
                    $literal = $this->toSparsevecLiteral($vec);
                    DB::connection('pgsql_rag')->statement(
                        "UPDATE rag_documents SET sparse_embedding = ?::sparsevec, splade_indexed_at = NOW() WHERE id = ?",
                        [$literal, $doc->id]
                    );
                    $indexed++;
                } catch (\Exception $e) {
                    Log::debug('SPLADEService: failed to store sparse vector', [
                        'doc_id' => $doc->id,
                        'error' => $e->getMessage(),
                    ]);
                    $failed++;
                }
            }
        }

        Log::info('SPLADEService: batch index complete', [
            'indexed' => $indexed,
            'failed' => $failed,
            'total' => count($docs),
        ]);

        return ['indexed' => $indexed, 'failed' => $failed, 'skipped' => 0];
    }

    /**
     * Convert sparse vector to pgvector sparsevec literal format.
     * Format: {idx1:weight1,idx2:weight2,...}/dimension
     */
    private function toSparsevecLiteral(array $vec): string
    {
        $parts = [];
        $indices = $vec['indices'] ?? [];
        $weights = $vec['weights'] ?? [];

        for ($i = 0; $i < count($indices); $i++) {
            $idx = (int) $indices[$i];
            $weight = round((float) ($weights[$i] ?? 0), 4);
            if ($weight > 0) {
                $parts[] = "{$idx}:{$weight}";
            }
        }

        return '{' . implode(',', $parts) . '}/' . self::VOCAB_SIZE;
    }
}
