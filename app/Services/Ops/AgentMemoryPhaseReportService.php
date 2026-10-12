<?php

namespace App\Services\Ops;

use App\Services\AgentMemoryPhaseTelemetryService;
use Illuminate\Support\Facades\DB;

class AgentMemoryPhaseReportService
{
    public const SCHEMA = 'ops_agent_memory_phase_report.v1';

    public function collect(int $hours = 24, int $limit = 1000): array
    {
        $hours = max(1, min($hours, (int) config('agent_memory.telemetry.report_max_hours', 168)));
        $limit = max(1, min($limit, (int) config('agent_memory.telemetry.report_max_rows', 5000)));

        try {
            $rows = DB::table('agent_execution_log')
                ->select(['duration_ms', 'outcome', 'context', 'created_at'])
                ->where('action_type', 'memory_retrieval')
                ->where('action_detail', 'prompt_memory_bundle')
                ->where('created_at', '>=', now()->subHours($hours))
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->all();
        } catch (\Throwable) {
            return $this->emptyReport($hours, $limit, 'unavailable');
        }

        return $this->summarize($rows, $hours, $limit);
    }

    /**
     * @param  array<int, object|array<string, mixed>>  $rows
     */
    public function summarize(array $rows, int $hours = 24, int $limit = 1000): array
    {
        $sources = [];
        $receiptDurations = [];
        $validReceipts = 0;
        $invalidReceipts = 0;
        $phaseFailures = 0;
        $contextChars = 0;

        foreach ($rows as $row) {
            $row = (array) $row;
            $context = $this->decodeContext($row['context'] ?? null);
            if (($context['schema'] ?? null) !== AgentMemoryPhaseTelemetryService::SCHEMA
                || ! is_array($context['phases'] ?? null)) {
                $invalidReceipts++;

                continue;
            }

            $validReceipts++;
            $receiptDurations[] = (float) ($row['duration_ms'] ?? 0.0);
            $phaseFailures += (int) ($context['phase_failures'] ?? 0);
            $contextChars += (int) ($context['context_chars'] ?? 0);

            foreach ($context['phases'] as $phase) {
                if (! is_array($phase)) {
                    continue;
                }

                $source = (string) ($phase['source'] ?? 'unknown');
                $sources[$source] ??= [
                    'observations' => 0,
                    'attempted' => 0,
                    'included' => 0,
                    'failures' => 0,
                    'duration_ms' => [],
                    'context_chars' => 0,
                ];
                $sources[$source]['observations']++;
                $sources[$source]['attempted'] += (bool) ($phase['attempted'] ?? false) ? 1 : 0;
                $sources[$source]['included'] += (bool) ($phase['included'] ?? false) ? 1 : 0;
                $sources[$source]['failures'] += ($phase['outcome'] ?? null) === 'failure' ? 1 : 0;
                $sources[$source]['duration_ms'][] = (float) ($phase['duration_ms'] ?? 0.0);
                $sources[$source]['context_chars'] += (int) ($phase['context_chars'] ?? 0);
            }
        }

        ksort($sources);
        foreach ($sources as &$source) {
            $durations = $source['duration_ms'];
            $source['avg_duration_ms'] = $this->average($durations);
            $source['p95_duration_ms'] = $this->percentile($durations, 0.95);
            unset($source['duration_ms']);
        }
        unset($source);

        $status = $validReceipts === 0
            ? 'no_data'
            : (($phaseFailures > 0 || $invalidReceipts > 0) ? 'observe_warning' : 'observe_ok');

        return [
            'schema' => self::SCHEMA,
            'status' => $status,
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'window_hours' => $hours,
            'row_limit' => $limit,
            'summary' => [
                'receipts' => count($rows),
                'valid_receipts' => $validReceipts,
                'invalid_receipts' => $invalidReceipts,
                'phase_failures' => $phaseFailures,
                'context_chars' => $contextChars,
                'avg_retrieval_ms' => $this->average($receiptDurations),
                'p95_retrieval_ms' => $this->percentile($receiptDurations, 0.95),
            ],
            'sources' => $sources,
            'posture' => [
                'read_only' => true,
                'content_free' => true,
                'memory_text_included' => false,
                'task_text_included' => false,
                'prompt_text_included' => false,
                'compression_applied' => false,
            ],
        ];
    }

    private function emptyReport(int $hours, int $limit, string $status): array
    {
        $report = $this->summarize([], $hours, $limit);
        $report['status'] = $status;

        return $report;
    }

    private function decodeContext(mixed $context): array
    {
        if (is_array($context)) {
            return $context;
        }

        if (! is_string($context) || $context === '') {
            return [];
        }

        try {
            $decoded = json_decode($context, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    /** @param array<int, float> $values */
    private function average(array $values): float
    {
        return $values === [] ? 0.0 : round(array_sum($values) / count($values), 3);
    }

    /** @param array<int, float> $values */
    private function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values, SORT_NUMERIC);
        $index = max(0, (int) ceil(count($values) * $percentile) - 1);

        return round((float) $values[$index], 3);
    }
}
