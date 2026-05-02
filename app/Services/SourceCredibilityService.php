<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Source Credibility Scoring Service
 *
 * 5-dimension credibility scoring for evidence sources:
 * 1. Domain authority (gov/edu/established news = high)
 * 2. Historical accuracy (track past verification results)
 * 3. Citation frequency (how often cited by other sources)
 * 4. Temporal relevance (recent vs outdated)
 * 5. Cross-reference score (corroborated by other sources)
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 * Database connection: pgsql_rag
 */
class SourceCredibilityService
{
    /** @var array Default dimension weights (sum to 1.0) */
    private const DEFAULT_WEIGHTS = [
        'domain_authority' => 0.30,
        'historical_accuracy' => 0.25,
        'citation_frequency' => 0.15,
        'temporal_relevance' => 0.15,
        'cross_reference' => 0.15,
    ];

    /** @var int Cache TTL for domain scores (24 hours) */
    private const DOMAIN_CACHE_TTL = 86400;

    /** @var int Cache TTL for composite scores (1 hour) */
    private const SCORE_CACHE_TTL = 3600;

    /** @var int Minimum verifications needed for historical accuracy */
    private const MIN_VERIFICATIONS_FOR_ACCURACY = 3;

    /** @var float Default score for unknown domains */
    private const DEFAULT_DOMAIN_SCORE = 0.50;

    /** @var float Bayesian prior alpha (initial "verified" pseudo-count) */
    private const BAYESIAN_PRIOR_ALPHA = 2.0;

    /** @var float Bayesian prior beta (initial "refuted" pseudo-count) */
    private const BAYESIAN_PRIOR_BETA = 2.0;

    /** @var float Exponential decay half-life in days for rolling accuracy */
    private const ROLLING_HALFLIFE_DAYS = 90;

    private ?DomainCredibilityService $domainCredibilityService = null;

    /** @var array Recency decay thresholds (days => score multiplier) */
    private const RECENCY_DECAY = [
        7 => 1.0,      // Last week: full score
        30 => 0.95,    // Last month: 95%
        90 => 0.85,    // Last 3 months: 85%
        180 => 0.70,   // Last 6 months: 70%
        365 => 0.55,   // Last year: 55%
        730 => 0.40,   // Last 2 years: 40%
        'older' => 0.25,
    ];

    /** @var array Custom weights override */
    private array $weights;

    public function __construct(array $customWeights = [])
    {
        $this->weights = array_merge(self::DEFAULT_WEIGHTS, $customWeights);
        $this->normalizeWeights();
    }

    private function getDomainCredibilityService(): DomainCredibilityService
    {
        if ($this->domainCredibilityService === null) {
            $this->domainCredibilityService = app(DomainCredibilityService::class);
        }
        return $this->domainCredibilityService;
    }

    /**
     * Calculate composite credibility score for a source
     *
     * @param string $url Source URL
     * @param array $options Options:
     *   - claim_id: int - Associate with specific claim for context
     *   - content: string - Source content for cross-reference analysis
     *   - published_at: string - Publication date for recency
     *   - other_sources: array - Other sources for cross-reference scoring
     *   - skip_cache: bool - Bypass cache (default: false)
     * @return array Detailed scoring breakdown
     */
    public function calculateScore(string $url, array $options = []): array
    {
        $domain = $this->extractDomain($url);
        $skipCache = $options['skip_cache'] ?? false;

        // Check cache for composite score
        $cacheKey = 'credibility_score:' . md5($url . json_encode($options));
        if (!$skipCache) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            // Calculate each dimension
            $scores = [
                'domain_authority' => $this->getDomainAuthorityScore($domain),
                'historical_accuracy' => $this->getHistoricalAccuracyScore($domain),
                'citation_frequency' => $this->getCitationFrequencyScore($domain),
                'temporal_relevance' => $this->getTemporalRelevanceScore($options['published_at'] ?? null),
                'cross_reference' => $this->getCrossReferenceScore(
                    $options['content'] ?? '',
                    $options['other_sources'] ?? []
                ),
            ];

            // Calculate weighted composite
            $compositeScore = 0.0;
            foreach ($scores as $dimension => $score) {
                $compositeScore += $score * ($this->weights[$dimension] ?? 0);
            }

            $result = [
                'success' => true,
                'url' => $url,
                'domain' => $domain,
                'composite_score' => round($compositeScore, 4),
                'dimension_scores' => $scores,
                'weights_used' => $this->weights,
                'tier' => $this->getTier($compositeScore),
                'confidence' => $this->calculateConfidence($domain, $scores),
            ];

            // Cache the result
            if (!$skipCache) {
                Cache::put($cacheKey, $result, self::SCORE_CACHE_TTL);
            }

            // Record score for future historical tracking
            $this->recordScore($domain, $url, $result);

            return $result;

        } catch (Exception $e) {
            Log::error('SourceCredibilityService: Score calculation failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'url' => $url,
                'domain' => $domain,
                'composite_score' => self::DEFAULT_DOMAIN_SCORE,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Calculate scores for multiple sources in batch
     *
     * @param array $sources Array of ['url' => string, 'content' => string, 'published_at' => string]
     * @return array Scored sources with cross-reference analysis
     */
    public function calculateBatchScores(array $sources): array
    {
        if (empty($sources)) {
            return [];
        }

        $results = [];
        $contents = array_column($sources, 'content');

        foreach ($sources as $index => $source) {
            $url = $source['url'] ?? '';
            if (empty($url)) {
                continue;
            }

            // Get other sources for cross-reference (excluding current)
            $otherSources = array_filter($sources, fn($s, $i) => $i !== $index, ARRAY_FILTER_USE_BOTH);

            $results[] = $this->calculateScore($url, [
                'content' => $source['content'] ?? '',
                'published_at' => $source['published_at'] ?? null,
                'other_sources' => $otherSources,
            ]);
        }

        return $results;
    }

    /**
     * Get domain authority score (Dimension 1)
     *
     * @param string $domain Domain name
     * @return float Score 0-1
     */
    public function getDomainAuthorityScore(string $domain): float
    {
        if (empty($domain)) {
            return self::DEFAULT_DOMAIN_SCORE;
        }

        $domain = strtolower($domain);

        // Check cache
        $cacheKey = 'domain_authority:' . $domain;
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Check database for custom domain score
        $customScore = $this->getCustomDomainScore($domain);
        if ($customScore !== null) {
            Cache::put($cacheKey, $customScore, self::DOMAIN_CACHE_TTL);
            return $customScore;
        }

        // Check predefined tiers
        $score = $this->lookupDomainScore($domain);

        Cache::put($cacheKey, $score, self::DOMAIN_CACHE_TTL);
        return $score;
    }

    /**
     * Get historical accuracy score (Dimension 2)
     *
     * FC-2: Uses Bayesian Beta-Binomial model with rolling window.
     * Prior: Beta(2,2) = neutral 0.5. Updated per verification result.
     * Posterior mean = alpha / (alpha + beta).
     * Falls back to simple ratio if Bayesian columns not populated.
     *
     * @param string $domain Domain name
     * @return float Score 0-1
     */
    public function getHistoricalAccuracyScore(string $domain): float
    {
        if (empty($domain)) {
            return 0.5;
        }

        try {
            // Try Bayesian posterior first (only if explicitly updated, not just defaults)
            $bayesian = DB::connection('pgsql_rag')->select("
                SELECT
                    MAX(bayesian_alpha) as bayesian_alpha,
                    MAX(bayesian_beta) as bayesian_beta,
                    COUNT(*) FILTER (WHERE verification_result IS NOT NULL) as total_verifications
                FROM source_credibility
                WHERE domain = ?
                  AND last_bayesian_update IS NOT NULL
            ", [$domain]);

            if (!empty($bayesian) && $bayesian[0]->bayesian_alpha !== null) {
                $alpha = (float) $bayesian[0]->bayesian_alpha;
                $beta = (float) $bayesian[0]->bayesian_beta;
                $total = (int) ($bayesian[0]->total_verifications ?? 0);

                if ($total < self::MIN_VERIFICATIONS_FOR_ACCURACY) {
                    return 0.5;
                }

                // Posterior mean of Beta distribution
                return min(1.0, max(0.0, $alpha / ($alpha + $beta)));
            }

            // Fallback: simple ratio for domains without Bayesian columns
            return $this->getSimpleAccuracyRatio($domain);

        } catch (Exception $e) {
            Log::warning('SourceCredibilityService: Historical accuracy lookup failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            return 0.5;
        }
    }

    /**
     * Simple accuracy ratio fallback (pre-FC-2 logic).
     */
    private function getSimpleAccuracyRatio(string $domain): float
    {
        try {
            $stats = DB::connection('pgsql_rag')->select("
                SELECT
                    COUNT(*) as total_verifications,
                    COUNT(*) FILTER (WHERE verification_result = 'verified') as verified_count,
                    COUNT(*) FILTER (WHERE verification_result = 'partially_verified') as partial_count
                FROM source_credibility
                WHERE domain = ?
                  AND verification_result IS NOT NULL
            ", [$domain]);

            $row = $stats[0] ?? null;
            if (!$row || $row->total_verifications < self::MIN_VERIFICATIONS_FOR_ACCURACY) {
                return 0.5;
            }

            $total = (int) $row->total_verifications;
            $verified = (int) $row->verified_count;
            $partial = (int) $row->partial_count;

            return min(1.0, max(0.0, ($verified * 1.0 + $partial * 0.5) / $total));
        } catch (Exception $e) {
            return 0.5;
        }
    }

    /**
     * Get citation frequency score (Dimension 3)
     *
     * How often this domain is cited by other sources
     *
     * @param string $domain Domain name
     * @return float Score 0-1
     */
    public function getCitationFrequencyScore(string $domain): float
    {
        if (empty($domain)) {
            return 0.3;
        }

        try {
            $stats = DB::connection('pgsql_rag')->select("
                SELECT
                    citation_count,
                    cited_by_count,
                    last_citation_at
                FROM source_credibility
                WHERE domain = ?
                ORDER BY updated_at DESC
                LIMIT 1
            ", [$domain]);

            if (empty($stats)) {
                return 0.3; // No citation data
            }

            $row = $stats[0];
            $citationCount = (int) ($row->citation_count ?? 0);
            $citedByCount = (int) ($row->cited_by_count ?? 0);

            // Logarithmic scaling for citation counts
            // 1 citation = 0.3, 10 = 0.5, 100 = 0.7, 1000 = 0.9
            $combinedCount = $citationCount + $citedByCount;

            if ($combinedCount === 0) {
                return 0.3;
            }

            $score = 0.3 + (0.6 * (log10($combinedCount + 1) / log10(1001)));
            return min(1.0, max(0.0, $score));

        } catch (Exception $e) {
            Log::warning('SourceCredibilityService: Citation frequency lookup failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            return 0.3;
        }
    }

    /**
     * Get temporal relevance score (Dimension 4)
     *
     * @param string|null $publishedAt Publication date
     * @return float Score 0-1
     */
    public function getTemporalRelevanceScore(?string $publishedAt): float
    {
        if (empty($publishedAt)) {
            return 0.5; // Unknown date, neutral score
        }

        try {
            $publishDate = new \DateTime($publishedAt);
            $now = new \DateTime();
            $daysDiff = $now->diff($publishDate)->days;

            foreach (self::RECENCY_DECAY as $threshold => $score) {
                if ($threshold === 'older') {
                    return $score;
                }
                if ($daysDiff <= $threshold) {
                    return $score;
                }
            }

            return self::RECENCY_DECAY['older'];

        } catch (Exception $e) {
            return 0.5;
        }
    }

    /**
     * Get cross-reference score (Dimension 5)
     *
     * How well this source is corroborated by other sources
     *
     * @param string $content Source content
     * @param array $otherSources Other sources to compare against
     * @return float Score 0-1
     */
    public function getCrossReferenceScore(string $content, array $otherSources): float
    {
        if (empty($content) || empty($otherSources)) {
            return 0.5; // Can't determine without comparison data
        }

        $corroborationCount = 0;
        $totalComparisons = 0;

        // Extract key claims/facts from content
        $contentTerms = $this->extractKeyTerms($content);
        if (empty($contentTerms)) {
            return 0.5;
        }

        foreach ($otherSources as $source) {
            $otherContent = $source['content'] ?? '';
            if (empty($otherContent)) {
                continue;
            }

            $otherTerms = $this->extractKeyTerms($otherContent);
            $totalComparisons++;

            // Calculate term overlap
            $intersection = array_intersect($contentTerms, $otherTerms);
            $overlapRatio = count($intersection) / count($contentTerms);

            // Consider corroborated if >30% key terms match
            if ($overlapRatio >= 0.3) {
                $corroborationCount++;
            }
        }

        if ($totalComparisons === 0) {
            return 0.5;
        }

        // Score based on corroboration ratio
        $ratio = $corroborationCount / $totalComparisons;

        // Scale: 0 corroboration = 0.3, 50% = 0.65, 100% = 1.0
        return 0.3 + (0.7 * $ratio);
    }

    /**
     * Record verification result for a source
     *
     * @param string $domain Domain name
     * @param string $url Full URL
     * @param string $verificationResult 'verified', 'refuted', 'partially_verified', 'unverifiable'
     * @param float|null $accuracyScore Optional accuracy score 0-1
     * @param int|null $claimId Associated claim ID
     * @return bool Success
     */
    public function recordVerificationResult(
        string $domain,
        string $url,
        string $verificationResult,
        ?float $accuracyScore = null,
        ?int $claimId = null
    ): bool {
        try {
            // Check if record exists
            $existing = DB::connection('pgsql_rag')->select("
                SELECT id FROM source_credibility WHERE domain = ? AND url = ?
            ", [$domain, $url]);

            if (!empty($existing)) {
                // Update existing record
                DB::connection('pgsql_rag')->update("
                    UPDATE source_credibility
                    SET verification_result = ?,
                        accuracy_score = COALESCE(?, accuracy_score),
                        verification_count = verification_count + 1,
                        last_verified_at = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE domain = ? AND url = ?
                ", [$verificationResult, $accuracyScore, $domain, $url]);
            } else {
                // Insert new record
                DB::connection('pgsql_rag')->insert("
                    INSERT INTO source_credibility (
                        domain, url, verification_result, accuracy_score,
                        verification_count, last_verified_at, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ", [$domain, $url, $verificationResult, $accuracyScore]);
            }

            // Invalidate cache
            Cache::forget('domain_authority:' . $domain);
            Cache::forget('credibility_score:' . md5($url));

            Log::info('SourceCredibilityService: Recorded verification', [
                'domain' => $domain,
                'result' => $verificationResult,
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('SourceCredibilityService: Failed to record verification', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Record citation relationship between sources
     *
     * @param string $citingDomain Domain that cites
     * @param string $citedDomain Domain being cited
     * @return bool Success
     */
    public function recordCitation(string $citingDomain, string $citedDomain): bool
    {
        try {
            // Increment citation count for cited domain
            DB::connection('pgsql_rag')->update("
                UPDATE source_credibility
                SET citation_count = citation_count + 1,
                    last_citation_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE domain = ?
            ", [$citedDomain]);

            // Increment cited_by for citing domain
            DB::connection('pgsql_rag')->update("
                UPDATE source_credibility
                SET cited_by_count = cited_by_count + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE domain = ?
            ", [$citingDomain]);

            return true;

        } catch (Exception $e) {
            Log::warning('SourceCredibilityService: Failed to record citation', [
                'citing' => $citingDomain,
                'cited' => $citedDomain,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Add or update custom domain score
     *
     * @param string $domain Domain name
     * @param float $score Custom score 0-1
     * @param string|null $notes Optional notes
     * @return bool Success
     */
    public function setCustomDomainScore(string $domain, float $score, ?string $notes = null): bool
    {
        $score = min(1.0, max(0.0, $score));
        $domain = strtolower($domain);

        try {
            $existing = DB::connection('pgsql_rag')->select("
                SELECT id FROM source_credibility WHERE domain = ? AND url IS NULL
            ", [$domain]);

            if (!empty($existing)) {
                DB::connection('pgsql_rag')->update("
                    UPDATE source_credibility
                    SET custom_score = ?,
                        notes = COALESCE(?, notes),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE domain = ? AND url IS NULL
                ", [$score, $notes, $domain]);
            } else {
                DB::connection('pgsql_rag')->insert("
                    INSERT INTO source_credibility (
                        domain, custom_score, notes, created_at, updated_at
                    ) VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ", [$domain, $score, $notes]);
            }

            // Invalidate cache
            Cache::forget('domain_authority:' . $domain);

            return true;

        } catch (Exception $e) {
            Log::error('SourceCredibilityService: Failed to set custom score', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get credibility statistics for reporting
     *
     * @return array Statistics
     */
    public function getStatistics(): array
    {
        try {
            $totalDomains = DB::connection('pgsql_rag')->select("
                SELECT COUNT(DISTINCT domain) as count FROM source_credibility
            ")[0]->count ?? 0;

            $verificationStats = DB::connection('pgsql_rag')->select("
                SELECT
                    verification_result,
                    COUNT(*) as count,
                    AVG(accuracy_score) as avg_accuracy
                FROM source_credibility
                WHERE verification_result IS NOT NULL
                GROUP BY verification_result
            ");

            $topDomains = DB::connection('pgsql_rag')->select("
                SELECT
                    domain,
                    COALESCE(custom_score, 0.5) as score,
                    verification_count,
                    citation_count
                FROM source_credibility
                WHERE domain IS NOT NULL
                ORDER BY verification_count DESC, citation_count DESC
                LIMIT 20
            ");

            return [
                'total_domains_tracked' => $totalDomains,
                'verification_breakdown' => $verificationStats,
                'top_domains' => $topDomains,
            ];

        } catch (Exception $e) {
            Log::error('SourceCredibilityService: Failed to get statistics', [
                'error' => $e->getMessage(),
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get scores for integration with EvidenceRetrieverService
     *
     * @param array $evidence Evidence items from EvidenceRetrieverService
     * @return array Evidence with enhanced credibility scores
     */
    public function enrichEvidenceWithScores(array $evidence): array
    {
        $otherSources = array_map(fn($e) => [
            'content' => $e['snippet'] ?? '',
            'url' => $e['source_url'] ?? '',
        ], $evidence);

        foreach ($evidence as &$item) {
            $url = $item['source_url'] ?? '';
            if (empty($url)) {
                $item['credibility'] = [
                    'composite_score' => 0.5,
                    'tier' => 'unknown',
                ];
                continue;
            }

            $score = $this->calculateScore($url, [
                'content' => $item['snippet'] ?? '',
                'published_at' => $item['published_at'] ?? $item['created_at'] ?? null,
                'other_sources' => $otherSources,
            ]);

            $item['credibility'] = [
                'composite_score' => $score['composite_score'] ?? 0.5,
                'dimension_scores' => $score['dimension_scores'] ?? [],
                'tier' => $score['tier'] ?? 'unknown',
                'confidence' => $score['confidence'] ?? 0.5,
            ];
        }

        return $evidence;
    }

    // =========================================================================
    // FC-2: Bayesian Credibility Updates
    // =========================================================================

    /**
     * Update Bayesian posterior for a domain after a verification result.
     *
     * Beta-Binomial: verified → alpha++, refuted → beta++, partial → alpha += 0.5.
     * Applies exponential decay to old observations (rolling window).
     *
     * @param string $domain Domain name
     * @param string $verificationResult 'verified', 'refuted', 'partially_verified'
     * @return array Updated alpha, beta, posterior mean
     */
    public function updateBayesian(string $domain, string $verificationResult): array
    {
        if (empty($domain)) {
            return ['success' => false, 'error' => 'Empty domain'];
        }

        try {
            // Get current Bayesian state
            $current = DB::connection('pgsql_rag')->select("
                SELECT bayesian_alpha, bayesian_beta, last_bayesian_update
                FROM source_credibility
                WHERE domain = ?
                  AND bayesian_alpha IS NOT NULL
                ORDER BY updated_at DESC
                LIMIT 1
            ", [$domain]);

            $alpha = self::BAYESIAN_PRIOR_ALPHA;
            $beta = self::BAYESIAN_PRIOR_BETA;

            if (!empty($current) && $current[0]->bayesian_alpha !== null) {
                $alpha = (float) $current[0]->bayesian_alpha;
                $beta = (float) $current[0]->bayesian_beta;

                // Apply time decay to existing observations before adding new one
                $lastUpdate = $current[0]->last_bayesian_update;
                if ($lastUpdate) {
                    $daysSince = (time() - strtotime($lastUpdate)) / 86400;
                    $decay = $this->calculateDecayFactor($daysSince);
                    // Decay pulls alpha/beta toward prior
                    $alpha = self::BAYESIAN_PRIOR_ALPHA + ($alpha - self::BAYESIAN_PRIOR_ALPHA) * $decay;
                    $beta = self::BAYESIAN_PRIOR_BETA + ($beta - self::BAYESIAN_PRIOR_BETA) * $decay;
                }
            }

            // Update based on result
            switch ($verificationResult) {
                case 'verified':
                    $alpha += 1.0;
                    break;
                case 'refuted':
                    $beta += 1.0;
                    break;
                case 'partially_verified':
                    $alpha += 0.5;
                    $beta += 0.5;
                    break;
            }

            $posteriorMean = $alpha / ($alpha + $beta);

            // Persist
            DB::connection('pgsql_rag')->update("
                UPDATE source_credibility
                SET bayesian_alpha = ?,
                    bayesian_beta = ?,
                    last_bayesian_update = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE domain = ?
            ", [$alpha, $beta, $domain]);

            // Invalidate cache
            Cache::forget('domain_authority:' . $domain);

            return [
                'success' => true,
                'domain' => $domain,
                'alpha' => round($alpha, 3),
                'beta' => round($beta, 3),
                'posterior_mean' => round($posteriorMean, 4),
                'verification_result' => $verificationResult,
            ];

        } catch (Exception $e) {
            Log::error('SourceCredibilityService: Bayesian update failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Recalculate Bayesian posteriors for all domains from verification history.
     *
     * Replays all verification events with time-decay weighting.
     * Intended for monthly scheduled recalculation.
     *
     * @param int $limit Max domains to recalculate (0 = all)
     * @return array Stats: domains processed, updated, errors
     */
    public function recalculateAllBayesian(int $limit = 0): array
    {
        $stats = ['processed' => 0, 'updated' => 0, 'errors' => 0];

        try {
            $sql = "
                SELECT domain, COUNT(*) as record_count
                FROM source_credibility
                WHERE verification_result IS NOT NULL
                GROUP BY domain
                HAVING COUNT(*) >= ?
                ORDER BY COUNT(*) DESC
            ";
            $params = [self::MIN_VERIFICATIONS_FOR_ACCURACY];

            if ($limit > 0) {
                $sql .= " LIMIT ?";
                $params[] = $limit;
            }

            $domains = DB::connection('pgsql_rag')->select($sql, $params);

            foreach ($domains as $domainRow) {
                $stats['processed']++;

                try {
                    $this->recalculateBayesianForDomain($domainRow->domain);
                    $stats['updated']++;
                } catch (Exception $e) {
                    $stats['errors']++;
                    Log::warning('SourceCredibilityService: Bayesian recalc failed for domain', [
                        'domain' => $domainRow->domain,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (Exception $e) {
            Log::error('SourceCredibilityService: Bayesian recalc batch failed', [
                'error' => $e->getMessage(),
            ]);
            $stats['errors']++;
        }

        return $stats;
    }

    /**
     * Recalculate Bayesian posterior for a single domain from its verification history.
     */
    public function recalculateBayesianForDomain(string $domain): array
    {
        try {
            $verifications = DB::connection('pgsql_rag')->select("
                SELECT verification_result, last_verified_at
                FROM source_credibility
                WHERE domain = ?
                  AND verification_result IS NOT NULL
                ORDER BY last_verified_at ASC
            ", [$domain]);

            $alpha = self::BAYESIAN_PRIOR_ALPHA;
            $beta = self::BAYESIAN_PRIOR_BETA;
            $now = time();

            foreach ($verifications as $v) {
                $daysAgo = $v->last_verified_at ? ($now - strtotime($v->last_verified_at)) / 86400 : 365;
                $weight = $this->calculateDecayFactor($daysAgo);

                switch ($v->verification_result) {
                    case 'verified':
                        $alpha += 1.0 * $weight;
                        break;
                    case 'refuted':
                        $beta += 1.0 * $weight;
                        break;
                    case 'partially_verified':
                        $alpha += 0.5 * $weight;
                        $beta += 0.5 * $weight;
                        break;
                }
            }

            $posteriorMean = $alpha / ($alpha + $beta);

            DB::connection('pgsql_rag')->update("
                UPDATE source_credibility
                SET bayesian_alpha = ?,
                    bayesian_beta = ?,
                    last_bayesian_update = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE domain = ?
            ", [$alpha, $beta, $domain]);

            Cache::forget('domain_authority:' . $domain);

            return [
                'domain' => $domain,
                'alpha' => round($alpha, 3),
                'beta' => round($beta, 3),
                'posterior_mean' => round($posteriorMean, 4),
                'verifications_replayed' => count($verifications),
            ];
        } catch (Exception $e) {
            Log::error('SourceCredibilityService: Bayesian recalc failed for domain', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Exponential decay factor for rolling window.
     * Half-life of 90 days: observation from 90 days ago counts as 0.5.
     */
    public function calculateDecayFactor(float $daysAgo): float
    {
        if ($daysAgo <= 0) {
            return 1.0;
        }
        return pow(0.5, $daysAgo / self::ROLLING_HALFLIFE_DAYS);
    }

    // =========================================================================
    // Private Helper Methods
    // =========================================================================

    /**
     * Extract domain from URL
     */
    private function extractDomain(string $url): string
    {
        if (empty($url)) {
            return '';
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            return '';
        }

        return preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * Lookup domain score from shared domain_credibility table
     */
    private function lookupDomainScore(string $domain): float
    {
        return $this->getDomainCredibilityService()->getScore($domain);
    }

    /**
     * Get custom domain score from database
     */
    private function getCustomDomainScore(string $domain): ?float
    {
        try {
            $result = DB::connection('pgsql_rag')->select("
                SELECT custom_score FROM source_credibility
                WHERE domain = ? AND custom_score IS NOT NULL
                ORDER BY updated_at DESC
                LIMIT 1
            ", [$domain]);

            if (!empty($result) && $result[0]->custom_score !== null) {
                return (float) $result[0]->custom_score;
            }

            return null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get tier label for a score
     */
    private function getTier(float $score): string
    {
        if ($score >= 0.90) return 'tier1_authoritative';
        if ($score >= 0.75) return 'tier2_major_news';
        if ($score >= 0.60) return 'tier3_reference';
        if ($score >= 0.45) return 'tier4_general';
        return 'tier5_low_credibility';
    }

    /**
     * Calculate confidence in the score
     */
    private function calculateConfidence(string $domain, array $scores): float
    {
        $confidence = 0.5;

        // Higher confidence if we have historical data
        try {
            $history = DB::connection('pgsql_rag')->select("
                SELECT verification_count, citation_count
                FROM source_credibility
                WHERE domain = ?
                LIMIT 1
            ", [$domain]);

            if (!empty($history)) {
                $verifications = (int) ($history[0]->verification_count ?? 0);
                $citations = (int) ($history[0]->citation_count ?? 0);

                // More data = higher confidence
                $dataPoints = $verifications + $citations;
                $confidence = min(0.95, 0.5 + (0.45 * (log10($dataPoints + 1) / log10(101))));
            }
        } catch (Exception $e) {
            // Keep default confidence
        }

        // Lower confidence if dimension scores vary widely
        $variance = $this->calculateVariance(array_values($scores));
        if ($variance > 0.1) {
            $confidence *= 0.9; // Reduce confidence for high variance
        }

        return round($confidence, 3);
    }

    /**
     * Calculate variance of scores
     */
    private function calculateVariance(array $values): float
    {
        if (count($values) < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / count($values);
        $squaredDiffs = array_map(fn($v) => pow($v - $mean, 2), $values);

        return array_sum($squaredDiffs) / count($values);
    }

    /**
     * Normalize weights to sum to 1.0
     */
    private function normalizeWeights(): void
    {
        $sum = array_sum($this->weights);
        if ($sum > 0 && $sum !== 1.0) {
            foreach ($this->weights as $key => $value) {
                $this->weights[$key] = $value / $sum;
            }
        }
    }

    /**
     * Extract key terms from text
     */
    private function extractKeyTerms(string $text): array
    {
        $stopwords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been',
            'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
            'could', 'should', 'may', 'might', 'must', 'shall', 'can', 'of', 'in',
            'to', 'for', 'on', 'with', 'at', 'by', 'from', 'as', 'and', 'or', 'but',
            'if', 'then', 'than', 'that', 'this', 'it', 'its', 'they', 'their', 'them',
            'he', 'she', 'his', 'her', 'we', 'our', 'you', 'your', 'said', 'says'];

        $words = preg_split('/\s+/', strtolower($text));
        $words = array_filter($words, function ($word) use ($stopwords) {
            $word = preg_replace('/[^a-z0-9]/', '', $word);
            return strlen($word) > 3 && !in_array($word, $stopwords);
        });

        return array_slice(array_unique(array_values($words)), 0, 50);
    }

    /**
     * Record score to database for tracking
     */
    private function recordScore(string $domain, string $url, array $result): void
    {
        if (empty($domain)) {
            return;
        }

        try {
            $existing = DB::connection('pgsql_rag')->select("
                SELECT id FROM source_credibility WHERE domain = ? AND url = ?
            ", [$domain, $url]);

            if (!empty($existing)) {
                DB::connection('pgsql_rag')->update("
                    UPDATE source_credibility
                    SET composite_score = ?,
                        dimension_scores = ?,
                        tier = ?,
                        confidence = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE domain = ? AND url = ?
                ", [
                    $result['composite_score'],
                    json_encode($result['dimension_scores'] ?? []),
                    $result['tier'],
                    $result['confidence'] ?? 0.5,
                    $domain,
                    $url,
                ]);
            } else {
                DB::connection('pgsql_rag')->insert("
                    INSERT INTO source_credibility (
                        domain, url, composite_score, dimension_scores, tier, confidence,
                        created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ", [
                    $domain,
                    $url,
                    $result['composite_score'],
                    json_encode($result['dimension_scores'] ?? []),
                    $result['tier'],
                    $result['confidence'] ?? 0.5,
                ]);
            }
        } catch (Exception $e) {
            // Non-critical, don't fail the main operation
            Log::debug('SourceCredibilityService: Failed to record score', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
