<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * RAG Query Tracing Service
 *
 * Records performance traces for RAG queries to enable
 * latency analysis, strategy optimization, and debugging.
 */
class RAGTracingService
{
    public function startTrace(string $query): int
    {
        DB::connection('pgsql_rag')->insert(
            "INSERT INTO rag_query_traces (query_text, created_at) VALUES (?, NOW())",
            [$query]
        );

        $result = DB::connection('pgsql_rag')->selectOne(
            "SELECT currval(pg_get_serial_sequence('rag_query_traces', 'id')) as id"
        );

        return (int) $result->id;
    }

    public function recordStep(int $traceId, string $stepName, int $durationMs, ?array $metadata = null): void
    {
        $existing = DB::connection('pgsql_rag')->selectOne(
            "SELECT metadata FROM rag_query_traces WHERE id = ?",
            [$traceId]
        );

        $currentMeta = $existing ? json_decode($existing->metadata ?? '{}', true) : [];
        $currentMeta['steps'] = $currentMeta['steps'] ?? [];
        $currentMeta['steps'][] = [
            'name' => $stepName,
            'duration_ms' => $durationMs,
            'data' => $metadata,
            'timestamp' => now()->toIso8601String(),
        ];

        DB::connection('pgsql_rag')->update(
            "UPDATE rag_query_traces SET metadata = ?::jsonb WHERE id = ?",
            [json_encode($currentMeta), $traceId]
        );
    }

    public function endTrace(int $traceId, int $resultCount, ?float $topSimilarity = null, ?string $strategy = null, ?array $extras = null): void
    {
        $sets = ['result_count = ?', 'top_similarity = ?'];
        $params = [$resultCount, $topSimilarity];

        if ($strategy) {
            $sets[] = 'strategy_used = ?';
            $params[] = $strategy;
        }

        // Calculate total time from metadata steps
        $existing = DB::connection('pgsql_rag')->selectOne(
            "SELECT metadata FROM rag_query_traces WHERE id = ?",
            [$traceId]
        );
        $meta = json_decode($existing->metadata ?? '{}', true);
        $steps = $meta['steps'] ?? [];
        $totalMs = array_sum(array_column($steps, 'duration_ms'));

        $sets[] = 'total_time_ms = ?';
        $params[] = $totalMs;

        if ($extras) {
            if (isset($extras['hyde_used'])) {
                $sets[] = 'hyde_used = ?';
                $params[] = $extras['hyde_used'];
            }
            if (isset($extras['raptor_used'])) {
                $sets[] = 'raptor_used = ?';
                $params[] = $extras['raptor_used'];
            }
            if (isset($extras['retrieval_time_ms'])) {
                $sets[] = 'retrieval_time_ms = ?';
                $params[] = $extras['retrieval_time_ms'];
            }
            if (isset($extras['rerank_time_ms'])) {
                $sets[] = 'rerank_time_ms = ?';
                $params[] = $extras['rerank_time_ms'];
            }
            if (isset($extras['filters'])) {
                $sets[] = 'filters_applied = ?::jsonb';
                $params[] = json_encode($extras['filters']);
            }
        }

        $params[] = $traceId;
        $setClause = implode(', ', $sets);

        DB::connection('pgsql_rag')->update(
            "UPDATE rag_query_traces SET {$setClause} WHERE id = ?",
            $params
        );
    }

    public function getTrace(int $traceId): ?object
    {
        return DB::connection('pgsql_rag')->selectOne(
            "SELECT * FROM rag_query_traces WHERE id = ?",
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
            "SELECT
                COUNT(*) as total_queries,
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
            "SELECT id, query_text, strategy_used, total_time_ms, result_count, top_similarity, created_at
             FROM rag_query_traces
             WHERE total_time_ms > ?
             ORDER BY total_time_ms DESC
             LIMIT ?",
            [$thresholdMs, $limit]
        );
    }

    public function getRecentTraces(int $limit = 20): array
    {
        return DB::connection('pgsql_rag')->select(
            "SELECT id, LEFT(query_text, 80) as query_preview, strategy_used, total_time_ms, result_count, top_similarity, created_at
             FROM rag_query_traces
             ORDER BY created_at DESC
             LIMIT ?",
            [$limit]
        );
    }
}
