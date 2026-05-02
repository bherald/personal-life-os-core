<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fact-Check Operations Service
 *
 * Provides agent-callable tool methods for the factcheck-ops agent.
 * Monitors the 5-stage fact-checking pipeline: claim decomposition,
 * checkworthiness scoring, evidence retrieval, NLI ranking, and verdict generation.
 *
 * All fact-check data lives in PostgreSQL (pgsql_rag connection).
 */
class FactCheckOpsService
{
    // =========================================================================
    // ASSESS TOOLS
    // =========================================================================

    /**
     * Get fact-check pipeline statistics — recent runs, success/failure rates,
     * stage-level timing, and throughput trends.
     */
    public function getPipelineStats(): array
    {
        try {
            $db = DB::connection('pgsql_rag');

            // Overall claim counts (last 30 days)
            $claimStats = $db->selectOne("
                SELECT COUNT(*) as total_claims,
                       COUNT(CASE WHEN checkworthiness_score >= 0.5 THEN 1 END) as checkworthy,
                       AVG(checkworthiness_score) as avg_checkworthiness,
                       MIN(created_at) as oldest,
                       MAX(created_at) as newest
                FROM claims
                WHERE created_at >= NOW() - INTERVAL '30 days'
            ");

            // Verdict counts (last 30 days)
            $verdictStats = $db->selectOne("
                SELECT COUNT(*) as total_verdicts,
                       COUNT(CASE WHEN verdict = 'supported' THEN 1 END) as supported,
                       COUNT(CASE WHEN verdict = 'refuted' THEN 1 END) as refuted,
                       COUNT(CASE WHEN verdict = 'inconclusive' THEN 1 END) as inconclusive,
                       AVG(confidence) as avg_confidence,
                       AVG(factuality_score) as avg_factuality
                FROM verdicts
                WHERE created_at >= NOW() - INTERVAL '30 days'
            ");

            // Evidence collection stats (last 30 days)
            $evidenceStats = $db->selectOne("
                SELECT COUNT(*) as total_evidence,
                       COUNT(DISTINCT claim_id) as claims_with_evidence,
                       AVG(nli_score) as avg_nli_score,
                       AVG(credibility_score) as avg_credibility
                FROM evidence
                WHERE created_at >= NOW() - INTERVAL '30 days'
            ");

            // Claims with zero evidence
            $zeroEvidence = $db->selectOne("
                SELECT COUNT(*) as count
                FROM claims c
                WHERE c.created_at >= NOW() - INTERVAL '30 days'
                AND c.checkworthiness_score >= 0.5
                AND NOT EXISTS (
                    SELECT 1 FROM evidence e WHERE e.claim_id = c.id
                )
            ");

            // Daily throughput (last 7 days)
            $dailyThroughput = $db->select("
                SELECT DATE(created_at) as date,
                       COUNT(*) as claims_processed
                FROM claims
                WHERE created_at >= NOW() - INTERVAL '7 days'
                GROUP BY DATE(created_at)
                ORDER BY date DESC
            ");

            $totalClaims = (int) ($claimStats->total_claims ?? 0);
            $claimsWithEvidence = (int) ($evidenceStats->claims_with_evidence ?? 0);

            return [
                'period' => 'last_30_days',
                'claims' => [
                    'total' => $totalClaims,
                    'checkworthy' => (int) ($claimStats->checkworthy ?? 0),
                    'avg_checkworthiness' => round((float) ($claimStats->avg_checkworthiness ?? 0), 3),
                    'zero_evidence' => (int) ($zeroEvidence->count ?? 0),
                    'evidence_coverage_pct' => $totalClaims > 0
                        ? round(($claimsWithEvidence / $totalClaims) * 100, 1)
                        : 0,
                ],
                'verdicts' => [
                    'total' => (int) ($verdictStats->total_verdicts ?? 0),
                    'supported' => (int) ($verdictStats->supported ?? 0),
                    'refuted' => (int) ($verdictStats->refuted ?? 0),
                    'inconclusive' => (int) ($verdictStats->inconclusive ?? 0),
                    'avg_confidence' => round((float) ($verdictStats->avg_confidence ?? 0), 3),
                    'avg_factuality' => round((float) ($verdictStats->avg_factuality ?? 0), 3),
                ],
                'evidence' => [
                    'total_pieces' => (int) ($evidenceStats->total_evidence ?? 0),
                    'avg_per_claim' => $claimsWithEvidence > 0
                        ? round((int) ($evidenceStats->total_evidence ?? 0) / $claimsWithEvidence, 1)
                        : 0,
                    'avg_nli_score' => round((float) ($evidenceStats->avg_nli_score ?? 0), 3),
                    'avg_credibility' => round((float) ($evidenceStats->avg_credibility ?? 0), 3),
                ],
                'daily_throughput' => array_map(fn($d) => [
                    'date' => $d->date,
                    'claims' => (int) $d->claims_processed,
                ], $dailyThroughput),
            ];
        } catch (\Exception $e) {
            Log::error('FactCheckOpsService::getPipelineStats failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get claim quality metrics — checkworthiness distribution,
     * decomposition patterns, claims needing attention.
     */
    public function getClaimQuality(): array
    {
        try {
            $db = DB::connection('pgsql_rag');

            // Checkworthiness distribution
            $distribution = $db->select("
                SELECT
                    CASE
                        WHEN checkworthiness_score >= 0.8 THEN 'high (0.8-1.0)'
                        WHEN checkworthiness_score >= 0.5 THEN 'medium (0.5-0.8)'
                        WHEN checkworthiness_score >= 0.3 THEN 'low (0.3-0.5)'
                        ELSE 'negligible (<0.3)'
                    END as tier,
                    COUNT(*) as count
                FROM claims
                WHERE created_at >= NOW() - INTERVAL '30 days'
                GROUP BY tier
                ORDER BY count DESC
            ");

            // Recent claims with entities extracted
            $entityStats = $db->selectOne("
                SELECT COUNT(*) as total,
                       COUNT(CASE WHEN entities IS NOT NULL AND entities != '[]' AND entities != 'null' THEN 1 END) as with_entities
                FROM claims
                WHERE created_at >= NOW() - INTERVAL '30 days'
            ");

            // Claims per source document
            $perDocument = $db->select("
                SELECT source_document_id, COUNT(*) as claim_count
                FROM claims
                WHERE created_at >= NOW() - INTERVAL '30 days'
                AND source_document_id IS NOT NULL
                GROUP BY source_document_id
                ORDER BY claim_count DESC
                LIMIT 10
            ");

            $total = (int) ($entityStats->total ?? 0);
            $withEntities = (int) ($entityStats->with_entities ?? 0);

            return [
                'checkworthiness_distribution' => array_map(fn($d) => [
                    'tier' => $d->tier,
                    'count' => (int) $d->count,
                ], $distribution),
                'entity_extraction' => [
                    'total_claims' => $total,
                    'with_entities' => $withEntities,
                    'extraction_rate_pct' => $total > 0 ? round(($withEntities / $total) * 100, 1) : 0,
                ],
                'claims_per_document' => array_map(fn($d) => [
                    'source_document_id' => $d->source_document_id,
                    'claim_count' => (int) $d->claim_count,
                ], $perDocument),
            ];
        } catch (\Exception $e) {
            Log::error('FactCheckOpsService::getClaimQuality failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get evidence health — NLI label distribution, source diversity,
     * retrieval effectiveness, credibility scoring.
     */
    public function getEvidenceHealth(): array
    {
        try {
            $db = DB::connection('pgsql_rag');

            // NLI label distribution
            $nliDistribution = $db->select("
                SELECT nli_label, COUNT(*) as count,
                       AVG(nli_score) as avg_score
                FROM evidence
                WHERE created_at >= NOW() - INTERVAL '30 days'
                GROUP BY nli_label
                ORDER BY count DESC
            ");

            // Source domain diversity
            $domainStats = $db->select("
                SELECT source_domain, COUNT(*) as evidence_count,
                       AVG(credibility_score) as avg_credibility
                FROM evidence
                WHERE created_at >= NOW() - INTERVAL '30 days'
                AND source_domain IS NOT NULL
                GROUP BY source_domain
                ORDER BY evidence_count DESC
                LIMIT 15
            ");

            // Evidence per claim distribution
            $evidencePerClaim = $db->select("
                SELECT
                    CASE
                        WHEN evidence_count >= 10 THEN '10+'
                        WHEN evidence_count >= 5 THEN '5-9'
                        WHEN evidence_count >= 3 THEN '3-4'
                        WHEN evidence_count >= 1 THEN '1-2'
                        ELSE '0'
                    END as bucket,
                    COUNT(*) as claim_count
                FROM (
                    SELECT c.id, COUNT(e.id) as evidence_count
                    FROM claims c
                    LEFT JOIN evidence e ON e.claim_id = c.id
                    WHERE c.created_at >= NOW() - INTERVAL '30 days'
                    AND c.checkworthiness_score >= 0.5
                    GROUP BY c.id
                ) sub
                GROUP BY bucket
                ORDER BY bucket
            ");

            // Credibility score distribution
            $credDistribution = $db->selectOne("
                SELECT AVG(credibility_score) as avg,
                       MIN(credibility_score) as min,
                       MAX(credibility_score) as max,
                       PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY credibility_score) as median
                FROM evidence
                WHERE created_at >= NOW() - INTERVAL '30 days'
                AND credibility_score IS NOT NULL
            ");

            return [
                'nli_distribution' => array_map(fn($d) => [
                    'label' => $d->nli_label,
                    'count' => (int) $d->count,
                    'avg_score' => round((float) $d->avg_score, 3),
                ], $nliDistribution),
                'source_diversity' => [
                    'unique_domains' => count($domainStats),
                    'top_domains' => array_map(fn($d) => [
                        'domain' => $d->source_domain,
                        'count' => (int) $d->evidence_count,
                        'avg_credibility' => round((float) ($d->avg_credibility ?? 0), 3),
                    ], $domainStats),
                ],
                'evidence_per_claim' => array_map(fn($d) => [
                    'bucket' => $d->bucket,
                    'claim_count' => (int) $d->claim_count,
                ], $evidencePerClaim),
                'credibility' => [
                    'avg' => round((float) ($credDistribution->avg ?? 0), 3),
                    'min' => round((float) ($credDistribution->min ?? 0), 3),
                    'max' => round((float) ($credDistribution->max ?? 0), 3),
                    'median' => round((float) ($credDistribution->median ?? 0), 3),
                ],
                'retrieval_intent' => array_map(fn($d) => [
                    'intent' => $d->intent,
                    'count' => (int) $d->count,
                ], $db->select("
                    SELECT COALESCE(retrieval_intent, 'general') as intent, COUNT(*) as count
                    FROM evidence
                    WHERE created_at >= NOW() - INTERVAL '30 days'
                    GROUP BY COALESCE(retrieval_intent, 'general')
                    ORDER BY count DESC
                ")),
            ];
        } catch (\Exception $e) {
            Log::error('FactCheckOpsService::getEvidenceHealth failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get verdict distribution — supported/refuted/inconclusive ratios,
     * confidence trends, factuality scores, human review rates.
     */
    public function getVerdictDistribution(): array
    {
        try {
            $db = DB::connection('pgsql_rag');

            // Verdict breakdown
            $verdicts = $db->select("
                SELECT verdict, COUNT(*) as count,
                       AVG(confidence) as avg_confidence,
                       AVG(factuality_score) as avg_factuality
                FROM verdicts
                WHERE created_at >= NOW() - INTERVAL '30 days'
                GROUP BY verdict
                ORDER BY count DESC
            ");

            // Human review stats
            $reviewStats = $db->selectOne("
                SELECT COUNT(*) as total,
                       COUNT(CASE WHEN human_reviewed = TRUE THEN 1 END) as reviewed,
                       COUNT(CASE WHEN human_reviewed = FALSE THEN 1 END) as pending
                FROM verdicts
                WHERE created_at >= NOW() - INTERVAL '30 days'
            ");

            // Confidence distribution
            $confDistribution = $db->select("
                SELECT
                    CASE
                        WHEN confidence >= 0.8 THEN 'high (0.8-1.0)'
                        WHEN confidence >= 0.5 THEN 'medium (0.5-0.8)'
                        WHEN confidence >= 0.3 THEN 'low (0.3-0.5)'
                        ELSE 'very_low (<0.3)'
                    END as tier,
                    COUNT(*) as count
                FROM verdicts
                WHERE created_at >= NOW() - INTERVAL '30 days'
                GROUP BY tier
                ORDER BY count DESC
            ");

            // Weekly trend
            $weeklyTrend = $db->select("
                SELECT DATE(created_at) as date,
                       COUNT(*) as verdicts,
                       AVG(confidence) as avg_confidence
                FROM verdicts
                WHERE created_at >= NOW() - INTERVAL '7 days'
                GROUP BY DATE(created_at)
                ORDER BY date DESC
            ");

            $total = (int) ($reviewStats->total ?? 0);
            $reviewed = (int) ($reviewStats->reviewed ?? 0);

            return [
                'by_verdict' => array_map(fn($v) => [
                    'verdict' => $v->verdict,
                    'count' => (int) $v->count,
                    'avg_confidence' => round((float) $v->avg_confidence, 3),
                    'avg_factuality' => round((float) ($v->avg_factuality ?? 0), 3),
                ], $verdicts),
                'human_review' => [
                    'total' => $total,
                    'reviewed' => $reviewed,
                    'pending' => (int) ($reviewStats->pending ?? 0),
                    'review_rate_pct' => $total > 0 ? round(($reviewed / $total) * 100, 1) : 0,
                ],
                'confidence_distribution' => array_map(fn($d) => [
                    'tier' => $d->tier,
                    'count' => (int) $d->count,
                ], $confDistribution),
                'weekly_trend' => array_map(fn($d) => [
                    'date' => $d->date,
                    'verdicts' => (int) $d->verdicts,
                    'avg_confidence' => round((float) $d->avg_confidence, 3),
                ], $weeklyTrend),
            ];
        } catch (\Exception $e) {
            Log::error('FactCheckOpsService::getVerdictDistribution failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get source credibility overview — trust score distribution,
     * tier breakdown, declining sources.
     */
    public function getSourceCredibilityOverview(): array
    {
        try {
            $db = DB::connection('pgsql_rag');

            // Overall stats
            $stats = $db->selectOne("
                SELECT COUNT(*) as total_sources,
                       AVG(composite_score) as avg_score,
                       COUNT(CASE WHEN tier = 'high' THEN 1 END) as tier_high,
                       COUNT(CASE WHEN tier = 'medium' THEN 1 END) as tier_medium,
                       COUNT(CASE WHEN tier = 'low' THEN 1 END) as tier_low,
                       COUNT(CASE WHEN tier = 'unknown' OR tier IS NULL THEN 1 END) as tier_unknown
                FROM source_credibility
            ");

            // Stale sources (not verified in 30+ days)
            $staleSources = $db->selectOne("
                SELECT COUNT(*) as count
                FROM source_credibility
                WHERE last_verified_at IS NULL
                   OR last_verified_at < NOW() - INTERVAL '30 days'
            ");

            // Lowest trust sources
            $lowestTrust = $db->select("
                SELECT domain, composite_score, tier, verification_count,
                       last_verified_at
                FROM source_credibility
                WHERE composite_score IS NOT NULL
                ORDER BY composite_score ASC
                LIMIT 10
            ");

            // Most-cited sources
            $mostCited = $db->select("
                SELECT domain, composite_score, citation_count, tier
                FROM source_credibility
                WHERE citation_count > 0
                ORDER BY citation_count DESC
                LIMIT 10
            ");

            return [
                'total_sources' => (int) ($stats->total_sources ?? 0),
                'avg_trust_score' => round((float) ($stats->avg_score ?? 0), 3),
                'tier_breakdown' => [
                    'high' => (int) ($stats->tier_high ?? 0),
                    'medium' => (int) ($stats->tier_medium ?? 0),
                    'low' => (int) ($stats->tier_low ?? 0),
                    'unknown' => (int) ($stats->tier_unknown ?? 0),
                ],
                'stale_sources' => (int) ($staleSources->count ?? 0),
                'lowest_trust' => array_map(fn($s) => [
                    'domain' => $s->domain,
                    'score' => round((float) $s->composite_score, 3),
                    'tier' => $s->tier,
                    'verifications' => (int) ($s->verification_count ?? 0),
                    'last_verified' => $s->last_verified_at,
                ], $lowestTrust),
                'most_cited' => array_map(fn($s) => [
                    'domain' => $s->domain,
                    'score' => round((float) $s->composite_score, 3),
                    'citations' => (int) $s->citation_count,
                    'tier' => $s->tier,
                ], $mostCited),
            ];
        } catch (\Exception $e) {
            Log::error('FactCheckOpsService::getSourceCredibilityOverview failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get contradiction queue — pending contradictions awaiting review,
     * severity distribution, age breakdown.
     */
    public function getContradictionQueue(): array
    {
        try {
            $db = DB::connection('pgsql_rag');

            // Pending contradictions
            $pending = $db->selectOne("
                SELECT COUNT(*) as total,
                       COUNT(CASE WHEN severity >= 0.7 THEN 1 END) as high_severity,
                       COUNT(CASE WHEN severity >= 0.4 AND severity < 0.7 THEN 1 END) as medium_severity,
                       COUNT(CASE WHEN severity < 0.4 THEN 1 END) as low_severity,
                       MIN(created_at) as oldest_pending
                FROM contradictions
                WHERE human_reviewed = FALSE
            ");

            // Severity label distribution
            $byLabel = $db->select("
                SELECT severity_label, COUNT(*) as count
                FROM contradictions
                WHERE human_reviewed = FALSE
                GROUP BY severity_label
                ORDER BY count DESC
            ");

            // Contradiction types distribution
            $byType = $db->select("
                SELECT jsonb_array_elements_text(contradiction_types) as type, COUNT(*) as count
                FROM contradictions
                WHERE human_reviewed = FALSE
                AND contradiction_types IS NOT NULL
                GROUP BY type
                ORDER BY count DESC
            ");

            // Already reviewed stats
            $reviewed = $db->selectOne("
                SELECT COUNT(*) as total,
                       COUNT(CASE WHEN is_valid = TRUE THEN 1 END) as confirmed_valid,
                       COUNT(CASE WHEN is_valid = FALSE THEN 1 END) as false_positive
                FROM contradictions
                WHERE human_reviewed = TRUE
            ");

            return [
                'pending' => [
                    'total' => (int) ($pending->total ?? 0),
                    'high_severity' => (int) ($pending->high_severity ?? 0),
                    'medium_severity' => (int) ($pending->medium_severity ?? 0),
                    'low_severity' => (int) ($pending->low_severity ?? 0),
                    'oldest_pending' => $pending->oldest_pending,
                ],
                'by_severity_label' => array_map(fn($d) => [
                    'label' => $d->severity_label,
                    'count' => (int) $d->count,
                ], $byLabel),
                'by_type' => array_map(fn($d) => [
                    'type' => $d->type,
                    'count' => (int) $d->count,
                ], $byType),
                'reviewed_history' => [
                    'total_reviewed' => (int) ($reviewed->total ?? 0),
                    'confirmed_valid' => (int) ($reviewed->confirmed_valid ?? 0),
                    'false_positive' => (int) ($reviewed->false_positive ?? 0),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('FactCheckOpsService::getContradictionQueue failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get review backlog — unreviewed verdicts and contradictions,
     * age distribution, urgency assessment.
     */
    public function getReviewBacklog(): array
    {
        try {
            $db = DB::connection('pgsql_rag');

            // Unreviewed verdicts
            $verdictBacklog = $db->selectOne("
                SELECT COUNT(*) as count,
                       MIN(created_at) as oldest,
                       AVG(confidence) as avg_confidence
                FROM verdicts
                WHERE human_reviewed = FALSE
            ");

            // Unreviewed contradictions
            $contradictionBacklog = $db->selectOne("
                SELECT COUNT(*) as count,
                       MIN(created_at) as oldest,
                       AVG(severity) as avg_severity
                FROM contradictions
                WHERE human_reviewed = FALSE
            ");

            // Age buckets for verdicts
            $verdictAge = $db->select("
                SELECT
                    CASE
                        WHEN created_at >= NOW() - INTERVAL '1 day' THEN 'today'
                        WHEN created_at >= NOW() - INTERVAL '7 days' THEN 'this_week'
                        WHEN created_at >= NOW() - INTERVAL '30 days' THEN 'this_month'
                        ELSE 'older'
                    END as age_bucket,
                    COUNT(*) as count
                FROM verdicts
                WHERE human_reviewed = FALSE
                GROUP BY age_bucket
                ORDER BY count DESC
            ");

            return [
                'verdicts' => [
                    'pending' => (int) ($verdictBacklog->count ?? 0),
                    'oldest' => $verdictBacklog->oldest ?? null,
                    'avg_confidence' => round((float) ($verdictBacklog->avg_confidence ?? 0), 3),
                ],
                'contradictions' => [
                    'pending' => (int) ($contradictionBacklog->count ?? 0),
                    'oldest' => $contradictionBacklog->oldest ?? null,
                    'avg_severity' => round((float) ($contradictionBacklog->avg_severity ?? 0), 3),
                ],
                'total_backlog' => (int) ($verdictBacklog->count ?? 0) + (int) ($contradictionBacklog->count ?? 0),
                'verdict_age_distribution' => array_map(fn($d) => [
                    'bucket' => $d->age_bucket,
                    'count' => (int) $d->count,
                ], $verdictAge),
            ];
        } catch (\Exception $e) {
            Log::error('FactCheckOpsService::getReviewBacklog failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // ACT TOOLS
    // =========================================================================

    /**
     * Rerun failed claims through the pipeline. Limited to avoid
     * overwhelming LLM and search resources.
     */
    public function rerunFailedClaims(int $limit = 5): array
    {
        try {
            $db = DB::connection('pgsql_rag');

            // Find checkworthy claims with no verdict (pipeline failed mid-way)
            $failedClaims = $db->select("
                SELECT c.id, c.normalized_claim, c.checkworthiness_score
                FROM claims c
                WHERE c.checkworthiness_score >= 0.5
                AND c.created_at >= NOW() - INTERVAL '30 days'
                AND NOT EXISTS (
                    SELECT 1 FROM verdicts v WHERE v.claim_id = c.id
                )
                ORDER BY c.checkworthiness_score DESC
                LIMIT ?
            ", [$limit]);

            if (empty($failedClaims)) {
                return ['rerun_count' => 0, 'message' => 'No failed claims found needing rerun'];
            }

            $results = [];
            $pipeline = app(FactCheckPipelineService::class);

            foreach ($failedClaims as $claim) {
                try {
                    $result = $pipeline->verifyClaim($claim->normalized_claim, [
                        'persist' => true,
                        'skip_decomposition' => true, // Already decomposed
                    ]);
                    $results[] = [
                        'claim_id' => $claim->id,
                        'status' => 'success',
                        'verdict' => $result['verdict'] ?? 'unknown',
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'claim_id' => $claim->id,
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return [
                'rerun_count' => count($results),
                'results' => $results,
                'succeeded' => count(array_filter($results, fn($r) => $r['status'] === 'success')),
                'failed' => count(array_filter($results, fn($r) => $r['status'] === 'failed')),
            ];
        } catch (\Exception $e) {
            Log::error('FactCheckOpsService::rerunFailedClaims failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Flag low-confidence verdicts for human review by creating
     * review queue entries.
     */
    public function flagLowConfidenceVerdicts(float $threshold = 0.4): array
    {
        try {
            $db = DB::connection('pgsql_rag');

            // Find low-confidence verdicts not yet reviewed
            $lowConfidence = $db->select("
                SELECT v.id, v.claim_id, v.verdict, v.confidence, v.factuality_score,
                       c.normalized_claim
                FROM verdicts v
                JOIN claims c ON c.id = v.claim_id
                WHERE v.confidence < ?
                AND v.human_reviewed = FALSE
                AND v.created_at >= NOW() - INTERVAL '30 days'
                ORDER BY v.confidence ASC
                LIMIT " . config('factcheck.flag_low_confidence_batch', 20) . "
            ", [$threshold]);

            if (empty($lowConfidence)) {
                return ['flagged' => 0, 'message' => 'No low-confidence verdicts found below threshold ' . $threshold];
            }

            // Submit each for review
            $flagged = 0;
            foreach ($lowConfidence as $verdict) {
                try {
                    $token = bin2hex(random_bytes(32));
                    DB::insert("
                        INSERT INTO agent_review_queue
                        (agent_id, review_type, title, summary, details, confidence, priority, status, token, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())
                    ", [
                        'factcheck-ops',
                        'factcheck_verdict',
                        "Low-confidence verdict: {$verdict->verdict} (conf: {$verdict->confidence})",
                        "Claim: " . mb_substr($verdict->normalized_claim, 0, 200),
                        json_encode([
                            'claim_id' => $verdict->claim_id,
                            'verdict_id' => $verdict->id,
                            'verdict' => $verdict->verdict,
                            'confidence' => $verdict->confidence,
                            'factuality_score' => $verdict->factuality_score,
                            'flagged_by' => 'factcheck-ops',
                        ]),
                        $verdict->confidence,
                        $verdict->confidence < 0.2 ? 1 : 0, // high priority if very low
                        $token,
                    ]);
                    $flagged++;
                } catch (\Exception $e) {
                    // Skip duplicates
                }
            }

            return [
                'flagged' => $flagged,
                'threshold' => $threshold,
                'total_found' => count($lowConfidence),
            ];
        } catch (\Exception $e) {
            Log::error('FactCheckOpsService::flagLowConfidenceVerdicts failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Refresh credibility scores for stale sources (not verified recently).
     */
    public function refreshStaleSources(int $limit = 10, int $staleDays = 30): array
    {
        try {
            $db = DB::connection('pgsql_rag');

            // Find stale sources
            $staleSources = $db->select("
                SELECT id, domain, url, composite_score, last_verified_at
                FROM source_credibility
                WHERE last_verified_at IS NULL
                   OR last_verified_at < NOW() - INTERVAL '{$staleDays} days'
                ORDER BY citation_count DESC
                LIMIT ?
            ", [$limit]);

            if (empty($staleSources)) {
                return ['refreshed' => 0, 'message' => "No sources stale beyond {$staleDays} days"];
            }

            $results = [];
            $credService = app(SourceCredibilityService::class);

            foreach ($staleSources as $source) {
                try {
                    $url = $source->url ?? "https://{$source->domain}";
                    $newScore = $credService->calculateComposite($url);
                    $results[] = [
                        'domain' => $source->domain,
                        'old_score' => round((float) $source->composite_score, 3),
                        'new_score' => round((float) ($newScore['composite_score'] ?? 0), 3),
                        'status' => 'refreshed',
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'domain' => $source->domain,
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return [
                'refreshed' => count(array_filter($results, fn($r) => $r['status'] === 'refreshed')),
                'failed' => count(array_filter($results, fn($r) => $r['status'] === 'failed')),
                'results' => $results,
            ];
        } catch (\Exception $e) {
            Log::error('FactCheckOpsService::refreshStaleSources failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }
}
