<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Timeout Manager Service
 *
 * Manages timeouts across the framework with:
 * - Predefined timeout hierarchy (cascading timeouts)
 * - Adaptive timeout calculation based on historical performance
 * - Automatic timeout adjustment (self-tuning)
 * - Performance statistics tracking
 *
 * Timeout Hierarchy (parent timeouts must be larger than child):
 * - http_request: 15s (basic HTTP call)
 * - rss_feed: 20s (RSS feed fetch)
 * - api_call: 25s (external API call)
 * - ai_processing: 120s (AI/LLM processing)
 * - node_execution: 180s (single workflow node)
 * - workflow_execution: 600s (entire workflow)
 *
 * Usage:
 * ```php
 * $timeoutManager = app(TimeoutManager::class);
 *
 * // Get timeout (uses adaptive if available, otherwise default)
 * $timeout = $timeoutManager->getTimeout('rss_feed');
 *
 * // Record execution for adaptive learning
 * $timeoutManager->recordExecution('rss_feed', 3.5, true);
 * ```
 */
class TimeoutManager
{
    // Default timeout hierarchy (in seconds)
    // N82: defaults loaded from config/timeouts.php at runtime via timeouts() method
    private const TIMEOUT_DEFAULTS = [
        'http_request' => 15,
        'rss_feed' => 20,
        'api_call' => 25,
        'ai_processing' => 300,
        'node_execution' => 420,
        'workflow_execution' => 900,
        'database_query' => 10,
        'file_upload' => 60,
    ];

    // Map from operation names → config/timeouts.php keys
    private const TIMEOUT_CONFIG_MAP = [
        'http_request'      => 'http',
        'rss_feed'          => 'rss',
        'api_call'          => 'api',
        'ai_processing'     => 'ai',
        'node_execution'    => 'node',
        'workflow_execution' => 'workflow',
        'database_query'    => 'db',
        'file_upload'       => 'file',
    ];

    /** @return array<string,int> Full timeout map, config-overridable */
    private function timeouts(): array
    {
        $map = self::TIMEOUT_DEFAULTS;
        foreach (self::TIMEOUT_CONFIG_MAP as $op => $key) {
            $map[$op] = (int) config("timeouts.{$key}", $map[$op]);
        }
        return $map;
    }

    // Timeout statistics cache key
    private const STATS_KEY = 'timeout_manager.stats';

    // Adaptive timeout settings
    private const MIN_SAMPLES = 10;         // Minimum executions before adaptive
    private const PERCENTILE = 95;          // Use P95 for timeout calculation
    private const BUFFER_MULTIPLIER = 1.5;  // Add 50% buffer to P95
    private const MAX_MULTIPLIER = 3.0;     // Max 3x default timeout

    /**
     * Get timeout for operation (adaptive or default)
     *
     * @param string $operation Operation name
     * @param int|null $override Manual override timeout (seconds)
     * @return int Timeout in seconds
     */
    public function getTimeout(string $operation, ?int $override = null): int
    {
        // Manual override takes precedence
        if ($override !== null) {
            return $override;
        }

        // Try adaptive timeout first
        $adaptive = $this->getAdaptiveTimeout($operation);

        if ($adaptive !== null) {
            return $adaptive;
        }

        // Fall back to default
        return $this->timeouts()[$operation] ?? 30;
    }

    /**
     * Record execution for adaptive timeout learning
     *
     * @param string $operation Operation name
     * @param float $durationSeconds Duration in seconds
     * @param bool $success Whether operation succeeded
     * @return void
     */
    public function recordExecution(string $operation, float $durationSeconds, bool $success): void
    {
        $stats = Cache::get(self::STATS_KEY, []);

        if (!isset($stats[$operation])) {
            $stats[$operation] = [
                'executions' => [],
                'failures' => 0,
                'successes' => 0,
            ];
        }

        // Keep last 100 executions for rolling average
        $stats[$operation]['executions'][] = $durationSeconds;
        if (count($stats[$operation]['executions']) > 100) {
            array_shift($stats[$operation]['executions']);
        }

        // Track success/failure
        if ($success) {
            $stats[$operation]['successes']++;
        } else {
            $stats[$operation]['failures']++;
        }

        // Store with 24-hour expiry
        Cache::put(self::STATS_KEY, $stats, 86400);

        // Log if execution time is concerning (> 80% of timeout)
        $currentTimeout = $this->getTimeout($operation);
        if ($durationSeconds > ($currentTimeout * 0.8)) {
            Log::warning("TimeoutManager: Operation approaching timeout", [
                'operation' => $operation,
                'duration' => round($durationSeconds, 2),
                'timeout' => $currentTimeout,
                'utilization' => round(($durationSeconds / $currentTimeout) * 100, 1) . '%',
            ]);
        }
    }

    /**
     * Calculate adaptive timeout based on historical P95
     *
     * @param string $operation Operation name
     * @return int|null Adaptive timeout in seconds, or null if insufficient data
     */
    private function getAdaptiveTimeout(string $operation): ?int
    {
        $stats = Cache::get(self::STATS_KEY, []);

        // Not enough data for adaptive timeout
        if (!isset($stats[$operation]) || count($stats[$operation]['executions']) < self::MIN_SAMPLES) {
            return null;
        }

        $executions = $stats[$operation]['executions'];
        sort($executions);

        // Calculate P95 (95th percentile)
        $p95Index = (int)(count($executions) * (self::PERCENTILE / 100));
        $p95 = $executions[$p95Index];

        // Add buffer for safety (50% more than P95)
        $adaptiveTimeout = (int)ceil($p95 * self::BUFFER_MULTIPLIER);

        // Clamp to reasonable bounds
        $defaultTimeout = $this->timeouts()[$operation] ?? 30;
        $minTimeout = $defaultTimeout;
        $maxTimeout = (int)($defaultTimeout * self::MAX_MULTIPLIER);

        $clamped = max($minTimeout, min($adaptiveTimeout, $maxTimeout));

        // Log if adaptive timeout differs significantly from default
        if (abs($clamped - $defaultTimeout) > ($defaultTimeout * 0.2)) {
            Log::info("TimeoutManager: Using adaptive timeout", [
                'operation' => $operation,
                'default_timeout' => $defaultTimeout,
                'adaptive_timeout' => $clamped,
                'p95' => round($p95, 2),
                'sample_count' => count($executions),
            ]);
        }

        return $clamped;
    }

    /**
     * Get performance statistics for operation
     *
     * @param string|null $operation Specific operation, or null for all
     * @return array Statistics
     */
    public function getStatistics(?string $operation = null): array
    {
        $stats = Cache::get(self::STATS_KEY, []);

        if ($operation !== null) {
            return $this->calculateStats($operation, $stats[$operation] ?? null);
        }

        // Return stats for all operations
        $result = [];
        foreach ($stats as $op => $data) {
            $result[$op] = $this->calculateStats($op, $data);
        }

        return $result;
    }

    /**
     * Calculate detailed statistics for an operation
     *
     * @param string $operation Operation name
     * @param array|null $data Raw statistics data
     * @return array Calculated statistics
     */
    private function calculateStats(string $operation, ?array $data): array
    {
        if ($data === null || empty($data['executions'])) {
            return [
                'operation' => $operation,
                'sample_count' => 0,
                'default_timeout' => $this->timeouts()[$operation] ?? 30,
                'adaptive_timeout' => null,
                'current_timeout' => $this->getTimeout($operation),
            ];
        }

        $executions = $data['executions'];
        sort($executions);

        $count = count($executions);

        return [
            'operation' => $operation,
            'sample_count' => $count,
            'avg' => round(array_sum($executions) / $count, 2),
            'min' => round(min($executions), 2),
            'max' => round(max($executions), 2),
            'p50' => round($executions[(int)($count * 0.50)], 2),
            'p95' => round($executions[(int)($count * 0.95)], 2),
            'p99' => round($executions[(int)($count * 0.99)], 2),
            'success_count' => $data['successes'] ?? 0,
            'failure_count' => $data['failures'] ?? 0,
            'success_rate' => $data['successes'] + $data['failures'] > 0
                ? round(($data['successes'] / ($data['successes'] + $data['failures'])) * 100, 1)
                : 0,
            'default_timeout' => $this->timeouts()[$operation] ?? 30,
            'adaptive_timeout' => $this->getAdaptiveTimeout($operation),
            'current_timeout' => $this->getTimeout($operation),
        ];
    }

    /**
     * Reset statistics for operation (or all operations)
     *
     * @param string|null $operation Operation to reset, or null for all
     * @return void
     */
    public function resetStatistics(?string $operation = null): void
    {
        if ($operation === null) {
            // Reset all
            Cache::forget(self::STATS_KEY);
            Log::info("TimeoutManager: All statistics reset");
        } else {
            // Reset specific operation
            $stats = Cache::get(self::STATS_KEY, []);
            unset($stats[$operation]);
            Cache::put(self::STATS_KEY, $stats, 86400);
            Log::info("TimeoutManager: Statistics reset", ['operation' => $operation]);
        }
    }

    /**
     * Get all defined operations
     *
     * @return array Operation names
     */
    public function getOperations(): array
    {
        return array_keys($this->timeouts());
    }

    /**
     * Validate timeout hierarchy (parent > child)
     *
     * Ensures cascading timeouts are properly configured
     *
     * @return array Validation results
     */
    public function validateHierarchy(): array
    {
        $issues = [];

        // Check: workflow_execution > node_execution
        if ($this->timeouts()['workflow_execution'] <= $this->timeouts()['node_execution']) {
            $issues[] = 'workflow_execution must be > node_execution';
        }

        // Check: node_execution > ai_processing
        if ($this->timeouts()['node_execution'] <= $this->timeouts()['ai_processing']) {
            $issues[] = 'node_execution must be > ai_processing';
        }

        // Check: ai_processing > api_call
        if ($this->timeouts()['ai_processing'] <= $this->timeouts()['api_call']) {
            $issues[] = 'ai_processing must be > api_call';
        }

        // Check: api_call > rss_feed
        if ($this->timeouts()['api_call'] <= $this->timeouts()['rss_feed']) {
            $issues[] = 'api_call must be > rss_feed';
        }

        // Check: rss_feed > http_request
        if ($this->timeouts()['rss_feed'] <= $this->timeouts()['http_request']) {
            $issues[] = 'rss_feed must be > http_request';
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'hierarchy' => $this->timeouts(),
        ];
    }

    /**
     * Get recommended timeout for new operation type
     *
     * @param string $parentOperation Parent operation in hierarchy
     * @param float $estimatedDuration Estimated duration in seconds
     * @return int Recommended timeout in seconds
     */
    public function recommendTimeout(string $parentOperation, float $estimatedDuration): int
    {
        $parentTimeout = $this->timeouts()[$parentOperation] ?? 30;

        // Recommendation: 2x estimated duration, but less than parent
        $recommended = (int)ceil($estimatedDuration * 2);

        // Ensure it's less than parent timeout
        return min($recommended, $parentTimeout - 5);
    }
}
