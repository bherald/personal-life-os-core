<?php

namespace App\Services\Ops;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

class CapacityReportService
{
    private const OBSERVE_THRESHOLDS = [
        'cpu_user_percent' => ['operator' => '<=', 'value' => 50],
        'cpu_wait_percent' => ['operator' => '<=', 'value' => 12],
        'memory_available_mb' => ['operator' => '>=', 'value' => 4000],
        'swap_used_mb' => ['operator' => '<=', 'value' => 18796],
        'blocks_out_per_s' => ['operator' => '<=', 'value' => 7052],
        'gpu_memory_used_mb' => ['operator' => '<=', 'value' => 4284],
        'gpu_utilization_percent' => ['operator' => '<=', 'value' => 100],
        'running_scheduled_jobs' => ['operator' => '<=', 'value' => 5],
        'queue_depth_total' => ['operator' => '<=', 'value' => 1],
    ];

    /**
     * @return array<string, mixed>
     */
    public function buildReport(): array
    {
        $captures = $this->loadCaptures();
        $scenarios = [];

        foreach (['idle', 'jobs', 'deploy'] as $scenario) {
            $scenarioCaptures = array_values(array_filter(
                $captures,
                static fn (array $capture): bool => ($capture['scenario'] ?? null) === $scenario
            ));

            usort(
                $scenarioCaptures,
                static fn (array $left, array $right): int => strcmp(
                    (string) ($right['captured_at'] ?? ''),
                    (string) ($left['captured_at'] ?? '')
                )
            );

            $latest = $scenarioCaptures[0] ?? null;
            $heavyWindowCaptures = $scenario === 'jobs'
                ? $this->countHeavyWindowCaptures($scenarioCaptures)
                : count($scenarioCaptures);

            $scenarios[$scenario] = [
                'captures' => count($scenarioCaptures),
                'latest_captured_at' => $latest['captured_at'] ?? null,
                'heavy_window_captures' => $heavyWindowCaptures,
                'has_heavy_window_capture' => $heavyWindowCaptures > 0,
                'latest_metrics' => $latest !== null ? $this->extractMetrics($latest) : [],
            ];
        }

        $warnings = $this->buildWarnings($scenarios);
        $enforcementReady = $scenarios['idle']['captures'] >= 3
            && $scenarios['jobs']['captures'] >= 3
            && $scenarios['jobs']['heavy_window_captures'] >= 3
            && $scenarios['deploy']['captures'] >= 3
            && $warnings === [];

        return [
            'version' => 1,
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'status' => $warnings === [] ? 'observe_ok' : 'observe_warning',
            'enforcement_ready' => $enforcementReady,
            'thresholds' => self::OBSERVE_THRESHOLDS,
            'scenarios' => $scenarios,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadCaptures(): array
    {
        $directory = storage_path('logs/host-baselines');
        $captures = [];

        foreach (File::glob($directory.'/*.json') ?: [] as $path) {
            $decoded = json_decode((string) File::get($path), true);
            if (! is_array($decoded)) {
                continue;
            }

            $decoded['path'] = $path;
            $captures[] = $decoded;
        }

        return $captures;
    }

    /**
     * @param  list<array<string, mixed>>  $captures
     */
    private function countHeavyWindowCaptures(array $captures): int
    {
        $count = 0;

        foreach ($captures as $capture) {
            $capturedAt = $capture['captured_at'] ?? null;
            if (! is_string($capturedAt) || $capturedAt === '') {
                continue;
            }

            $local = Carbon::parse($capturedAt)->setTimezone('America/New_York');
            $minutes = ($local->hour * 60) + $local->minute;

            if ($minutes >= 240 && $minutes < 340) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $capture
     * @return array<string, mixed>
     */
    private function extractMetrics(array $capture): array
    {
        $gpu = $capture['gpu'][0] ?? [];
        $queueDepths = $capture['app']['queue_depths'] ?? [];

        return [
            'cpu_user_percent' => $capture['vmstat']['cpu_user_percent'] ?? null,
            'cpu_wait_percent' => $capture['vmstat']['cpu_wait_percent'] ?? null,
            'memory_available_mb' => $capture['memory_mb']['available'] ?? null,
            'swap_used_mb' => $capture['memory_mb']['swap_used'] ?? null,
            'blocks_out_per_s' => $capture['vmstat']['blocks_out_per_s'] ?? null,
            'gpu_memory_used_mb' => $gpu['memory_used_mb'] ?? null,
            'gpu_utilization_percent' => $gpu['utilization_gpu_percent'] ?? null,
            'running_scheduled_jobs' => count((array) ($capture['app']['running_jobs'] ?? [])),
            'queue_depth_total' => array_sum(array_map('intval', (array) $queueDepths)),
            'queue_depths' => $queueDepths,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $scenarios
     * @return list<string>
     */
    private function buildWarnings(array $scenarios): array
    {
        $warnings = [];

        if (($scenarios['jobs']['captures'] ?? 0) < 3 || ($scenarios['jobs']['heavy_window_captures'] ?? 0) < 3) {
            $warnings[] = 'jobs baseline is not enforcement-ready: capture 3 samples during the 4:00-5:39 AM heavy window';
        }

        foreach ($scenarios as $scenario => $summary) {
            $metrics = $summary['latest_metrics'] ?? [];

            foreach (self::OBSERVE_THRESHOLDS as $metric => $threshold) {
                if (! array_key_exists($metric, $metrics) || $metrics[$metric] === null) {
                    continue;
                }

                $value = (float) $metrics[$metric];
                $limit = (float) $threshold['value'];
                $operator = $threshold['operator'];
                $ok = $operator === '>=' ? $value >= $limit : $value <= $limit;

                if (! $ok) {
                    $warnings[] = sprintf(
                        '%s latest %s=%s violates observe threshold %s %s',
                        $scenario,
                        $metric,
                        $metrics[$metric],
                        $operator,
                        $threshold['value']
                    );
                }
            }
        }

        return $warnings;
    }
}
