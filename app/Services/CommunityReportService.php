<?php

namespace App\Services;

use App\Traits\RecursionAware;
use App\Support\PgVector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Community Report Service
 *
 * Generates LLM-powered summaries for knowledge graph communities and embeds
 * them as 768-dim vectors for cosine search (global search mode).
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
class CommunityReportService
{
    use RecursionAware;

    private ?AIService $aiService = null;

    private const CONNECTION = 'pgsql_rag';

    private function getAIService(): AIService
    {
        if ($this->aiService === null) {
            $this->aiService = app(AIService::class);
        }
        return $this->aiService;
    }

    /**
     * Generate reports for communities that don't have one yet.
     *
     * @param array $options {
     *   min_community_size: int (default 3),
     *   limit: int (default 50),
     *   sleep_ms: int (default 2000),
     *   run_id: string (optional, filter by detection run)
     * }
     * @return array {success, reports_generated, total_tokens, duration_ms, errors}
     */
    public function generateReports(array $options = []): array
    {
        // RLM: Try recursive community report generation
        $rlm = $this->tryRecursive('community_reports', 'hierarchical_summarize', ['options' => $options], function ($ctx) {
            return $this->generateReports($ctx['options'] ?? []);
        });
        if ($rlm !== null) {
            return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
        }

        $startTime = microtime(true);
        $minSize = $options['min_community_size'] ?? 3;
        $limit = $options['limit'] ?? 50;
        $sleepMs = $options['sleep_ms'] ?? 2000;
        $runId = $options['run_id'] ?? null;

        $stats = ['generated' => 0, 'failed' => 0, 'total_tokens' => 0, 'errors' => []];

        try {
            // Find communities needing reports
            $runFilter = $runId ? "AND c.detection_run_id = ?::uuid" : "";
            $params = [$minSize];
            if ($runId) $params[] = $runId;
            $params[] = $limit;

            $communities = DB::connection(self::CONNECTION)->select("
                SELECT c.id, c.community_id, c.level, c.entity_count, c.edge_count, c.entity_ids, c.detection_run_id
                FROM knowledge_graph_communities c
                LEFT JOIN knowledge_graph_community_reports cr ON cr.community_id = c.id
                WHERE cr.id IS NULL AND c.entity_count >= ?
                {$runFilter}
                ORDER BY c.entity_count DESC
                LIMIT ?
            ", $params);

            if (empty($communities)) {
                return [
                    'success' => true,
                    'reports_generated' => 0,
                    'message' => 'All eligible communities already have reports',
                ];
            }

            foreach ($communities as $i => $community) {
                try {
                    $result = $this->generateSingleReport($community);

                    if ($result['success']) {
                        $stats['generated']++;
                        $stats['total_tokens'] += $result['token_count'] ?? 0;
                    } else {
                        $stats['failed']++;
                        $stats['errors'][] = "Community {$community->id}: " . ($result['error'] ?? 'Unknown');
                    }
                } catch (Exception $e) {
                    $stats['failed']++;
                    $stats['errors'][] = "Community {$community->id}: " . $e->getMessage();
                }

                // Rate limiting
                if ($sleepMs > 0 && $i < count($communities) - 1) {
                    usleep($sleepMs * 1000);
                }
            }

            // Update detection run report count
            if ($runId && $stats['generated'] > 0) {
                DB::connection(self::CONNECTION)->update(
                    "UPDATE knowledge_graph_detection_runs SET reports_generated = reports_generated + ? WHERE id = ?::uuid",
                    [$stats['generated'], $runId]
                );
            }

            $elapsed = (int) ((microtime(true) - $startTime) * 1000);

            return [
                'success' => true,
                'reports_generated' => $stats['generated'],
                'reports_failed' => $stats['failed'],
                'total_tokens' => $stats['total_tokens'],
                'duration_ms' => $elapsed,
                'errors' => $stats['errors'],
            ];
        } catch (Exception $e) {
            Log::error('CommunityReportService: generateReports failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get a community report by community ID.
     */
    public function getReport(int $communityId): ?array
    {
        $report = DB::connection(self::CONNECTION)->selectOne(
            "SELECT * FROM knowledge_graph_community_reports WHERE community_id = ?",
            [$communityId]
        );

        if (!$report) {
            return null;
        }

        return [
            'id' => $report->id,
            'community_id' => $report->community_id,
            'level' => $report->level,
            'title' => $report->title,
            'summary' => $report->summary,
            'key_entities' => json_decode($report->key_entities, true),
            'key_relationships' => json_decode($report->key_relationships, true),
            'themes' => json_decode($report->themes, true),
            'rating' => $report->rating,
            'token_count' => $report->token_count,
            'created_at' => $report->created_at,
        ];
    }

    /**
     * Search community reports by embedding similarity (global search).
     *
     * @param string $query Search query text
     * @param int $limit Max results
     * @return array [{report, similarity}]
     */
    public function searchReports(string $query, int $limit = 5): array
    {
        // Generate query embedding
        $embeddingResult = $this->getAIService()->generateEmbedding($query);

        if (empty($embeddingResult['success']) || empty($embeddingResult['embedding'])) {
            return [];
        }

        $vectorStr = PgVector::literal($embeddingResult['embedding']);

        $results = DB::connection(self::CONNECTION)->select("
            SELECT cr.*,
                   1 - (cr.embedding <=> ?::vector) as similarity,
                   c.entity_count, c.edge_count, c.level as community_level
            FROM knowledge_graph_community_reports cr
            JOIN knowledge_graph_communities c ON c.id = cr.community_id
            WHERE cr.embedding IS NOT NULL
            ORDER BY cr.embedding <=> ?::vector ASC
            LIMIT ?
        ", [$vectorStr, $vectorStr, $limit]);

        return array_map(function ($row) {
            return [
                'report' => [
                    'id' => $row->id,
                    'community_id' => $row->community_id,
                    'title' => $row->title,
                    'summary' => $row->summary,
                    'key_entities' => json_decode($row->key_entities, true),
                    'key_relationships' => json_decode($row->key_relationships, true),
                    'themes' => json_decode($row->themes, true),
                    'rating' => $row->rating,
                    'level' => $row->level,
                ],
                'similarity' => round((float) $row->similarity, 4),
                'entity_count' => $row->entity_count,
                'edge_count' => $row->edge_count,
            ];
        }, $results);
    }

    /**
     * Regenerate a single community's report.
     */
    public function regenerateReport(int $communityId): array
    {
        // Delete existing report
        DB::connection(self::CONNECTION)->delete(
            "DELETE FROM knowledge_graph_community_reports WHERE community_id = ?",
            [$communityId]
        );

        $community = DB::connection(self::CONNECTION)->selectOne(
            "SELECT * FROM knowledge_graph_communities WHERE id = ?",
            [$communityId]
        );

        if (!$community) {
            return ['success' => false, 'error' => 'Community not found'];
        }

        return $this->generateSingleReport($community);
    }

    // ── Private methods ──────────────────────────────────────────────

    /**
     * Generate a report for a single community.
     */
    private function generateSingleReport(object $community): array
    {
        $entityIds = json_decode($community->entity_ids, true);

        if (empty($entityIds)) {
            return ['success' => false, 'error' => 'Community has no entities'];
        }

        // Fetch entities
        $placeholders = implode(',', array_fill(0, count($entityIds), '?'));
        $entities = DB::connection(self::CONNECTION)->select(
            "SELECT id, canonical_name, entity_type, aliases, properties, degree, pagerank
             FROM knowledge_graph_entities WHERE id IN ({$placeholders})
             ORDER BY pagerank DESC",
            $entityIds
        );

        // Fetch internal relationships
        $relationships = DB::connection(self::CONNECTION)->select(
            "SELECT subject, subject_type, predicate, object, object_type, confidence
             FROM knowledge_graph
             WHERE subject_entity_id IN ({$placeholders}) AND object_entity_id IN ({$placeholders})
             ORDER BY confidence DESC
             LIMIT 50",
            array_merge($entityIds, $entityIds)
        );

        // Build prompt
        $entityList = [];
        foreach ($entities as $e) {
            $props = json_decode($e->properties, true);
            $propsStr = '';
            if ($props && is_array($props)) {
                $flatProps = [];
                foreach ($props as $k => $v) {
                    $flatProps[] = $k . ': ' . (is_array($v) ? json_encode($v) : (string) $v);
                }
                $propsStr = ' (' . implode(', ', $flatProps) . ')';
            }
            $entityList[] = "- {$e->canonical_name} [{$e->entity_type}]{$propsStr}";
        }

        $relList = [];
        foreach ($relationships as $r) {
            $relList[] = "- {$r->subject} --[{$r->predicate}]--> {$r->object} (confidence: {$r->confidence})";
        }

        $entityText = implode("\n", $entityList);
        $relText = !empty($relList) ? implode("\n", $relList) : "No internal relationships found.";

        $prompt = <<<PROMPT
You are analyzing a community of related entities from a knowledge graph.

Community contains {$community->entity_count} entities and {$community->edge_count} internal relationships.

Entities:
{$entityText}

Relationships:
{$relText}

Generate a structured analysis of this community. Respond in JSON format ONLY:
{
  "title": "Concise theme name (max 10 words)",
  "summary": "2-3 sentences describing what this community represents and why these entities are connected",
  "key_entities": [{"name": "entity_name", "type": "type", "role": "why this entity is important"}],
  "key_relationships": [{"subject": "A", "predicate": "relationship", "object": "B", "significance": "why this matters"}],
  "themes": ["theme1", "theme2", "theme3"],
  "rating": 0.0
}

For "rating", score 0.0-1.0 based on how coherent and informative this community is:
- 0.8-1.0: Strong thematic coherence, clear relationships
- 0.5-0.7: Moderate coherence, some connections are weak
- 0.0-0.4: Low coherence, entities loosely connected

Limit key_entities to top 5 and key_relationships to top 5. Respond with ONLY the JSON object.
PROMPT;

        $result = $this->getAIService()->process($prompt, [
            'factual_mode' => true,
            'max_tokens' => 2000,
        ]);

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => 'LLM call failed: ' . ($result['error'] ?? 'Unknown'),
            ];
        }

        // Parse JSON response
        $parsed = $this->parseJsonResponse($result['response']);

        if (!$parsed) {
            return [
                'success' => false,
                'error' => 'Failed to parse LLM response as JSON',
                'raw_response' => substr($result['response'], 0, 500),
            ];
        }

        // Generate embedding for the summary
        $summaryText = ($parsed['title'] ?? '') . '. ' . ($parsed['summary'] ?? '');
        $embeddingResult = $this->getAIService()->generateEmbedding($summaryText);
        $vectorStr = null;
        if (!empty($embeddingResult['success']) && !empty($embeddingResult['embedding'])) {
            $vectorStr = PgVector::literal($embeddingResult['embedding']);
        }

        // Estimate token count
        $tokenCount = (int) (strlen($prompt) / 4) + (int) (strlen($result['response'] ?? '') / 4);

        // Store report
        DB::connection(self::CONNECTION)->insert("
            INSERT INTO knowledge_graph_community_reports
                (community_id, level, title, summary, key_entities, key_relationships, themes, rating, embedding, token_count, detection_run_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::jsonb, ?, ?::vector, ?, ?::uuid, NOW(), NOW())
        ", [
            $community->id,
            $community->level,
            $parsed['title'] ?? 'Untitled Community',
            $parsed['summary'] ?? '',
            json_encode($parsed['key_entities'] ?? []),
            json_encode($parsed['key_relationships'] ?? []),
            json_encode($parsed['themes'] ?? []),
            (float) ($parsed['rating'] ?? 0.5),
            $vectorStr,
            $tokenCount,
            $community->detection_run_id ?? null,
        ]);

        return [
            'success' => true,
            'title' => $parsed['title'] ?? 'Untitled',
            'rating' => (float) ($parsed['rating'] ?? 0.5),
            'token_count' => $tokenCount,
            'has_embedding' => $vectorStr !== null,
        ];
    }

    /**
     * Parse JSON from LLM response with multiple fallback strategies.
     */
    private function parseJsonResponse(string $response): ?array
    {
        // Try direct parse
        $parsed = json_decode($response, true);
        if (is_array($parsed) && isset($parsed['title'])) {
            return $parsed;
        }

        // Try extracting from markdown code block
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $response, $matches)) {
            $parsed = json_decode($matches[1], true);
            if (is_array($parsed) && isset($parsed['title'])) {
                return $parsed;
            }
        }

        // Try greedy brace matching
        if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $response, $matches)) {
            $parsed = json_decode($matches[0], true);
            if (is_array($parsed) && isset($parsed['title'])) {
                return $parsed;
            }
        }

        // Try finding first { to last }
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $parsed = json_decode(substr($response, $start, $end - $start + 1), true);
            if (is_array($parsed) && isset($parsed['title'])) {
                return $parsed;
            }
        }

        return null;
    }
}
