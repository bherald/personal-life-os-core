<?php

namespace App\Services\Watchdog;

use Illuminate\Support\Facades\DB;

/**
 * APL #8B layers 4 + 6 — data-freshness / notification-delivery observer.
 *
 * Iterates `expected_outputs_catalog` rows and evaluates each through a
 * safe parametric handler. No operator-authored SQL is executed — all
 * check logic goes through the handler whitelist below.
 *
 * Handler contract: each handler gets the catalog row, returns either
 *   ['status' => 'pass', 'message' => '...']
 *   ['status' => 'fail', 'message' => '...']
 *
 * New check types land as new handler methods; catalog rows opt in by
 * setting their `check_type`.
 */
class DataFreshnessChecker
{
    public const CHECK_TABLE_ROW_RECENT = 'table_row_recent';
    public const CHECK_JOB_RUN_RECENT = 'job_run_recent';
    public const CHECK_LOG_PATTERN_RECENT = 'log_pattern_recent';

    /**
     * Run every enabled catalog row. Returns a report ordered by severity
     * (critical → warn → info) so the caller can decide exit codes.
     *
     * @return array<int, array{id:int, expected_item:string, severity:string, status:string, message:string, check_type:string}>
     */
    public function runAll(): array
    {
        $rows = DB::select(
            "SELECT id, expected_item, check_type, check_params, freshness_window_minutes, severity
             FROM expected_outputs_catalog
             WHERE enabled = 1
             ORDER BY FIELD(severity, 'critical', 'warn', 'info'), id"
        );

        $report = [];
        foreach ($rows as $row) {
            $params = json_decode((string) $row->check_params, true);
            if (! is_array($params)) {
                $report[] = $this->result($row, 'fail', 'invalid check_params JSON');
                continue;
            }

            try {
                $result = match ($row->check_type) {
                    self::CHECK_TABLE_ROW_RECENT => $this->checkTableRowRecent($params, (int) $row->freshness_window_minutes),
                    self::CHECK_JOB_RUN_RECENT => $this->checkJobRunRecent($params, (int) $row->freshness_window_minutes),
                    self::CHECK_LOG_PATTERN_RECENT => $this->checkLogPatternRecent($params, (int) $row->freshness_window_minutes),
                    default => ['status' => 'fail', 'message' => "unknown check_type: {$row->check_type}"],
                };
            } catch (\Throwable $e) {
                $result = ['status' => 'fail', 'message' => 'handler threw: '.$e->getMessage()];
            }

            $report[] = $this->result($row, $result['status'], $result['message']);
        }

        return $report;
    }

    /**
     * Evaluate a single catalog row by id. Useful for targeted runs from
     * artisan (--id=N) or test isolation.
     */
    public function runOne(int $catalogId): ?array
    {
        $row = DB::selectOne(
            'SELECT id, expected_item, check_type, check_params, freshness_window_minutes, severity
             FROM expected_outputs_catalog WHERE id = ? AND enabled = 1',
            [$catalogId]
        );
        if (! $row) {
            return null;
        }

        $params = json_decode((string) $row->check_params, true);
        if (! is_array($params)) {
            return $this->result($row, 'fail', 'invalid check_params JSON');
        }

        try {
            $result = match ($row->check_type) {
                self::CHECK_TABLE_ROW_RECENT => $this->checkTableRowRecent($params, (int) $row->freshness_window_minutes),
                self::CHECK_JOB_RUN_RECENT => $this->checkJobRunRecent($params, (int) $row->freshness_window_minutes),
                self::CHECK_LOG_PATTERN_RECENT => $this->checkLogPatternRecent($params, (int) $row->freshness_window_minutes),
                default => ['status' => 'fail', 'message' => "unknown check_type: {$row->check_type}"],
            };
        } catch (\Throwable $e) {
            $result = ['status' => 'fail', 'message' => 'handler threw: '.$e->getMessage()];
        }

        return $this->result($row, $result['status'], $result['message']);
    }

    /**
     * Handler: table_row_recent.
     * params: {"table": "rag_documents", "column": "created_at"}
     */
    private function checkTableRowRecent(array $params, int $windowMinutes): array
    {
        $table = $this->safeIdentifier($params['table'] ?? '');
        $column = $this->safeIdentifier($params['column'] ?? '');
        if ($table === '' || $column === '') {
            return ['status' => 'fail', 'message' => 'table_row_recent requires {table, column}'];
        }

        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM {$table} WHERE {$column} >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$windowMinutes]
        );
        $count = (int) ($row->n ?? 0);

        if ($count > 0) {
            return ['status' => 'pass', 'message' => "{$count} row(s) in {$table}.{$column} within {$windowMinutes}m"];
        }
        return ['status' => 'fail', 'message' => "0 rows in {$table}.{$column} within {$windowMinutes}m — expected output missing"];
    }

    /**
     * Handler: job_run_recent.
     * params: {"job_name": "rag_sentence_indexing", "status_in": ["success"]}
     */
    private function checkJobRunRecent(array $params, int $windowMinutes): array
    {
        $jobName = (string) ($params['job_name'] ?? '');
        $statuses = is_array($params['status_in'] ?? null) ? $params['status_in'] : ['success'];
        $statuses = array_values(array_filter($statuses, 'is_string'));

        if ($jobName === '' || $statuses === []) {
            return ['status' => 'fail', 'message' => 'job_run_recent requires {job_name, status_in:[]}'];
        }

        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM scheduled_job_runs sjr
             JOIN scheduled_jobs sj ON sj.id = sjr.scheduled_job_id
             WHERE sj.name = ?
               AND sjr.status IN ({$placeholders})
               AND sjr.completed_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$jobName, ...$statuses, $windowMinutes]
        );
        $count = (int) ($row->n ?? 0);

        $statusesStr = implode('|', $statuses);
        if ($count > 0) {
            return ['status' => 'pass', 'message' => "{$count} {$statusesStr} run(s) of {$jobName} within {$windowMinutes}m"];
        }
        return ['status' => 'fail', 'message' => "no {$statusesStr} runs of {$jobName} within {$windowMinutes}m"];
    }

    /**
     * Handler: log_pattern_recent.
     * params: {"pattern": "daily_digests", "log_path": "storage/logs/laravel.log"}
     *
     * Scans the tail of the log for the pattern. Operator-provided
     * pattern is a plain substring match — no regex injection risk, and
     * intentionally less powerful so a typo can't nuke the check.
     */
    private function checkLogPatternRecent(array $params, int $windowMinutes): array
    {
        $pattern = (string) ($params['pattern'] ?? '');
        $logPath = (string) ($params['log_path'] ?? storage_path('logs/laravel.log'));
        $tailLines = (int) ($params['tail_lines'] ?? 5000);

        if ($pattern === '' || ! is_file($logPath) || ! is_readable($logPath)) {
            return ['status' => 'fail', 'message' => 'log_pattern_recent requires {pattern} and a readable log_path'];
        }

        // Last N lines of the log. Read everything in the 1 MB seek window
        // into a buffer, then slice the tail — earlier code stopped at the
        // first $tailLines of the chunk, which on a busy log captured the
        // OLDEST lines in the window and missed anything recent.
        $handle = fopen($logPath, 'r');
        if ($handle === false) {
            return ['status' => 'fail', 'message' => 'could not open log_path'];
        }
        $buffer = [];
        try {
            fseek($handle, -min(filesize($logPath), 1024 * 1024), SEEK_END); // last 1 MB cap
            while (($line = fgets($handle)) !== false) {
                $buffer[] = $line;
            }
        } finally {
            fclose($handle);
        }
        $lines = $tailLines > 0 && count($buffer) > $tailLines
            ? array_slice($buffer, -$tailLines)
            : $buffer;

        $windowStart = time() - ($windowMinutes * 60);
        foreach ($lines as $line) {
            if (! str_contains($line, $pattern)) {
                continue;
            }
            // Timestamp extraction: "[2026-04-22 14:00:00]" at line start.
            if (preg_match('~^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]~', $line, $m)) {
                $ts = strtotime($m[1]);
                if ($ts !== false && $ts >= $windowStart) {
                    return ['status' => 'pass', 'message' => "pattern '{$pattern}' found in log within {$windowMinutes}m"];
                }
            } else {
                // No timestamp available — presence counts.
                return ['status' => 'pass', 'message' => "pattern '{$pattern}' found in recent tail (no timestamp)"];
            }
        }

        return ['status' => 'fail', 'message' => "pattern '{$pattern}' NOT found in log within {$windowMinutes}m"];
    }

    /**
     * Whitelist check: only allow bare identifiers [A-Za-z0-9_] to appear
     * in table/column names. Prevents a malicious catalog row from using
     * ';' or ' OR 1=1' to escape the WHERE clause.
     */
    private function safeIdentifier(string $name): string
    {
        return preg_match('~^[A-Za-z][A-Za-z0-9_]{0,63}$~', $name) ? $name : '';
    }

    /**
     * @return array{id:int, expected_item:string, severity:string, status:string, message:string, check_type:string}
     */
    private function result(object $row, string $status, string $message): array
    {
        return [
            'id' => (int) $row->id,
            'expected_item' => (string) $row->expected_item,
            'severity' => (string) $row->severity,
            'status' => $status,
            'message' => $message,
            'check_type' => (string) $row->check_type,
        ];
    }
}
