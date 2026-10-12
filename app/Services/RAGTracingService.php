<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class RAGTracingService
{
    public const TRACE_SCHEMA = 'plos.rag_query_trace.v2';

    public const LEGACY_SCHEMA = 'plos.rag_query_trace.legacy.v1';

    public const REPORT_SCHEMA = 'plos.rag_trace_phase_report.v1';

    public const FINGERPRINT_PREFIX = 'hmac-sha256:';

    private const PHASES = [
        'embedding',
        'vector',
        'hybrid',
        'rerank',
        'mmr',
        'temporal_decay',
        'hyde',
    ];

    /**
     * Measure one fixed retrieval phase without retaining callback input/output.
     *
     * @param  array<string, int>  $phases
     */
    public function measure(string $phase, callable $callback, array &$phases): mixed
    {
        $phase = in_array($phase, self::PHASES, true) ? $phase : 'unknown';
        $startedAt = hrtime(true);

        try {
            return $callback();
        } finally {
            $phases[$phase] = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        }
    }

    /**
     * Write one complete content-free retrieval receipt.
     *
     * @param  array<string, int>  $phases
     */
    public function recordSearch(
        string $query,
        string $strategy,
        array $phases,
        int $totalTimeMs,
        int $resultCount,
        ?float $topSimilarity,
        bool $hydeUsed,
        bool $raptorUsed,
        bool $documentTypeFilter,
        bool $agentScopeFilter,
        string $outcome = 'success',
        ?string $failurePhase = null,
    ): void {
        $phases = $this->normalizePhases($phases);
        $outcome = $outcome === 'success' ? 'success' : 'failure';
        $failurePhase = in_array($failurePhase, self::PHASES, true) ? $failurePhase : null;
        $retrievalMs = (int) array_sum(array_intersect_key($phases, array_flip(['vector', 'hybrid', 'hyde'])));
        $rerankMs = (int) array_sum(array_intersect_key($phases, array_flip(['rerank', 'mmr', 'temporal_decay'])));

        DB::connection('pgsql_rag')->insert(
            'INSERT INTO rag_query_traces
                (query_text, strategy_used, retrieval_time_ms, rerank_time_ms, total_time_ms,
                 result_count, top_similarity, hyde_used, raptor_used, filters_applied,
                 metadata, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, NOW())',
            [
                $this->queryFingerprint($query),
                $outcome === 'success'
                    ? $this->normalizeStrategy($strategy)
                    : $this->normalizeStrategy('error_'.($failurePhase ?? 'unknown')),
                $retrievalMs,
                $rerankMs,
                max(0, $totalTimeMs),
                max(0, $resultCount),
                $topSimilarity,
                $hydeUsed,
                $raptorUsed,
                json_encode([
                    'document_type' => $documentTypeFilter,
                    'agent_scope' => $agentScopeFilter,
                ], JSON_THROW_ON_ERROR),
                json_encode([
                    'schema' => self::TRACE_SCHEMA,
                    'content_free' => true,
                    'query_text_included' => false,
                    'documents_included' => false,
                    'filter_values_included' => false,
                    'exception_text_included' => false,
                    'fingerprint_algorithm' => 'hmac-sha256',
                    'outcome' => $outcome,
                    'failure_phase' => $failurePhase,
                    'phase_durations_ms' => $phases,
                ], JSON_THROW_ON_ERROR),
            ]
        );
    }

    public function queryFingerprint(string $query): string
    {
        $appKey = (string) config('app.key');
        if ($appKey === '') {
            throw new \RuntimeException('APP_KEY is required for RAG trace fingerprinting.');
        }

        $key = hash('sha256', 'plos-rag-query-trace-v1|'.$appKey, true);

        return self::FINGERPRINT_PREFIX.hash_hmac('sha256', $query, $key);
    }

    /**
     * Replace legacy raw queries and metadata with keyed, content-free receipts.
     *
     * @return array<string, int|string|bool>
     */
    public function sanitizeLegacyQueries(int $limit, bool $execute, bool $confirmed, int $batchSize): array
    {
        $policy = app(RagTraceHygieneRuntimePolicyService::class)->required();
        if ($limit < 1 || $limit > $policy['max_rows'] || $batchSize !== $policy['batch_size']) {
            throw new \RuntimeException('RAG trace hygiene received invalid runtime policy values.');
        }

        $connection = DB::connection('pgsql_rag');
        $eligible = (int) ($connection->selectOne(
            'SELECT COUNT(*)::int AS count FROM rag_query_traces WHERE query_text NOT LIKE ?',
            [self::FINGERPRINT_PREFIX.'%']
        )->count ?? 0);

        $payload = [
            'schema' => 'plos.rag_trace_hygiene.v1',
            'status' => $execute ? 'ready' : 'preview',
            'dry_run' => ! $execute,
            'eligible' => $eligible,
            'selected' => min($eligible, $limit),
            'sanitized' => 0,
            'remaining' => $eligible,
            'content_free' => true,
        ];

        if (! $execute) {
            return $payload;
        }
        if (! $confirmed) {
            $payload['status'] = 'confirmation_required';

            return $payload;
        }

        $rows = $connection->select(
            'SELECT id, query_text FROM rag_query_traces
              WHERE query_text NOT LIKE ? ORDER BY id ASC LIMIT ?',
            [self::FINGERPRINT_PREFIX.'%', $limit]
        );

        foreach (array_chunk($rows, $batchSize) as $batch) {
            $values = [];
            $bindings = [];
            foreach ($batch as $row) {
                $values[] = '(?, ?)';
                $bindings[] = (int) $row->id;
                $bindings[] = $this->queryFingerprint((string) $row->query_text);
            }

            $payload['sanitized'] += $connection->update(
                'UPDATE rag_query_traces AS target
                    SET query_text = source.fingerprint::text,
                        strategy_used = COALESCE(target.strategy_used, ?),
                        filters_applied = NULL,
                        metadata = ?::jsonb
                   FROM (VALUES '.implode(', ', $values).') AS source(id, fingerprint)
                  WHERE target.id = source.id::bigint',
                array_merge([
                    'legacy_incomplete',
                    json_encode([
                        'schema' => self::LEGACY_SCHEMA,
                        'content_free' => true,
                        'query_text_included' => false,
                        'documents_included' => false,
                        'filter_values_included' => false,
                        'outcome' => 'legacy_incomplete',
                    ], JSON_THROW_ON_ERROR),
                ], $bindings)
            );
        }

        $payload['remaining'] = max(0, $eligible - (int) $payload['sanitized']);
        $payload['status'] = $payload['remaining'] === 0 ? 'complete' : 'partial';

        return $payload;
    }

    /** @return array<string, mixed> */
    public function phaseReport(int $hours = 24, int $limit = 5000): array
    {
        $hours = max(1, min($hours, (int) config('rag.trace_report.max_hours', 168)));
        $limit = max(1, min($limit, (int) config('rag.trace_report.max_rows', 10000)));

        try {
            $rows = DB::connection('pgsql_rag')->select(
                'SELECT strategy_used, retrieval_time_ms, rerank_time_ms, total_time_ms,
                        result_count, top_similarity, metadata, created_at
                   FROM rag_query_traces
                  WHERE created_at >= ?
                  ORDER BY created_at DESC LIMIT ?',
                [now()->subHours($hours), $limit]
            );
        } catch (\Throwable) {
            $report = $this->summarizePhaseRows([], $hours, $limit);
            $report['status'] = 'unavailable';

            return $report;
        }

        return $this->summarizePhaseRows($rows, $hours, $limit);
    }

    /**
     * @param  array<int, object|array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function summarizePhaseRows(array $rows, int $hours = 24, int $limit = 5000): array
    {
        $valid = 0;
        $legacy = 0;
        $failures = 0;
        $totals = [];
        $phases = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $metadata = $this->decodeMetadata($row['metadata'] ?? null);
            if (($metadata['schema'] ?? null) !== self::TRACE_SCHEMA) {
                $legacy++;

                continue;
            }

            $valid++;
            $failures += ($metadata['outcome'] ?? null) === 'failure' ? 1 : 0;
            $totals[] = (float) ($row['total_time_ms'] ?? 0);
            foreach ((array) ($metadata['phase_durations_ms'] ?? []) as $phase => $duration) {
                if (! in_array($phase, self::PHASES, true) || ! is_numeric($duration)) {
                    continue;
                }
                $phases[$phase][] = max(0.0, (float) $duration);
            }
        }

        ksort($phases);
        $phaseSummary = [];
        foreach ($phases as $phase => $durations) {
            $phaseSummary[$phase] = [
                'observations' => count($durations),
                'avg_ms' => $this->average($durations),
                'p95_ms' => $this->percentile($durations, 0.95),
            ];
        }

        return [
            'schema' => self::REPORT_SCHEMA,
            'status' => $valid === 0
                ? ($rows === [] ? 'no_data' : 'legacy_only')
                : (($failures > 0 || $legacy > 0) ? 'observe_warning' : 'observe_ok'),
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'window_hours' => $hours,
            'row_limit' => $limit,
            'summary' => [
                'receipts' => count($rows),
                'valid_receipts' => $valid,
                'legacy_incomplete_receipts' => $legacy,
                'failed_receipts' => $failures,
                'avg_total_ms' => $this->average($totals),
                'p95_total_ms' => $this->percentile($totals, 0.95),
            ],
            'phases' => $phaseSummary,
            'posture' => [
                'read_only' => true,
                'content_free' => true,
                'query_text_included' => false,
                'documents_included' => false,
                'filter_values_included' => false,
                'exception_text_included' => false,
            ],
        ];
    }

    public function getTrace(int $traceId): ?object
    {
        return DB::connection('pgsql_rag')->selectOne(
            "SELECT id,
                    CASE WHEN query_text LIKE 'hmac-sha256:%' THEN query_text ELSE 'legacy-unsanitized' END AS query_fingerprint,
                    strategy_used, retrieval_time_ms,
                    rerank_time_ms, total_time_ms, result_count, top_similarity, hyde_used,
                    raptor_used, COALESCE(filters_applied, '{}'::jsonb) <> '{}'::jsonb AS filters_used,
                    created_at
               FROM rag_query_traces WHERE id = ?",
            [$traceId]
        ) ?: null;
    }

    public function getQueryStats(?string $startDate = null, ?string $endDate = null): array
    {
        $params = [];
        $where = '';
        if ($startDate && $endDate) {
            $where = 'WHERE created_at BETWEEN ? AND ?';
            $params = [$startDate, $endDate];
        }

        $stats = DB::connection('pgsql_rag')->selectOne(
            "SELECT COUNT(*) as total_queries,
                    COUNT(total_time_ms) as completed_queries,
                    AVG(total_time_ms) as avg_latency_ms,
                    PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY total_time_ms) as p95_latency_ms,
                    AVG(result_count) as avg_results,
                    AVG(top_similarity) as avg_top_similarity
               FROM rag_query_traces {$where}",
            $params
        );

        $strategyDist = DB::connection('pgsql_rag')->select(
            "SELECT strategy_used, COUNT(*) as count
               FROM rag_query_traces {$where}
              GROUP BY strategy_used ORDER BY count DESC",
            $params
        );

        return [
            'total_queries' => $stats->total_queries ?? 0,
            'completed_queries' => $stats->completed_queries ?? 0,
            'avg_latency_ms' => round($stats->avg_latency_ms ?? 0, 1),
            'p95_latency_ms' => round($stats->p95_latency_ms ?? 0, 1),
            'avg_results' => round($stats->avg_results ?? 0, 1),
            'avg_top_similarity' => round($stats->avg_top_similarity ?? 0, 4),
            'strategy_distribution' => $strategyDist,
        ];
    }

    public function getSlowQueries(int $thresholdMs = 5000, int $limit = 20): array
    {
        return DB::connection('pgsql_rag')->select(
            "SELECT id,
                    CASE WHEN query_text LIKE 'hmac-sha256:%' THEN query_text ELSE 'legacy-unsanitized' END AS query_fingerprint,
                    strategy_used, total_time_ms,
                    result_count, top_similarity, created_at
               FROM rag_query_traces
              WHERE total_time_ms > ?
              ORDER BY total_time_ms DESC LIMIT ?",
            [$thresholdMs, $limit]
        );
    }

    public function getRecentTraces(int $limit = 20): array
    {
        return DB::connection('pgsql_rag')->select(
            "SELECT id,
                    CASE WHEN query_text LIKE 'hmac-sha256:%' THEN query_text ELSE 'legacy-unsanitized' END AS query_fingerprint,
                    strategy_used, total_time_ms,
                    result_count, top_similarity, created_at
               FROM rag_query_traces ORDER BY created_at DESC LIMIT ?",
            [$limit]
        );
    }

    /** @param array<string, int|float> $phases */
    private function normalizePhases(array $phases): array
    {
        $normalized = [];
        foreach ($phases as $phase => $duration) {
            if (in_array($phase, self::PHASES, true)) {
                $normalized[$phase] = max(0, (int) round((float) $duration));
            }
        }

        return $normalized;
    }

    private function normalizeStrategy(string $strategy): string
    {
        $strategy = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $strategy) ?? 'unknown');

        return mb_substr(trim($strategy, '_') ?: 'unknown', 0, 50);
    }

    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }
        if (! is_string($metadata) || $metadata === '') {
            return [];
        }

        try {
            $decoded = json_decode($metadata, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    /** @param array<int, float|int> $values */
    private function average(array $values): float
    {
        return $values === [] ? 0.0 : round(array_sum($values) / count($values), 3);
    }

    /** @param array<int, float|int> $values */
    private function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values, SORT_NUMERIC);

        return round((float) $values[max(0, (int) ceil(count($values) * $percentile) - 1)], 3);
    }
}
