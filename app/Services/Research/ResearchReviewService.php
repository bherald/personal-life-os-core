<?php

namespace App\Services\Research;

use App\Services\RAGService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * ResearchReviewService - Human review queue for research facts
 *
 * Handles the approval/rejection workflow for AI-discovered facts.
 * All facts require human approval before indexing to RAG.
 *
 * Rejection tracking enables deduplication for recurring research:
 * - Rejected facts are remembered
 * - Future runs skip facts with matching hashes
 * - Prevents wasting human review time on duplicates
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
class ResearchReviewService
{
    private string $connection = 'pgsql_rag';
    private RAGService $ragService;

    public function __construct(RAGService $ragService)
    {
        $this->ragService = $ragService;
    }

    /**
     * Get all facts pending human review with rich context
     *
     * Returns facts sorted by confidence (highest first) with:
     * - Verification breakdown (external confirmed/denied, RAG match, LLM confidence)
     * - Source URLs for reference
     * - Mission context
     */
    public function getPendingFacts(int $limit = 50, int $offset = 0, ?string $missionId = null): array
    {
        $where = "WHERE f.review_status = 'pending'";
        $params = [];

        if ($missionId) {
            $where .= " AND f.mission_id = ?";
            $params[] = $missionId;
        }

        $params[] = $limit;
        $params[] = $offset;

        $facts = DB::connection($this->connection)->select("
            SELECT
                f.id,
                f.fact_statement,
                f.fact_hash,
                f.fact_type,
                f.confidence_score,
                f.verification_status,
                f.source_count,
                f.verification_summary,
                f.external_sources_checked,
                f.external_sources_confirmed,
                f.external_sources_denied,
                f.rag_match_score,
                f.llm_confidence,
                f.source_urls,
                f.context_snippet,
                f.created_at,
                m.id as mission_id,
                m.title as mission_title,
                m.domain_category,
                m.rag_category,
                m.verification_level
            FROM research_facts f
            LEFT JOIN research_missions m ON m.id = f.mission_id
            {$where}
            ORDER BY f.confidence_score DESC, f.created_at ASC
            LIMIT ? OFFSET ?
        ", $params);

        // Get total count
        $countParams = $missionId ? [$missionId] : [];
        $countWhere = $missionId ? "WHERE f.review_status = 'pending' AND f.mission_id = ?" : "WHERE f.review_status = 'pending'";
        $count = DB::connection($this->connection)->select("
            SELECT COUNT(*) as total FROM research_facts f {$countWhere}
        ", $countParams);

        return [
            'facts' => array_map(function ($fact) {
                $result = (array) $fact;
                $result['source_urls'] = json_decode($result['source_urls'] ?? '[]', true);
                $result['verification_summary'] = json_decode($result['verification_summary'] ?? '{}', true);
                return $result;
            }, $facts),
            'total' => (int) ($count[0]->total ?? 0),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Approve a fact - index to RAG
     *
     * Marks the fact as approved and indexes it to the RAG knowledge base.
     * Uses the mission's rag_category for proper categorization.
     */
    public function approveFact(string $factId, ?string $reviewedBy = 'human'): array
    {
        $fact = $this->getFact($factId);
        if (!$fact) {
            return ['success' => false, 'error' => 'Fact not found'];
        }

        if ($fact['review_status'] !== 'pending') {
            return ['success' => false, 'error' => 'Fact has already been reviewed'];
        }

        DB::connection($this->connection)->beginTransaction();
        try {
            // Update fact status
            DB::connection($this->connection)->update("
                UPDATE research_facts
                SET review_status = 'approved',
                    reviewed_at = CURRENT_TIMESTAMP,
                    reviewed_by = ?,
                    needs_human_review = false,
                    human_review_action = 'approved',
                    human_reviewed_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ", [$reviewedBy, $factId]);

            // Index to RAG if not already indexed
            if (!$fact['indexed_to_rag']) {
                $ragDocument = $this->ragService->indexDocument(
                    documentType: 'research_fact',
                    content: $fact['fact_statement'] . "\n\n" . ($fact['context_snippet'] ?? ''),
                    title: "Fact: " . substr($fact['fact_statement'], 0, 100),
                    metadata: [
                        'mission_id' => $fact['mission_id'],
                        'mission_title' => $fact['mission_title'],
                        'fact_type' => $fact['fact_type'],
                        'confidence_score' => $fact['confidence_score'],
                        'source_urls' => $fact['source_urls'],
                        'verified_at' => $fact['verified_at'],
                        'approved_at' => now()->toISOString(),
                    ],
                    sourceId: $fact['id'],
                    sourceType: 'research_fact',
                    designation: $fact['rag_category'] ?? $fact['domain_category'] ?? 'research'
                );

                // Update with RAG document ID
                DB::connection($this->connection)->update("
                    UPDATE research_facts
                    SET indexed_to_rag = true,
                        rag_document_id = ?,
                        indexed_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ", [$ragDocument->id, $factId]);
            }

            DB::connection($this->connection)->commit();

            Log::info('Research fact approved and indexed to RAG', [
                'fact_id' => $factId,
                'mission_id' => $fact['mission_id'],
                'category' => $fact['rag_category'] ?? $fact['domain_category'],
            ]);

            return [
                'success' => true,
                'message' => 'Fact approved and saved to knowledge base',
                'fact_id' => $factId,
                'category' => $fact['rag_category'] ?? $fact['domain_category'],
            ];

        } catch (Exception $e) {
            DB::connection($this->connection)->rollBack();

            Log::error('Failed to approve fact', [
                'fact_id' => $factId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => 'Failed to approve fact: ' . $e->getMessage()];
        }
    }

    /**
     * Reject a fact - add to rejection tracking for deduplication
     *
     * Marks the fact as rejected and records it in research_rejected_facts
     * to prevent future recurring research from re-surfacing this fact.
     */
    public function rejectFact(string $factId, ?string $reason = null, ?string $reviewedBy = 'human'): array
    {
        $fact = $this->getFact($factId);
        if (!$fact) {
            return ['success' => false, 'error' => 'Fact not found'];
        }

        if ($fact['review_status'] !== 'pending') {
            return ['success' => false, 'error' => 'Fact has already been reviewed'];
        }

        DB::connection($this->connection)->beginTransaction();
        try {
            // Update fact status
            DB::connection($this->connection)->update("
                UPDATE research_facts
                SET review_status = 'rejected',
                    reviewed_at = CURRENT_TIMESTAMP,
                    reviewed_by = ?,
                    skip_reason = ?,
                    needs_human_review = false,
                    human_review_action = 'skipped',
                    human_reviewed_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ", [$reviewedBy, $reason, $factId]);

            // Add to rejection tracking for future deduplication
            DB::connection($this->connection)->statement("
                INSERT INTO research_rejected_facts (fact_hash, original_fact_statement, rejection_reason, mission_id, rejected_by)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT (fact_hash) DO UPDATE
                SET rejection_count = research_rejected_facts.rejection_count + 1,
                    last_rejected_at = CURRENT_TIMESTAMP,
                    rejection_reason = COALESCE(EXCLUDED.rejection_reason, research_rejected_facts.rejection_reason)
            ", [$fact['fact_hash'], $fact['fact_statement'], $reason, $fact['mission_id'], $reviewedBy]);

            DB::connection($this->connection)->commit();

            Log::info('Research fact rejected and tracked for deduplication', [
                'fact_id' => $factId,
                'fact_hash' => $fact['fact_hash'],
                'mission_id' => $fact['mission_id'],
                'reason' => $reason,
            ]);

            return [
                'success' => true,
                'message' => 'Fact rejected and tracked for deduplication',
                'fact_id' => $factId,
            ];

        } catch (Exception $e) {
            DB::connection($this->connection)->rollBack();

            Log::error('Failed to reject fact', [
                'fact_id' => $factId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => 'Failed to reject fact: ' . $e->getMessage()];
        }
    }

    /**
     * Bulk approve all pending facts from a mission
     */
    public function approveMission(string $missionId, ?string $reviewedBy = 'human'): array
    {
        $pendingFacts = DB::connection($this->connection)->select("
            SELECT id FROM research_facts
            WHERE mission_id = ? AND review_status = 'pending'
        ", [$missionId]);

        $approved = 0;
        $failed = 0;
        $errors = [];

        foreach ($pendingFacts as $fact) {
            $result = $this->approveFact($fact->id, $reviewedBy);
            if ($result['success']) {
                $approved++;
            } else {
                $failed++;
                $errors[] = $result['error'];
            }
        }

        return [
            'success' => $failed === 0,
            'approved_count' => $approved,
            'failed_count' => $failed,
            'errors' => array_unique($errors),
        ];
    }

    /**
     * Bulk reject all pending facts from a mission
     */
    public function rejectMission(string $missionId, ?string $reason = null, ?string $reviewedBy = 'human'): array
    {
        $pendingFacts = DB::connection($this->connection)->select("
            SELECT id FROM research_facts
            WHERE mission_id = ? AND review_status = 'pending'
        ", [$missionId]);

        $rejected = 0;
        $failed = 0;
        $errors = [];

        foreach ($pendingFacts as $fact) {
            $result = $this->rejectFact($fact->id, $reason, $reviewedBy);
            if ($result['success']) {
                $rejected++;
            } else {
                $failed++;
                $errors[] = $result['error'];
            }
        }

        return [
            'success' => $failed === 0,
            'rejected_count' => $rejected,
            'failed_count' => $failed,
            'errors' => array_unique($errors),
        ];
    }

    /**
     * Get a single fact with full context
     */
    public function getFact(string $factId): ?array
    {
        $facts = DB::connection($this->connection)->select("
            SELECT
                f.*,
                m.title as mission_title,
                m.domain_category,
                m.rag_category
            FROM research_facts f
            LEFT JOIN research_missions m ON m.id = f.mission_id
            WHERE f.id = ?
        ", [$factId]);

        if (empty($facts)) {
            return null;
        }

        $fact = (array) $facts[0];
        $fact['source_urls'] = json_decode($fact['source_urls'] ?? '[]', true);
        $fact['verification_summary'] = json_decode($fact['verification_summary'] ?? '{}', true);
        return $fact;
    }

    /**
     * Get deduplication statistics
     */
    public function getDeduplicationStats(): array
    {
        // Total rejected facts tracked
        $rejectedCount = DB::connection($this->connection)->select("
            SELECT COUNT(*) as count, SUM(rejection_count) as total_rejections
            FROM research_rejected_facts
        ");

        // Facts auto-skipped due to deduplication
        $autoSkipped = DB::connection($this->connection)->select("
            SELECT COUNT(*) as count FROM research_facts
            WHERE review_status = 'auto_skipped'
        ");

        // Breakdown by skip reason
        $skipReasons = DB::connection($this->connection)->select("
            SELECT
                CASE
                    WHEN skip_reason LIKE 'Semantic duplicate%' THEN 'by_rag_similarity'
                    WHEN skip_reason LIKE 'Previously rejected%' THEN 'by_rejection_match'
                    WHEN skip_reason LIKE 'Exists with status%' THEN 'by_hash_match'
                    ELSE 'other'
                END as reason_type,
                COUNT(*) as count
            FROM research_facts
            WHERE review_status = 'auto_skipped'
            GROUP BY reason_type
        ");

        $reasonBreakdown = [];
        foreach ($skipReasons as $r) {
            $reasonBreakdown[$r->reason_type] = (int) $r->count;
        }

        // Recent deduplication (last 24 hours)
        $recent = DB::connection($this->connection)->select("
            SELECT COUNT(*) as count FROM research_facts
            WHERE review_status = 'auto_skipped'
            AND created_at >= NOW() - INTERVAL '24 hours'
        ");

        return [
            'total_rejection_patterns_tracked' => (int) ($rejectedCount[0]->count ?? 0),
            'total_rejections_over_time' => (int) ($rejectedCount[0]->total_rejections ?? 0),
            'total_auto_skipped' => (int) ($autoSkipped[0]->count ?? 0),
            'breakdown' => $reasonBreakdown,
            'last_24h_deduplicated' => (int) ($recent[0]->count ?? 0),
        ];
    }

    /**
     * Get list of rejected facts for audit
     */
    public function getRejectedFacts(int $limit = 50, int $offset = 0): array
    {
        $facts = DB::connection($this->connection)->select("
            SELECT
                rrf.*,
                m.title as mission_title
            FROM research_rejected_facts rrf
            LEFT JOIN research_missions m ON m.id = rrf.mission_id
            ORDER BY rrf.last_rejected_at DESC
            LIMIT ? OFFSET ?
        ", [$limit, $offset]);

        $count = DB::connection($this->connection)->select("
            SELECT COUNT(*) as total FROM research_rejected_facts
        ");

        return [
            'facts' => array_map(fn($f) => (array) $f, $facts),
            'total' => (int) ($count[0]->total ?? 0),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Check if a fact hash has been previously rejected
     */
    public function isRejectedFact(string $factHash): ?array
    {
        $result = DB::connection($this->connection)->select("
            SELECT fact_hash, rejection_reason, rejection_count, last_rejected_at
            FROM research_rejected_facts
            WHERE fact_hash = ?
        ", [$factHash]);

        return !empty($result) ? (array) $result[0] : null;
    }

    /**
     * Remove a fact from rejection tracking (allow it to be re-evaluated)
     */
    public function unrejectFact(string $factHash): array
    {
        try {
            DB::connection($this->connection)->delete("
                DELETE FROM research_rejected_facts WHERE fact_hash = ?
            ", [$factHash]);

            return ['success' => true, 'message' => 'Fact removed from rejection tracking'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get review queue statistics
     */
    public function getReviewQueueStats(): array
    {
        // Pending counts
        $pending = DB::connection($this->connection)->select("
            SELECT
                COUNT(*) FILTER (WHERE review_status = 'pending') as pending_facts,
                COUNT(*) FILTER (WHERE review_status = 'approved' AND reviewed_at >= CURRENT_DATE) as approved_today,
                COUNT(*) FILTER (WHERE review_status = 'rejected' AND reviewed_at >= CURRENT_DATE) as rejected_today,
                COUNT(*) FILTER (WHERE review_status = 'auto_skipped' AND created_at >= CURRENT_DATE) as auto_skipped_today
            FROM research_facts
        ");

        // Legacy topic results pending
        $topicPending = DB::connection($this->connection)->select("
            SELECT COUNT(*) as count FROM research_results WHERE status = 'pending'
        ");

        // Verification confidence breakdown
        $confidenceBreakdown = DB::connection($this->connection)->select("
            SELECT
                COUNT(*) FILTER (WHERE confidence_score >= 0.80) as high_confidence,
                COUNT(*) FILTER (WHERE confidence_score >= 0.50 AND confidence_score < 0.80) as medium_confidence,
                COUNT(*) FILTER (WHERE confidence_score < 0.50) as low_confidence,
                AVG(confidence_score) as avg_confidence
            FROM research_facts
            WHERE review_status = 'pending'
        ");

        return [
            'pending_facts' => (int) ($pending[0]->pending_facts ?? 0),
            'pending_topic_results' => (int) ($topicPending[0]->count ?? 0),
            'total_pending' => (int) ($pending[0]->pending_facts ?? 0) + (int) ($topicPending[0]->count ?? 0),
            'approved_today' => (int) ($pending[0]->approved_today ?? 0),
            'rejected_today' => (int) ($pending[0]->rejected_today ?? 0),
            'auto_skipped_today' => (int) ($pending[0]->auto_skipped_today ?? 0),
            'verification_breakdown' => [
                'avg_confidence' => round((float) ($confidenceBreakdown[0]->avg_confidence ?? 0), 2),
                'high_confidence_count' => (int) ($confidenceBreakdown[0]->high_confidence ?? 0),
                'medium_confidence_count' => (int) ($confidenceBreakdown[0]->medium_confidence ?? 0),
                'low_confidence_count' => (int) ($confidenceBreakdown[0]->low_confidence ?? 0),
            ],
        ];
    }
}
