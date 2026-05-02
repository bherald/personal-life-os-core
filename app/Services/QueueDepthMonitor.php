<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Queue;

/**
 * QueueDepthMonitor - Auto-Scaling Queue Depth Detection
 *
 * E01 Phase 3.4: Monitors AI request queue depth to enable intelligent
 * load balancing and auto-scaling decisions.
 *
 * Features:
 * - Real-time queue depth tracking
 * - Moving average calculation for trend detection
 * - Threshold-based alerting
 * - Recommended scaling actions
 * - Integration with AIService for load-aware routing
 *
 * Use cases:
 * - Determine when to spin up additional workers
 * - Route requests to less-loaded providers
 * - Implement backpressure when overloaded
 * - Metrics for monitoring dashboards
 */
class QueueDepthMonitor
{
    /** @var string Cache key prefix for queue metrics */
    private const CACHE_PREFIX = 'queue_depth_';

    /** @var int Window size for moving average (samples) */
    private const MOVING_AVG_WINDOW = 10;

    /** @var array Default thresholds for scaling decisions */
    private array $thresholds;

    /** @var array Queue names to monitor */
    private array $queues;

    public function __construct(array $options = [])
    {
        $this->thresholds = $options['thresholds'] ?? [
            'low' => 5,           // Below this: scale down
            'normal' => 20,       // Normal operating range
            'high' => 50,         // Above this: consider scaling up
            'critical' => 100,    // Above this: immediate action needed
        ];

        $this->queues = $options['queues'] ?? [
            config('queue.connections.redis.queue', 'default'),
            'high',
            'default',
            'low',
            'long-running',
            'workflow',
            'speculative',
        ];
        $this->queues = array_values(array_unique(array_filter($this->queues)));
    }

    /**
     * Get current queue depth for all monitored queues
     *
     * @return array Queue depth stats
     */
    public function getCurrentDepth(): array
    {
        $depths = [];
        $totalDepth = 0;

        foreach ($this->queues as $queueName) {
            $depth = $this->getQueueSize($queueName);
            $depths[$queueName] = $depth;
            $totalDepth += $depth;
        }

        // Record this sample for moving average
        $this->recordSample($totalDepth);

        return [
            'queues' => $depths,
            'total' => $totalDepth,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Get queue size for a specific queue
     *
     * @param string $queueName
     * @return int
     */
    private function getQueueSize(string $queueName): int
    {
        try {
            // Try Redis first (most common queue driver)
            if ($this->isRedisQueue()) {
                return $this->getRedisQueueSize($queueName);
            }

            // Fall back to database queue
            if ($this->isDatabaseQueue()) {
                return $this->getDatabaseQueueSize($queueName);
            }

            // For sync or other drivers, return 0
            return 0;
        } catch (\Exception $e) {
            Log::warning('QueueDepthMonitor: Failed to get queue size', [
                'queue' => $queueName,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Check if using Redis queue driver
     */
    private function isRedisQueue(): bool
    {
        return config('queue.default') === 'redis';
    }

    /**
     * Check if using database queue driver
     */
    private function isDatabaseQueue(): bool
    {
        return config('queue.default') === 'database';
    }

    /**
     * Get Redis queue size
     */
    private function getRedisQueueSize(string $queueName): int
    {
        try {
            $connection = config('queue.connections.redis.connection', 'default');

            // Laravel's Redis connection already applies the configured prefix.
            $queueKey = "queues:{$queueName}";
            $delayedKey = "queues:{$queueName}:delayed";
            $reservedKey = "queues:{$queueName}:reserved";

            $redis = Redis::connection($connection);

            $pending = $redis->llen($queueKey) ?? 0;
            $delayed = $redis->zcard($delayedKey) ?? 0;
            $reserved = $redis->zcard($reservedKey) ?? 0;

            return $pending + $delayed + $reserved;
        } catch (\Exception $e) {
            Log::warning('QueueDepthMonitor: Redis error', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Get database queue size
     */
    private function getDatabaseQueueSize(string $queueName): int
    {
        try {
            $table = config('queue.connections.database.table', 'jobs');
            $result = \DB::selectOne("SELECT COUNT(*) as count FROM {$table} WHERE queue = ?", [$queueName]);
            return $result->count ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Record sample for moving average calculation
     */
    private function recordSample(int $depth): void
    {
        $key = self::CACHE_PREFIX . 'samples';
        $samples = Cache::get($key, []);

        // Add new sample
        $samples[] = [
            'depth' => $depth,
            'timestamp' => microtime(true),
        ];

        // Keep only last N samples
        $samples = array_slice($samples, -self::MOVING_AVG_WINDOW);

        Cache::put($key, $samples, 3600); // 1 hour TTL
    }

    /**
     * Get moving average of queue depth
     *
     * @return float
     */
    public function getMovingAverage(): float
    {
        $samples = Cache::get(self::CACHE_PREFIX . 'samples', []);

        if (empty($samples)) {
            return 0.0;
        }

        $sum = array_sum(array_column($samples, 'depth'));
        return round($sum / count($samples), 2);
    }

    /**
     * Get queue depth trend (increasing, stable, decreasing)
     *
     * @return array Trend analysis
     */
    public function getTrend(): array
    {
        $samples = Cache::get(self::CACHE_PREFIX . 'samples', []);

        if (count($samples) < 3) {
            return ['trend' => 'unknown', 'slope' => 0];
        }

        // Simple linear regression
        $n = count($samples);
        $xSum = 0;
        $ySum = 0;
        $xySum = 0;
        $x2Sum = 0;

        foreach ($samples as $i => $sample) {
            $xSum += $i;
            $ySum += $sample['depth'];
            $xySum += $i * $sample['depth'];
            $x2Sum += $i * $i;
        }

        $denominator = $n * $x2Sum - $xSum * $xSum;

        if ($denominator == 0) {
            return ['trend' => 'stable', 'slope' => 0];
        }

        $slope = ($n * $xySum - $xSum * $ySum) / $denominator;

        $trend = 'stable';
        if ($slope > 2) $trend = 'increasing';
        elseif ($slope < -2) $trend = 'decreasing';

        return [
            'trend' => $trend,
            'slope' => round($slope, 2),
            'samples' => $n,
        ];
    }

    /**
     * Get current status level based on thresholds
     *
     * @return string Status level: low, normal, high, critical
     */
    public function getStatusLevel(): string
    {
        $depth = $this->getCurrentDepth()['total'];

        if ($depth >= $this->thresholds['critical']) return 'critical';
        if ($depth >= $this->thresholds['high']) return 'high';
        if ($depth >= $this->thresholds['normal']) return 'normal';
        if ($depth <= $this->thresholds['low']) return 'low';

        return 'normal';
    }

    /**
     * Get scaling recommendation based on current state
     *
     * @return array Scaling recommendation
     */
    public function getScalingRecommendation(): array
    {
        $depth = $this->getCurrentDepth();
        $average = $this->getMovingAverage();
        $trend = $this->getTrend();
        $status = $this->getStatusLevel();

        $recommendation = [
            'action' => 'maintain',
            'reason' => 'Queue depth is within normal range',
            'workers' => null,
        ];

        // Critical: Immediate scale up
        if ($status === 'critical') {
            $recommendation = [
                'action' => 'scale_up_urgent',
                'reason' => "Queue depth ({$depth['total']}) exceeds critical threshold",
                'workers' => '+3',
                'priority' => 'high',
            ];
        }
        // High with increasing trend: Scale up
        elseif ($status === 'high' && $trend['trend'] === 'increasing') {
            $recommendation = [
                'action' => 'scale_up',
                'reason' => "Queue depth is high ({$depth['total']}) and increasing",
                'workers' => '+2',
            ];
        }
        // High but stable/decreasing: Monitor
        elseif ($status === 'high') {
            $recommendation = [
                'action' => 'monitor',
                'reason' => "Queue depth is high ({$depth['total']}) but " . $trend['trend'],
                'workers' => null,
            ];
        }
        // Low with decreasing trend: Consider scale down
        elseif ($status === 'low' && $trend['trend'] !== 'increasing') {
            $recommendation = [
                'action' => 'scale_down',
                'reason' => "Queue depth is low ({$depth['total']}), can reduce workers",
                'workers' => '-1',
            ];
        }

        return [
            'recommendation' => $recommendation,
            'current_depth' => $depth,
            'moving_average' => $average,
            'trend' => $trend,
            'status' => $status,
            'thresholds' => $this->thresholds,
        ];
    }

    /**
     * Check if load shedding should be activated
     *
     * @return bool True if requests should be rejected/delayed
     */
    public function shouldShedLoad(): bool
    {
        $status = $this->getStatusLevel();
        return $status === 'critical';
    }

    /**
     * Get estimated wait time based on queue depth and processing rate
     *
     * @param float $avgProcessingTime Average time to process one request (seconds)
     * @param int $workers Number of active workers
     * @return float Estimated wait time in seconds
     */
    public function getEstimatedWaitTime(float $avgProcessingTime = 2.0, int $workers = 1): float
    {
        $depth = $this->getCurrentDepth()['total'];
        $workers = max($workers, 1); // Prevent division by zero

        // Simple estimation: (queue_depth / workers) * avg_processing_time
        return round(($depth / $workers) * $avgProcessingTime, 2);
    }

    /**
     * Get comprehensive metrics for monitoring dashboard
     *
     * @return array All queue metrics
     */
    public function getMetrics(): array
    {
        $depth = $this->getCurrentDepth();
        $scaling = $this->getScalingRecommendation();

        return [
            'depth' => $depth,
            'moving_average' => $this->getMovingAverage(),
            'trend' => $this->getTrend(),
            'status' => $this->getStatusLevel(),
            'should_shed_load' => $this->shouldShedLoad(),
            'estimated_wait_seconds' => $this->getEstimatedWaitTime(),
            'scaling' => $scaling['recommendation'],
            'thresholds' => $this->thresholds,
            'recorded_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Reset all cached metrics (for testing)
     */
    public function reset(): void
    {
        Cache::forget(self::CACHE_PREFIX . 'samples');
    }
}
