<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RagBacklogService
{
    private const CONNECTION = 'pgsql_rag';

    private const KG_MIN_CHARS = 50;

    private const THROUGHPUT_LOOKBACK_DAYS = 7;

    private const SCALE_TABLES = [
        'rag_documents',
        'rag_sentence_embeddings',
        'raptor_summaries',
        'file_semantic_embeddings',
        'knowledge_graph',
        'knowledge_graph_entities',
        'knowledge_graph_entity_embeddings',
        'knowledge_graph_edge_history',
        'knowledge_graph_hyperedges',
    ];

    private const RAG_OPTIONAL_COLUMNS = [
        'parent_id',
        'compressed_content',
        'context_prefix',
        'sparse_embedding',
        'hype_indexed_at',
        'image_embedding',
    ];

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

    public function getScaleBaseline(): array
    {
        $errors = [];
        $ragDocumentColumns = $this->scaleTableColumns('rag_documents', $errors);
        $availableTables = $this->scaleAvailableTables($errors);
        $summary = $this->scaleSummary($errors, $ragDocumentColumns);
        $documentTypes = $this->scaleDocumentTypes($errors, $ragDocumentColumns);
        $storage = $this->scaleStorage($errors);
        $embeddingTables = $this->scaleEmbeddingTables($errors, $availableTables);

        return [
            'version' => 1,
            'mode' => 'observe',
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'status' => $errors === [] ? 'observe_ok' : 'observe_warning',
            'summary' => $summary,
            'document_types' => $documentTypes,
            'storage' => $storage,
            'embedding_tables' => $embeddingTables,
            'schema' => [
                'rag_documents_columns' => $ragDocumentColumns,
                'missing_optional_columns' => array_values(array_diff(self::RAG_OPTIONAL_COLUMNS, $ragDocumentColumns)),
                'available_tables' => array_keys($availableTables),
                'missing_tables' => array_values(array_filter(
                    self::SCALE_TABLES,
                    fn (string $table): bool => ! isset($availableTables[$table])
                )),
            ],
            'recommendations' => $this->scaleRecommendations($summary, $storage, $errors),
            'evidence_errors' => $errors,
            'note' => 'Scale baseline is read-only; do not change chunking, compression, vector dimensions, or indexing policy from this report alone.',
        ];
    }

    public function scaleBaselineToMarkdown(array $payload): string
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $storage = is_array($payload['storage'] ?? null) ? $payload['storage'] : [];
        $lines = [
            '# RAG Scale Baseline',
            '',
            '- Mode: `'.($payload['mode'] ?? 'observe').'`',
            '- Status: `'.($payload['status'] ?? 'unknown').'`',
            '- Captured: `'.($payload['captured_at'] ?? 'unknown').'`',
            '- Documents: `'.(int) ($summary['documents'] ?? 0).'`',
            '- Content chars: `'.(int) ($summary['content_chars'] ?? 0).'`',
            '- Average content chars: `'.(int) ($summary['avg_content_chars'] ?? 0).'`',
            '- Max content chars: `'.(int) ($summary['max_content_chars'] ?? 0).'`',
            '- Compressed documents: `'.(int) ($summary['compressed_documents'] ?? 0).'`',
            '- Contextualized documents: `'.(int) ($summary['contextualized_documents'] ?? 0).'`',
            '- Total relation MB: `'.(float) ($storage['total_relation_mb'] ?? 0.0).'`',
            '',
            '## Document Types',
            '',
        ];

        foreach (($payload['document_types'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $lines[] = sprintf(
                '- `%s`: `%d` docs, avg `%d` chars, total `%d` chars',
                (string) ($row['document_type'] ?? 'unknown'),
                (int) ($row['documents'] ?? 0),
                (int) ($row['avg_content_chars'] ?? 0),
                (int) ($row['content_chars'] ?? 0)
            );
        }
        if (($payload['document_types'] ?? []) === []) {
            $lines[] = '- None.';
        }

        $lines[] = '';
        $lines[] = '## Embedding Tables';
        $lines[] = '';
        foreach (($payload['embedding_tables'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (($row['exists'] ?? true) === false) {
                $lines[] = sprintf(
                    '- `%s`: missing',
                    (string) ($row['table'] ?? 'unknown')
                );

                continue;
            }
            $lines[] = sprintf(
                '- `%s`: `%d` rows',
                (string) ($row['table'] ?? 'unknown'),
                (int) ($row['rows'] ?? 0)
            );
        }

        $lines[] = '';
        $lines[] = '## Recommendations';
        $lines[] = '';
        foreach (($payload['recommendations'] ?? []) as $recommendation) {
            $lines[] = '- '.$recommendation;
        }

        return implode("\n", $lines)."\n";
    }

    private function countDocuments(): int
    {
        return $this->selectCount('SELECT COUNT(*) as c FROM rag_documents');
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     * @param  list<string>  $columns
     * @return array<string, float|int|null>
     */
    private function scaleSummary(array &$errors, array $columns): array
    {
        $contentSum = $this->hasScaleColumn($columns, 'content') ? 'COALESCE(SUM(LENGTH(content)), 0)' : '0';
        $contentAvg = $this->hasScaleColumn($columns, 'content') ? 'COALESCE(AVG(LENGTH(content)), 0)' : '0';
        $contentMax = $this->hasScaleColumn($columns, 'content') ? 'COALESCE(MAX(LENGTH(content)), 0)' : '0';
        $parentDocuments = $this->hasScaleColumn($columns, 'parent_id')
            ? 'SUM(CASE WHEN parent_id IS NULL THEN 1 ELSE 0 END)'
            : 'COUNT(*)';
        $childDocuments = $this->hasScaleColumn($columns, 'parent_id')
            ? 'SUM(CASE WHEN parent_id IS NOT NULL THEN 1 ELSE 0 END)'
            : '0';
        $compressedDocuments = $this->hasScaleColumn($columns, 'compressed_content')
            ? "SUM(CASE WHEN compressed_content IS NOT NULL AND compressed_content <> '' THEN 1 ELSE 0 END)"
            : '0';
        $contextualizedDocuments = $this->hasScaleColumn($columns, 'context_prefix')
            ? "SUM(CASE WHEN context_prefix IS NOT NULL AND context_prefix <> '' THEN 1 ELSE 0 END)"
            : '0';
        $sparseDocuments = $this->hasScaleColumn($columns, 'sparse_embedding')
            ? 'SUM(CASE WHEN sparse_embedding IS NOT NULL THEN 1 ELSE 0 END)'
            : '0';
        $hypeDocuments = $this->hasScaleColumn($columns, 'hype_indexed_at')
            ? 'SUM(CASE WHEN hype_indexed_at IS NOT NULL THEN 1 ELSE 0 END)'
            : '0';
        $imageEmbeddingDocuments = $this->hasScaleColumn($columns, 'image_embedding')
            ? 'SUM(CASE WHEN image_embedding IS NOT NULL THEN 1 ELSE 0 END)'
            : '0';

        try {
            $row = DB::connection(self::CONNECTION)->selectOne(
                "SELECT
                    COUNT(*) AS documents,
                    {$contentSum} AS content_chars,
                    {$contentAvg} AS avg_content_chars,
                    {$contentMax} AS max_content_chars,
                    {$parentDocuments} AS parent_documents,
                    {$childDocuments} AS child_documents,
                    {$compressedDocuments} AS compressed_documents,
                    {$contextualizedDocuments} AS contextualized_documents,
                    {$sparseDocuments} AS sparse_documents,
                    {$hypeDocuments} AS hype_documents,
                    {$imageEmbeddingDocuments} AS image_embedding_documents
                 FROM rag_documents"
            );

            $documents = (int) ($row->documents ?? 0);
            $compressed = (int) ($row->compressed_documents ?? 0);
            $contextualized = (int) ($row->contextualized_documents ?? 0);

            return [
                'documents' => $documents,
                'parent_documents' => (int) ($row->parent_documents ?? 0),
                'child_documents' => (int) ($row->child_documents ?? 0),
                'content_chars' => (int) ($row->content_chars ?? 0),
                'avg_content_chars' => (int) round((float) ($row->avg_content_chars ?? 0)),
                'max_content_chars' => (int) ($row->max_content_chars ?? 0),
                'compressed_documents' => $compressed,
                'compressed_ratio' => $documents > 0 ? round($compressed / $documents, 4) : null,
                'contextualized_documents' => $contextualized,
                'contextualized_ratio' => $documents > 0 ? round($contextualized / $documents, 4) : null,
                'sparse_documents' => (int) ($row->sparse_documents ?? 0),
                'hype_documents' => (int) ($row->hype_documents ?? 0),
                'image_embedding_documents' => (int) ($row->image_embedding_documents ?? 0),
            ];
        } catch (\Throwable $e) {
            $this->recordScaleError($errors, 'rag_scale_summary_failed', $e);

            return [
                'documents' => 0,
                'parent_documents' => 0,
                'child_documents' => 0,
                'content_chars' => 0,
                'avg_content_chars' => 0,
                'max_content_chars' => 0,
                'compressed_documents' => 0,
                'compressed_ratio' => null,
                'contextualized_documents' => 0,
                'contextualized_ratio' => null,
                'sparse_documents' => 0,
                'hype_documents' => 0,
                'image_embedding_documents' => 0,
            ];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     * @param  list<string>  $columns
     * @return list<array<string, int|string>>
     */
    private function scaleDocumentTypes(array &$errors, array $columns): array
    {
        $documentType = $this->hasScaleColumn($columns, 'document_type') ? 'document_type' : "'unknown'";
        $groupBy = $this->hasScaleColumn($columns, 'document_type') ? 'GROUP BY document_type' : '';
        $orderBy = $this->hasScaleColumn($columns, 'document_type')
            ? 'ORDER BY documents DESC, document_type ASC'
            : 'ORDER BY documents DESC';
        $contentSum = $this->hasScaleColumn($columns, 'content') ? 'COALESCE(SUM(LENGTH(content)), 0)' : '0';
        $contentAvg = $this->hasScaleColumn($columns, 'content') ? 'COALESCE(AVG(LENGTH(content)), 0)' : '0';
        $contentMax = $this->hasScaleColumn($columns, 'content') ? 'COALESCE(MAX(LENGTH(content)), 0)' : '0';
        $compressedDocuments = $this->hasScaleColumn($columns, 'compressed_content')
            ? "SUM(CASE WHEN compressed_content IS NOT NULL AND compressed_content <> '' THEN 1 ELSE 0 END)"
            : '0';

        try {
            $rows = DB::connection(self::CONNECTION)->select(
                "SELECT
                    {$documentType} AS document_type,
                    COUNT(*) AS documents,
                    {$contentSum} AS content_chars,
                    {$contentAvg} AS avg_content_chars,
                    {$contentMax} AS max_content_chars,
                    {$compressedDocuments} AS compressed_documents
                 FROM rag_documents
                 {$groupBy}
                 {$orderBy}
                 LIMIT 25"
            );

            return array_map(fn (object $row): array => [
                'document_type' => (string) ($row->document_type ?? 'unknown'),
                'documents' => (int) ($row->documents ?? 0),
                'content_chars' => (int) ($row->content_chars ?? 0),
                'avg_content_chars' => (int) round((float) ($row->avg_content_chars ?? 0)),
                'max_content_chars' => (int) ($row->max_content_chars ?? 0),
                'compressed_documents' => (int) ($row->compressed_documents ?? 0),
            ], $rows);
        } catch (\Throwable $e) {
            $this->recordScaleError($errors, 'rag_scale_document_types_failed', $e);

            return [];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     * @return array<string, float|int>
     */
    private function scaleStorage(array &$errors): array
    {
        try {
            $row = DB::connection(self::CONNECTION)->selectOne(
                "SELECT
                    pg_total_relation_size('rag_documents') AS rag_documents_total_bytes,
                    pg_relation_size('rag_documents') AS rag_documents_heap_bytes,
                    pg_indexes_size('rag_documents') AS rag_documents_index_bytes"
            );

            $total = (int) ($row->rag_documents_total_bytes ?? 0);
            $heap = (int) ($row->rag_documents_heap_bytes ?? 0);
            $index = (int) ($row->rag_documents_index_bytes ?? 0);

            return [
                'rag_documents_total_bytes' => $total,
                'rag_documents_heap_bytes' => $heap,
                'rag_documents_index_bytes' => $index,
                'total_relation_mb' => round($total / 1048576, 2),
                'heap_mb' => round($heap / 1048576, 2),
                'index_mb' => round($index / 1048576, 2),
            ];
        } catch (\Throwable $e) {
            $this->recordScaleError($errors, 'rag_scale_storage_failed', $e);

            return [
                'rag_documents_total_bytes' => 0,
                'rag_documents_heap_bytes' => 0,
                'rag_documents_index_bytes' => 0,
                'total_relation_mb' => 0.0,
                'heap_mb' => 0.0,
                'index_mb' => 0.0,
            ];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     * @param  array<string, bool>  $availableTables
     * @return list<array<string, bool|int|string>>
     */
    private function scaleEmbeddingTables(array &$errors, array $availableTables): array
    {
        $tables = [];
        foreach (self::SCALE_TABLES as $table) {
            if (! isset($availableTables[$table])) {
                $tables[] = [
                    'table' => $table,
                    'exists' => false,
                    'rows' => 0,
                ];

                continue;
            }

            try {
                $row = DB::connection(self::CONNECTION)->selectOne("SELECT COUNT(*) AS rows_count FROM {$table}");
                $tables[] = [
                    'table' => $table,
                    'exists' => true,
                    'rows' => (int) ($row->rows_count ?? 0),
                ];
            } catch (\Throwable $e) {
                $this->recordScaleError($errors, 'rag_scale_table_count_failed', $e, ['table' => $table]);
            }
        }

        return $tables;
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     * @return list<string>
     */
    private function scaleTableColumns(string $table, array &$errors): array
    {
        try {
            $rows = DB::connection(self::CONNECTION)->select(
                "SELECT column_name
                 FROM information_schema.columns
                 WHERE table_schema = 'public'
                   AND table_name = ?
                 ORDER BY ordinal_position",
                [$table]
            );

            return array_values(array_map(
                fn (object $row): string => (string) $row->column_name,
                $rows
            ));
        } catch (\Throwable $e) {
            $this->recordScaleError($errors, 'rag_scale_column_lookup_failed', $e, ['table' => $table]);

            return [];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     * @return array<string, bool>
     */
    private function scaleAvailableTables(array &$errors): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::SCALE_TABLES), '?'));

        try {
            $rows = DB::connection(self::CONNECTION)->select(
                "SELECT table_name
                 FROM information_schema.tables
                 WHERE table_schema = 'public'
                   AND table_name IN ({$placeholders})
                 ORDER BY table_name",
                self::SCALE_TABLES
            );

            $available = [];
            foreach ($rows as $row) {
                $available[(string) $row->table_name] = true;
            }

            return $available;
        } catch (\Throwable $e) {
            $this->recordScaleError($errors, 'rag_scale_table_lookup_failed', $e);

            return [];
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasScaleColumn(array $columns, string $column): bool
    {
        return in_array($column, $columns, true);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $storage
     * @param  list<array<string, mixed>>  $errors
     * @return list<string>
     */
    private function scaleRecommendations(array $summary, array $storage, array $errors): array
    {
        $recommendations = [];

        if ($errors !== []) {
            $recommendations[] = 'Fix missing scale-baseline evidence before making RAG storage or indexing changes.';
        }

        if ((int) ($summary['max_content_chars'] ?? 0) > 100000) {
            $recommendations[] = 'Inspect the largest RAG documents before changing chunking; oversized payloads may need source-specific compaction.';
        }

        if ((float) ($summary['compressed_ratio'] ?? 0.0) < 0.25 && (int) ($summary['documents'] ?? 0) > 10000) {
            $recommendations[] = 'Compression coverage is low for a large corpus; measure retrieval quality before expanding compression.';
        }

        if ((float) ($storage['total_relation_mb'] ?? 0.0) > 10240) {
            $recommendations[] = 'rag_documents is above 10 GB; collect index/bloat and query latency evidence before bulk indexing growth.';
        }

        $recommendations[] = 'Keep TODO-018 in observe/planning mode until this baseline is compared with retrieval quality and job runtime evidence.';

        return $recommendations;
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     * @param  array<string, mixed>  $context
     */
    private function recordScaleError(array &$errors, string $code, \Throwable $e, array $context = []): void
    {
        $errors[] = [
            'code' => $code,
            'context' => [
                ...$context,
                'connection' => self::CONNECTION,
                'error' => $this->compactMessage($e->getMessage()),
            ],
        ];
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
