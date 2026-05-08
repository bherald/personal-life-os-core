<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class DbaTelemetryReportService
{
    private const STATUS_RANK = [
        'observe_ok' => 0,
        'observe_warning' => 1,
        'review_required' => 2,
    ];

    public function collect(bool $weekly = false, bool $dryRun = false, bool $deep = false): array
    {
        $payload = [
            'version' => 1,
            'mode' => 'observe',
            'dry_run' => $dryRun,
            'deep' => $deep,
            'window' => $weekly ? 'weekly' : 'current',
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'sections' => [],
            'threshold_breaches' => [],
            'recommendations' => [],
        ];

        if ($dryRun) {
            $payload['status'] = 'observe_ok';
            $payload['sections'] = [
                'dry_run' => [
                    'status' => 'observe_ok',
                    'note' => 'Dry run only; no database or Redis probes executed.',
                ],
            ];

            return $payload;
        }

        $agentRecursionTable = $this->collectMysqlTable('agent_recursion_calls');

        $payload['sections'] = [
            'mysql_storage' => $this->collectMysqlStorage($agentRecursionTable),
            'arc_growth' => $this->collectAgentRecursionGrowth($agentRecursionTable, $deep),
            'postgres_storage' => $this->collectPostgresStorage(),
            'redis_health' => $this->collectRedisHealth(),
        ];

        [$breaches, $recommendations] = $this->evaluateThresholds($payload['sections']);
        $payload['threshold_breaches'] = $breaches;
        $payload['recommendations'] = $recommendations;
        $payload['status'] = $this->worstStatus(array_merge(
            array_column($payload['sections'], 'status'),
            array_column($breaches, 'status')
        ));

        return $payload;
    }

    public function toMarkdown(array $payload): string
    {
        $lines = [
            '# DBA Telemetry Report',
            '',
            '- Mode: `'.($payload['mode'] ?? 'observe').'`',
            '- Status: `'.($payload['status'] ?? 'unknown').'`',
            '- Captured: `'.($payload['captured_at'] ?? 'unknown').'`',
            '- Window: `'.($payload['window'] ?? 'current').'`',
            '',
            '## Sections',
            '',
        ];

        foreach (($payload['sections'] ?? []) as $name => $section) {
            $lines[] = '- `'.$name.'`: `'.($section['status'] ?? 'unknown').'`';
        }

        $lines[] = '';
        $lines[] = '## Threshold Breaches';
        $lines[] = '';
        foreach (($payload['threshold_breaches'] ?? []) as $breach) {
            $lines[] = '- `'.($breach['status'] ?? 'observe_warning').'` '.$breach['message'];
        }
        if (($payload['threshold_breaches'] ?? []) === []) {
            $lines[] = '- None.';
        }

        $lines[] = '';
        $lines[] = '## Recommendations';
        $lines[] = '';
        foreach (($payload['recommendations'] ?? []) as $recommendation) {
            $lines[] = '- '.$recommendation;
        }
        if (($payload['recommendations'] ?? []) === []) {
            $lines[] = '- No human action recommended from this observe-only sample.';
        }

        return implode("\n", $lines)."\n";
    }

    public function compactPayload(array $payload): array
    {
        $sections = $payload['sections'] ?? [];
        $thresholdBreaches = array_values($payload['threshold_breaches'] ?? []);
        $recommendations = array_values($payload['recommendations'] ?? []);
        $arcSummary = $sections['arc_growth']['summary'] ?? [];
        $arcTable = $sections['mysql_storage']['agent_recursion_calls'] ?? [];
        $redis = $sections['redis_health'] ?? [];
        $postgres = $sections['postgres_storage'] ?? [];

        return [
            'version' => $payload['version'] ?? 1,
            'captured_at' => $payload['captured_at'] ?? now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'mode' => $payload['mode'] ?? 'observe',
            'window' => $payload['window'] ?? 'current',
            'compact' => true,
            'dry_run' => (bool) ($payload['dry_run'] ?? false),
            'deep' => (bool) ($payload['deep'] ?? false),
            'status' => $payload['status'] ?? 'unknown',
            'section_statuses' => $this->sectionStatuses($sections),
            'posture' => $this->compactPosture(),
            'threshold_breach_count' => count($thresholdBreaches),
            'threshold_breach_status_counts' => $this->countValues($thresholdBreaches, 'status'),
            'recommendation_count' => count($recommendations),
            'arc' => [
                'status' => $sections['arc_growth']['status'] ?? $sections['mysql_storage']['status'] ?? 'unknown',
                'rows_total_estimate' => (int) ($arcSummary['rows_total_estimate'] ?? $arcTable['table_rows'] ?? 0),
                'total_gb' => (float) ($arcSummary['total_gb'] ?? $arcTable['total_gb'] ?? 0.0),
                'oldest_created_at' => $arcSummary['oldest_created_at'] ?? null,
                'newest_created_at' => $arcSummary['newest_created_at'] ?? null,
                'raw_recent_scan_skipped' => (bool) ($arcSummary['raw_recent_scan_skipped'] ?? false),
                'raw_recent_scan_limit' => isset($arcSummary['raw_recent_scan_limit'])
                    ? (int) $arcSummary['raw_recent_scan_limit']
                    : null,
            ],
            'redis' => [
                'status' => $redis['status'] ?? 'unknown',
                'used_memory_mb' => isset($redis['used_memory_mb']) ? (float) $redis['used_memory_mb'] : null,
                'memory_ratio' => $redis['memory_ratio'] ?? null,
                'fragmentation_ratio' => $redis['fragmentation_ratio'] ?? null,
                'key_count' => isset($redis['key_count']) ? (int) $redis['key_count'] : null,
                'evicted_keys' => isset($redis['evicted_keys']) ? (int) $redis['evicted_keys'] : null,
                'rejected_connections' => isset($redis['rejected_connections']) ? (int) $redis['rejected_connections'] : null,
                'blocked_clients' => isset($redis['blocked_clients']) ? (int) $redis['blocked_clients'] : null,
            ],
            'postgres' => [
                'status' => $postgres['status'] ?? 'unknown',
                'database_total_gb' => isset($postgres['database_total_gb'])
                    ? (float) $postgres['database_total_gb']
                    : null,
                'top_table_count' => count($postgres['top_tables'] ?? []),
                'dead_tuple_top_count' => count($postgres['dead_tuple_top'] ?? []),
            ],
        ];
    }

    /**
     * @return array<string, bool|string>
     */
    private function compactPosture(): array
    {
        return [
            'scope' => 'aggregate_only',
            'mode' => 'observe',
            'read_only' => true,
            'writes_enabled' => false,
            'cleanup_enabled' => false,
            'arc_execute_enabled' => false,
            'scheduler_changes_enabled' => false,
            'notification_sends_enabled' => false,
            'raw_table_dumps_included' => false,
            'service_strategy_rows_included' => false,
            'recommendation_text_included' => false,
            'destructive_sql_included' => false,
        ];
    }

    public function compactToMarkdown(array $payload): string
    {
        $arc = $payload['arc'] ?? [];
        $redis = $payload['redis'] ?? [];
        $postgres = $payload['postgres'] ?? [];
        $posture = is_array($payload['posture'] ?? null) ? $payload['posture'] : [];

        $lines = [
            '# DBA Telemetry Compact Report',
            '',
            '- Mode: `'.($payload['mode'] ?? 'observe').'`',
            '- Status: `'.($payload['status'] ?? 'unknown').'`',
            '- Captured: `'.($payload['captured_at'] ?? 'unknown').'`',
            '- Window: `'.($payload['window'] ?? 'current').'`',
            '- Threshold breaches: `'.(int) ($payload['threshold_breach_count'] ?? 0).'`',
            '- Recommendations: `'.(int) ($payload['recommendation_count'] ?? 0).'`',
            '',
            '## Posture',
            '',
            '- Scope: `'.($posture['scope'] ?? 'aggregate_only').'`',
            '- Mode: `'.($posture['mode'] ?? 'observe').'`',
            '- Read only: `'.(($posture['read_only'] ?? true) ? 'true' : 'false').'`',
            '- Writes enabled: `'.(($posture['writes_enabled'] ?? false) ? 'true' : 'false').'`',
            '- ARC execute enabled: `'.(($posture['arc_execute_enabled'] ?? false) ? 'true' : 'false').'`',
            '- Raw table dumps included: `'.(($posture['raw_table_dumps_included'] ?? false) ? 'true' : 'false').'`',
            '',
            '## ARC',
            '',
            '- Rows estimate: `'.($arc['rows_total_estimate'] ?? 'n/a').'`',
            '- Total GB: `'.($arc['total_gb'] ?? 'n/a').'`',
            '- Oldest row: `'.($arc['oldest_created_at'] ?? 'n/a').'`',
            '- Raw scan skipped: `'.(($arc['raw_recent_scan_skipped'] ?? false) ? 'true' : 'false').'`',
            '',
            '## Redis',
            '',
            '- Status: `'.($redis['status'] ?? 'unknown').'`',
            '- Used MB: `'.($redis['used_memory_mb'] ?? 'n/a').'`',
            '- Memory ratio: `'.($redis['memory_ratio'] ?? 'n/a').'`',
            '- Fragmentation: `'.($redis['fragmentation_ratio'] ?? 'n/a').'`',
            '- Keys: `'.($redis['key_count'] ?? 'n/a').'`',
            '',
            '## PostgreSQL',
            '',
            '- Status: `'.($postgres['status'] ?? 'unknown').'`',
            '- Total GB: `'.($postgres['database_total_gb'] ?? 'n/a').'`',
            '- Dead tuple top count: `'.($postgres['dead_tuple_top_count'] ?? 'n/a').'`',
        ];

        return implode("\n", $lines)."\n";
    }

    private function collectMysqlStorage(?array $agentRecursionTable): array
    {
        try {
            $rows = DB::select(
                'SELECT table_name, table_rows, data_length, index_length,
                        (data_length + index_length) AS total_bytes
                   FROM information_schema.tables
                  WHERE table_schema = DATABASE()
                    AND table_type = ?
                  ORDER BY total_bytes DESC
                  LIMIT 20',
                ['BASE TABLE']
            );

            $topTables = array_map(fn (object $row): array => $this->mysqlTableRow($row), $rows);
            $totalBytes = array_sum(array_column($topTables, 'total_bytes'));
            $arc = $agentRecursionTable ?? $this->findTable($topTables, 'agent_recursion_calls');

            return [
                'status' => 'observe_ok',
                'source' => 'information_schema.tables',
                'schema_top20_total_gb' => $this->bytesToGb($totalBytes),
                'top_tables' => $topTables,
                'agent_recursion_calls' => $arc,
            ];
        } catch (\Throwable $e) {
            return $this->failedSection('information_schema.tables', $e);
        }
    }

    private function collectAgentRecursionGrowth(?array $agentRecursionTable, bool $deep): array
    {
        try {
            $oldest = DB::selectOne(
                'SELECT created_at AS created_at
                   FROM agent_recursion_calls
                  WHERE created_at IS NOT NULL
                  ORDER BY created_at ASC
                  LIMIT 1'
            );
            $newest = DB::selectOne(
                'SELECT created_at AS created_at
                   FROM agent_recursion_calls
                  WHERE created_at IS NOT NULL
                  ORDER BY created_at DESC
                  LIMIT 1'
            );

            $rowEstimate = (int) ($agentRecursionTable['table_rows'] ?? 0);
            $rawScanLimit = $this->configInt('arc_raw_scan_row_limit', 5_000_000);
            $groupingLimit = $this->configInt('service_strategy_grouping_row_limit', 100_000);
            $rawScanSkipped = ! $deep && $rowEstimate > $rawScanLimit;
            $recent = null;
            $rows7d = null;
            $moveOns7d = null;
            $serviceRows = [];
            $serviceStrategySkipped = true;
            $serviceStrategySkipReason = 'Skipped raw service/strategy grouping because raw recent aggregation was skipped.';

            if (! $rawScanSkipped) {
                $recent = DB::selectOne(
                    'SELECT
                        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS rows_24h,
                        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS rows_7d,
                        COUNT(*) AS rows_30d,
                        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN COALESCE(tokens_used, 0) ELSE 0 END) AS tokens_7d,
                        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND move_on_triggered = 1 THEN 1 ELSE 0 END) AS move_ons_7d
                     FROM agent_recursion_calls
                     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
                );

                $rows7d = (int) ($recent->rows_7d ?? 0);
                $moveOns7d = (int) ($recent->move_ons_7d ?? 0);
                $serviceRows = $rows7d <= $groupingLimit
                    ? $this->collectArcServiceStrategyTop()
                    : [];
                $serviceStrategySkipped = $rows7d > $groupingLimit;
                $serviceStrategySkipReason = $serviceStrategySkipped
                    ? 'Skipped raw service/strategy grouping because 7-day rows exceeded '.number_format($groupingLimit).'.'
                    : null;
            }

            return [
                'status' => 'observe_ok',
                'source' => 'agent_recursion_calls',
                'summary' => [
                    'rows_total_estimate' => (int) ($agentRecursionTable['table_rows'] ?? 0),
                    'total_gb' => (float) ($agentRecursionTable['total_gb'] ?? 0.0),
                    'oldest_created_at' => $this->nullableString($oldest->created_at ?? null),
                    'newest_created_at' => $this->nullableString($newest->created_at ?? null),
                    'raw_recent_scan_skipped' => $rawScanSkipped,
                    'raw_recent_scan_limit' => $rawScanLimit,
                    'raw_recent_scan_skip_reason' => $rawScanSkipped
                        ? 'Skipped raw recent aggregation because estimated rows exceeded '.number_format($rawScanLimit).'; rerun with --deep during an off-peak window if exact recent counts are needed.'
                        : null,
                    'rows_24h' => $recent === null ? null : (int) ($recent->rows_24h ?? 0),
                    'rows_7d' => $rows7d,
                    'rows_30d' => $recent === null ? null : (int) ($recent->rows_30d ?? 0),
                    'tokens_7d' => $recent === null ? null : (int) ($recent->tokens_7d ?? 0),
                    'move_on_rate_7d' => $rows7d > 0 ? round(((int) $moveOns7d) / $rows7d, 4) : null,
                    'service_strategy_top_skipped' => $serviceStrategySkipped,
                    'service_strategy_skip_reason' => $serviceStrategySkipReason,
                ],
                'service_strategy_top' => $serviceRows,
            ];
        } catch (\Throwable $e) {
            return $this->failedSection('agent_recursion_calls', $e);
        }
    }

    private function collectArcServiceStrategyTop(): array
    {
        $serviceRows = DB::select(
            'SELECT service_name, strategy, COUNT(*) AS calls,
                    SUM(COALESCE(tokens_used, 0)) AS tokens,
                    MAX(depth) AS max_depth
               FROM agent_recursion_calls
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              GROUP BY service_name, strategy
              ORDER BY calls DESC
              LIMIT 20'
        );

        return array_map(fn (object $row): array => [
            'service_name' => $this->nullableString($row->service_name ?? null) ?? 'unknown',
            'strategy' => $this->nullableString($row->strategy ?? null) ?? 'unknown',
            'calls' => (int) ($row->calls ?? 0),
            'tokens' => (int) ($row->tokens ?? 0),
            'max_depth' => (int) ($row->max_depth ?? 0),
        ], $serviceRows);
    }

    private function collectPostgresStorage(): array
    {
        try {
            $db = DB::connection('pgsql_rag');
            $database = $db->selectOne('SELECT pg_database_size(current_database()) AS total_bytes');
            $tables = $db->select(
                'SELECT relname AS table_name,
                        pg_total_relation_size(relid) AS total_bytes,
                        pg_relation_size(relid) AS data_bytes,
                        pg_indexes_size(relid) AS index_bytes
                   FROM pg_catalog.pg_statio_user_tables
                  ORDER BY pg_total_relation_size(relid) DESC
                  LIMIT 20'
            );
            $deadTuples = $db->select(
                'SELECT relname AS table_name, n_live_tup, n_dead_tup, last_autovacuum, last_autoanalyze
                   FROM pg_stat_user_tables
                  ORDER BY n_dead_tup DESC
                  LIMIT 10'
            );

            return [
                'status' => 'observe_ok',
                'source' => 'pgsql_rag.pg_catalog',
                'database_total_gb' => $this->bytesToGb((int) ($database->total_bytes ?? 0)),
                'top_tables' => array_map(fn (object $row): array => [
                    'table_name' => (string) ($row->table_name ?? ''),
                    'total_bytes' => (int) ($row->total_bytes ?? 0),
                    'total_gb' => $this->bytesToGb((int) ($row->total_bytes ?? 0)),
                    'data_bytes' => (int) ($row->data_bytes ?? 0),
                    'index_bytes' => (int) ($row->index_bytes ?? 0),
                ], $tables),
                'dead_tuple_top' => array_map(fn (object $row): array => [
                    'table_name' => (string) ($row->table_name ?? ''),
                    'live_tuples' => (int) ($row->n_live_tup ?? 0),
                    'dead_tuples' => (int) ($row->n_dead_tup ?? 0),
                    'last_autovacuum' => $this->nullableString($row->last_autovacuum ?? null),
                    'last_autoanalyze' => $this->nullableString($row->last_autoanalyze ?? null),
                ], $deadTuples),
            ];
        } catch (\Throwable $e) {
            return $this->failedSection('pgsql_rag.pg_catalog', $e);
        }
    }

    private function collectRedisHealth(): array
    {
        try {
            $memory = $this->redisInfoSection(Redis::info('memory') ?: [], 'memory');
            $stats = $this->redisInfoSection(Redis::info('stats') ?: [], 'stats');
            $dbSize = Redis::command('DBSIZE') ?? 0;

            $used = (int) ($memory['used_memory'] ?? 0);
            $max = (int) ($memory['maxmemory'] ?? 0);

            return [
                'status' => 'observe_ok',
                'source' => 'redis_info(default)',
                'used_memory_bytes' => $used,
                'used_memory_mb' => round($used / 1024 / 1024, 1),
                'peak_memory_bytes' => (int) ($memory['used_memory_peak'] ?? 0),
                'maxmemory_bytes' => $max,
                'memory_ratio' => $max > 0 ? round($used / $max, 4) : null,
                'fragmentation_ratio' => isset($memory['mem_fragmentation_ratio'])
                    ? (float) $memory['mem_fragmentation_ratio']
                    : null,
                'evicted_keys' => (int) ($stats['evicted_keys'] ?? 0),
                'rejected_connections' => (int) ($stats['rejected_connections'] ?? 0),
                'blocked_clients' => (int) ($stats['blocked_clients'] ?? 0),
                'key_count' => (int) $dbSize,
            ];
        } catch (\Throwable $e) {
            return $this->failedSection('redis_info', $e);
        }
    }

    private function evaluateThresholds(array $sections): array
    {
        $breaches = [];
        $recommendations = [];

        $arc = $sections['mysql_storage']['agent_recursion_calls'] ?? null;
        if (is_array($arc)) {
            if (($arc['total_gb'] ?? 0) >= $this->configFloat('arc_size_review_gb', 10.0)
                || ($arc['table_rows'] ?? 0) >= $this->configInt('arc_size_review_rows', 10_000_000)) {
                $breaches[] = $this->breach(
                    'arc-size-review',
                    'observe_warning',
                    'agent_recursion_calls exceeds the partition/retention review threshold.'
                );
                $recommendations[] = 'Review agent_recursion_calls retention and partition design; do not clean up automatically.';
            }
        }

        $arcSummary = $sections['arc_growth']['summary'] ?? [];
        if (($arcSummary['raw_recent_scan_skipped'] ?? false) === true) {
            $breaches[] = $this->breach(
                'arc-growth-scan-skipped',
                'observe_warning',
                'agent_recursion_calls raw recent aggregation was skipped because the table is above the safe scan threshold.'
            );
            $recommendations[] = 'Review ARC growth with off-peak deep report evidence or summary-table evidence before expanding autonomy.';
        }
        if (($arcSummary['rows_7d'] ?? 0) >= $this->configInt('arc_growth_review_rows_7d', 1_000_000)) {
            $breaches[] = $this->breach(
                'arc-growth-review',
                'observe_warning',
                'agent_recursion_calls inserted at least 1,000,000 rows in the last 7 days.'
            );
            $recommendations[] = 'Inspect service/strategy concentration for recursion growth before expanding autonomy.';
        }
        $redis = $sections['redis_health'] ?? [];
        $memoryRatio = $redis['memory_ratio'] ?? null;
        if (is_float($memoryRatio) || is_int($memoryRatio)) {
            if ($memoryRatio >= $this->configFloat('redis_memory_review_ratio', 0.85)) {
                $breaches[] = $this->breach('redis-memory-review', 'review_required', 'Redis memory exceeds 85% of maxmemory.');
                $recommendations[] = 'Review Redis memory/key families today; do not flush automatically.';
            } elseif ($memoryRatio >= $this->configFloat('redis_memory_warning_ratio', 0.70)) {
                $breaches[] = $this->breach('redis-memory-warning', 'observe_warning', 'Redis memory exceeds 70% of maxmemory.');
            }
        }
        $fragmentation = $redis['fragmentation_ratio'] ?? null;
        if ((is_float($fragmentation) || is_int($fragmentation)) && $fragmentation >= $this->configFloat('redis_fragmentation_warning_ratio', 1.8)) {
            $breaches[] = $this->breach('redis-fragmentation-warning', 'observe_warning', 'Redis fragmentation ratio exceeds 1.8.');
        }

        return [$breaches, array_values(array_unique($recommendations))];
    }

    private function collectMysqlTable(string $table): ?array
    {
        try {
            $row = DB::selectOne(
                'SELECT table_name, table_rows, data_length, index_length,
                        (data_length + index_length) AS total_bytes
                   FROM information_schema.tables
                  WHERE table_schema = DATABASE()
                    AND table_name = ?',
                [$table]
            );
        } catch (\Throwable) {
            return null;
        }

        return $row ? $this->mysqlTableRow($row) : null;
    }

    private function mysqlTableRow(object $row): array
    {
        $dataBytes = (int) ($row->data_length ?? $row->DATA_LENGTH ?? 0);
        $indexBytes = (int) ($row->index_length ?? $row->INDEX_LENGTH ?? 0);
        $totalBytes = (int) ($row->total_bytes ?? $row->TOTAL_BYTES ?? ($dataBytes + $indexBytes));

        return [
            'table_name' => (string) ($row->table_name ?? $row->TABLE_NAME ?? ''),
            'table_rows' => (int) ($row->table_rows ?? $row->TABLE_ROWS ?? 0),
            'data_bytes' => $dataBytes,
            'index_bytes' => $indexBytes,
            'total_bytes' => $totalBytes,
            'total_gb' => $this->bytesToGb($totalBytes),
        ];
    }

    private function findTable(array $rows, string $table): ?array
    {
        foreach ($rows as $row) {
            if (($row['table_name'] ?? null) === $table) {
                return $row;
            }
        }

        return null;
    }

    private function redisInfoSection(array $info, string $section): array
    {
        $nested = $info[$section] ?? $info[ucfirst($section)] ?? null;
        if (is_array($nested)) {
            return $nested;
        }

        return $info;
    }

    private function sectionStatuses(array $sections): array
    {
        $statuses = [];
        foreach ($sections as $name => $section) {
            $statuses[(string) $name] = (string) ($section['status'] ?? 'unknown');
        }

        return $statuses;
    }

    private function countValues(array $rows, string $key): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $value = (string) ($row[$key] ?? 'unknown');
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    private function markdownList(array $values): string
    {
        $values = array_values(array_filter(array_map(
            static fn (mixed $value): string => (string) $value,
            $values
        )));

        return $values === [] ? 'none' : implode(', ', $values);
    }

    private function failedSection(string $source, \Throwable $e): array
    {
        return [
            'status' => 'observe_warning',
            'source' => $source,
            'error' => $e->getMessage(),
        ];
    }

    private function breach(string $id, string $status, string $message): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'message' => $message,
        ];
    }

    private function worstStatus(array $statuses): string
    {
        $worst = 'observe_ok';
        foreach ($statuses as $status) {
            $status = (string) $status;
            if ((self::STATUS_RANK[$status] ?? 0) > self::STATUS_RANK[$worst]) {
                $worst = $status;
            }
        }

        return $worst;
    }

    private function bytesToGb(int $bytes): float
    {
        return round($bytes / 1024 / 1024 / 1024, 3);
    }

    private function configInt(string $key, int $default): int
    {
        return (int) config('health_thresholds.dba_telemetry.'.$key, $default);
    }

    private function configFloat(string $key, float $default): float
    {
        return (float) config('health_thresholds.dba_telemetry.'.$key, $default);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
