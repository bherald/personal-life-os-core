<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * RAG-11: Temporal RAG Scoring — T-GRAG (IJCAI 2025)
 *
 * Blends semantic similarity with document recency so that time-sensitive
 * queries (news, current events, recent updates) prefer newer sources.
 *
 * Formula:
 *   adjusted_score = (1 - decay_weight) × similarity
 *                  + decay_weight       × recency_score
 *
 *   recency_score = exp(-ln(2) × days_old / half_life_days)
 *
 * With half_life = 365 days:
 *   - Today (0 days)    → recency = 1.00
 *   - 1 year old        → recency = 0.50
 *   - 2 years old       → recency = 0.25
 *   - 5 years old       → recency = 0.09
 *
 * Temporal detection: keyword heuristic on the query — no LLM needed.
 * Historical queries ("when was X founded") get decay_weight = 0 automatically.
 *
 * Reference: T-GRAG — Temporal Graph-RAG, IJCAI 2025
 */
class TemporalScoringService
{
    /** Default weight given to recency vs semantic similarity */
    public const DECAY_WEIGHT = 0.15;

    /** Days after which a document is considered half as recent */
    public const HALF_LIFE_DAYS = 365;

    /** Temporal query keywords — presence suggests recency matters */
    private const TEMPORAL_KEYWORDS = [
        'latest', 'recent', 'current', 'today', 'now', 'new', 'newest',
        'this year', 'this month', 'this week', 'right now', 'currently',
        'updated', 'just', 'breaking', 'recently', 'upcoming', 'trending',
        '2024', '2025', '2026',
    ];

    /** Historical/archival keywords — recency should NOT apply */
    private const HISTORICAL_KEYWORDS = [
        'history', 'historical', 'founded', 'established', 'born', 'died',
        'original', 'first', 'earliest', 'ancient', 'old', 'classic',
        'traditional', 'archive', 'legacy', 'past', 'origin', 'ancestry',
        'genealogy', 'ancestor', 'birth', 'death', 'census', '18', '19',
    ];

    // =========================================================================
    // Query temporality detection
    // =========================================================================

    /**
     * Return true if the query signals that recency is important.
     * False for historical/archival queries where temporal decay is counter-productive.
     */
    public function isTemporalQuery(string $query): bool
    {
        $lower = mb_strtolower($query);

        // Historical signal dominates — don't apply decay
        foreach (self::HISTORICAL_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                return false;
            }
        }

        foreach (self::TEMPORAL_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // Decay application
    // =========================================================================

    /**
     * Apply exponential time-decay to a RAGService results array.
     *
     * @param  array  $results       RAGService::search() / deepSearch() result format
     * @param  float  $decayWeight   0.0 = pure similarity, 1.0 = pure recency
     * @param  float  $halfLifeDays  Days until recency score halves
     * @return array  Same format, sorted by adjusted_score desc, with 'temporal_score' added
     */
    public function applyDecay(
        array $results,
        float $decayWeight = self::DECAY_WEIGHT,
        float $halfLifeDays = self::HALF_LIFE_DAYS
    ): array {
        if (empty($results) || $decayWeight <= 0.0) {
            return $results;
        }

        $now = Carbon::now();

        foreach ($results as &$result) {
            $similarity = (float) ($result['similarity'] ?? 0.0);

            // Web pseudo-docs (id=0) have no created_at — treat as maximally recent
            $docId = $result['document']->id ?? 0;
            if ($docId === 0) {
                $recencyScore = 1.0;
            } else {
                $createdAt = $result['document']->created_at ?? null;
                if ($createdAt === null) {
                    $recencyScore = 0.5; // Unknown age → neutral
                } else {
                    $daysOld      = max(0, Carbon::parse($createdAt)->diffInDays($now));
                    $recencyScore = $this->exponentialDecay($daysOld, $halfLifeDays);
                }
            }

            $adjustedScore = ((1.0 - $decayWeight) * $similarity)
                           + ($decayWeight * $recencyScore);

            $result['temporal_score']   = round($adjustedScore, 4);
            $result['recency_score']    = round($recencyScore, 4);
            $result['days_old']         = isset($createdAt) ? (int) Carbon::parse($createdAt)->diffInDays($now) : null;
        }
        unset($result);

        // Re-sort by temporal_score descending
        usort($results, fn($a, $b) => ($b['temporal_score'] ?? 0) <=> ($a['temporal_score'] ?? 0));

        return $results;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Exponential decay: score = exp(-ln(2) × t / half_life)
     * Returns 1.0 at t=0, 0.5 at t=half_life, approaching 0 for old docs.
     */
    public function exponentialDecay(float $daysOld, float $halfLifeDays): float
    {
        if ($halfLifeDays <= 0) {
            return 1.0;
        }

        return exp(-log(2) * $daysOld / $halfLifeDays);
    }
}
