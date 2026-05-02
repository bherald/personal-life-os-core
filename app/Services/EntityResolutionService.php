<?php

namespace App\Services;

use App\Support\PgVector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EntityResolutionService
{
    private const CONNECTION = 'pgsql_rag';

    // Thresholds loaded from system_configs (SC-3), fallback to hardcoded defaults
    private float $autoMergeThreshold;
    private float $llmCompareThreshold;
    private float $llmMergeConfidence;

    public function __construct(
        private AIService $aiService,
        private KnowledgeGraphService $kgService,
    ) {
        try {
            $config = app(SystemConfigService::class);
            $this->autoMergeThreshold = $config->getFloat('entity_resolution.auto_merge_threshold', 0.95);
            $this->llmCompareThreshold = $config->getFloat('entity_resolution.llm_compare_threshold', 0.75);
            $this->llmMergeConfidence = $config->getFloat('entity_resolution.llm_merge_confidence', 0.85);
        } catch (\Throwable $e) {
            $this->autoMergeThreshold = 0.95;
            $this->llmCompareThreshold = 0.75;
            $this->llmMergeConfidence = 0.85;
        }
    }

    /**
     * Build rich text representation of an entity for embedding.
     * Combines name, aliases, type, properties, and top connected triples.
     */
    public function buildEntityEmbeddingText(int $entityId): ?string
    {
        $entity = DB::connection(self::CONNECTION)->selectOne("
            SELECT id, canonical_name, entity_type, aliases, properties
            FROM knowledge_graph_entities
            WHERE id = ?
        ", [$entityId]);

        if (!$entity) {
            return null;
        }

        $aliases = json_decode($entity->aliases ?? '[]', true) ?: [];
        $properties = json_decode($entity->properties ?? '{}', true) ?: [];

        $parts = [];
        $parts[] = "Entity: {$entity->canonical_name}";
        $parts[] = "Type: {$entity->entity_type}";

        if (!empty($aliases)) {
            $parts[] = "Also known as: " . implode(', ', array_slice($aliases, 0, 10));
        }

        if (!empty($properties)) {
            $propStrings = [];
            foreach (array_slice($properties, 0, 10, true) as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $propStrings[] = "{$key}: {$value}";
                }
            }
            if (!empty($propStrings)) {
                $parts[] = "Properties: " . implode('; ', $propStrings);
            }
        }

        // Get top 10 connected triples (as subject or object)
        $triples = DB::connection(self::CONNECTION)->select("
            (SELECT subject, predicate, object
             FROM knowledge_graph
             WHERE subject_entity_id = ? AND t_expired IS NULL
             ORDER BY confidence DESC
             LIMIT 5)
            UNION ALL
            (SELECT subject, predicate, object
             FROM knowledge_graph
             WHERE object_entity_id = ? AND t_expired IS NULL
             ORDER BY confidence DESC
             LIMIT 5)
        ", [$entityId, $entityId]);

        if (!empty($triples)) {
            $tripleStrings = [];
            foreach ($triples as $t) {
                $tripleStrings[] = "{$t->subject} → {$t->predicate} → {$t->object}";
            }
            $parts[] = "Relationships: " . implode('; ', $tripleStrings);
        }

        $text = implode("\n", $parts);

        // Cap at ~2000 chars
        if (strlen($text) > 2000) {
            $text = substr($text, 0, 2000);
        }

        return $text;
    }

    /**
     * Generate and store embedding for a single entity.
     */
    public function embedEntity(int $entityId): bool
    {
        $text = $this->buildEntityEmbeddingText($entityId);
        if (!$text) {
            return false;
        }

        $entity = DB::connection(self::CONNECTION)->selectOne("
            SELECT entity_type FROM knowledge_graph_entities WHERE id = ?
        ", [$entityId]);

        if (!$entity) {
            return false;
        }

        $result = $this->aiService->generateEmbedding($text);

        if (!($result['success'] ?? false) || empty($result['embedding'])) {
            Log::warning('EntityResolution: Embedding failed', [
                'entity_id' => $entityId,
                'error' => $result['error'] ?? 'no embedding',
            ]);
            return false;
        }

        $embeddingStr = PgVector::literal($result['embedding']);

        DB::connection(self::CONNECTION)->statement("
            INSERT INTO knowledge_graph_entity_embeddings
                (entity_id, entity_type, embedding_text, embedding, created_at, updated_at)
            VALUES (?, ?, ?, ?::vector, NOW(), NOW())
            ON CONFLICT (entity_id) DO UPDATE SET
                entity_type = EXCLUDED.entity_type,
                embedding_text = EXCLUDED.embedding_text,
                embedding = EXCLUDED.embedding,
                updated_at = NOW()
        ", [$entityId, $entity->entity_type, $text, $embeddingStr]);

        return true;
    }

    /**
     * Backfill embeddings for entities that don't have them yet.
     */
    public function backfillEmbeddings(array $options = [], ?callable $onProgress = null): array
    {
        $limit = (int) ($options['limit'] ?? 50);
        $entityType = $options['entity_type'] ?? null;
        $dryRun = $options['dry_run'] ?? false;

        $typeFilter = $entityType ? "AND kge.entity_type = ?" : "";
        $bindings = $entityType ? [$entityType] : [];

        $missing = DB::connection(self::CONNECTION)->select("
            SELECT kge.id
            FROM knowledge_graph_entities kge
            LEFT JOIN knowledge_graph_entity_embeddings kgee ON kgee.entity_id = kge.id
            WHERE kgee.id IS NULL
              {$typeFilter}
            ORDER BY kge.degree DESC NULLS LAST, kge.id ASC
            LIMIT ?
        ", array_merge($bindings, [$limit]));

        $totalMissing = count($missing);

        if ($dryRun) {
            // Count total missing
            $totalCount = DB::connection(self::CONNECTION)->selectOne("
                SELECT COUNT(*) as cnt
                FROM knowledge_graph_entities kge
                LEFT JOIN knowledge_graph_entity_embeddings kgee ON kgee.entity_id = kge.id
                WHERE kgee.id IS NULL
                  {$typeFilter}
            ", $entityType ? [$entityType] : []);

            return [
                'total_missing' => (int) $totalCount->cnt,
                'would_process' => $totalMissing,
                'dry_run' => true,
            ];
        }

        $success = 0;
        $failed = 0;
        $processed = 0;

        foreach ($missing as $i => $row) {
            try {
                if ($this->embedEntity($row->id)) {
                    $success++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('EntityResolution: Backfill error', [
                    'entity_id' => $row->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $processed++;

            if ($onProgress) {
                $onProgress($processed, $totalMissing);
            }

            // Sleep between batches
            if ($processed % config('rag.entity_embed_batch', 50) === 0 && $processed < $totalMissing) {
                usleep(config('rag.entity_embed_sleep', 1000) * 1000);
            }
        }

        return [
            'processed' => $processed,
            'success' => $success,
            'failed' => $failed,
        ];
    }

    /**
     * Remove embedding for an entity (after merge/delete).
     */
    public function removeEmbedding(int $entityId): void
    {
        DB::connection(self::CONNECTION)->delete("
            DELETE FROM knowledge_graph_entity_embeddings WHERE entity_id = ?
        ", [$entityId]);
    }

    /**
     * Find candidate duplicate pairs using embedding similarity (ANN via HNSW).
     */
    public function findCandidates(array $options = []): array
    {
        $limit = (int) ($options['limit'] ?? 50);
        $entityType = $options['entity_type'] ?? null;
        $minSimilarity = (float) ($options['min_similarity'] ?? $this->llmCompareThreshold);

        $typeFilter = $entityType ? "AND e1.entity_type = ?" : "";
        $baseBindings = $entityType ? [$entityType] : [];

        // Get entities to scan
        $entities = DB::connection(self::CONNECTION)->select("
            SELECT e1.entity_id, e1.entity_type
            FROM knowledge_graph_entity_embeddings e1
            WHERE 1=1
              {$typeFilter}
            ORDER BY e1.entity_id ASC
            LIMIT ?
        ", array_merge($baseBindings, [$limit]));

        $pairs = [];
        $seen = [];

        // Pre-fetch all entity names to avoid N+1 queries in the inner loop
        $entityIds = array_column($entities, 'entity_id');
        $entityNames = [];
        if (!empty($entityIds)) {
            $placeholders = implode(',', array_fill(0, count($entityIds), '?'));
            $nameRows = DB::connection(self::CONNECTION)->select("
                SELECT id, canonical_name FROM knowledge_graph_entities WHERE id IN ({$placeholders})
            ", $entityIds);
            foreach ($nameRows as $row) {
                $entityNames[$row->id] = $row->canonical_name;
            }
        }

        foreach ($entities as $entity) {
            $neighbors = DB::connection(self::CONNECTION)->select("
                SELECT e2.entity_id, kge.canonical_name, kge.entity_type,
                       1 - (e1.embedding <=> e2.embedding) as similarity
                FROM knowledge_graph_entity_embeddings e1
                JOIN knowledge_graph_entity_embeddings e2
                  ON e2.entity_id != e1.entity_id AND e2.entity_type = e1.entity_type
                JOIN knowledge_graph_entities kge ON kge.id = e2.entity_id
                WHERE e1.entity_id = ?
                  AND 1 - (e1.embedding <=> e2.embedding) >= ?
                ORDER BY e1.embedding <=> e2.embedding ASC
                LIMIT 5
            ", [$entity->entity_id, $minSimilarity]);

            foreach ($neighbors as $neighbor) {
                // Deduplicate pairs (A,B) and (B,A)
                $pairKey = min($entity->entity_id, $neighbor->entity_id) . ':' . max($entity->entity_id, $neighbor->entity_id);
                if (isset($seen[$pairKey])) {
                    continue;
                }
                $seen[$pairKey] = true;

                $pairs[] = [
                    'entity_a_id' => $entity->entity_id,
                    'entity_a_name' => $entityNames[$entity->entity_id] ?? '?',
                    'entity_b_id' => $neighbor->entity_id,
                    'entity_b_name' => $neighbor->canonical_name,
                    'entity_type' => $neighbor->entity_type,
                    'similarity' => round((float) $neighbor->similarity, 4),
                ];
            }
        }

        // Sort by similarity descending
        usort($pairs, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return $pairs;
    }

    /**
     * Use LLM to compare two entities and determine if they're the same.
     */
    public function compareEntitiesByLLM(int $entityIdA, int $entityIdB): array
    {
        $textA = $this->buildEntityEmbeddingText($entityIdA);
        $textB = $this->buildEntityEmbeddingText($entityIdB);

        if (!$textA || !$textB) {
            return ['success' => false, 'error' => 'Could not build entity text'];
        }

        $prompt = <<<PROMPT
You are an entity resolution expert. Determine if these two entities refer to the same real-world entity.

ENTITY A:
{$textA}

ENTITY B:
{$textB}

Analyze carefully:
1. Do the names refer to the same person, place, organization, or concept?
2. Consider abbreviations, aliases, nicknames, alternate spellings
3. Check if properties and relationships are consistent (not contradictory)
4. Different entities can have similar names — be precise

Respond with ONLY valid JSON (no markdown):
{
  "same_entity": true/false,
  "confidence": 0.0-1.0,
  "reasoning": "brief explanation",
  "suggested_canonical": "preferred name if same entity, null otherwise"
}
PROMPT;

        $result = $this->aiService->process($prompt, [
            'use_cache' => false,
            'temperature' => 0.1,
            'task_type' => 'entity_resolution',
        ]);

        if (!($result['success'] ?? false)) {
            return ['success' => false, 'error' => $result['error'] ?? 'LLM call failed'];
        }

        $text = $result['response'] ?? '';

        // Parse JSON from response — handle backtick fences
        $text = preg_replace('/`{2,}json\s*/i', '', $text);
        $text = preg_replace('/`{2,}\s*$/', '', $text);
        $text = trim($text);

        $parsed = json_decode($text, true);

        if (!$parsed || !isset($parsed['same_entity'], $parsed['confidence'])) {
            return ['success' => false, 'error' => 'Failed to parse LLM response', 'raw' => $text];
        }

        return [
            'success' => true,
            'same_entity' => (bool) $parsed['same_entity'],
            'confidence' => (float) $parsed['confidence'],
            'reasoning' => $parsed['reasoning'] ?? '',
            'suggested_canonical' => $parsed['suggested_canonical'] ?? null,
        ];
    }

    /**
     * Main orchestrator: find candidates, auto-merge high-confidence, LLM-compare medium, submit uncertain for review.
     */
    public function resolveCandidates(array $options = [], ?callable $onProgress = null): array
    {
        $startTime = microtime(true);
        $limit = (int) ($options['limit'] ?? 50);
        $dryRun = $options['dry_run'] ?? false;
        $entityType = $options['entity_type'] ?? null;

        $stats = [
            'phase' => 'resolve',
            'entities_processed' => 0,
            'candidates_found' => 0,
            'auto_merged' => 0,
            'llm_compared' => 0,
            'llm_merged' => 0,
            'submitted_for_review' => 0,
            'errors' => 0,
        ];

        // Find candidates
        $candidates = $this->findCandidates([
            'limit' => $limit,
            'entity_type' => $entityType,
        ]);

        $stats['candidates_found'] = count($candidates);
        $stats['entities_processed'] = $limit;

        if ($onProgress) {
            $onProgress('candidates', $stats);
        }

        $llmCount = 0;

        foreach ($candidates as $pair) {
            try {
                $sim = $pair['similarity'];

                if ($sim >= $this->autoMergeThreshold) {
                    // Auto-merge — high confidence
                    if (!$dryRun) {
                        $merged = $this->mergeAndCleanup(
                            $pair['entity_a_id'],
                            $pair['entity_b_id'],
                            "Auto-merged: embedding similarity {$sim}"
                        );
                        if ($merged) {
                            $stats['auto_merged']++;
                        } else {
                            $stats['errors']++;
                        }
                    } else {
                        $stats['auto_merged']++;
                    }
                } elseif ($sim >= $this->llmCompareThreshold && $llmCount < config('rag.entity_compare_batch', 20)) {
                    // LLM comparison
                    $llmCount++;
                    $stats['llm_compared']++;

                    if ($dryRun) {
                        continue;
                    }

                    $comparison = $this->compareEntitiesByLLM($pair['entity_a_id'], $pair['entity_b_id']);

                    if (!($comparison['success'] ?? false)) {
                        $stats['errors']++;
                        continue;
                    }

                    if ($comparison['same_entity'] && $comparison['confidence'] >= $this->llmMergeConfidence) {
                        $merged = $this->mergeAndCleanup(
                            $pair['entity_a_id'],
                            $pair['entity_b_id'],
                            "LLM confirmed: {$comparison['reasoning']} (confidence: {$comparison['confidence']})"
                        );
                        if ($merged) {
                            $stats['llm_merged']++;
                        } else {
                            $stats['errors']++;
                        }
                    } elseif ($comparison['same_entity'] && $comparison['confidence'] < $this->llmMergeConfidence) {
                        // Uncertain — submit for human review
                        $this->submitForReview($pair, $comparison);
                        $stats['submitted_for_review']++;
                    }
                    // If not same_entity, skip — no action needed

                    usleep(config('rag.entity_compare_sleep', 2000) * 1000);
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning('EntityResolution: Resolution error', [
                    'pair' => [$pair['entity_a_id'], $pair['entity_b_id']],
                    'error' => $e->getMessage(),
                ]);
            }

            if ($onProgress) {
                $onProgress('processing', $stats);
            }
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);
        $stats['duration_ms'] = $durationMs;

        // Record run
        if (!$dryRun) {
            DB::connection(self::CONNECTION)->insert("
                INSERT INTO entity_resolution_runs
                    (phase, entities_processed, candidates_found, auto_merged, llm_compared,
                     llm_merged, submitted_for_review, errors, duration_ms, metadata, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, NOW())
            ", [
                $stats['phase'],
                $stats['entities_processed'],
                $stats['candidates_found'],
                $stats['auto_merged'],
                $stats['llm_compared'],
                $stats['llm_merged'],
                $stats['submitted_for_review'],
                $stats['errors'],
                $durationMs,
                json_encode(['entity_type' => $entityType, 'limit' => $limit]),
            ]);
        }

        return $stats;
    }

    /**
     * Merge two entities: choose keeper (higher degree), delegate to KGService, update embeddings.
     */
    public function mergeAndCleanup(int $entityIdA, int $entityIdB, string $reason): bool
    {
        // Choose keeper — entity with higher degree
        $degrees = DB::connection(self::CONNECTION)->select("
            SELECT id, COALESCE(degree, 0) as degree
            FROM knowledge_graph_entities
            WHERE id IN (?, ?)
        ", [$entityIdA, $entityIdB]);

        if (count($degrees) < 2) {
            return false;
        }

        $degreeMap = [];
        foreach ($degrees as $d) {
            $degreeMap[$d->id] = (int) $d->degree;
        }

        // Keep the one with higher degree (or lower ID as tiebreaker)
        if (($degreeMap[$entityIdA] ?? 0) >= ($degreeMap[$entityIdB] ?? 0)) {
            $targetId = $entityIdA;
            $sourceId = $entityIdB;
        } else {
            $targetId = $entityIdB;
            $sourceId = $entityIdA;
        }

        Log::info('EntityResolution: Merging entities', [
            'source' => $sourceId,
            'target' => $targetId,
            'reason' => $reason,
        ]);

        $merged = $this->kgService->mergeEntities($sourceId, $targetId);

        if (!$merged) {
            return false;
        }

        // Remove source embedding
        $this->removeEmbedding($sourceId);

        // Re-embed target with updated context
        try {
            $this->embedEntity($targetId);
        } catch (\Throwable $e) {
            Log::warning('EntityResolution: Re-embed target failed', [
                'target_id' => $targetId,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * Submit uncertain pair for human review.
     */
    private function submitForReview(array $pair, array $comparison): void
    {
        try {
            $token = bin2hex(random_bytes(16));
            DB::insert("
                INSERT INTO agent_review_queue
                    (review_type, title, summary, details, confidence, priority, status, token, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())
            ", [
                'entity_merge_proposal',
                "Merge? {$pair['entity_a_name']} ↔ {$pair['entity_b_name']}",
                $comparison['reasoning'] ?? 'LLM suggests possible match',
                json_encode([
                    'entity_a_id' => $pair['entity_a_id'],
                    'entity_a_name' => $pair['entity_a_name'],
                    'entity_b_id' => $pair['entity_b_id'],
                    'entity_b_name' => $pair['entity_b_name'],
                    'entity_type' => $pair['entity_type'],
                    'embedding_similarity' => $pair['similarity'],
                    'llm_confidence' => $comparison['confidence'] ?? 0,
                    'llm_reasoning' => $comparison['reasoning'] ?? '',
                    'suggested_canonical' => $comparison['suggested_canonical'] ?? null,
                ]),
                $comparison['confidence'] ?? 0,
                2,
                $token,
            ]);
        } catch (\Throwable $e) {
            Log::warning('EntityResolution: Failed to submit for review', [
                'pair' => [$pair['entity_a_id'], $pair['entity_b_id']],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get entity resolution statistics.
     */
    public function getStatistics(): array
    {
        try {
            $totalEntities = DB::connection(self::CONNECTION)->selectOne("
                SELECT COUNT(*) as cnt FROM knowledge_graph_entities
            ");

            $embeddedCount = DB::connection(self::CONNECTION)->selectOne("
                SELECT COUNT(*) as cnt FROM knowledge_graph_entity_embeddings
            ");

            $total = (int) ($totalEntities->cnt ?? 0);
            $embedded = (int) ($embeddedCount->cnt ?? 0);
            $coverage = $total > 0 ? round(100.0 * $embedded / $total, 1) : 0;

            // Recent runs (last 7 days)
            $recentRuns = DB::connection(self::CONNECTION)->select("
                SELECT phase, entities_processed, candidates_found, auto_merged,
                       llm_compared, llm_merged, submitted_for_review, errors,
                       duration_ms, created_at
                FROM entity_resolution_runs
                WHERE created_at >= NOW() - INTERVAL '7 days'
                ORDER BY created_at DESC
                LIMIT 10
            ");

            // 7-day totals
            $totals = DB::connection(self::CONNECTION)->selectOne("
                SELECT COALESCE(SUM(auto_merged), 0) as total_auto_merged,
                       COALESCE(SUM(llm_merged), 0) as total_llm_merged,
                       COALESCE(SUM(candidates_found), 0) as total_candidates,
                       COALESCE(SUM(submitted_for_review), 0) as total_reviews,
                       COUNT(*) as run_count
                FROM entity_resolution_runs
                WHERE created_at >= NOW() - INTERVAL '7 days'
            ");

            // Pending reviews
            $pendingReviews = DB::selectOne("
                SELECT COUNT(*) as cnt
                FROM agent_review_queue
                WHERE review_type = 'entity_merge_proposal' AND status = 'pending'
            ");

            return [
                'total_entities' => $total,
                'embedded_count' => $embedded,
                'coverage_pct' => $coverage,
                'pending_reviews' => (int) ($pendingReviews->cnt ?? 0),
                'recent_runs' => array_map(fn($r) => (array) $r, $recentRuns),
                'totals_7d' => [
                    'auto_merged' => (int) ($totals->total_auto_merged ?? 0),
                    'llm_merged' => (int) ($totals->total_llm_merged ?? 0),
                    'total_merged' => (int) ($totals->total_auto_merged ?? 0) + (int) ($totals->total_llm_merged ?? 0),
                    'candidates_found' => (int) ($totals->total_candidates ?? 0),
                    'submitted_for_review' => (int) ($totals->total_reviews ?? 0),
                    'run_count' => (int) ($totals->run_count ?? 0),
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('EntityResolution: Stats failed', ['error' => $e->getMessage()]);
            return [
                'error' => $e->getMessage(),
                'total_entities' => 0,
                'embedded_count' => 0,
                'coverage_pct' => 0,
            ];
        }
    }
}
