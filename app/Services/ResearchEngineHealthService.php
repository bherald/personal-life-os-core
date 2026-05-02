<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Research Engine Health Service
 *
 * Passive shared state for research engine health. Populated by research-ops agent,
 * read by any service (WebResearchService, genealogy-researcher tools) to skip
 * known-dead engines before expensive multi-engine queries.
 *
 * Pattern follows rss_feed_health — lightweight MySQL table with per-engine rows.
 */
class ResearchEngineHealthService
{
    private const TABLE = 'research_engine_health';

    // Status thresholds (same as RssFeedHealthService pattern)
    private const DEGRADED_THRESHOLD = 2;
    private const FAILED_THRESHOLD = 5;

    // Engine names in fallback chain order
    private const ENGINE_NAMES = ['newsapi', 'wikipedia', 'searxng', 'curl_scraper', 'puppeteer'];

    // =========================================================================
    // PRODUCER: Called by research-ops agent tool
    // =========================================================================

    /**
     * Snapshot current health of all research engines.
     * Polls WebResearchService engine status + ResearchEnhancementsService circuit breakers,
     * then persists to research_engine_health table.
     *
     * Registered as agent tool: research_update_engine_health
     */
    public function updateAllEngineHealth(): array
    {
        try {
            $engineStatuses = app(WebResearchService::class)->getEngineStatus();
            $enhancements = app(ResearchEnhancementsService::class);

            $results = [];
            $healthyCnt = 0;
            $degradedCnt = 0;
            $failedCnt = 0;

            foreach (self::ENGINE_NAMES as $engineName) {
                // Find matching engine from WebResearchService
                $engineData = $this->findEngineData($engineStatuses, $engineName);

                // Get circuit breaker state
                $cbStatus = $enhancements->getCircuitBreakerStatus($engineName);

                // Check if API key is configured (for key-dependent engines)
                $apiKeyConfigured = $this->isApiKeyConfigured($engineName);

                // Determine health status
                $failureCount = $engineData['failure_count'] ?? 0;
                $isActive = $engineData['active'] ?? true;
                $cbState = $cbStatus['state'] ?? 'closed';

                $status = $this->determineStatus($failureCount, $isActive, $cbState, $apiKeyConfigured);

                // Calculate avg response time from circuit breaker data if available
                $avgResponseMs = $cbStatus['avg_response_ms'] ?? null;

                // Update the record
                $this->upsertEngineHealth($engineName, [
                    'status' => $status,
                    'last_check_at' => now(),
                    'consecutive_failures' => $failureCount,
                    'total_checks_increment' => true,
                    'is_active' => $isActive,
                    'circuit_breaker_state' => $cbState,
                    'circuit_breaker_opened_at' => $cbState === 'open' ? ($cbStatus['opened_at'] ?? now()) : null,
                    'is_api_key_configured' => $apiKeyConfigured,
                    'last_success_at' => $engineData['last_success'] ?? null,
                    'last_failure_at' => $engineData['last_failure'] ?? null,
                    'last_error_message' => $cbStatus['last_error'] ?? null,
                    'avg_response_time_ms' => $avgResponseMs,
                ]);

                match ($status) {
                    'healthy' => $healthyCnt++,
                    'degraded' => $degradedCnt++,
                    'failed' => $failedCnt++,
                    default => null,
                };

                $results[] = [
                    'engine' => $engineName,
                    'status' => $status,
                    'active' => $isActive,
                    'circuit_breaker' => $cbState,
                    'api_key_configured' => $apiKeyConfigured,
                    'failure_count' => $failureCount,
                ];
            }

            $overallStatus = $failedCnt >= 3 ? 'critical' : ($degradedCnt + $failedCnt >= 2 ? 'degraded' : 'healthy');

            $summary = [
                'engines' => $results,
                'chain_summary' => [
                    'total' => count(self::ENGINE_NAMES),
                    'healthy' => $healthyCnt,
                    'degraded' => $degradedCnt,
                    'failed' => $failedCnt,
                    'overall_status' => $overallStatus,
                    'checked_at' => now()->toIso8601String(),
                ],
            ];

            Log::info('ResearchEngineHealthService: Updated all engine health', $summary['chain_summary']);

            return $summary;
        } catch (\Exception $e) {
            Log::error('ResearchEngineHealthService: Failed to update engine health', [
                'error' => $e->getMessage(),
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // CONSUMER: Called by WebResearchService / any service before research queries
    // =========================================================================

    /**
     * Get health hints for all engines.
     * Returns lightweight array of engine_name => status for pre-filtering.
     * Cached 5 minutes to avoid DB hits on every research query.
     *
     * @return array<string, array{status: string, active: bool, circuit: string, skip: bool}>
     */
    public function getEngineHealthHints(): array
    {
        try {
            $rows = DB::select("
                SELECT engine_name, status, is_active, circuit_breaker_state,
                       last_check_at, consecutive_failures, avg_response_time_ms
                FROM " . self::TABLE . "
                ORDER BY chain_position ASC
            ");

            $hints = [];
            foreach ($rows as $row) {
                $skip = $row->status === 'failed'
                    || $row->status === 'degraded'
                    || !$row->is_active
                    || $row->circuit_breaker_state === 'open';

                $hints[$row->engine_name] = [
                    'status' => $row->status,
                    'active' => (bool) $row->is_active,
                    'circuit' => $row->circuit_breaker_state,
                    'skip' => $skip,
                    'consecutive_failures' => $row->consecutive_failures,
                    'avg_response_ms' => $row->avg_response_time_ms,
                    'last_check' => $row->last_check_at,
                ];
            }

            return $hints;
        } catch (\Exception $e) {
            // Table might not exist yet or DB error — return empty (no filtering)
            Log::debug('ResearchEngineHealthService: Could not read engine health hints', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Check if a specific engine should be skipped based on health state.
     * Returns false (don't skip) if no health data available — fail open.
     */
    public function shouldSkipEngine(string $engineName): bool
    {
        $hints = $this->getEngineHealthHints();

        if (empty($hints) || !isset($hints[$engineName])) {
            return false; // No data = don't skip (fail open)
        }

        return $hints[$engineName]['skip'];
    }

    /**
     * Get full health summary for API/dashboard.
     */
    public function getHealthSummary(): array
    {
        try {
            $rows = DB::select("
                SELECT *
                FROM " . self::TABLE . "
                ORDER BY chain_position ASC
            ");

            $engines = [];
            $healthyCnt = 0;
            $degradedCnt = 0;
            $failedCnt = 0;
            $unknownCnt = 0;

            foreach ($rows as $row) {
                $engines[] = [
                    'engine_name' => $row->engine_name,
                    'display_name' => $row->display_name,
                    'status' => $row->status,
                    'chain_position' => $row->chain_position,
                    'is_active' => (bool) $row->is_active,
                    'is_api_key_configured' => (bool) $row->is_api_key_configured,
                    'circuit_breaker_state' => $row->circuit_breaker_state,
                    'consecutive_failures' => $row->consecutive_failures,
                    'consecutive_successes' => $row->consecutive_successes,
                    'total_checks' => $row->total_checks,
                    'total_successes' => $row->total_successes,
                    'total_failures' => $row->total_failures,
                    'total_timeouts' => $row->total_timeouts,
                    'avg_response_time_ms' => $row->avg_response_time_ms,
                    'last_check_at' => $row->last_check_at,
                    'last_success_at' => $row->last_success_at,
                    'last_failure_at' => $row->last_failure_at,
                    'last_error_message' => $row->last_error_message,
                    'last_error_type' => $row->last_error_type,
                    'success_rate' => $row->total_checks > 0
                        ? round(($row->total_successes / $row->total_checks) * 100, 1)
                        : null,
                ];

                match ($row->status) {
                    'healthy' => $healthyCnt++,
                    'degraded' => $degradedCnt++,
                    'failed' => $failedCnt++,
                    default => $unknownCnt++,
                };
            }

            $overallStatus = match (true) {
                $failedCnt >= 3 => 'critical',
                ($degradedCnt + $failedCnt) >= 2 => 'degraded',
                $unknownCnt === count($rows) => 'unknown',
                default => 'healthy',
            };

            return [
                'engines' => $engines,
                'summary' => [
                    'total' => count($engines),
                    'healthy' => $healthyCnt,
                    'degraded' => $degradedCnt,
                    'failed' => $failedCnt,
                    'unknown' => $unknownCnt,
                    'overall_status' => $overallStatus,
                    'fallback_chain_intact' => $failedCnt < count($engines),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('ResearchEngineHealthService: Failed to get health summary', [
                'error' => $e->getMessage(),
            ]);
            return ['error' => $e->getMessage(), 'engines' => [], 'summary' => []];
        }
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================

    /**
     * Match engine data from WebResearchService::getEngineStatus() by name.
     */
    private function findEngineData(array $engineStatuses, string $engineName): array
    {
        $nameMap = [
            'newsapi' => 'NewsAPI',
            'wikipedia' => 'Wikipedia',
            'searxng' => 'SearXNG',
            'curl_scraper' => 'Curl Direct Scraping',
            'puppeteer' => 'Puppeteer',
        ];

        $displayName = $nameMap[$engineName] ?? $engineName;

        foreach ($engineStatuses as $engine) {
            $name = $engine['name'] ?? '';
            if (
                strcasecmp($name, $displayName) === 0
                || strcasecmp($name, $engineName) === 0
                || stripos($name, $engineName) !== false
            ) {
                return $engine;
            }
        }

        return ['active' => true, 'failure_count' => 0];
    }

    /**
     * Check if an API key is configured for key-dependent engines.
     */
    private function isApiKeyConfigured(string $engineName): bool
    {
        return match ($engineName) {
            'newsapi' => !empty(config('services.newsapi.api_key')),
            default => true, // Wikipedia, SearXNG, Curl, Puppeteer don't need API keys
        };
    }

    /**
     * Determine health status from engine metrics.
     */
    private function determineStatus(int $failureCount, bool $isActive, string $cbState, bool $apiKeyConfigured): string
    {
        if (!$apiKeyConfigured || !$isActive || $cbState === 'open') {
            return 'failed';
        }

        if ($failureCount >= self::FAILED_THRESHOLD) {
            return 'failed';
        }

        if ($failureCount >= self::DEGRADED_THRESHOLD || $cbState === 'half_open') {
            return 'degraded';
        }

        return 'healthy';
    }

    /**
     * Insert or update an engine health record.
     */
    private function upsertEngineHealth(string $engineName, array $data): void
    {
        $exists = DB::selectOne("SELECT id, total_checks, total_successes, total_failures FROM " . self::TABLE . " WHERE engine_name = ?", [$engineName]);

        if (!$exists) {
            return; // Engine not seeded — skip
        }

        $totalChecks = $exists->total_checks + 1;
        $totalSuccesses = $exists->total_successes;
        $totalFailures = $exists->total_failures;

        // If status is healthy, it was a success; if failed/degraded, it was a failure
        if ($data['status'] === 'healthy') {
            $totalSuccesses++;
        } elseif ($data['status'] === 'failed') {
            $totalFailures++;
        }

        $consecutiveSuccesses = $data['status'] === 'healthy'
            ? ($exists->total_checks > 0 ? DB::selectOne("SELECT consecutive_successes FROM " . self::TABLE . " WHERE engine_name = ?", [$engineName])->consecutive_successes + 1 : 1)
            : 0;

        $sets = [
            'status = ?',
            'last_check_at = ?',
            'is_active = ?',
            'circuit_breaker_state = ?',
            'is_api_key_configured = ?',
            'total_checks = ?',
            'total_successes = ?',
            'total_failures = ?',
            'consecutive_failures = ?',
            'consecutive_successes = ?',
        ];

        $params = [
            $data['status'],
            $data['last_check_at'],
            $data['is_active'] ? 1 : 0,
            $data['circuit_breaker_state'],
            $data['is_api_key_configured'] ? 1 : 0,
            $totalChecks,
            $totalSuccesses,
            $totalFailures,
            $data['consecutive_failures'],
            $consecutiveSuccesses,
        ];

        if ($data['circuit_breaker_state'] === 'open' && $data['circuit_breaker_opened_at']) {
            $sets[] = 'circuit_breaker_opened_at = ?';
            $params[] = $data['circuit_breaker_opened_at'];
        }

        if (!empty($data['last_success_at'])) {
            $sets[] = 'last_success_at = ?';
            $params[] = $data['last_success_at'];
        }

        if (!empty($data['last_failure_at'])) {
            $sets[] = 'last_failure_at = ?';
            $params[] = $data['last_failure_at'];
        }

        if (!empty($data['last_error_message'])) {
            $sets[] = 'last_error_message = ?';
            $params[] = substr($data['last_error_message'], 0, 65535);
        }

        if ($data['avg_response_time_ms'] !== null) {
            $sets[] = 'avg_response_time_ms = ?';
            $params[] = $data['avg_response_time_ms'];
        }

        // Reset alert when engine recovers
        if ($data['status'] === 'healthy') {
            $sets[] = 'alert_sent = 0';
            $sets[] = 'alert_sent_at = NULL';
        }

        $params[] = $engineName;

        DB::update(
            "UPDATE " . self::TABLE . " SET " . implode(', ', $sets) . " WHERE engine_name = ?",
            $params
        );
    }
}
