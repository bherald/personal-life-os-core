<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RagBacklogService
{
    private const CONNECTION = 'pgsql_rag';

    private const KG_MIN_CHARS = 50;

    private const THROUGHPUT_LOOKBACK_DAYS = 7;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $evidenceErrors = [];

    /**
     * Mirror the default non-force builder semantics used by the scheduled
     * RAPTOR, sentence embedding, and KG jobs so reporting stays truthful.
     */
    public function getDigestMetrics(): array
    {
        $this->evidenceErrors = [];

        $raptorPending = $this->countRaptorPending();
        $sentencePending = $this->countSentencePending();
        $kgFresh = $this->countKgFreshPending();
        $kgStale = $this->countKgStalePending();
        $kgPending = $kgFresh + $kgStale;
        $raptorThroughput = $this->estimateDailyThroughput(['raptor_build']);
        $sentenceThroughput = $this->estimateDailyThroughput(['rag_sentence_indexing']);
        $kgThroughput = $this->estimateDailyThroughput(['knowledge_graph_build', 'knowledge_graph_catchup']);

        return [
            'documents' => $this->countDocuments(),
            'raptor' => [
                'pending' => $raptorPending,
                'throughput_per_day' => $raptorThroughput,
                'eta_days' => $this->estimateEtaDays($raptorPending, $raptorThroughput),
            ],
            'sentence' => [
                'pending' => $sentencePending,
                'throughput_per_day' => $sentenceThroughput,
                'eta_days' => $this->estimateEtaDays($sentencePending, $sentenceThroughput),
            ],
            'kg' => [
                'fresh' => $kgFresh,
                'stale' => $kgStale,
                'pending' => $kgPending,
                'entities' => $this->countKgEntities(),
                'throughput_per_day' => $kgThroughput,
                'eta_days' => $this->estimateEtaDays($kgPending, $kgThroughput),
            ],
            'evidence_errors' => $this->evidenceErrors,
        ];
    }

    public function getNetBurn(int $days = 7): array
    {
        $days = max(1, min($days, 30));
        $lanes = ['kg_fresh', 'kg_stale', 'raptor', 'sentence'];
        $placeholders = implode(', ', array_fill(0, count($lanes), '?'));

        try {
            $rows = DB::select(
                "SELECT pipeline, snapshot_date, pending, delta_from_prev
                 FROM pipeline_metrics_snapshots
                 WHERE pipeline IN ({$placeholders})
                   AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
                 ORDER BY pipeline, snapshot_date",
                $lanes
            );
        } catch (\Throwable $e) {
            Log::warning('RagBacklogService: net-burn snapshot query failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'window_days' => $days,
                'lanes' => $this->emptyNetBurnLanes(),
                'evidence_errors' => [[
                    'code' => 'net_burn_query_failed',
                    'context' => [
                        'error' => $this->compactMessage($e->getMessage()),
                    ],
                ]],
            ];
        }

        $grouped = array_fill_keys($lanes, []);
        foreach ($rows as $row) {
            $pipeline = (string) ($row->pipeline ?? '');
            if (array_key_exists($pipeline, $grouped)) {
                $grouped[$pipeline][] = $row;
            }
        }

        $summaries = [];
        foreach ($grouped as $lane => $laneRows) {
            $summaries[$lane] = $this->summarizeNetBurnRows($laneRows);
        }
        $summaries['kg'] = $this->summarizeCombinedNetBurnRows([
            ...$grouped['kg_fresh'],
            ...$grouped['kg_stale'],
        ]);

        return [
            'window_days' => $days,
            'lanes' => $summaries,
            'evidence_errors' => [],
        ];
    }

    private function countDocuments(): int
    {
        return $this->selectCount('SELECT COUNT(*) as c FROM rag_documents');
    }

    private function countRaptorPending(): int
    {
        return $this->selectCount(
            'SELECT COUNT(*) as c
             FROM rag_documents
             WHERE parent_id IS NULL
               AND raptor_indexed_at IS NULL
               AND COALESCE(raptor_error_count, 0) < 3
               AND raptor_eligible = 1'
        );
    }

    private function countSentencePending(): int
    {
        return $this->selectCount(
            "SELECT COUNT(*) as c
             FROM rag_documents
             WHERE sentence_indexed_at IS NULL
               AND (embedding_mode IS NULL OR embedding_mode = 'chunk')
               AND se_eligible = 1"
        );
    }

    private function countKgFreshPending(): int
    {
        return $this->selectCount(
            'SELECT COUNT(*) as c
             FROM rag_documents
             WHERE kg_extracted_at IS NULL
               AND LENGTH(content) >= ?',
            [self::KG_MIN_CHARS]
        );
    }

    private function countKgStalePending(): int
    {
        return $this->selectCount(
            'SELECT COUNT(*) as c
             FROM rag_documents
             WHERE kg_extracted_at IS NOT NULL
               AND content_hash IS NOT NULL
               AND content_hash IS DISTINCT FROM kg_content_hash
               AND LENGTH(content) >= ?',
            [self::KG_MIN_CHARS]
        );
    }

    private function countKgEntities(): int
    {
        return $this->selectCount('SELECT COUNT(*) as c FROM knowledge_graph_entities');
    }

    private function estimateDailyThroughput(array $jobNames): int
    {
        if (empty($jobNames)) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($jobNames), '?'));

        try {
            $result = DB::selectOne(
                sprintf(
                    'SELECT COALESCE(SUM(sjr.items_processed), 0) AS items
                     FROM scheduled_job_runs sjr
                     JOIN scheduled_jobs sj ON sj.id = sjr.scheduled_job_id
                     WHERE sj.name IN (%s)
                       AND sjr.status = ?
                       AND sjr.started_at >= NOW() - INTERVAL %d DAY',
                    $placeholders,
                    self::THROUGHPUT_LOOKBACK_DAYS
                ),
                [...$jobNames, 'success']
            );

            $items = (int) ($result?->items ?? 0);

            return (int) round($items / self::THROUGHPUT_LOOKBACK_DAYS);
        } catch (\Throwable $e) {
            Log::warning('RagBacklogService: throughput query failed', [
                'jobs' => $jobNames,
                'error' => $e->getMessage(),
            ]);
            $this->recordEvidenceError('throughput_query_failed', [
                'jobs' => $jobNames,
                'error' => $this->compactMessage($e->getMessage()),
            ]);

            return 0;
        }
    }

    private function estimateEtaDays(int $pending, int $throughputPerDay): ?float
    {
        if ($pending <= 0 || $throughputPerDay <= 0) {
            return null;
        }

        return round($pending / $throughputPerDay, 1);
    }

    private function summarizeNetBurnRows(array $rows): array
    {
        usort($rows, fn (object $a, object $b): int => strcmp((string) $a->snapshot_date, (string) $b->snapshot_date));

        $points = count($rows);
        $latest = $points > 0 ? $rows[$points - 1] : null;
        $deltas = [];
        foreach ($rows as $row) {
            if ($row->delta_from_prev !== null) {
                $deltas[] = (int) $row->delta_from_prev;
            }
        }

        return $this->summarizeNetBurnValues(
            $points,
            $latest ? (int) $latest->pending : null,
            $deltas
        );
    }

    private function summarizeCombinedNetBurnRows(array $rows): array
    {
        $byDate = [];
        foreach ($rows as $row) {
            $date = (string) $row->snapshot_date;
            $byDate[$date] ??= [
                'pending' => 0,
                'delta' => 0,
                'has_delta' => false,
            ];
            $byDate[$date]['pending'] += (int) $row->pending;
            if ($row->delta_from_prev !== null) {
                $byDate[$date]['delta'] += (int) $row->delta_from_prev;
                $byDate[$date]['has_delta'] = true;
            }
        }

        ksort($byDate);
        $points = count($byDate);
        $latest = $points > 0 ? end($byDate) : null;
        $deltas = [];
        foreach ($byDate as $point) {
            if ($point['has_delta']) {
                $deltas[] = $point['delta'];
            }
        }

        return $this->summarizeNetBurnValues(
            $points,
            is_array($latest) ? (int) $latest['pending'] : null,
            $deltas
        );
    }

    private function summarizeNetBurnValues(int $points, ?int $latestPending, array $deltas): array
    {
        $deltaCount = count($deltas);
        $deltaTotal = $deltaCount > 0 ? array_sum($deltas) : null;
        $deltaAverage = $deltaCount > 0 ? round($deltaTotal / $deltaCount, 1) : null;

        $trend = 'insufficient_data';
        if ($points >= 3 && $deltaCount >= 2 && $deltaAverage !== null) {
            $trend = match (true) {
                $deltaAverage < -0.5 => 'shrinking',
                $deltaAverage > 0.5 => 'growing',
                default => 'steady',
            };
        }

        return [
            'points' => $points,
            'pending' => $latestPending,
            'delta_total' => $deltaTotal,
            'delta_avg_per_day' => $deltaAverage,
            'net_burn_per_day' => $deltaAverage !== null && $deltaAverage < 0 ? abs($deltaAverage) : 0.0,
            'trend' => $trend,
        ];
    }

    private function emptyNetBurnLanes(): array
    {
        $summary = $this->summarizeNetBurnValues(0, null, []);

        return [
            'kg_fresh' => $summary,
            'kg_stale' => $summary,
            'kg' => $summary,
            'raptor' => $summary,
            'sentence' => $summary,
        ];
    }

    private function selectCount(string $sql, array $params = []): int
    {
        try {
            return (int) (DB::connection(self::CONNECTION)->selectOne($sql, $params)?->c ?? 0);
        } catch (\Throwable $e) {
            Log::warning('RagBacklogService: count query failed', [
                'sql' => mb_substr($sql, 0, 160),
                'error' => $e->getMessage(),
            ]);
            $this->recordEvidenceError('count_query_failed', [
                'connection' => self::CONNECTION,
                'query' => $this->compactSql($sql),
                'error' => $this->compactMessage($e->getMessage()),
            ]);

            return 0;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordEvidenceError(string $code, array $context): void
    {
        $this->evidenceErrors[] = [
            'code' => $code,
            'context' => $context,
        ];
    }

    private function compactSql(string $sql): string
    {
        return mb_substr((string) preg_replace('/\s+/', ' ', trim($sql)), 0, 160);
    }

    private function compactMessage(string $message): string
    {
        return mb_substr((string) preg_replace('/\s+/', ' ', trim($message)), 0, 300);
    }
}
