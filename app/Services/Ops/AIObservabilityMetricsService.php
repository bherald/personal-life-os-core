<?php

namespace App\Services\Ops;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AIObservabilityMetricsService
{
    private const MAX_SAMPLE_ROWS = 100000;

    /**
     * @return array<string,mixed>
     */
    public function snapshot(int $hours = 24): array
    {
        $hours = max(1, min(168, $hours));

        return Cache::remember("ai_observability_request_metrics:{$hours}", 60, function () use ($hours): array {
            if (! Schema::hasTable('llm_model_request_logs')) {
                return $this->emptySnapshot($hours, 'llm_model_request_logs_unavailable');
            }

            try {
                $windowEnd = now();
                $windowStart = $windowEnd->copy()->subHours($hours);
                $baselineStart = $windowStart->copy()->subHours($hours);
                $current = $this->windowRows($windowStart, $windowEnd, true);
                $baseline = $this->windowRows($baselineStart, $windowStart);
            } catch (Throwable $e) {
                Log::warning('AI observability request metrics query failed', ['error' => $e->getMessage()]);

                return $this->emptySnapshot($hours, 'request_metrics_query_failed');
            }

            $payload = $this->summarize(
                $current['rows'],
                $hours,
                $current['sampled'],
                $current['total_rows'],
            );
            $baselinePayload = $this->summarize($baseline['rows'], $hours);
            $payload['comparison'] = $this->buildComparison(
                $payload['routes'],
                $baselinePayload['routes'],
                $hours,
                $windowStart,
                $windowEnd,
                $baselineStart,
                $current['sampled'],
                $baseline['sampled'],
                count($current['rows']),
                count($baseline['rows']),
                $current['total_rows'],
                $baseline['total_rows'],
            );

            return $payload;
        });
    }

    /**
     * @param  list<array<string,mixed>>  $currentRows
     * @param  list<array<string,mixed>>  $baselineRows
     * @return array<string,mixed>
     */
    public function compareWindows(
        array $currentRows,
        array $baselineRows,
        int $hours = 24,
        ?Carbon $currentStart = null,
        ?Carbon $currentEnd = null,
        ?Carbon $baselineStart = null,
        bool $currentSampled = false,
        bool $baselineSampled = false,
        ?int $currentTotalRows = null,
        ?int $baselineTotalRows = null,
    ): array {
        $hours = max(1, min(168, $hours));
        $currentEnd ??= now();
        $currentStart ??= $currentEnd->copy()->subHours($hours);
        $baselineStart ??= $currentStart->copy()->subHours($hours);

        return $this->buildComparison(
            $this->summarize($currentRows, $hours)['routes'],
            $this->summarize($baselineRows, $hours)['routes'],
            $hours,
            $currentStart,
            $currentEnd,
            $baselineStart,
            $currentSampled,
            $baselineSampled,
            count($currentRows),
            count($baselineRows),
            $currentTotalRows ?? count($currentRows),
            $baselineTotalRows ?? count($baselineRows),
        );
    }

    /**
     * @param  list<array<string,mixed>>  $currentRoutes
     * @param  list<array<string,mixed>>  $baselineRoutes
     * @return array<string,mixed>
     */
    private function buildComparison(
        array $currentRoutes,
        array $baselineRoutes,
        int $hours,
        Carbon $currentStart,
        Carbon $currentEnd,
        Carbon $baselineStart,
        bool $currentSampled,
        bool $baselineSampled,
        int $currentSampleRows,
        int $baselineSampleRows,
        int $currentTotalRows,
        int $baselineTotalRows,
    ): array {
        $baselineByKey = [];
        foreach ($baselineRoutes as $route) {
            $baselineByKey[$this->routeKey($route)] = $route;
        }

        $routes = [];
        foreach ($currentRoutes as $current) {
            $baseline = $baselineByKey[$this->routeKey($current)] ?? null;
            $routes[] = [
                'instance_id' => $current['instance_id'],
                'model_name' => $current['model_name'],
                'model_role' => $current['model_role'],
                'request_kind' => $current['request_kind'],
                'current' => $this->comparisonMetrics($current),
                'baseline' => $baseline === null ? null : $this->comparisonMetrics($baseline),
                'delta' => $baseline === null ? null : [
                    'failure_rate_points' => $this->difference($current['failure_rate'], $baseline['failure_rate']),
                    'avg_latency_percent' => $this->percentChange($current['avg_latency_ms'], $baseline['avg_latency_ms']),
                    'p95_latency_percent' => $this->percentChange($current['p95_latency_ms'], $baseline['p95_latency_ms']),
                    'tokens_per_second_percent' => $this->percentChange($current['avg_tokens_per_second'], $baseline['avg_tokens_per_second']),
                ],
            ];
        }

        return [
            'source' => 'llm_model_request_logs',
            'window_hours' => $hours,
            'current_window' => [
                'start' => $currentStart->toIso8601String(),
                'end' => $currentEnd->toIso8601String(),
                'sampled' => $currentSampled,
                'sample_rows' => $currentSampleRows,
                'total_rows' => $currentTotalRows,
            ],
            'baseline_window' => [
                'start' => $baselineStart->toIso8601String(),
                'end' => $currentStart->toIso8601String(),
                'sampled' => $baselineSampled,
                'sample_rows' => $baselineSampleRows,
                'total_rows' => $baselineTotalRows,
            ],
            'route_count' => count($routes),
            'matched_route_count' => count(array_filter($routes, static fn (array $route): bool => $route['baseline'] !== null)),
            'routes' => $routes,
        ];
    }

    /** @return array{rows:list<array<string,mixed>>,sampled:bool,total_rows:int} */
    private function windowRows(Carbon $start, Carbon $end, bool $includeEnd = false): array
    {
        $query = DB::table('llm_model_request_logs')
            ->where('created_at', '>=', $start);
        $query->where('created_at', $includeEnd ? '<=' : '<', $end);
        $totalRows = (clone $query)->count();
        $columns = [
            'instance_id',
            'model_name',
            'caller',
            'model_role',
            'request_kind',
            'success',
            'latency_ms',
            'tokens_per_second',
            'quality_score',
            'escalated',
            'error_summary',
            'created_at',
        ];
        if (Schema::hasColumns('llm_model_request_logs', ['route_trace_id', 'route_attempt_index'])) {
            $columns = [...$columns, 'route_trace_id', 'route_attempt_index'];
        }
        $usageColumns = [
            'input_tokens',
            'cached_input_tokens',
            'cache_write_input_tokens',
            'output_tokens',
            'reasoning_output_tokens',
        ];
        if (Schema::hasColumns('llm_model_request_logs', $usageColumns)) {
            $columns = [...$columns, ...$usageColumns];
        }

        $rows = $query
            ->orderByDesc('id')
            ->limit(self::MAX_SAMPLE_ROWS)
            ->get($columns)
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        return [
            'rows' => $rows,
            'sampled' => $totalRows > self::MAX_SAMPLE_ROWS,
            'total_rows' => $totalRows,
        ];
    }

    /** @param array<string,mixed> $route */
    private function routeKey(array $route): string
    {
        return implode('|', [
            $route['instance_id'] ?? 'unknown',
            $route['model_name'] ?? 'unknown',
            $route['model_role'] ?? 'unassigned',
            $route['request_kind'] ?? 'chat',
        ]);
    }

    /**
     * @param  array<string,mixed>  $route
     * @return array<string,int|float|null>
     */
    private function comparisonMetrics(array $route): array
    {
        return [
            'calls' => (int) ($route['calls'] ?? 0),
            'success_rate' => $route['success_rate'] ?? null,
            'failure_rate' => $route['failure_rate'] ?? null,
            'avg_latency_ms' => $route['avg_latency_ms'] ?? null,
            'p95_latency_ms' => $route['p95_latency_ms'] ?? null,
            'avg_tokens_per_second' => $route['avg_tokens_per_second'] ?? null,
        ];
    }

    private function difference(mixed $current, mixed $baseline): ?float
    {
        if (! is_numeric($current) || ! is_numeric($baseline)) {
            return null;
        }

        return round((float) $current - (float) $baseline, 2);
    }

    private function percentChange(mixed $current, mixed $baseline): ?float
    {
        if (! is_numeric($current) || ! is_numeric($baseline) || (float) $baseline === 0.0) {
            return null;
        }

        return round((((float) $current - (float) $baseline) / abs((float) $baseline)) * 100, 2);
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    public function summarize(array $rows, int $hours = 24, bool $sampled = false, ?int $totalRows = null): array
    {
        $groups = [];
        $instances = [];
        $kinds = [];

        foreach ($rows as $row) {
            $instanceId = $this->text($row['instance_id'] ?? null, 'unknown');
            $modelName = $this->text($row['model_name'] ?? null, 'unknown');
            $modelRole = $this->text($row['model_role'] ?? null, 'unassigned');
            $requestKind = $this->text($row['request_kind'] ?? null, 'chat');
            $key = implode('|', [$instanceId, $modelName, $modelRole, $requestKind]);

            $groups[$key] ??= $this->newBucket([
                'instance_id' => $instanceId,
                'model_name' => $modelName,
                'model_role' => $modelRole,
                'request_kind' => $requestKind,
            ]);
            $instances[$instanceId] ??= $this->newBucket(['instance_id' => $instanceId]);
            $kinds[$requestKind] ??= $this->newBucket(['request_kind' => $requestKind]);

            $this->addRow($groups[$key], $row);
            $this->addRow($instances[$instanceId], $row);
            $this->addRow($kinds[$requestKind], $row);
        }

        $routeStats = array_map(fn (array $bucket): array => $this->finishBucket($bucket), array_values($groups));
        $instanceStats = array_map(fn (array $bucket): array => $this->finishBucket($bucket), array_values($instances));
        $kindStats = array_map(fn (array $bucket): array => $this->finishBucket($bucket), array_values($kinds));
        $summary = $this->finishBucket($this->bucketFromRows($rows));

        usort($routeStats, static fn (array $a, array $b): int => $b['calls'] <=> $a['calls']);
        usort($instanceStats, static fn (array $a, array $b): int => $b['calls'] <=> $a['calls']);
        usort($kindStats, static fn (array $a, array $b): int => $b['calls'] <=> $a['calls']);

        return [
            'source' => 'llm_model_request_logs',
            'window_hours' => max(1, min(168, $hours)),
            'sampled' => $sampled,
            'sample_rows' => count($rows),
            'total_rows' => $totalRows ?? count($rows),
            'summary' => $summary,
            'routes' => $routeStats,
            'instances' => $instanceStats,
            'request_kinds' => $kindStats,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function bucketFromRows(array $rows): array
    {
        $bucket = $this->newBucket([]);
        foreach ($rows as $row) {
            $this->addRow($bucket, $row);
        }

        return $bucket;
    }

    /**
     * @param  array<string,mixed>  $dimensions
     * @return array<string,mixed>
     */
    private function newBucket(array $dimensions): array
    {
        return $dimensions + [
            'calls' => 0,
            'successes' => 0,
            'failures' => 0,
            'escalated' => 0,
            'latencies' => [],
            'tokens_per_second' => [],
            'quality_scores' => [],
            'usage_reported_calls' => 0,
            'model_reported_calls' => 0,
            'caller_reported_calls' => 0,
            'model_role_reported_calls' => 0,
            'request_kind_reported_calls' => 0,
            'latency_reported_calls' => 0,
            'route_trace_reported_calls' => 0,
            'input_tokens' => 0,
            'cached_input_tokens' => 0,
            'cache_write_input_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_output_tokens' => 0,
            'errors' => [],
            'last_used_at' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $bucket
     * @param  array<string,mixed>  $row
     */
    private function addRow(array &$bucket, array $row): void
    {
        $success = filter_var($row['success'] ?? false, FILTER_VALIDATE_BOOL);
        $bucket['calls']++;
        $bucket[$success ? 'successes' : 'failures']++;
        $bucket['escalated'] += filter_var($row['escalated'] ?? false, FILTER_VALIDATE_BOOL) ? 1 : 0;

        if (is_numeric($row['latency_ms'] ?? null)) {
            $bucket['latencies'][] = max(0, (int) $row['latency_ms']);
            $bucket['latency_reported_calls']++;
        }
        $bucket['model_reported_calls'] += trim((string) ($row['model_name'] ?? '')) !== '' ? 1 : 0;
        $bucket['caller_reported_calls'] += trim((string) ($row['caller'] ?? '')) !== '' ? 1 : 0;
        $bucket['model_role_reported_calls'] += trim((string) ($row['model_role'] ?? '')) !== '' ? 1 : 0;
        $bucket['request_kind_reported_calls'] += trim((string) ($row['request_kind'] ?? '')) !== '' ? 1 : 0;
        $traceId = trim((string) ($row['route_trace_id'] ?? ''));
        $attemptIndex = filter_var($row['route_attempt_index'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $bucket['route_trace_reported_calls'] += preg_match('/\A[0-9a-f]{32}\z/', $traceId) === 1 && $attemptIndex !== false ? 1 : 0;
        if (is_numeric($row['tokens_per_second'] ?? null)) {
            $bucket['tokens_per_second'][] = max(0.0, (float) $row['tokens_per_second']);
        }
        if (is_numeric($row['quality_score'] ?? null)) {
            $bucket['quality_scores'][] = max(0.0, min(1.0, (float) $row['quality_score']));
        }

        $usageReported = false;
        foreach ([
            'input_tokens',
            'cached_input_tokens',
            'cache_write_input_tokens',
            'output_tokens',
            'reasoning_output_tokens',
        ] as $field) {
            if (is_numeric($row[$field] ?? null) && (int) $row[$field] >= 0) {
                $bucket[$field] += (int) $row[$field];
                $usageReported = true;
            }
        }
        $bucket['usage_reported_calls'] += $usageReported ? 1 : 0;

        $error = trim((string) ($row['error_summary'] ?? ''));
        if (! $success && $error !== '') {
            $bucket['errors'][$error] = ($bucket['errors'][$error] ?? 0) + 1;
        }

        $createdAt = trim((string) ($row['created_at'] ?? ''));
        if ($createdAt !== '' && ($bucket['last_used_at'] === null || $createdAt > $bucket['last_used_at'])) {
            $bucket['last_used_at'] = $createdAt;
        }
    }

    /**
     * @param  array<string,mixed>  $bucket
     * @return array<string,mixed>
     */
    private function finishBucket(array $bucket): array
    {
        $latencies = $bucket['latencies'];
        sort($latencies, SORT_NUMERIC);
        $tokensPerSecond = $bucket['tokens_per_second'];
        $qualityScores = $bucket['quality_scores'];
        $errors = $bucket['errors'];
        arsort($errors, SORT_NUMERIC);

        unset($bucket['latencies'], $bucket['tokens_per_second'], $bucket['quality_scores'], $bucket['errors']);

        $calls = (int) $bucket['calls'];
        $failures = (int) $bucket['failures'];
        $bucket['success_rate'] = $calls > 0 ? round(((int) $bucket['successes'] / $calls) * 100, 2) : null;
        $bucket['failure_rate'] = $calls > 0 ? round(($failures / $calls) * 100, 2) : null;
        $bucket['avg_latency_ms'] = $latencies !== [] ? (int) round(array_sum($latencies) / count($latencies)) : null;
        $bucket['p50_latency_ms'] = $this->percentile($latencies, 0.50);
        $bucket['p95_latency_ms'] = $this->percentile($latencies, 0.95);
        $bucket['max_latency_ms'] = $latencies !== [] ? max($latencies) : null;
        $bucket['avg_tokens_per_second'] = $tokensPerSecond !== []
            ? round(array_sum($tokensPerSecond) / count($tokensPerSecond), 2)
            : null;
        $bucket['avg_quality_score'] = $qualityScores !== []
            ? round(array_sum($qualityScores) / count($qualityScores), 4)
            : null;
        $bucket['usage_coverage_rate'] = $calls > 0
            ? round(((int) $bucket['usage_reported_calls'] / $calls) * 100, 2)
            : null;
        foreach ([
            'model', 'caller', 'model_role', 'request_kind', 'latency', 'route_trace',
        ] as $dimension) {
            $bucket[$dimension.'_coverage_rate'] = $calls > 0
                ? round(((int) $bucket[$dimension.'_reported_calls'] / $calls) * 100, 2)
                : null;
        }
        $bucket['cached_input_ratio'] = (int) $bucket['input_tokens'] > 0
            ? round(((int) $bucket['cached_input_tokens'] / (int) $bucket['input_tokens']) * 100, 2)
            : null;
        $bucket['top_error'] = $errors !== [] ? (string) array_key_first($errors) : null;
        $bucket['health_state'] = $this->healthState($calls, $failures, $bucket['avg_latency_ms']);

        return $bucket;
    }

    /**
     * @param  list<int>  $values
     */
    private function percentile(array $values, float $percentile): ?int
    {
        if ($values === []) {
            return null;
        }

        $index = max(0, (int) ceil(count($values) * $percentile) - 1);

        return $values[min($index, count($values) - 1)];
    }

    private function healthState(int $calls, int $failures, ?int $avgLatencyMs): string
    {
        if ($calls >= 5 && $failures / $calls >= 0.25) {
            return 'failing';
        }
        if ($calls >= 5 && ($failures / $calls >= 0.10 || ($avgLatencyMs ?? 0) >= 30000)) {
            return 'degraded';
        }

        return $calls > 0 ? 'healthy' : 'idle';
    }

    private function text(mixed $value, string $fallback): string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? $fallback : $text;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptySnapshot(int $hours, string $reason): array
    {
        $currentEnd = now();
        $currentStart = $currentEnd->copy()->subHours($hours);
        $baselineStart = $currentStart->copy()->subHours($hours);

        return [
            'source' => 'llm_model_request_logs',
            'window_hours' => $hours,
            'sampled' => false,
            'sample_rows' => 0,
            'total_rows' => 0,
            'unavailable_reason' => $reason,
            'summary' => $this->finishBucket($this->newBucket([])),
            'routes' => [],
            'instances' => [],
            'request_kinds' => [],
            'comparison' => $this->compareWindows(
                [],
                [],
                $hours,
                $currentStart,
                $currentEnd,
                $baselineStart,
            ),
        ];
    }
}
