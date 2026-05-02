<?php

namespace App\Services;

use App\Support\PgVector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Traits\RecursionAware;
use Exception;

/**
 * Semantic Deduplication Service for RAG Documents
 *
 * Prevents near-duplicate documents from entering the RAG index using
 * a multi-strategy approach:
 * 1. Exact hash match (SHA-256 of normalized content)
 * 2. Title + source match (same document re-indexed)
 * 3. Embedding similarity (cosine similarity via pgvector)
 *
 * Each strategy returns early if a match is found, minimizing computation.
 */
class SemDeDupService
{
    use RecursionAware;

    private AIService $aiService;

    /** Default similarity threshold for blocking duplicates */
    private const DEFAULT_BLOCK_THRESHOLD = 0.95;

    /** Warning threshold - similar but allowed through */
    private const DEFAULT_WARN_THRESHOLD = 0.85;

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Check if content is a duplicate of existing RAG documents
     *
     * Runs strategies in order: exact hash → title+source → embedding similarity.
     * Returns early on first match.
     *
     * @param string $content Document content
     * @param string|null $title Document title
     * @param string|null $sourceType Source type
     * @param string|int|null $sourceId Source identifier
     * @param array $options {
     *   skip_dedup: bool (force index, skip all checks),
     *   dedup_threshold: float (override block threshold),
     *   on_match: string (skip|merge|update),
     * }
     * @return array {
     *   is_duplicate: bool,
     *   action: string (block|merge|update|warn|allow),
     *   strategy: string|null,
     *   matched_document_id: int|null,
     *   similarity: float|null,
     *   content_hash: string,
     * }
     */
    public function checkDuplicate(
        string $content,
        ?string $title = null,
        ?string $sourceType = null,
        string|int|null $sourceId = null,
        array $options = []
    ): array {
        if (!empty($options['skip_dedup'])) {
            $hash = $this->computeContentHash($content);
            return [
                'is_duplicate' => false,
                'action' => 'allow',
                'strategy' => null,
                'matched_document_id' => null,
                'similarity' => null,
                'content_hash' => $hash,
            ];
        }

        // RLM: Try recursive deduplication check
        $rlm = $this->tryRecursive('sem_dedup', 'quality_gate_retry', ['content' => $content, 'title' => $title, 'source_type' => $sourceType, 'source_id' => $sourceId, 'options' => $options], function ($ctx) {
            return $this->checkDuplicate($ctx['content'] ?? $ctx['data'], $ctx['title'] ?? null, $ctx['source_type'] ?? null, $ctx['source_id'] ?? null, $ctx['options'] ?? []);
        });
        if ($rlm !== null) {
            return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
        }

        $normalized = $this->normalizeContent($content);
        $contentHash = $this->computeContentHash($content);
        $blockThreshold = $options['dedup_threshold'] ?? self::DEFAULT_BLOCK_THRESHOLD;
        $onMatch = $options['on_match'] ?? 'skip';

        // Strategy 1: Exact hash match
        $hashMatch = $this->checkExactHash($contentHash);
        if ($hashMatch) {
            $this->logDedupResult($title, $sourceType, $contentHash, 'exact_hash', $hashMatch, 1.0, 'blocked');
            return [
                'is_duplicate' => true,
                'action' => 'block',
                'strategy' => 'exact_hash',
                'matched_document_id' => $hashMatch,
                'similarity' => 1.0,
                'content_hash' => $contentHash,
            ];
        }

        // Strategy 2: Title + source match
        if ($title && $sourceType) {
            $titleMatch = $this->checkTitleSource($title, $sourceType, $sourceId);
            if ($titleMatch) {
                $action = $onMatch === 'update' ? 'update' : ($onMatch === 'merge' ? 'merge' : 'block');
                $this->logDedupResult($title, $sourceType, $contentHash, 'title_source', $titleMatch, null, $action);
                return [
                    'is_duplicate' => true,
                    'action' => $action,
                    'strategy' => 'title_source',
                    'matched_document_id' => $titleMatch,
                    'similarity' => null,
                    'content_hash' => $contentHash,
                ];
            }
        }

        // Strategy 3: Embedding similarity (near-duplicate detection)
        $embeddingResult = $this->checkEmbeddingSimilarity($content, $blockThreshold);
        if ($embeddingResult) {
            $this->logDedupResult(
                $title, $sourceType, $contentHash, 'embedding',
                $embeddingResult['document_id'], $embeddingResult['similarity'], 'blocked'
            );
            return [
                'is_duplicate' => true,
                'action' => 'block',
                'strategy' => 'embedding',
                'matched_document_id' => $embeddingResult['document_id'],
                'similarity' => $embeddingResult['similarity'],
                'content_hash' => $contentHash,
            ];
        }

        // Strategy 3b: Warning-level similarity
        $warnResult = $this->checkEmbeddingSimilarity($content, self::DEFAULT_WARN_THRESHOLD);
        if ($warnResult) {
            $this->logDedupResult(
                $title, $sourceType, $contentHash, 'embedding_warn',
                $warnResult['document_id'], $warnResult['similarity'], 'warned'
            );
            Log::warning('SemDedup: Near-duplicate detected but allowed', [
                'title' => $title,
                'matched_id' => $warnResult['document_id'],
                'similarity' => $warnResult['similarity'],
            ]);
            return [
                'is_duplicate' => false,
                'action' => 'warn',
                'strategy' => 'embedding_warn',
                'matched_document_id' => $warnResult['document_id'],
                'similarity' => $warnResult['similarity'],
                'content_hash' => $contentHash,
            ];
        }

        // No duplicate found
        $this->logDedupResult($title, $sourceType, $contentHash, 'none', null, null, 'allowed');
        return [
            'is_duplicate' => false,
            'action' => 'allow',
            'strategy' => null,
            'matched_document_id' => null,
            'similarity' => null,
            'content_hash' => $contentHash,
        ];
    }

    /**
     * Check for exact content hash match
     *
     * @return int|null Matched document ID or null
     */
    public function checkExactHash(string $contentHash): ?int
    {
        $result = DB::connection('pgsql_rag')->selectOne(
            "SELECT id FROM rag_documents WHERE content_hash = ? LIMIT 1",
            [$contentHash]
        );

        return $result ? (int) $result->id : null;
    }

    /**
     * Check for title + source type match
     *
     * @return int|null Matched document ID or null
     */
    public function checkTitleSource(string $title, string $sourceType, string|int|null $sourceId = null): ?int
    {
        if ($sourceId !== null) {
            $result = DB::connection('pgsql_rag')->selectOne(
                "SELECT id FROM rag_documents WHERE title = ? AND source_type = ? AND source_id = ? LIMIT 1",
                [$title, $sourceType, (string) $sourceId]
            );
        } else {
            $result = DB::connection('pgsql_rag')->selectOne(
                "SELECT id FROM rag_documents WHERE title = ? AND source_type = ? LIMIT 1",
                [$title, $sourceType]
            );
        }

        return $result ? (int) $result->id : null;
    }

    /**
     * Check for near-duplicate via embedding cosine similarity
     *
     * Generates an embedding for the content and searches existing documents
     * using pgvector's cosine distance operator.
     *
     * @return array|null {document_id, similarity} or null
     */
    public function checkEmbeddingSimilarity(string $content, float $threshold): ?array
    {
        try {
            $result = $this->aiService->generateEmbedding($content);
            if (!$result['success']) {
                Log::warning('SemDedup: Embedding generation failed, skipping similarity check');
                return null;
            }

            $embeddingStr = PgVector::literal($result['embedding']);

            $match = DB::connection('pgsql_rag')->selectOne(
                "SELECT id, title,
                        1 - (embedding <=> '{$embeddingStr}'::vector) as similarity
                 FROM rag_documents
                 WHERE embedding IS NOT NULL
                 ORDER BY embedding <=> '{$embeddingStr}'::vector ASC
                 LIMIT 1"
            );

            if ($match && (float) $match->similarity >= $threshold) {
                return [
                    'document_id' => (int) $match->id,
                    'similarity' => round((float) $match->similarity, 5),
                ];
            }
        } catch (Exception $e) {
            Log::warning('SemDedup: Embedding similarity check failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Normalize content for consistent hashing
     *
     * Lowercases, collapses whitespace, strips non-alphanumeric characters.
     */
    public function normalizeContent(string $content): string
    {
        $content = mb_strtolower($content);
        $content = preg_replace('/\s+/', ' ', $content);
        $content = preg_replace('/[^\p{L}\p{N}\s]/u', '', $content);
        return trim($content);
    }

    /**
     * Compute SHA-256 hash of normalized content
     */
    public function computeContentHash(string $content): string
    {
        return hash('sha256', $this->normalizeContent($content));
    }

    /**
     * Log a dedup decision to the audit log
     */
    public function logDedupResult(
        ?string $title,
        ?string $sourceType,
        string $contentHash,
        string $strategy,
        ?int $matchedDocumentId,
        ?float $similarity,
        string $actionTaken
    ): void {
        try {
            DB::connection('pgsql_rag')->insert(
                "INSERT INTO rag_dedup_log (incoming_title, incoming_source_type, incoming_content_hash, strategy, matched_document_id, similarity_score, action_taken, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $title ? substr($title, 0, 500) : null,
                    $sourceType ? substr($sourceType, 0, 100) : null,
                    $contentHash,
                    $strategy,
                    $matchedDocumentId,
                    $similarity,
                    $actionTaken,
                ]
            );
        } catch (Exception $e) {
            Log::warning('SemDedup: Failed to log dedup result', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Merge metadata from new document into existing one
     *
     * JSONB merge: existing metadata is updated with new keys.
     */
    public function mergeMetadata(int $existingDocId, ?array $newMetadata): bool
    {
        if (empty($newMetadata)) {
            return true;
        }

        try {
            $metaJson = json_encode($newMetadata);
            DB::connection('pgsql_rag')->update(
                "UPDATE rag_documents
                 SET metadata = COALESCE(metadata, '{}'::jsonb) || ?::jsonb,
                     updated_at = NOW()
                 WHERE id = ?",
                [$metaJson, $existingDocId]
            );
            return true;
        } catch (Exception $e) {
            Log::error('SemDedup: Metadata merge failed', ['doc_id' => $existingDocId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Update existing document content, re-generate embedding
     */
    public function updateExisting(int $existingDocId, string $content, ?string $title, ?array $metadata): bool
    {
        try {
            $trimmed = trim($content);
            $result = $this->aiService->generateEmbedding($trimmed);
            if (!$result['success']) {
                Log::error('SemDedup: Re-embedding failed for update', ['doc_id' => $existingDocId]);
                return false;
            }

            $embeddingStr = PgVector::literal($result['embedding']);
            $contentHash = $this->computeContentHash($content);

            $params = [$trimmed, $contentHash];
            $setClauses = "content = ?, content_hash = ?";

            if ($title !== null) {
                $setClauses .= ", title = ?";
                $params[] = $title;
            }

            if ($metadata !== null) {
                $setClauses .= ", metadata = ?::jsonb";
                $params[] = json_encode($metadata);
            }

            $params[] = $existingDocId;

            DB::connection('pgsql_rag')->update(
                "UPDATE rag_documents
                 SET {$setClauses}, embedding = '{$embeddingStr}'::vector, updated_at = NOW()
                 WHERE id = ?",
                $params
            );

            return true;
        } catch (Exception $e) {
            Log::error('SemDedup: Update existing failed', ['doc_id' => $existingDocId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Scan existing documents for duplicates (batch operation)
     *
     * @return array Stats about duplicates found
     */
    public function scanExistingDocuments(int $batchSize = 100, ?string $documentType = null): array
    {
        $stats = ['scanned' => 0, 'duplicates_found' => 0, 'errors' => 0];

        $whereClause = "WHERE (dedup_status IS NULL OR dedup_status = 'unchecked')";
        $params = [];

        if ($documentType) {
            $whereClause .= " AND document_type = ?";
            $params[] = $documentType;
        }

        $params[] = $batchSize;

        $documents = DB::connection('pgsql_rag')->select(
            "SELECT id, title, content, source_type, source_id, content_hash
             FROM rag_documents
             {$whereClause}
             ORDER BY id ASC
             LIMIT ?",
            $params
        );

        foreach ($documents as $doc) {
            $stats['scanned']++;
            try {
                $contentHash = $doc->content_hash ?: $this->computeContentHash($doc->content);

                // Check for hash duplicates (excluding self)
                $hashDup = DB::connection('pgsql_rag')->selectOne(
                    "SELECT id FROM rag_documents WHERE content_hash = ? AND id != ? LIMIT 1",
                    [$contentHash, $doc->id]
                );

                if ($hashDup) {
                    DB::connection('pgsql_rag')->update(
                        "UPDATE rag_documents SET dedup_status = 'duplicate', dedup_matched_id = ?, dedup_similarity = 1.0, dedup_checked_at = NOW(), content_hash = ? WHERE id = ?",
                        [$hashDup->id, $contentHash, $doc->id]
                    );
                    $stats['duplicates_found']++;
                    continue;
                }

                // Update hash and mark as unique
                DB::connection('pgsql_rag')->update(
                    "UPDATE rag_documents SET dedup_status = 'unique', dedup_checked_at = NOW(), content_hash = ? WHERE id = ?",
                    [$contentHash, $doc->id]
                );
            } catch (Exception $e) {
                Log::warning('SemDedup: Scan error for doc', ['id' => $doc->id, 'error' => $e->getMessage()]);
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Backfill content hashes for existing documents
     */
    public function backfillHashes(int $batchSize = 500, ?string $documentType = null): array
    {
        $stats = ['processed' => 0, 'errors' => 0];

        $whereClause = "WHERE content_hash IS NULL";
        $params = [];

        if ($documentType) {
            $whereClause .= " AND document_type = ?";
            $params[] = $documentType;
        }

        $params[] = $batchSize;

        $documents = DB::connection('pgsql_rag')->select(
            "SELECT id, content FROM rag_documents {$whereClause} ORDER BY id ASC LIMIT ?",
            $params
        );

        foreach ($documents as $doc) {
            try {
                $hash = $this->computeContentHash($doc->content);
                DB::connection('pgsql_rag')->update(
                    "UPDATE rag_documents SET content_hash = ? WHERE id = ?",
                    [$hash, $doc->id]
                );
                $stats['processed']++;
            } catch (Exception $e) {
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Find duplicates among existing documents using content hash
     */
    public function findDuplicates(?string $documentType = null): array
    {
        $whereClause = "WHERE content_hash IS NOT NULL";
        $params = [];

        if ($documentType) {
            $whereClause .= " AND document_type = ?";
            $params[] = $documentType;
        }

        $duplicates = DB::connection('pgsql_rag')->select(
            "SELECT content_hash, COUNT(*) as cnt, MIN(id) as first_id,
                    array_agg(id ORDER BY id) as all_ids,
                    MIN(title) as sample_title
             FROM rag_documents
             {$whereClause}
             GROUP BY content_hash
             HAVING COUNT(*) > 1
             ORDER BY cnt DESC
             LIMIT 100",
            $params
        );

        return array_map(function ($row) {
            return [
                'content_hash' => $row->content_hash,
                'count' => (int) $row->cnt,
                'first_id' => (int) $row->first_id,
                'all_ids' => $row->all_ids,
                'sample_title' => $row->sample_title,
            ];
        }, $duplicates);
    }

    /**
     * Remove duplicate documents, keeping the oldest one per hash group
     *
     * @return int Number of documents removed
     */
    public function removeDuplicates(bool $dryRun = true, ?string $documentType = null): int
    {
        $duplicates = $this->findDuplicates($documentType);
        $removed = 0;

        foreach ($duplicates as $group) {
            // Keep the first (oldest) document, remove the rest
            $idsToRemove = trim($group['all_ids'], '{}');
            $allIds = array_map('intval', explode(',', $idsToRemove));
            $keepId = $group['first_id'];
            $removeIds = array_filter($allIds, fn($id) => $id !== $keepId);

            if (empty($removeIds)) {
                continue;
            }

            if ($dryRun) {
                $removed += count($removeIds);
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($removeIds), '?'));
            DB::connection('pgsql_rag')->delete(
                "DELETE FROM rag_documents WHERE id IN ({$placeholders})",
                $removeIds
            );
            $removed += count($removeIds);

            Log::info('SemDedup: Removed duplicates', [
                'kept' => $keepId,
                'removed' => $removeIds,
                'hash' => $group['content_hash'],
            ]);
        }

        return $removed;
    }

    /**
     * Get dedup statistics
     */
    public function getStats(): array
    {
        $statusCounts = DB::connection('pgsql_rag')->select(
            "SELECT COALESCE(dedup_status, 'unchecked') as status, COUNT(*) as cnt
             FROM rag_documents
             GROUP BY dedup_status"
        );

        $byStatus = [];
        foreach ($statusCounts as $row) {
            $byStatus[$row->status] = (int) $row->cnt;
        }

        $strategyCounts = DB::connection('pgsql_rag')->select(
            "SELECT strategy, COUNT(*) as cnt
             FROM rag_dedup_log
             GROUP BY strategy"
        );

        $byStrategy = [];
        foreach ($strategyCounts as $row) {
            $byStrategy[$row->strategy] = (int) $row->cnt;
        }

        $actionCounts = DB::connection('pgsql_rag')->select(
            "SELECT action_taken, COUNT(*) as cnt
             FROM rag_dedup_log
             GROUP BY action_taken"
        );

        $byAction = [];
        foreach ($actionCounts as $row) {
            $byAction[$row->action_taken] = (int) $row->cnt;
        }

        $totalDocs = DB::connection('pgsql_rag')->selectOne(
            "SELECT COUNT(*) as cnt FROM rag_documents"
        );

        $hashCoverage = DB::connection('pgsql_rag')->selectOne(
            "SELECT COUNT(*) as cnt FROM rag_documents WHERE content_hash IS NOT NULL"
        );

        return [
            'total_documents' => (int) ($totalDocs->cnt ?? 0),
            'hash_coverage' => (int) ($hashCoverage->cnt ?? 0),
            'by_dedup_status' => $byStatus,
            'log_by_strategy' => $byStrategy,
            'log_by_action' => $byAction,
        ];
    }
}
