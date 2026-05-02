<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Claim Verification Service
 *
 * Verifies claims against web evidence using:
 * 1. Query generation for evidence retrieval
 * 2. Web search via SearXNG
 * 3. Natural Language Inference (NLI) for evidence classification
 * 4. Verdict aggregation with factuality scoring
 *
 * Based on: VeriScore pattern from research-synthesis-feb2026.md §5
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
class ClaimVerificationService
{
    private AIService $aiService;
    private SearXNGService $searxngService;
    private ?DomainCredibilityService $domainCredibilityService = null;

    /** @var int Default number of search queries per claim */
    private const DEFAULT_QUERY_COUNT = 3;

    /** @var int Maximum evidence snippets per claim */
    private const MAX_EVIDENCE_PER_CLAIM = 10;

    /** @var float Minimum NLI confidence to count as supporting/contradicting */
    private const NLI_CONFIDENCE_THRESHOLD = 0.6;

    /** @var int Minimum providers for consensus (primary + devil's advocate) */
    private const MIN_CONSENSUS_PROVIDERS = 2;

    /** @var int Maximum providers to query for consensus */
    private const MAX_CONSENSUS_PROVIDERS = 3;

    public function __construct(AIService $aiService, SearXNGService $searxngService)
    {
        $this->aiService = $aiService;
        $this->searxngService = $searxngService;
    }

    private function getDomainCredibilityService(): DomainCredibilityService
    {
        if ($this->domainCredibilityService === null) {
            $this->domainCredibilityService = app(DomainCredibilityService::class);
        }
        return $this->domainCredibilityService;
    }

    /**
     * Verify an array of claims against web evidence
     *
     * @param array $claims Array of claims to verify (objects with 'id', 'normalized_claim')
     * @param array $options Options:
     *   - query_count: int - Number of search queries per claim
     *   - persist: bool - Save evidence and verdicts to database
     *   - max_evidence: int - Maximum evidence per claim
     * @return array Verification results with verdicts
     */
    public function verify(array $claims, array $options = []): array
    {
        $startTime = microtime(true);
        $persist = $options['persist'] ?? true;
        $queryCount = $options['query_count'] ?? self::DEFAULT_QUERY_COUNT;
        $maxEvidence = $options['max_evidence'] ?? self::MAX_EVIDENCE_PER_CLAIM;
        $useConsensus = $options['consensus_verification'] ?? false;
        $useMultiHop = $options['multi_hop_verification'] ?? false;
        $useTemporalReasoning = $options['temporal_reasoning'] ?? true; // FC-7: on by default

        $results = [];
        $stats = [
            'claims_processed' => 0,
            'evidence_collected' => 0,
            'verdicts' => ['supported' => 0, 'refuted' => 0, 'inconclusive' => 0],
        ];

        foreach ($claims as $claim) {
            $claimId = $claim->id ?? $claim['id'] ?? null;
            $claimText = $claim->normalized_claim ?? $claim['normalized_claim'] ?? '';

            if (empty($claimText)) {
                continue;
            }

            try {
                // Step 1: Generate search queries
                $queries = $this->generateQueries($claimText, $queryCount);

                // Step 2: Search for evidence
                $evidence = [];
                foreach ($queries as $query) {
                    $searchResults = $this->searxngService->search($query, 5);

                    if ($searchResults['success'] && !empty($searchResults['results'])) {
                        foreach ($searchResults['results'] as $rank => $result) {
                            $evidence[] = [
                                'snippet' => $result['snippet'] ?? $result['title'] ?? '',
                                'source_url' => $result['url'] ?? '',
                                'source_title' => $result['title'] ?? '',
                                'source_domain' => parse_url($result['url'] ?? '', PHP_URL_HOST),
                                'retrieval_query' => $query,
                                'retrieval_rank' => $rank + 1,
                            ];
                        }
                    }
                }

                // Deduplicate by URL and limit
                $evidence = $this->deduplicateEvidence($evidence, $maxEvidence);
                $stats['evidence_collected'] += count($evidence);

                // Step 3: Verify claim against evidence
                $verdict = $this->verifyAgainstEvidence($claimText, $evidence);

                // Step 3b: Multi-LLM consensus verification (FC-1)
                if ($useConsensus) {
                    $consensus = $this->verifyWithConsensus($claimText, $evidence, $verdict);
                    $verdict = $consensus['final_verdict'];

                    if ($persist && $claimId) {
                        $this->persistConsensusVerdict($claimId, $consensus);
                    }
                }

                // Step 3c: Multi-hop KG verification (FC-6)
                if ($useMultiHop) {
                    try {
                        $multiHop = app(MultiHopVerificationService::class)->verify($claimText);
                        if ($multiHop['paths_found'] > 0 && $multiHop['confidence'] > 0.5) {
                            // Blend multi-hop verdict with evidence-based verdict
                            $verdict = $this->blendMultiHopVerdict($verdict, $multiHop);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('ClaimVerification: Multi-hop verification failed', [
                            'claim_id' => $claimId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Step 3d: Temporal claim reasoning (FC-7) — flag stale evidence, adjust confidence
                if ($useTemporalReasoning) {
                    try {
                        $verdict = app(TemporalClaimReasoningService::class)->reason($claimText, $evidence, $verdict);
                    } catch (\Throwable $e) {
                        Log::warning('ClaimVerification: Temporal reasoning failed', [
                            'claim_id' => $claimId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Step 4: Persist results
                if ($persist && $claimId) {
                    $this->persistEvidence($claimId, $evidence, $verdict);
                    $this->persistVerdict($claimId, $verdict);

                    // FC-2: Feed verdict back to source credibility (Bayesian update)
                    $this->updateSourceCredibilityFromVerdict($evidence, $verdict);
                }

                $stats['claims_processed']++;
                $stats['verdicts'][$verdict['verdict']]++;

                $results[] = [
                    'claim_id' => $claimId,
                    'claim' => $claimText,
                    'verdict' => $verdict['verdict'],
                    'confidence' => $verdict['confidence'],
                    'factuality_score' => $verdict['factuality_score'],
                    'evidence_count' => count($evidence),
                    'supporting_count' => $verdict['supporting_count'],
                    'contradicting_count' => $verdict['contradicting_count'],
                    'evidence_summary' => $verdict['evidence_summary'],
                ];

            } catch (\Throwable $e) {
                Log::error('ClaimVerification: Claim verification failed', [
                    'claim_id' => $claimId,
                    'error' => $e->getMessage(),
                ]);

                $results[] = [
                    'claim_id' => $claimId,
                    'claim' => $claimText,
                    'verdict' => 'inconclusive',
                    'confidence' => 0,
                    'error' => $e->getMessage(),
                ];

                $stats['verdicts']['inconclusive']++;
            }
        }

        $duration = round((microtime(true) - $startTime) * 1000);

        Log::info('ClaimVerification: Batch complete', [
            'claims' => $stats['claims_processed'],
            'evidence' => $stats['evidence_collected'],
            'verdicts' => $stats['verdicts'],
            'duration_ms' => $duration,
        ]);

        return [
            'success' => true,
            'results' => $results,
            'stats' => $stats,
            'duration_ms' => $duration,
        ];
    }

    /**
     * Generate search queries for a claim
     *
     * @param string $claim The claim to generate queries for
     * @param int $count Number of queries to generate
     * @return array Array of search queries
     */
    public function generateQueries(string $claim, int $count = 3): array
    {
        $prompt = <<<PROMPT
Generate {$count} distinct search queries to find evidence about this claim:

Claim: "{$claim}"

Rules:
1. Each query should approach the claim from a different angle
2. Include queries that could find BOTH supporting AND contradicting evidence
3. Use specific names, dates, and numbers from the claim
4. Avoid leading or biased query phrasing
5. Keep queries under 10 words for better search results

Output ONLY a JSON array of query strings (no markdown):
["query 1", "query 2", "query 3"]
PROMPT;

        $result = $this->aiService->process($prompt, [
            'factual_mode' => true,
            'max_tokens' => 200,
        ]);

        if (!$result['success']) {
            // Fallback: use claim as query
            return [$claim];
        }

        $parsed = $this->parseJsonResponse($result['response'], true);

        if (empty($parsed) || !is_array($parsed)) {
            return [$claim];
        }

        // Ensure we have string queries
        $queries = array_filter($parsed, 'is_string');

        return !empty($queries) ? array_slice($queries, 0, $count) : [$claim];
    }

    /**
     * Verify a claim against collected evidence using NLI
     *
     * @param string $claim The claim to verify
     * @param array $evidence Array of evidence snippets
     * @return array Verdict with confidence and summary
     */
    public function verifyAgainstEvidence(string $claim, array $evidence): array
    {
        if (empty($evidence)) {
            return [
                'verdict' => 'inconclusive',
                'confidence' => 0,
                'factuality_score' => null,
                'supporting_count' => 0,
                'contradicting_count' => 0,
                'neutral_count' => 0,
                'evidence_summary' => 'No evidence found for verification.',
            ];
        }

        $supporting = 0;
        $contradicting = 0;
        $neutral = 0;
        $nliResults = [];

        // Classify each evidence snippet
        foreach ($evidence as &$item) {
            $nli = $this->classifyEvidence($claim, $item['snippet']);
            $item['nli_label'] = $nli['label'];
            $item['nli_score'] = $nli['score'];
            $item['credibility_score'] = $this->getCredibilityScore($item['source_domain']);

            // Weight by credibility
            $weightedScore = $nli['score'] * $item['credibility_score'];

            if ($nli['label'] === 'supported' && $weightedScore >= self::NLI_CONFIDENCE_THRESHOLD * 0.5) {
                $supporting++;
            } elseif ($nli['label'] === 'contradicted' && $weightedScore >= self::NLI_CONFIDENCE_THRESHOLD * 0.5) {
                $contradicting++;
            } else {
                $neutral++;
            }

            $nliResults[] = $item;
        }

        // Calculate factuality score: supported / (supported + contradicted)
        $factuality = null;
        if ($supporting + $contradicting > 0) {
            $factuality = round($supporting / ($supporting + $contradicting), 3);
        }

        // Determine verdict
        $verdict = $this->determineVerdict($supporting, $contradicting, $neutral, $factuality);

        // Generate evidence summary
        $summary = $this->generateEvidenceSummary($claim, $nliResults, $verdict);

        return [
            'verdict' => $verdict['verdict'],
            'confidence' => $verdict['confidence'],
            'factuality_score' => $factuality,
            'supporting_count' => $supporting,
            'contradicting_count' => $contradicting,
            'neutral_count' => $neutral,
            'evidence_summary' => $summary,
            'evidence_details' => $nliResults,
        ];
    }

    /**
     * Classify evidence snippet relative to claim (NLI)
     *
     * @param string $claim The claim
     * @param string $evidence The evidence snippet
     * @return array ['label' => string, 'score' => float]
     */
    private function classifyEvidence(string $claim, string $evidence): array
    {
        if (empty($evidence) || strlen($evidence) < 20) {
            return ['label' => 'neutral', 'score' => 0.5];
        }

        $prompt = <<<PROMPT
Classify the relationship between this claim and evidence:

CLAIM: "{$claim}"

EVIDENCE: "{$evidence}"

Classification rules:
- SUPPORTED: Evidence directly confirms or strongly supports the claim
- CONTRADICTED: Evidence directly denies or contradicts the claim
- NEUTRAL: Evidence is unrelated, tangential, or neither confirms nor denies

Output ONLY valid JSON (no markdown):
{"label": "supported|contradicted|neutral", "score": 0.0-1.0, "reason": "brief explanation"}
PROMPT;

        $result = $this->aiService->process($prompt, [
            'factual_mode' => true,
            'max_tokens' => 150,
        ]);

        if (!$result['success']) {
            return ['label' => 'neutral', 'score' => 0.5];
        }

        $parsed = $this->parseJsonResponse($result['response']);

        $label = $parsed['label'] ?? 'neutral';
        if (!in_array($label, ['supported', 'contradicted', 'neutral'])) {
            $label = 'neutral';
        }

        return [
            'label' => $label,
            'score' => (float) ($parsed['score'] ?? 0.5),
        ];
    }

    /**
     * Determine final verdict based on evidence counts
     *
     * @param int $supporting Count of supporting evidence
     * @param int $contradicting Count of contradicting evidence
     * @param int $neutral Count of neutral evidence
     * @param float|null $factuality Factuality score
     * @return array ['verdict' => string, 'confidence' => float]
     */
    /**
     * FC-8: 5-Class Verdict System (PolitiFact/FACT5 standard)
     *
     * Maps evidence counts and factuality score to one of:
     *   true, mostly_true, half_true, mostly_false, false
     *
     * Falls back to 'inconclusive' when evidence is insufficient.
     */
    private function determineVerdict(int $supporting, int $contradicting, int $neutral, ?float $factuality): array
    {
        $total = $supporting + $contradicting + $neutral;

        if ($total === 0) {
            return ['verdict' => 'inconclusive', 'confidence' => 0];
        }

        // Calculate evidence ratio
        $evidenceRatio = ($supporting + $contradicting) / $total;

        // Not enough decisive evidence
        if ($supporting + $contradicting < 2 || $evidenceRatio < 0.3) {
            $confidence = max(0.1, $evidenceRatio);
            return ['verdict' => 'inconclusive', 'confidence' => round($confidence, 3)];
        }

        // Use factuality score for 5-class determination
        if ($factuality !== null) {
            if ($factuality >= 0.85 && $supporting >= 2) {
                $confidence = min(0.95, 0.5 + ($factuality * 0.4) + ($supporting * 0.05));
                return ['verdict' => 'true', 'confidence' => round($confidence, 3)];
            }
            if ($factuality >= 0.65 && $supporting > $contradicting) {
                $confidence = min(0.85, 0.4 + ($factuality * 0.4) + ($supporting * 0.05));
                return ['verdict' => 'mostly_true', 'confidence' => round($confidence, 3)];
            }
            if ($factuality <= 0.15 && $contradicting >= 2) {
                $confidence = min(0.95, 0.5 + ((1 - $factuality) * 0.4) + ($contradicting * 0.05));
                return ['verdict' => 'false', 'confidence' => round($confidence, 3)];
            }
            if ($factuality <= 0.35 && $contradicting > $supporting) {
                $confidence = min(0.85, 0.4 + ((1 - $factuality) * 0.4) + ($contradicting * 0.05));
                return ['verdict' => 'mostly_false', 'confidence' => round($confidence, 3)];
            }
        }

        // Mixed or moderate evidence → half_true
        if ($supporting > 0 && $contradicting > 0) {
            $confidence = max(0.2, min(0.6, $evidenceRatio * 0.5));
            return ['verdict' => 'half_true', 'confidence' => round($confidence, 3)];
        }

        // Some evidence but not strong enough for true/false
        if ($supporting > $contradicting) {
            return ['verdict' => 'mostly_true', 'confidence' => round(0.4 + ($evidenceRatio * 0.3), 3)];
        }
        if ($contradicting > $supporting) {
            return ['verdict' => 'mostly_false', 'confidence' => round(0.4 + ($evidenceRatio * 0.3), 3)];
        }

        return ['verdict' => 'half_true', 'confidence' => round(max(0.2, $evidenceRatio * 0.4), 3)];
    }

    /**
     * Generate a summary of evidence for/against the claim
     *
     * @param string $claim The claim
     * @param array $evidence Evidence with NLI labels
     * @param array $verdict The determined verdict
     * @return string Summary text
     */
    private function generateEvidenceSummary(string $claim, array $evidence, array $verdict): string
    {
        $supporting = array_filter($evidence, fn($e) => ($e['nli_label'] ?? '') === 'supported');
        $contradicting = array_filter($evidence, fn($e) => ($e['nli_label'] ?? '') === 'contradicted');

        $parts = [];

        if (!empty($supporting)) {
            $sources = array_map(fn($e) => $e['source_domain'] ?? 'unknown', array_slice($supporting, 0, 3));
            $parts[] = 'Supporting evidence from: ' . implode(', ', $sources);
        }

        if (!empty($contradicting)) {
            $sources = array_map(fn($e) => $e['source_domain'] ?? 'unknown', array_slice($contradicting, 0, 3));
            $parts[] = 'Contradicting evidence from: ' . implode(', ', $sources);
        }

        if (empty($parts)) {
            return 'No definitive supporting or contradicting evidence found.';
        }

        return implode('. ', $parts) . '.';
    }

    /**
     * Get credibility score for a domain (from shared domain_credibility table)
     *
     * @param string|null $domain Domain name
     * @return float Credibility score 0-1
     */
    private function getCredibilityScore(?string $domain): float
    {
        return $this->getDomainCredibilityService()->getScore($domain ?? '');
    }

    /**
     * Deduplicate evidence by URL
     *
     * @param array $evidence Evidence array
     * @param int $maxCount Maximum evidence to keep
     * @return array Deduplicated evidence
     */
    private function deduplicateEvidence(array $evidence, int $maxCount): array
    {
        $seen = [];
        $unique = [];

        foreach ($evidence as $item) {
            $url = $item['source_url'] ?? '';
            if (!empty($url) && !isset($seen[$url])) {
                $seen[$url] = true;
                $unique[] = $item;

                if (count($unique) >= $maxCount) {
                    break;
                }
            }
        }

        return $unique;
    }

    /**
     * Persist evidence to database
     *
     * @param int $claimId Claim ID
     * @param array $evidence Evidence with NLI labels
     * @param array $verdict Verdict data
     */
    private function persistEvidence(int $claimId, array $evidence, array $verdict): void
    {
        foreach ($evidence as $item) {
            DB::connection('pgsql_rag')->insert("
                INSERT INTO evidence (
                    claim_id, snippet, source_url, source_title, source_domain,
                    nli_label, nli_score, credibility_score, retrieval_query, retrieval_rank
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $claimId,
                $item['snippet'] ?? '',
                $item['source_url'] ?? '',
                $item['source_title'] ?? '',
                $item['source_domain'] ?? '',
                $item['nli_label'] ?? 'neutral',
                $item['nli_score'] ?? 0.5,
                $item['credibility_score'] ?? 0.5,
                $item['retrieval_query'] ?? '',
                $item['retrieval_rank'] ?? 0,
            ]);
        }
    }

    /**
     * Persist verdict to database
     *
     * @param int $claimId Claim ID
     * @param array $verdict Verdict data
     */
    private function persistVerdict(int $claimId, array $verdict): void
    {
        // Use upsert pattern (INSERT ... ON CONFLICT UPDATE)
        DB::connection('pgsql_rag')->statement("
            INSERT INTO verdicts (
                claim_id, verdict, confidence, factuality_score, evidence_summary,
                supporting_count, contradicting_count, neutral_count
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (claim_id) DO UPDATE SET
                verdict = EXCLUDED.verdict,
                confidence = EXCLUDED.confidence,
                factuality_score = EXCLUDED.factuality_score,
                evidence_summary = EXCLUDED.evidence_summary,
                supporting_count = EXCLUDED.supporting_count,
                contradicting_count = EXCLUDED.contradicting_count,
                neutral_count = EXCLUDED.neutral_count,
                updated_at = CURRENT_TIMESTAMP
        ", [
            $claimId,
            $verdict['verdict'],
            $verdict['confidence'],
            $verdict['factuality_score'],
            $verdict['evidence_summary'],
            $verdict['supporting_count'],
            $verdict['contradicting_count'],
            $verdict['neutral_count'],
        ]);
    }

    /**
     * FC-2: Feed verification verdict back to source credibility for Bayesian updates.
     *
     * FC-6: Blend multi-hop KG verdict with evidence-based verdict.
     * KG paths are weighted at 30% — structured triples are precise but narrow.
     */
    private function blendMultiHopVerdict(array $evidenceVerdict, array $multiHopResult): array
    {
        $kgWeight = 0.30;
        $evidenceWeight = 0.70;

        // Map KG verdict to numeric score
        $kgScore = match ($multiHopResult['verdict']) {
            'supported' => 1.0,
            'contradicted' => 0.0,
            default => 0.5,
        };

        $evidenceScore = $evidenceVerdict['factuality_score'] ?? 0.5;
        $blended = ($evidenceScore * $evidenceWeight) + ($kgScore * $multiHopResult['confidence'] * $kgWeight);

        // Determine blended verdict
        $blendedVerdict = $blended >= 0.65 ? 'supported' : ($blended <= 0.35 ? 'refuted' : 'inconclusive');

        $evidenceVerdict['factuality_score'] = round($blended, 3);
        $evidenceVerdict['verdict'] = $blendedVerdict;
        $evidenceVerdict['multi_hop'] = [
            'verdict' => $multiHopResult['verdict'],
            'confidence' => $multiHopResult['confidence'],
            'paths_found' => $multiHopResult['paths_found'],
            'max_hops' => $multiHopResult['max_hops_used'],
            'reasoning' => $multiHopResult['reasoning'],
        ];

        // Boost confidence if KG and evidence agree
        if ($multiHopResult['verdict'] === $evidenceVerdict['verdict']) {
            $evidenceVerdict['confidence'] = min(1.0, ($evidenceVerdict['confidence'] ?? 0.5) * 1.15);
        }

        return $evidenceVerdict;
    }

    /**
     * FC-2: Feed verification verdict back to source credibility for Bayesian updates.
     *
     * Maps verdict to verification result per evidence source domain,
     * then updates the Bayesian posterior for each domain.
     */
    private function updateSourceCredibilityFromVerdict(array $evidence, array $verdict): void
    {
        try {
            $credService = app(SourceCredibilityService::class);
            $verdictLabel = $verdict['verdict'] ?? 'inconclusive';

            // Map claim verdict to source verification result
            $verificationResult = match ($verdictLabel) {
                'supported' => 'verified',
                'refuted' => 'refuted',
                'partially_supported', 'mixed' => 'partially_verified',
                default => null,
            };

            if ($verificationResult === null) {
                return; // Skip inconclusive — doesn't inform source quality
            }

            // Update each unique evidence source domain
            $processedDomains = [];
            foreach ($evidence as $item) {
                $domain = $item['source_domain'] ?? '';
                if (empty($domain) || isset($processedDomains[$domain])) {
                    continue;
                }
                $processedDomains[$domain] = true;

                $url = $item['source_url'] ?? "https://{$domain}";
                $credService->recordVerificationResult($domain, $url, $verificationResult);
                $credService->updateBayesian($domain, $verificationResult);
            }
        } catch (\Throwable $e) {
            // Non-critical: don't fail verification if credibility update fails
            Log::warning('ClaimVerification: Source credibility feedback failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get verification status for a claim
     *
     * @param int $claimId Claim ID
     * @return array|null Verdict data or null if not verified
     */
    public function getVerdict(int $claimId): ?array
    {
        $result = DB::connection('pgsql_rag')->select("
            SELECT v.*, c.normalized_claim
            FROM verdicts v
            JOIN claims c ON c.id = v.claim_id
            WHERE v.claim_id = ?
        ", [$claimId]);

        return !empty($result) ? (array) $result[0] : null;
    }

    /**
     * Get evidence for a claim
     *
     * @param int $claimId Claim ID
     * @return array Evidence records
     */
    public function getEvidence(int $claimId): array
    {
        return DB::connection('pgsql_rag')->select("
            SELECT * FROM evidence
            WHERE claim_id = ?
            ORDER BY nli_score DESC, credibility_score DESC
        ", [$claimId]);
    }

    /**
     * Get claims pending human review
     *
     * @param int $limit Maximum claims to return
     * @return array Verdicts needing review
     */
    public function getPendingReview(int $limit = 50): array
    {
        return DB::connection('pgsql_rag')->select("
            SELECT v.*, c.normalized_claim, c.source_text, c.checkworthiness_score
            FROM verdicts v
            JOIN claims c ON c.id = v.claim_id
            WHERE v.human_reviewed = FALSE
            ORDER BY v.confidence ASC, c.checkworthiness_score DESC
            LIMIT ?
        ", [$limit]);
    }

    /**
     * Submit human review for a verdict
     *
     * @param int $claimId Claim ID
     * @param string $verdict Human-determined verdict
     * @param string $reviewer Reviewer identifier
     * @param string|null $notes Review notes
     * @return bool Success
     */
    public function submitReview(int $claimId, string $verdict, string $reviewer, ?string $notes = null): bool
    {
        if (!in_array($verdict, ['supported', 'refuted', 'inconclusive'])) {
            return false;
        }

        $affected = DB::connection('pgsql_rag')->update("
            UPDATE verdicts SET
                verdict = ?,
                human_reviewed = TRUE,
                reviewed_by = ?,
                review_notes = ?,
                reviewed_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE claim_id = ?
        ", [$verdict, $reviewer, $notes, $claimId]);

        return $affected > 0;
    }

    // =========================================================================
    // Multi-LLM Consensus Verification (FC-1)
    // Based on LoCal (ACM Web 2025) multi-agent fact-checking pattern
    // =========================================================================

    /**
     * Verify a claim using multiple LLM providers for consensus
     *
     * Runs the primary verdict through:
     * 1. A second provider for independent verification
     * 2. A devil's advocate prompt that actively tries to disprove the claim
     * Then builds a consensus verdict weighted by agreement.
     *
     * @param string $claim The claim text
     * @param array $evidence Evidence collected for this claim
     * @param array $primaryVerdict The initial single-provider verdict
     * @return array Consensus result with final_verdict, provider_details, agreement
     */
    public function verifyWithConsensus(string $claim, array $evidence, array $primaryVerdict): array
    {
        $startTime = microtime(true);
        $providerResults = [];

        // Provider 1: Primary verdict (already computed)
        $providerResults[] = [
            'provider' => 'primary',
            'verdict' => $primaryVerdict['verdict'],
            'confidence' => $primaryVerdict['confidence'],
            'factuality_score' => $primaryVerdict['factuality_score'],
        ];

        // Build evidence summary for secondary providers
        $evidenceSummary = $this->buildEvidenceSummaryForConsensus($evidence);

        // Provider 2: Independent verification via external provider
        $secondaryVerdict = $this->getSecondaryVerdict($claim, $evidenceSummary);
        if ($secondaryVerdict !== null) {
            $providerResults[] = $secondaryVerdict;
        }

        // Provider 3: Devil's advocate — actively tries to disprove the primary verdict
        $devilsAdvocate = $this->getDevilsAdvocateVerdict($claim, $evidenceSummary, $primaryVerdict['verdict']);
        $devilAdvocateVerdict = null;
        $devilAdvocateConfidence = null;
        if ($devilsAdvocate !== null) {
            $providerResults[] = $devilsAdvocate;
            $devilAdvocateVerdict = $devilsAdvocate['verdict'];
            $devilAdvocateConfidence = $devilsAdvocate['confidence'];
        }

        // Build consensus from all provider results
        $consensus = $this->buildConsensus($providerResults, $primaryVerdict);

        $duration = round((microtime(true) - $startTime) * 1000);

        Log::info('ClaimVerification: Consensus complete', [
            'claim' => substr($claim, 0, 80),
            'providers' => count($providerResults),
            'primary_verdict' => $primaryVerdict['verdict'],
            'consensus_verdict' => $consensus['verdict'],
            'agreement_ratio' => $consensus['agreement_ratio'],
            'duration_ms' => $duration,
        ]);

        return [
            'final_verdict' => $consensus,
            'provider_details' => $providerResults,
            'provider_count' => count($providerResults),
            'agreement_ratio' => $consensus['agreement_ratio'],
            'devil_advocate_verdict' => $devilAdvocateVerdict,
            'devil_advocate_confidence' => $devilAdvocateConfidence,
            'duration_ms' => $duration,
        ];
    }

    /**
     * Build a concise evidence summary for secondary providers
     */
    private function buildEvidenceSummaryForConsensus(array $evidence): string
    {
        $parts = [];
        $count = 0;
        foreach ($evidence as $item) {
            if ($count >= 5) break;
            $snippet = $item['snippet'] ?? '';
            $domain = $item['source_domain'] ?? 'unknown';
            if (!empty($snippet)) {
                $parts[] = "[{$domain}] " . substr($snippet, 0, 200);
                $count++;
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * Get an independent verdict from a secondary provider
     */
    private function getSecondaryVerdict(string $claim, string $evidenceSummary): ?array
    {
        $prompt = <<<PROMPT
You are an independent fact-checker verifying a claim against evidence.

CLAIM: "{$claim}"

EVIDENCE:
{$evidenceSummary}

Evaluate the evidence and determine if the claim is supported, contradicted, or inconclusive.
Consider source quality, specificity of evidence, and whether evidence directly addresses the claim.

Output ONLY valid JSON (no markdown):
{"verdict": "supported|contradicted|inconclusive", "confidence": 0.0-1.0, "reasoning": "2-3 sentence explanation"}
PROMPT;

        $result = $this->aiService->process($prompt, [
            'factual_mode' => true,
            'max_tokens' => 250,
            'use_cache' => false,
            'suppressAlert' => true,
        ]);

        if (!$result['success']) {
            Log::warning('ClaimVerification: Secondary provider failed', [
                'error' => $result['error'] ?? 'unknown',
            ]);
            return null;
        }

        $parsed = $this->parseJsonResponse($result['response']);
        $verdict = $parsed['verdict'] ?? null;

        if (!in_array($verdict, ['supported', 'contradicted', 'inconclusive'])) {
            return null;
        }

        return [
            'provider' => 'secondary_' . ($result['provider'] ?? 'external'),
            'verdict' => $verdict,
            'confidence' => (float) ($parsed['confidence'] ?? 0.5),
            'reasoning' => $parsed['reasoning'] ?? '',
        ];
    }

    /**
     * Get a devil's advocate verdict that actively tries to disprove the primary verdict
     */
    private function getDevilsAdvocateVerdict(string $claim, string $evidenceSummary, string $primaryVerdict): ?array
    {
        $oppositeStance = match ($primaryVerdict) {
            'supported' => 'Find reasons why this claim might be FALSE or misleading',
            'refuted' => 'Find reasons why this claim might actually be TRUE or partially correct',
            default => 'Find the strongest argument both FOR and AGAINST this claim',
        };

        $prompt = <<<PROMPT
You are a devil's advocate fact-checker. Your role is to challenge the initial assessment.

CLAIM: "{$claim}"

EVIDENCE:
{$evidenceSummary}

INITIAL ASSESSMENT: The claim was initially judged as "{$primaryVerdict}".

YOUR TASK: {$oppositeStance}. Look for:
- Evidence that was misinterpreted or taken out of context
- Missing context that changes the meaning
- Logical gaps in the reasoning
- Alternative explanations for the evidence

After your critical analysis, give your honest final verdict. You are NOT required to disagree — only disagree if the evidence warrants it.

Output ONLY valid JSON (no markdown):
{"verdict": "supported|contradicted|inconclusive", "confidence": 0.0-1.0, "counterarguments": "key challenges found", "final_assessment": "brief honest conclusion"}
PROMPT;

        $result = $this->aiService->process($prompt, [
            'factual_mode' => true,
            'max_tokens' => 300,
            'use_cache' => false,
            'suppressAlert' => true,
        ]);

        if (!$result['success']) {
            Log::warning('ClaimVerification: Devil\'s advocate failed', [
                'error' => $result['error'] ?? 'unknown',
            ]);
            return null;
        }

        $parsed = $this->parseJsonResponse($result['response']);
        $verdict = $parsed['verdict'] ?? null;

        if (!in_array($verdict, ['supported', 'contradicted', 'inconclusive'])) {
            return null;
        }

        return [
            'provider' => 'devils_advocate_' . ($result['provider'] ?? 'default'),
            'verdict' => $verdict,
            'confidence' => (float) ($parsed['confidence'] ?? 0.5),
            'counterarguments' => $parsed['counterarguments'] ?? '',
            'final_assessment' => $parsed['final_assessment'] ?? '',
        ];
    }

    /**
     * Build consensus verdict from multiple provider results
     *
     * Weights: primary=1.0, secondary=0.8, devil's_advocate=0.6
     * Agreement ratio: fraction of providers that agree with consensus verdict
     */
    private function buildConsensus(array $providerResults, array $primaryVerdict): array
    {
        if (count($providerResults) < self::MIN_CONSENSUS_PROVIDERS) {
            // Not enough providers — fall back to primary verdict
            return array_merge($primaryVerdict, ['agreement_ratio' => 1.0, 'consensus_method' => 'single_provider']);
        }

        // Weight each provider's verdict
        $weights = [
            'primary' => 1.0,
            'secondary' => 0.8,
            'devils_advocate' => 0.6,
        ];

        $verdictScores = ['supported' => 0, 'contradicted' => 0, 'inconclusive' => 0];
        $totalWeight = 0;
        $totalConfidence = 0;
        $totalFactuality = 0;
        $factualityCount = 0;

        foreach ($providerResults as $result) {
            $providerType = 'secondary'; // default
            if (str_starts_with($result['provider'], 'primary')) {
                $providerType = 'primary';
            } elseif (str_starts_with($result['provider'], 'devils_advocate')) {
                $providerType = 'devils_advocate';
            }

            $weight = $weights[$providerType] ?? 0.5;
            $verdict = $result['verdict'] ?? 'inconclusive';
            $confidence = $result['confidence'] ?? 0.5;

            // Map 'refuted' to 'contradicted' for internal consistency
            if ($verdict === 'refuted') {
                $verdict = 'contradicted';
            }

            if (isset($verdictScores[$verdict])) {
                $verdictScores[$verdict] += $weight * $confidence;
            }

            $totalWeight += $weight;
            $totalConfidence += $confidence * $weight;

            if (isset($result['factuality_score']) && $result['factuality_score'] !== null) {
                $totalFactuality += $result['factuality_score'] * $weight;
                $factualityCount += $weight;
            }
        }

        // Find winning verdict
        arsort($verdictScores);
        $winningVerdict = array_key_first($verdictScores);
        $winningScore = $verdictScores[$winningVerdict];

        // Map back to external verdict names
        $externalVerdict = match ($winningVerdict) {
            'supported' => 'supported',
            'contradicted' => 'refuted',
            default => 'inconclusive',
        };

        // Calculate agreement ratio
        $agreeing = 0;
        foreach ($providerResults as $result) {
            $v = $result['verdict'] ?? 'inconclusive';
            if ($v === 'refuted') $v = 'contradicted';
            if ($v === $winningVerdict) {
                $agreeing++;
            }
        }
        $agreementRatio = round($agreeing / count($providerResults), 3);

        // Consensus confidence: boost if agreement is high, penalize if low
        $baseConfidence = $totalWeight > 0 ? $totalConfidence / $totalWeight : 0;
        $consensusConfidence = $baseConfidence * (0.7 + 0.3 * $agreementRatio);
        $consensusConfidence = round(min(0.99, $consensusConfidence), 3);

        // If devil's advocate strongly disagrees (high confidence), downgrade confidence
        foreach ($providerResults as $result) {
            if (str_starts_with($result['provider'], 'devils_advocate')) {
                $daVerdict = $result['verdict'] ?? 'inconclusive';
                if ($daVerdict === 'refuted') $daVerdict = 'contradicted';
                if ($daVerdict !== $winningVerdict && ($result['confidence'] ?? 0) > 0.7) {
                    $consensusConfidence = round($consensusConfidence * 0.8, 3);
                }
            }
        }

        // Factuality score
        $factuality = $factualityCount > 0 ? round($totalFactuality / $factualityCount, 3) : $primaryVerdict['factuality_score'];

        return [
            'verdict' => $externalVerdict,
            'confidence' => $consensusConfidence,
            'factuality_score' => $factuality,
            'supporting_count' => $primaryVerdict['supporting_count'] ?? 0,
            'contradicting_count' => $primaryVerdict['contradicting_count'] ?? 0,
            'neutral_count' => $primaryVerdict['neutral_count'] ?? 0,
            'evidence_summary' => $primaryVerdict['evidence_summary'] ?? '',
            'agreement_ratio' => $agreementRatio,
            'consensus_method' => 'multi_provider',
            'evidence_details' => $primaryVerdict['evidence_details'] ?? [],
        ];
    }

    /**
     * Persist consensus verdict details to database
     */
    private function persistConsensusVerdict(int $claimId, array $consensus): void
    {
        try {
            DB::connection('pgsql_rag')->insert("
                INSERT INTO consensus_verdicts (
                    claim_id, provider_count, agreement_ratio,
                    consensus_verdict, consensus_confidence,
                    devil_advocate_verdict, devil_advocate_confidence,
                    provider_details
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?::jsonb)
            ", [
                $claimId,
                $consensus['provider_count'],
                $consensus['agreement_ratio'],
                $consensus['final_verdict']['verdict'] ?? null,
                $consensus['final_verdict']['confidence'] ?? null,
                $consensus['devil_advocate_verdict'],
                $consensus['devil_advocate_confidence'],
                json_encode($consensus['provider_details']),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ClaimVerification: Failed to persist consensus', [
                'claim_id' => $claimId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Parse JSON response from AI
     *
     * @param string $response AI response text
     * @param bool $expectArray Whether to expect an array
     * @return array Parsed JSON
     */
    private function parseJsonResponse(string $response, bool $expectArray = false): array
    {
        $response = preg_replace('/```json\s*/', '', $response);
        $response = preg_replace('/```\s*/', '', $response);
        $response = trim($response);

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('ClaimVerification: JSON parse failed', [
                'error' => json_last_error_msg(),
                'response' => substr($response, 0, 200),
            ]);
            return $expectArray ? [] : [];
        }

        return $decoded ?? ($expectArray ? [] : []);
    }
}
