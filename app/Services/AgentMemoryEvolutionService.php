<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AG-3: Memory Evolution + Bidirectional Links
 *
 * Implements the A-Mem (NeurIPS 2025) / Zettelkasten pattern for agent memories:
 * when a new episodic summary is distilled, this service finds semantically related
 * past summaries and creates bidirectional links between them.
 *
 * Link types (determined by similarity threshold):
 *   related      — similarity 0.40–0.75 — same topic/domain
 *   extends      — similarity 0.75–0.88 — builds on/refines a prior experience
 *   evolved_from — similarity > 0.88    — supersedes a prior experience;
 *                                         old summary is archived
 *
 * Links enable 1-hop memory traversal during recall: when a matching memory is
 * found, its linked memories are also surfaced, providing richer context.
 *
 * All operations are non-fatal — failures are logged at debug level only.
 */
class AgentMemoryEvolutionService
{
    /** Minimum similarity to create any link */
    private const LINK_MIN_SIMILARITY = 0.40;

    /** Similarity above which a link is 'extends' rather than 'related' */
    private const EXTENDS_THRESHOLD = 0.75;

    /** Similarity above which a link is 'evolved_from' (old memory archived) */
    private const EVOLVED_FROM_THRESHOLD = 0.88;

    /** Max past summaries to scan per distillation */
    private const SCAN_LIMIT = 10;

    /** Max links to create per distillation (keeps graph sparse) */
    private const MAX_LINKS_PER_DISTILL = 3;

    /** Max linked memories to return for context enrichment */
    private const MAX_LINKED_CONTEXT = 2;

    private AIService $aiService;

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    // =========================================================================
    // LINK: Create bidirectional links after episodic distillation
    // =========================================================================

    /**
     * Find related past episodic summaries and create bidirectional links.
     *
     * Called by AgentEpisodicMemoryService immediately after a new summary
     * is stored and embedded. Non-fatal throughout.
     *
     * @param int    $summaryId The newly created summary ID
     * @param string $agentId   Agent identifier
     */
    public function linkAfterDistill(int $summaryId, string $agentId): void
    {
        try {
            // Load the embedding for the new summary (PostgreSQL/pgvector)
            $embeddingRow = DB::connection('pgsql_rag')->selectOne("
                SELECT embedding::text AS embedding
                FROM agent_episode_embeddings
                WHERE summary_id = ? AND agent_id = ?
                LIMIT 1
            ", [$summaryId, $agentId]);

            if (!$embeddingRow || empty($embeddingRow->embedding)) {
                // No embedding yet — can't link
                return;
            }

            $embeddingStr = $embeddingRow->embedding;

            // Find the most similar past summaries via pgvector — IDs + similarities
            $pgRows = DB::connection('pgsql_rag')->select("
                SELECT summary_id,
                       1 - (embedding <=> ?::vector) AS similarity
                FROM agent_episode_embeddings
                WHERE agent_id = ?
                  AND summary_id != ?
                  AND 1 - (embedding <=> ?::vector) >= ?
                ORDER BY embedding <=> ?::vector ASC
                LIMIT ?
            ", [
                $embeddingStr,
                $agentId,
                $summaryId,
                $embeddingStr,
                self::LINK_MIN_SIMILARITY,
                $embeddingStr,
                self::SCAN_LIMIT,
            ]);

            if (empty($pgRows)) {
                return;
            }

            // Build similarity map and fetch MySQL summary records
            $simMap = [];
            foreach ($pgRows as $row) {
                $simMap[(int) $row->summary_id] = (float) $row->similarity;
            }
            $ids = array_keys($simMap);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $candidates = DB::select("
                SELECT id AS summary_id
                FROM agent_episode_summaries
                WHERE id IN ({$placeholders}) AND is_archived = 0
            ", $ids);

            if (empty($candidates)) {
                return;
            }

            $linksCreated = 0;

            foreach ($candidates as $candidate) {
                if ($linksCreated >= self::MAX_LINKS_PER_DISTILL) {
                    break;
                }

                $targetId   = (int) $candidate->summary_id;
                $similarity = $simMap[$targetId] ?? 0.0;

                // Skip if a link between these two already exists
                if ($this->linkExists($agentId, 'episodic', $summaryId, 'episodic', $targetId)) {
                    continue;
                }

                $linkType = $this->determineLinkType($similarity);
                $strength = round(min($similarity, 0.99), 2);

                // Create A → B
                DB::insert("
                    INSERT INTO agent_memory_links
                        (agent_id, source_type, source_id, target_type, target_id, link_type, strength, created_at)
                    VALUES (?, 'episodic', ?, 'episodic', ?, ?, ?, NOW())
                ", [$agentId, $summaryId, $targetId, $linkType, $strength]);

                // Create B → A (bidirectional)
                DB::insert("
                    INSERT INTO agent_memory_links
                        (agent_id, source_type, source_id, target_type, target_id, link_type, strength, created_at)
                    VALUES (?, 'episodic', ?, 'episodic', ?, ?, ?, NOW())
                ", [$agentId, $targetId, $summaryId, $linkType, $strength]);

                // evolved_from: archive the superseded summary
                if ($linkType === 'evolved_from') {
                    DB::update("
                        UPDATE agent_episode_summaries SET is_archived = 1 WHERE id = ?
                    ", [$targetId]);
                    Log::info('AgentMemoryEvolution: Summary evolved and archived', [
                        'agent_id'    => $agentId,
                        'new_summary' => $summaryId,
                        'archived'    => $targetId,
                        'similarity'  => $similarity,
                    ]);
                }

                $linksCreated++;
            }

            if ($linksCreated > 0) {
                Log::debug('AgentMemoryEvolution: Links created', [
                    'agent_id'      => $agentId,
                    'summary_id'    => $summaryId,
                    'links_created' => $linksCreated,
                ]);
            }

        } catch (\Throwable $e) {
            Log::debug('AgentMemoryEvolution: linkAfterDistill failed (non-fatal)', [
                'agent_id'   => $agentId,
                'summary_id' => $summaryId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // RECALL: Enrich context with linked memories (1-hop traversal)
    // =========================================================================

    /**
     * Given a primary episodic summary, return text snippets from its linked
     * memories for context enrichment during recall.
     *
     * Returns at most MAX_LINKED_CONTEXT entries, prioritised by link strength.
     *
     * @param  int $summaryId  The primary recalled summary ID
     * @return array Array of {summary, outcome, date, link_type, strength}
     */
    public function getLinkedContext(int $summaryId): array
    {
        try {
            $linked = DB::select("
                SELECT s.summary,
                       s.outcome,
                       s.created_at,
                       l.link_type,
                       l.strength
                FROM agent_memory_links l
                JOIN agent_episode_summaries s ON s.id = l.target_id
                WHERE l.source_type  = 'episodic'
                  AND l.source_id    = ?
                  AND l.target_type  = 'episodic'
                  AND s.is_archived  = 0
                ORDER BY l.strength DESC
                LIMIT ?
            ", [$summaryId, self::MAX_LINKED_CONTEXT]);

            return array_map(fn($row) => [
                'summary'   => $row->summary,
                'outcome'   => $row->outcome,
                'date'      => substr($row->created_at, 0, 10),
                'link_type' => $row->link_type,
                'strength'  => (float) $row->strength,
            ], $linked);

        } catch (\Throwable $e) {
            Log::debug('AgentMemoryEvolution: getLinkedContext failed (non-fatal)', [
                'summary_id' => $summaryId,
                'error'      => $e->getMessage(),
            ]);
            return [];
        }
    }

    // =========================================================================
    // STATS
    // =========================================================================

    /**
     * Return link statistics for a given agent.
     */
    public function getStats(string $agentId): array
    {
        try {
            $rows = DB::select("
                SELECT link_type, COUNT(*) / 2 AS count
                FROM agent_memory_links
                WHERE agent_id = ? AND source_type = 'episodic'
                GROUP BY link_type
            ", [$agentId]);

            $stats = ['related' => 0, 'extends' => 0, 'evolved_from' => 0, 'total' => 0];
            foreach ($rows as $row) {
                $stats[$row->link_type] = (int) $row->count;
                $stats['total'] += (int) $row->count;
            }
            return $stats;

        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function determineLinkType(float $similarity): string
    {
        if ($similarity >= self::EVOLVED_FROM_THRESHOLD) {
            return 'evolved_from';
        }
        if ($similarity >= self::EXTENDS_THRESHOLD) {
            return 'extends';
        }
        return 'related';
    }

    private function linkExists(string $agentId, string $sourceType, int $sourceId, string $targetType, int $targetId): bool
    {
        try {
            $existing = DB::selectOne("
                SELECT id FROM agent_memory_links
                WHERE agent_id    = ?
                  AND source_type = ?
                  AND source_id   = ?
                  AND target_type = ?
                  AND target_id   = ?
                LIMIT 1
            ", [$agentId, $sourceType, $sourceId, $targetType, $targetId]);

            return $existing !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
