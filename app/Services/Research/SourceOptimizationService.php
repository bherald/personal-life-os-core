<?php

namespace App\Services\Research;

use App\Services\SystemConfigService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SourceOptimizationService - Self-healing and optimization for research sources
 *
 * Like RssFeedHealthService, this service:
 * - Reviews source performance and adjusts trust/safety scores
 * - Attempts to heal failing sources
 * - Optimizes discovery rules based on actual performance
 * - Identifies new high-value sources based on patterns
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
class SourceOptimizationService
{
    private const RUNTIME_POLICY_CONFIG_KEY = 'research.source_maintenance_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'enabled' => true,
        'run_healing_default' => true,
        'run_refresh_default' => true,
        'run_discover_default' => true,
        'run_optimize_default' => true,
        'send_notification_default' => false,
        'healthy_threshold' => 0,
        'degraded_threshold' => 2,
        'failed_threshold' => 5,
        'healing_source_limit' => 50,
        'healing_connect_timeout_seconds' => 5,
        'healing_request_timeout_seconds' => 10,
        'healing_redirect_max' => 5,
        'rule_low_success_rate' => 0.30,
        'rule_high_success_rate' => 0.80,
        'rule_tld_min_total' => 5,
        'rule_category_min_total' => 3,
        'rule_unused_disable_days' => 30,
        'tld_analysis_min_sources' => 3,
        'category_domain_analysis_min_observations' => 3,
        'category_domain_analysis_limit' => 50,
        'category_stale_days' => 30,
        'category_critical_min_active' => 3,
        'category_critical_min_search_url' => 2,
        'category_warning_min_active' => 5,
        'category_warning_min_search_url' => 3,
        'category_stale_ratio' => 0.5,
        'category_list' => [
            'academic',
            'finance',
            'genealogy',
            'general',
            'government',
            'health',
            'legal',
            'medical',
            'news',
            'science',
            'technology',
        ],
        'category_refresh_limit' => 20,
        'category_refresh_connect_timeout_seconds' => 5,
        'category_refresh_request_timeout_seconds' => 15,
        'category_refresh_sleep_microseconds' => 300000,
        'deactivation_high_trust_min' => 8.0,
        'deactivation_medium_trust_min' => 6.0,
        'deactivation_high_trust_failure_threshold' => 10,
        'deactivation_medium_trust_failure_threshold' => 7,
        'deactivation_default_failure_threshold' => 5,
        'deactivation_failure_success_multiplier' => 2.0,
        'report_failing_limit' => 20,
        'report_top_performing_limit' => 20,
        'report_top_performing_min_observations' => 5,
        'report_min_success_rate_pct' => 70.0,
        'report_degraded_recommendation_threshold' => 10,
        'report_unused_rule_recommendation_threshold' => 5,
        'report_min_avg_trust' => 0.5,
        'suggest_success_min_count' => 5,
        'suggest_success_min_rate' => 0.7,
        'suggest_successful_domain_limit' => 20,
        'suggest_common_tld_limit' => 5,
        'suggest_common_keyword_limit' => 10,
        'suggest_keyword_term_limit' => 3,
        'suggest_keyword_terms_per_base' => 2,
    ];

    private string $connection = 'pgsql_rag';

    private DynamicSourceDiscoveryService $discoveryService;

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    public function __construct(DynamicSourceDiscoveryService $discoveryService)
    {
        $this->discoveryService = $discoveryService;
    }

    // =========================================================================
    // SELF-HEALING - Attempt to recover failing sources
    // =========================================================================

    /**
     * Run self-healing on all failing sources
     *
     * @return array Results of the healing operation
     */
    public function runSelfHealing(): array
    {
        $startTime = microtime(true);
        $results = [
            'sources_checked' => 0,
            'sources_healed' => 0,
            'sources_deactivated' => 0,
            'healing_attempts' => [],
        ];

        try {
            // Get sources that need healing (consecutive failures >= degraded threshold)
            $failingSources = DB::connection($this->connection)->select('
                SELECT id, domain, full_url, consecutive_failures, last_error_message,
                       trust_score, safety_score, is_whitelisted
                FROM discovered_sources
                WHERE is_active = true
                AND consecutive_failures >= ?
                ORDER BY consecutive_failures DESC, trust_score DESC
                LIMIT ?
            ', [
                $this->runtimePolicyInt('degraded_threshold'),
                $this->runtimePolicyInt('healing_source_limit'),
            ]);

            foreach ($failingSources as $source) {
                $results['sources_checked']++;
                $healResult = $this->attemptSourceHeal((array) $source);

                $results['healing_attempts'][] = [
                    'domain' => $source->domain,
                    'consecutive_failures' => $source->consecutive_failures,
                    'result' => $healResult,
                ];

                if ($healResult['success']) {
                    $results['sources_healed']++;
                } elseif ($healResult['action'] === 'deactivated') {
                    $results['sources_deactivated']++;
                }
            }

            $results['duration_ms'] = round((microtime(true) - $startTime) * 1000);

            Log::info('Source self-healing completed', $results);

        } catch (Exception $e) {
            Log::error('Source self-healing failed', ['error' => $e->getMessage()]);
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Attempt to heal a single failing source
     *
     * @param  array  $source  Source data
     * @return array Healing result
     */
    public function attemptSourceHeal(array $source): array
    {
        $sourceId = $source['id'];
        $domain = $source['domain'];
        $url = $source['full_url'] ?? "https://{$domain}";

        // If too many failures, just deactivate
        if (($source['consecutive_failures'] ?? 0) >= $this->runtimePolicyInt('failed_threshold')) {
            if (! ($source['is_whitelisted'] ?? false)) {
                // Don't deactivate whitelisted sources automatically
                DB::connection($this->connection)->update('
                    UPDATE discovered_sources SET
                        is_active = false,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ', [$sourceId]);

                return [
                    'success' => false,
                    'action' => 'deactivated',
                    'reason' => 'Too many consecutive failures',
                ];
            }
        }

        // Try healing methods
        $healingMethods = [
            'check_url_redirect' => 'Check for URL redirect',
            'try_https' => 'Try HTTPS if using HTTP',
            'try_www_variant' => 'Try www variant',
            'check_robots_txt' => 'Check robots.txt compliance',
        ];

        foreach ($healingMethods as $method => $description) {
            $result = $this->tryHealingMethod($sourceId, $domain, $url, $method);
            if ($result['success']) {
                Log::info("Source healed via {$method}", [
                    'domain' => $domain,
                    'new_url' => $result['new_url'] ?? $url,
                ]);

                // Reset consecutive failures
                DB::connection($this->connection)->update("
                    UPDATE discovered_sources SET
                        consecutive_failures = 0,
                        full_url = COALESCE(?, full_url),
                        last_error_message = 'Healed via: ' || ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ", [$result['new_url'] ?? null, $method, $sourceId]);

                return [
                    'success' => true,
                    'action' => 'healed',
                    'method' => $method,
                    'new_url' => $result['new_url'] ?? null,
                ];
            }
        }

        // No healing method worked
        return [
            'success' => false,
            'action' => 'no_healing_available',
            'reason' => 'All healing methods failed',
        ];
    }

    /**
     * Try a specific healing method
     */
    private function tryHealingMethod(string $sourceId, string $domain, string $url, string $method): array
    {
        $connectTimeout = $this->runtimePolicyInt('healing_connect_timeout_seconds');
        $requestTimeout = $this->runtimePolicyInt('healing_request_timeout_seconds');
        $redirectMax = $this->runtimePolicyInt('healing_redirect_max');

        try {
            switch ($method) {
                case 'check_url_redirect':
                    // Follow redirects and see if final URL works
                    $response = Http::connectTimeout($connectTimeout)->timeout($requestTimeout)
                        ->withOptions(['allow_redirects' => ['max' => $redirectMax, 'track_redirects' => true]])
                        ->head($url);

                    if ($response->successful()) {
                        $finalUrl = $response->effectiveUri()?->__toString() ?? $url;
                        if ($finalUrl !== $url) {
                            return ['success' => true, 'new_url' => $finalUrl];
                        }

                        return ['success' => true];
                    }
                    break;

                case 'try_https':
                    if (str_starts_with($url, 'http://')) {
                        $httpsUrl = str_replace('http://', 'https://', $url);
                        $response = Http::connectTimeout($connectTimeout)->timeout($requestTimeout)->head($httpsUrl);
                        if ($response->successful()) {
                            return ['success' => true, 'new_url' => $httpsUrl];
                        }
                    }
                    break;

                case 'try_www_variant':
                    $parsed = parse_url($url);
                    $host = $parsed['host'] ?? $domain;
                    if (! str_starts_with($host, 'www.')) {
                        $wwwUrl = str_replace("://{$host}", "://www.{$host}", $url);
                        $response = Http::connectTimeout($connectTimeout)->timeout($requestTimeout)->head($wwwUrl);
                        if ($response->successful()) {
                            return ['success' => true, 'new_url' => $wwwUrl];
                        }
                    }
                    break;

                case 'check_robots_txt':
                    if ((bool) config('scraping.bypass_robots_txt', true)) {
                        return ['success' => true, 'reason' => 'robots bypassed by runtime policy'];
                    }

                    // Check if robots.txt allows access
                    $robotsUrl = "https://{$domain}/robots.txt";
                    $response = Http::connectTimeout($connectTimeout)->timeout($requestTimeout)->get($robotsUrl);
                    if ($response->successful()) {
                        $robotsTxt = $response->body();
                        // Very simple check - if robots.txt exists and doesn't explicitly disallow
                        if (! str_contains($robotsTxt, 'Disallow: /')) {
                            return ['success' => true];
                        }
                    }
                    break;
            }
        } catch (Exception $e) {
            // Method failed
        }

        return ['success' => false];
    }

    // =========================================================================
    // RULE OPTIMIZATION - Adjust rules based on performance
    // =========================================================================

    /**
     * Optimize discovery rules based on actual performance data
     *
     * @return array Optimization results
     */
    public function optimizeDiscoveryRules(): array
    {
        $startTime = microtime(true);
        $results = [
            'rules_updated' => 0,
            'rules_disabled' => 0,
            'tld_adjustments' => [],
            'category_adjustments' => [],
        ];

        try {
            // 1. Analyze TLD performance
            $lowSuccessRate = $this->runtimePolicyFloat('rule_low_success_rate');
            $tldMinTotal = $this->runtimePolicyInt('rule_tld_min_total');
            $categoryMinTotal = $this->runtimePolicyInt('rule_category_min_total');
            $unusedRuleDisableDays = $this->runtimePolicyInt('rule_unused_disable_days');

            $tldPerformance = $this->analyzeTLDPerformance();
            foreach ($tldPerformance as $tld => $perf) {
                if ($perf['success_rate'] < $lowSuccessRate && $perf['total'] >= $tldMinTotal) {
                    // Reduce trust for poor-performing TLDs
                    $adjustment = $this->adjustTLDRule($tld, -0.1);
                    if ($adjustment) {
                        $results['tld_adjustments'][] = $adjustment;
                        $results['rules_updated']++;
                    }
                }
            }

            // 2. Analyze category domain performance
            $categoryPerformance = $this->analyzeCategoryDomainPerformance();
            foreach ($categoryPerformance as $record) {
                if ($record['success_rate'] < $lowSuccessRate && $record['total'] >= $categoryMinTotal) {
                    // Mark low-performing category domains as needing review
                    $results['category_adjustments'][] = [
                        'domain' => $record['domain'],
                        'category' => $record['domain_category'],
                        'success_rate' => $record['success_rate'],
                        'action' => 'flagged_for_review',
                    ];
                }
            }

            // 3. Disable rules that have never been applied after 30 days
            $disabledRules = DB::connection($this->connection)->update("
                UPDATE discovery_rules SET
                    is_active = false,
                    notes = COALESCE(notes, '') || ' [Auto-disabled: never used after 30 days]',
                    updated_at = CURRENT_TIMESTAMP
                WHERE is_active = true
                AND times_applied = 0
                AND created_at < CURRENT_TIMESTAMP - (? * INTERVAL '1 day')
            ", [$unusedRuleDisableDays]);
            $results['rules_disabled'] += $disabledRules;

            // 4. Update success rates for patterns
            $this->updatePatternSuccessRates();

            $results['duration_ms'] = round((microtime(true) - $startTime) * 1000);
            $this->discoveryService->clearRulesCache();

            Log::info('Discovery rule optimization completed', $results);

        } catch (Exception $e) {
            Log::error('Discovery rule optimization failed', ['error' => $e->getMessage()]);
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Analyze TLD performance from discovered sources
     */
    private function analyzeTLDPerformance(): array
    {
        $results = DB::connection($this->connection)->select("
            SELECT
                CASE
                    WHEN domain LIKE '%.gov' THEN 'gov'
                    WHEN domain LIKE '%.edu' THEN 'edu'
                    WHEN domain LIKE '%.mil' THEN 'mil'
                    WHEN domain LIKE '%.org' THEN 'org'
                    WHEN domain LIKE '%.gov.uk' THEN 'gov.uk'
                    WHEN domain LIKE '%.ac.uk' THEN 'ac.uk'
                    ELSE SPLIT_PART(domain, '.', -1)
                END as tld,
                COUNT(*) as total,
                SUM(success_count) as total_successes,
                SUM(failure_count) as total_failures,
                AVG(trust_score) as avg_trust,
                AVG(safety_score) as avg_safety
            FROM discovered_sources
            WHERE is_active = true
            GROUP BY 1
            HAVING COUNT(*) >= ?
            ORDER BY total DESC
        ", [$this->runtimePolicyInt('tld_analysis_min_sources')]);

        $performance = [];
        foreach ($results as $row) {
            $total = $row->total_successes + $row->total_failures;
            $performance[$row->tld] = [
                'total' => (int) $row->total,
                'successes' => (int) $row->total_successes,
                'failures' => (int) $row->total_failures,
                'success_rate' => $total > 0 ? $row->total_successes / $total : 0,
                'avg_trust' => (float) $row->avg_trust,
                'avg_safety' => (float) $row->avg_safety,
            ];
        }

        return $performance;
    }

    /**
     * Adjust a TLD rule's trust score
     */
    private function adjustTLDRule(string $tld, float $adjustment): ?array
    {
        $rule = DB::connection($this->connection)->select("
            SELECT id, rule_name, trust_score_value
            FROM discovery_rules
            WHERE rule_type = 'tld_trust'
            AND match_pattern = ?
            AND is_active = true
            LIMIT 1
        ", [".{$tld}"]);

        if (empty($rule)) {
            return null;
        }

        $currentScore = (float) $rule[0]->trust_score_value;
        $newScore = max(0.1, min(0.99, $currentScore + $adjustment));

        DB::connection($this->connection)->update("
            UPDATE discovery_rules SET
                trust_score_value = ?,
                notes = COALESCE(notes, '') || ' [Auto-adjusted from ' || ? || ' to ' || ? || ' based on performance]',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ", [$newScore, $currentScore, $newScore, $rule[0]->id]);

        return [
            'tld' => $tld,
            'previous_score' => $currentScore,
            'new_score' => $newScore,
            'adjustment' => $adjustment,
        ];
    }

    /**
     * Analyze category domain performance
     */
    private function analyzeCategoryDomainPerformance(): array
    {
        $results = DB::connection($this->connection)->select("
            SELECT
                domain, domain_category,
                success_count, failure_count,
                trust_score, safety_score
            FROM discovered_sources
            WHERE domain_category IS NOT NULL
            AND domain_category != 'unknown'
            AND (success_count + failure_count) >= ?
            ORDER BY (failure_count::float / GREATEST(success_count + failure_count, 1)) DESC
            LIMIT ?
        ", [
            $this->runtimePolicyInt('category_domain_analysis_min_observations'),
            $this->runtimePolicyInt('category_domain_analysis_limit'),
        ]);

        return array_map(function ($row) {
            $total = $row->success_count + $row->failure_count;

            return [
                'domain' => $row->domain,
                'domain_category' => $row->domain_category,
                'total' => $total,
                'success_rate' => $total > 0 ? $row->success_count / $total : 0,
                'trust_score' => (float) $row->trust_score,
            ];
        }, $results);
    }

    /**
     * Update success rates for discovery patterns
     */
    private function updatePatternSuccessRates(): void
    {
        // Calculate success rates from feedback
        DB::connection($this->connection)->statement("
            UPDATE source_discovery_patterns p SET
                success_rate_pct = COALESCE(
                    (SELECT
                        (COUNT(*) FILTER (WHERE f.feedback_type IN ('excellent', 'good')) * 100.0 /
                         GREATEST(COUNT(*), 1))
                     FROM source_performance_feedback f
                     JOIN discovered_sources s ON f.source_id = s.id
                     WHERE s.discovery_query = p.pattern_used
                    ), p.success_rate_pct
                ),
                updated_at = CURRENT_TIMESTAMP
            WHERE times_used >= 3
        ");
    }

    // =========================================================================
    // SOURCE DISCOVERY SUGGESTIONS
    // =========================================================================

    /**
     * Suggest new sources based on successful patterns
     *
     * @param  string  $category  Category to get suggestions for
     * @param  int  $limit  Number of suggestions
     * @return array Suggested sources
     */
    public function suggestNewSources(string $category, int $limit = 10): array
    {
        // Get successful domains in this category
        $successfulDomains = DB::connection($this->connection)->select('
            SELECT domain, domain_category, specializations, trust_score
            FROM discovered_sources
            WHERE is_active = true
            AND is_blacklisted = false
            AND (domain_category = ? OR specializations @> ?::jsonb)
            AND success_count >= ?
            AND (success_count::float / GREATEST(success_count + failure_count, 1)) >= ?
            ORDER BY trust_score DESC, success_count DESC
            LIMIT ?
        ', [
            $category,
            json_encode([$category]),
            $this->runtimePolicyInt('suggest_success_min_count'),
            $this->runtimePolicyFloat('suggest_success_min_rate'),
            $this->runtimePolicyInt('suggest_successful_domain_limit'),
        ]);

        if (empty($successfulDomains)) {
            return [];
        }

        // Extract common patterns from successful domains
        $domainList = array_column($successfulDomains, 'domain');
        $tldCounts = [];
        $wordCounts = [];

        foreach ($domainList as $domain) {
            // Count TLDs
            $parts = explode('.', $domain);
            $tld = end($parts);
            $tldCounts[$tld] = ($tldCounts[$tld] ?? 0) + 1;

            // Count domain words
            $words = preg_split('/[.\-_]/', $domain);
            foreach ($words as $word) {
                if (strlen($word) > 3) {
                    $wordCounts[$word] = ($wordCounts[$word] ?? 0) + 1;
                }
            }
        }

        arsort($tldCounts);
        arsort($wordCounts);

        return [
            'based_on_count' => count($successfulDomains),
            'common_tlds' => array_slice($tldCounts, 0, $this->runtimePolicyInt('suggest_common_tld_limit'), true),
            'common_keywords' => array_slice($wordCounts, 0, $this->runtimePolicyInt('suggest_common_keyword_limit'), true),
            'suggested_search_terms' => $this->generateSearchTerms($category, $wordCounts),
            'example_domains' => array_slice($domainList, 0, $this->runtimePolicyInt('suggest_common_tld_limit')),
        ];
    }

    /**
     * Generate search terms for finding new sources
     */
    private function generateSearchTerms(string $category, array $wordCounts): array
    {
        $categoryTerms = [
            'genealogy' => ['genealogy', 'family history', 'vital records', 'ancestry', 'archives'],
            'science' => ['research', 'journal', 'academic', 'scholarly', 'peer-reviewed'],
            'news' => ['news', 'journalism', 'reporting', 'media'],
            'medical' => ['medical', 'health', 'clinical', 'healthcare', 'medicine'],
            'legal' => ['law', 'legal', 'court', 'statute', 'regulation'],
        ];

        $baseTerms = $categoryTerms[$category] ?? [$category];
        $keywordTerms = array_keys(array_slice($wordCounts, 0, $this->runtimePolicyInt('suggest_keyword_term_limit'), true));
        $keywordTermsPerBase = $this->runtimePolicyInt('suggest_keyword_terms_per_base');

        $suggestions = [];
        foreach ($baseTerms as $base) {
            $suggestions[] = "{$base} database .gov";
            $suggestions[] = "{$base} records .edu";
            foreach (array_slice($keywordTerms, 0, $keywordTermsPerBase) as $keyword) {
                $suggestions[] = "{$base} {$keyword}";
            }
        }

        return array_unique($suggestions);
    }

    // =========================================================================
    // REPORTING
    // =========================================================================

    /**
     * Generate a health report for all sources
     *
     * @return array Comprehensive health report
     */
    public function generateHealthReport(): array
    {
        $report = [
            'generated_at' => now()->toIso8601String(),
            'summary' => [],
            'failing_sources' => [],
            'top_performing' => [],
            'rule_effectiveness' => [],
            'recommendations' => [],
        ];

        // Summary stats
        $summary = DB::connection($this->connection)->select('
            SELECT
                COUNT(*) as total_sources,
                COUNT(*) FILTER (WHERE is_active) as active_sources,
                COUNT(*) FILTER (WHERE is_whitelisted) as whitelisted,
                COUNT(*) FILTER (WHERE is_blacklisted) as blacklisted,
                COUNT(*) FILTER (WHERE consecutive_failures >= ? AND is_active) as degraded,
                COUNT(*) FILTER (WHERE consecutive_failures >= ?) as failed,
                COUNT(*) FILTER (WHERE success_count > 0 AND failure_count = 0) as perfect,
                ROUND(AVG(trust_score)::numeric, 3) as avg_trust,
                ROUND(AVG(safety_score)::numeric, 3) as avg_safety,
                SUM(success_count) as total_successes,
                SUM(failure_count) as total_failures
            FROM discovered_sources
        ', [
            $this->runtimePolicyInt('degraded_threshold'),
            $this->runtimePolicyInt('failed_threshold'),
        ]);
        $report['summary'] = (array) ($summary[0] ?? []);

        // Calculate overall health percentage
        $total = $report['summary']['total_successes'] + $report['summary']['total_failures'];
        $report['summary']['overall_success_rate'] = $total > 0
            ? round(($report['summary']['total_successes'] / $total) * 100, 1)
            : 0;

        // Failing sources
        $failing = DB::connection($this->connection)->select('
            SELECT domain, consecutive_failures, last_error_message, last_failure_at,
                   trust_score, is_whitelisted
            FROM discovered_sources
            WHERE is_active = true AND consecutive_failures >= ?
            ORDER BY consecutive_failures DESC
            LIMIT ?
        ', [
            $this->runtimePolicyInt('degraded_threshold'),
            $this->runtimePolicyInt('report_failing_limit'),
        ]);
        $report['failing_sources'] = array_map(fn ($r) => (array) $r, $failing);

        // Top performing
        $topPerforming = DB::connection($this->connection)->select('
            SELECT domain, success_count, failure_count, trust_score,
                   ROUND((success_count::numeric / GREATEST(success_count + failure_count, 1) * 100), 1) as success_rate
            FROM discovered_sources
            WHERE is_active = true AND (success_count + failure_count) >= ?
            ORDER BY (success_count::float / GREATEST(success_count + failure_count, 1)) DESC
            LIMIT ?
        ', [
            $this->runtimePolicyInt('report_top_performing_min_observations'),
            $this->runtimePolicyInt('report_top_performing_limit'),
        ]);
        $report['top_performing'] = array_map(fn ($r) => (array) $r, $topPerforming);

        // Rule effectiveness
        $ruleStats = DB::connection($this->connection)->select('
            SELECT
                rule_type,
                COUNT(*) as rule_count,
                SUM(times_applied) as total_applications,
                AVG(times_applied) as avg_applications,
                COUNT(*) FILTER (WHERE times_applied = 0) as unused_rules
            FROM discovery_rules
            WHERE is_active = true
            GROUP BY rule_type
            ORDER BY total_applications DESC
        ');
        $report['rule_effectiveness'] = array_map(fn ($r) => (array) $r, $ruleStats);

        // Generate recommendations
        $report['recommendations'] = $this->generateRecommendations($report);

        return $report;
    }

    /**
     * Generate recommendations based on health report
     */
    private function generateRecommendations(array $report): array
    {
        $recommendations = [];

        // Check for high failure rate
        $successRate = $report['summary']['overall_success_rate'] ?? 100;
        if ($successRate < $this->runtimePolicyFloat('report_min_success_rate_pct')) {
            $recommendations[] = [
                'priority' => 'high',
                'type' => 'performance',
                'message' => "Overall success rate is {$successRate}%. Consider reviewing failing sources.",
            ];
        }

        // Check for many failing sources
        $degradedCount = $report['summary']['degraded'] ?? 0;
        if ($degradedCount > $this->runtimePolicyInt('report_degraded_recommendation_threshold')) {
            $recommendations[] = [
                'priority' => 'medium',
                'type' => 'maintenance',
                'message' => "{$degradedCount} sources are degraded. Run self-healing: php artisan research:optimize --heal",
            ];
        }

        // Check for unused rules
        foreach ($report['rule_effectiveness'] as $ruleType) {
            if (($ruleType['unused_rules'] ?? 0) > $this->runtimePolicyInt('report_unused_rule_recommendation_threshold')) {
                $recommendations[] = [
                    'priority' => 'low',
                    'type' => 'cleanup',
                    'message' => "{$ruleType['unused_rules']} unused {$ruleType['rule_type']} rules. Consider reviewing or removing.",
                ];
            }
        }

        // Check trust score distribution
        $avgTrust = $report['summary']['avg_trust'] ?? 0.5;
        if ($avgTrust < $this->runtimePolicyFloat('report_min_avg_trust')) {
            $recommendations[] = [
                'priority' => 'medium',
                'type' => 'quality',
                'message' => "Average trust score is low ({$avgTrust}). Consider adding more high-trust sources.",
            ];
        }

        return $recommendations;
    }

    // =========================================================================
    // CATEGORY HEALTH & AUTO-REFRESH
    // =========================================================================

    /**
     * Get health status for all research categories
     *
     * @return array Category health data
     */
    public function getCategoryHealth(): array
    {
        $curatedStats = DB::connection($this->connection)->select("
            SELECT
                research_category as category,
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE is_active = true) as active,
                COUNT(*) FILTER (WHERE search_url_template IS NOT NULL AND search_url_template != '') as with_search_url,
                ROUND(AVG(trust_score)::numeric, 2) as avg_trust,
                SUM(COALESCE(success_count, 0)) as total_successes,
                SUM(COALESCE(failure_count, 0)) as total_failures,
                MAX(last_success_at) as last_success,
                COUNT(*) FILTER (WHERE last_success_at < CURRENT_TIMESTAMP - (? * INTERVAL '1 day') OR last_success_at IS NULL) as stale
            FROM research_sources
            WHERE research_category IS NOT NULL
            GROUP BY research_category
            ORDER BY research_category
        ", [$this->runtimePolicyInt('category_stale_days')]);

        $categories = [];
        foreach ($curatedStats as $row) {
            $total = ($row->total_successes ?? 0) + ($row->total_failures ?? 0);
            $categories[$row->category] = [
                'total_sources' => (int) $row->total,
                'active_sources' => (int) $row->active,
                'with_search_url' => (int) $row->with_search_url,
                'avg_trust' => (float) $row->avg_trust,
                'success_rate' => $total > 0 ? round($row->total_successes / $total * 100, 1) : null,
                'stale_sources' => (int) $row->stale,
                'last_success' => $row->last_success,
                'health_status' => $this->calculateCategoryHealthStatus($row),
            ];
        }

        return $categories;
    }

    /**
     * Calculate health status for a category
     */
    private function calculateCategoryHealthStatus(object $row): string
    {
        $active = (int) $row->active;
        $withSearchUrl = (int) $row->with_search_url;
        $stale = (int) $row->stale;

        if (
            $active < $this->runtimePolicyInt('category_critical_min_active')
            || $withSearchUrl < $this->runtimePolicyInt('category_critical_min_search_url')
        ) {
            return 'critical';
        }

        if ($stale > $active * $this->runtimePolicyFloat('category_stale_ratio')) {
            return 'degraded';
        }

        if (
            $active < $this->runtimePolicyInt('category_warning_min_active')
            || $withSearchUrl < $this->runtimePolicyInt('category_warning_min_search_url')
        ) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * Get categories that need attention
     *
     * @return array Categories needing maintenance
     */
    public function getCategoriesNeedingAttention(): array
    {
        $health = $this->getCategoryHealth();
        $needsAttention = [];

        $allCategories = $this->runtimePolicyStringList('category_list');

        foreach ($allCategories as $category) {
            if (! isset($health[$category])) {
                // Category has no sources at all
                $needsAttention[$category] = [
                    'reason' => 'no_sources',
                    'action' => 'discover',
                    'priority' => 'high',
                ];
            } elseif ($health[$category]['health_status'] === 'critical') {
                $needsAttention[$category] = [
                    'reason' => 'critical_health',
                    'action' => 'discover',
                    'priority' => 'high',
                    'details' => $health[$category],
                ];
            } elseif ($health[$category]['health_status'] === 'degraded') {
                $needsAttention[$category] = [
                    'reason' => 'degraded_health',
                    'action' => 'refresh',
                    'priority' => 'medium',
                    'details' => $health[$category],
                ];
            } elseif ($health[$category]['stale_sources'] > 0) {
                $needsAttention[$category] = [
                    'reason' => 'stale_sources',
                    'action' => 'refresh',
                    'priority' => 'low',
                    'details' => $health[$category],
                ];
            }
        }

        return $needsAttention;
    }

    /**
     * Refresh sources for a specific category
     *
     * @param  string  $category  Category to refresh
     * @return array Refresh results
     */
    public function refreshCategorySources(string $category): array
    {
        $results = [
            'category' => $category,
            'sources_checked' => 0,
            'sources_verified' => 0,
            'sources_failed' => 0,
            'sources_deactivated' => 0,
        ];

        // Get sources that need verification
        $sources = DB::connection($this->connection)->select('
            SELECT id, name, base_url, search_url_template
            FROM research_sources
            WHERE research_category = ?
            AND is_active = true
            ORDER BY
                CASE WHEN last_success_at IS NULL THEN 0 ELSE 1 END,
                last_success_at ASC
            LIMIT ?
        ', [$category, $this->runtimePolicyInt('category_refresh_limit')]);

        foreach ($sources as $source) {
            $results['sources_checked']++;

            try {
                $url = $source->base_url;
                $response = Http::connectTimeout($this->runtimePolicyInt('category_refresh_connect_timeout_seconds'))
                    ->timeout($this->runtimePolicyInt('category_refresh_request_timeout_seconds'))
                    ->withHeaders(['User-Agent' => 'ResearchBot/1.0 (Automated Research System)'])
                    ->head($url);

                if ($response->successful()) {
                    DB::connection($this->connection)->update('
                        UPDATE research_sources SET
                            last_success_at = CURRENT_TIMESTAMP,
                            success_count = COALESCE(success_count, 0) + 1,
                            failure_count = GREATEST(COALESCE(failure_count, 0) - 1, 0),
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ', [$source->id]);
                    $results['sources_verified']++;
                } else {
                    $this->recordSourceFailure($source->id, "HTTP {$response->status()}");
                    $results['sources_failed']++;

                    // Deactivate if too many failures
                    if ($this->shouldDeactivateSource($source->id)) {
                        $this->deactivateSource($source->id);
                        $results['sources_deactivated']++;
                    }
                }
            } catch (\Exception $e) {
                $this->recordSourceFailure($source->id, $e->getMessage());
                $results['sources_failed']++;
            }

            // Rate limit
            usleep($this->runtimePolicyInt('category_refresh_sleep_microseconds'));
        }

        return $results;
    }

    /**
     * Record a source failure
     */
    private function recordSourceFailure(int $sourceId, string $error): void
    {
        DB::connection($this->connection)->update('
            UPDATE research_sources SET
                last_failure_at = CURRENT_TIMESTAMP,
                failure_count = COALESCE(failure_count, 0) + 1,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ', [$sourceId]);

        Log::debug('Research source check failed', [
            'source_id' => $sourceId,
            'error' => substr($error, 0, 200),
        ]);
    }

    /**
     * Check if a source should be deactivated
     */
    private function shouldDeactivateSource(int $sourceId): bool
    {
        $result = DB::connection($this->connection)->select('
            SELECT failure_count, success_count, trust_score
            FROM research_sources
            WHERE id = ?
        ', [$sourceId]);

        if (empty($result)) {
            return false;
        }

        $source = $result[0];
        $failures = (int) $source->failure_count;
        $successes = (int) $source->success_count;
        $trust = (float) $source->trust_score;

        // High trust sources get more chances
        $threshold = $trust >= $this->runtimePolicyFloat('deactivation_high_trust_min')
            ? $this->runtimePolicyInt('deactivation_high_trust_failure_threshold')
            : (
                $trust >= $this->runtimePolicyFloat('deactivation_medium_trust_min')
                    ? $this->runtimePolicyInt('deactivation_medium_trust_failure_threshold')
                    : $this->runtimePolicyInt('deactivation_default_failure_threshold')
            );

        return $failures >= $threshold
            && $failures > $successes * $this->runtimePolicyFloat('deactivation_failure_success_multiplier');
    }

    /**
     * Deactivate a source
     */
    private function deactivateSource(int $sourceId): void
    {
        DB::connection($this->connection)->update("
            UPDATE research_sources SET
                is_active = false,
                notes = COALESCE(notes, '') || ' [Auto-deactivated due to failures]',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ", [$sourceId]);

        Log::info('Research source auto-deactivated', ['source_id' => $sourceId]);
    }

    /**
     * Run comprehensive maintenance across all categories
     *
     * @return array Maintenance results
     */
    public function runComprehensiveMaintenance(): array
    {
        $startTime = microtime(true);
        $results = [
            'started_at' => now()->toIso8601String(),
            'self_healing' => null,
            'rule_optimization' => null,
            'category_health' => null,
            'categories_refreshed' => [],
            'recommendations' => [],
        ];

        // 1. Run self-healing
        $results['self_healing'] = $this->runSelfHealing();

        // 2. Optimize rules
        $results['rule_optimization'] = $this->optimizeDiscoveryRules();

        // 3. Check category health
        $results['category_health'] = $this->getCategoryHealth();
        $needsAttention = $this->getCategoriesNeedingAttention();

        // 4. Refresh categories that need it
        foreach ($needsAttention as $category => $info) {
            if (in_array($info['action'], ['refresh', 'discover'])) {
                $refreshResult = $this->refreshCategorySources($category);
                $results['categories_refreshed'][$category] = $refreshResult;
            }
        }

        // 5. Generate recommendations
        foreach ($needsAttention as $category => $info) {
            $results['recommendations'][] = [
                'category' => $category,
                'priority' => $info['priority'],
                'reason' => $info['reason'],
                'action' => $info['action'],
            ];
        }

        $results['duration_seconds'] = round(microtime(true) - $startTime, 2);
        $results['completed_at'] = now()->toIso8601String();

        Log::info('Comprehensive research maintenance completed', [
            'duration' => $results['duration_seconds'],
            'categories_refreshed' => count($results['categories_refreshed']),
            'recommendations' => count($results['recommendations']),
        ]);

        return $results;
    }

    private function runtimePolicyBool(string $key): bool
    {
        $fallback = (bool) self::DEFAULT_RUNTIME_POLICY[$key];
        $value = $this->runtimePolicy()[$key] ?? $fallback;
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $fallback;
    }

    private function runtimePolicyInt(string $key): int
    {
        return $this->positiveInt(
            $this->runtimePolicy()[$key] ?? null,
            (int) self::DEFAULT_RUNTIME_POLICY[$key]
        );
    }

    private function runtimePolicyFloat(string $key): float
    {
        $fallback = (float) self::DEFAULT_RUNTIME_POLICY[$key];
        $value = $this->runtimePolicy()[$key] ?? null;

        return is_numeric($value) ? (float) $value : $fallback;
    }

    /**
     * @return list<string>
     */
    private function runtimePolicyStringList(string $key): array
    {
        $fallback = self::DEFAULT_RUNTIME_POLICY[$key];
        $value = $this->runtimePolicy()[$key] ?? $fallback;

        if (! is_array($value)) {
            return $fallback;
        }

        $strings = array_values(array_filter(
            array_map(
                fn ($item) => is_scalar($item) ? trim((string) $item) : '',
                $value
            ),
            fn (string $item) => $item !== ''
        ));

        return $strings !== [] ? $strings : $fallback;
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimePolicy(): array
    {
        if ($this->runtimePolicy !== null) {
            return $this->runtimePolicy;
        }

        $configured = app(SystemConfigService::class)->get(self::RUNTIME_POLICY_CONFIG_KEY, self::DEFAULT_RUNTIME_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        return $this->runtimePolicy = array_replace(self::DEFAULT_RUNTIME_POLICY, $configured);
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $fallback;
    }
}
