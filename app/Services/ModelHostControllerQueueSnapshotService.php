<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ModelHostControllerQueueSnapshotService
{
    /** @var list<string> */
    private const ACTIVITY_TABLES = [
        'scheduled_job_runs',
        'task_custody_records',
    ];

    /** @var list<string> */
    private const TABLES = [
        'jobs',
        'failed_jobs',
        'scheduled_jobs',
        'scheduled_job_runs',
        'task_custody_records',
    ];

    /** @var array<string,list<string>> */
    private const SAFE_COLUMNS = [
        'jobs' => ['id', 'queue', 'attempts', 'reserved_at', 'available_at', 'created_at'],
        'failed_jobs' => ['id', 'uuid', 'queue', 'failed_at'],
        'scheduled_jobs' => ['id', 'name', 'enabled', 'last_run_status', 'last_pid', 'max_parallel', 'running_pids', 'running_count', 'next_run_at', 'run_count', 'fail_count'],
        'scheduled_job_runs' => ['id', 'scheduled_job_id', 'started_at', 'completed_at', 'status', 'duration_seconds', 'items_processed', 'triggered_by', 'pid', 'worker_id'],
        'task_custody_records' => ['id', 'surface', 'surface_ref', 'owner_token', 'acquired_at', 'expires_at', 'released_at', 'outcome'],
    ];

    /** @var array<string,list<string>> */
    private const PRESERVATION_COLUMNS = [
        'jobs' => ['id', 'queue', 'attempts', 'reserved_at', 'available_at', 'created_at'],
        'failed_jobs' => ['id', 'uuid', 'queue', 'failed_at'],
        'scheduled_jobs' => ['id', 'name', 'enabled', 'max_parallel'],
    ];

    /** @return array<string,mixed> */
    public function capture(): array
    {
        $tables = [];
        foreach (self::TABLES as $table) {
            $tables[$table] = $this->tableSnapshot($table);
        }

        $canonical = json_encode($tables, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $preservation = [];
        foreach (self::PRESERVATION_COLUMNS as $table => $columns) {
            $preservation[$table] = $this->tableSnapshot($table, $columns);
        }
        $preservationCanonical = json_encode($preservation, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'schema' => 'plos.model_host_controller_queue_snapshot.v1',
            'tables' => $tables,
            'snapshot_sha256' => hash('sha256', $canonical),
            'preservation_sha256' => hash('sha256', $preservationCanonical),
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function tableSnapshot(string $table, ?array $selectedColumns = null): array
    {
        if (! Schema::hasTable($table)) {
            return ['present' => false, 'count' => null, 'rows_sha256' => null];
        }
        if ($selectedColumns === null && in_array($table, self::ACTIVITY_TABLES, true)) {
            return $this->activitySnapshot($table);
        }

        $columns = array_values(array_filter(
            $selectedColumns ?? self::SAFE_COLUMNS[$table] ?? [],
            static fn (string $column): bool => Schema::hasColumn($table, $column),
        ));
        $rows = [];
        $count = null;
        if ($columns !== []) {
            $query = DB::table($table)->select($columns);
            if (Schema::hasColumn($table, 'id')) {
                $query->orderBy('id');
            }
            foreach ($query->get() as $row) {
                $rows[] = array_map(static fn (mixed $value): mixed => $value, (array) $row);
            }
            $count = count($rows);
        }

        return [
            'present' => true,
            'count' => $count ?? (int) DB::table($table)->count(),
            'rows_sha256' => hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
    }

    /** @return array<string,mixed> */
    private function activitySnapshot(string $table): array
    {
        $summary = [
            'count' => (int) DB::table($table)->count(),
            'maximum_id' => Schema::hasColumn($table, 'id') ? DB::table($table)->max('id') : null,
            'active_count' => null,
            'failed_count' => null,
        ];
        $completionColumn = match ($table) {
            'scheduled_job_runs' => 'completed_at',
            'task_custody_records' => 'released_at',
            default => null,
        };
        if ($completionColumn !== null && Schema::hasColumn($table, $completionColumn)) {
            $summary['active_count'] = (int) DB::table($table)->whereNull($completionColumn)->count();
        }
        if ($table === 'scheduled_job_runs' && Schema::hasColumn($table, 'status')) {
            $summary['failed_count'] = (int) DB::table($table)
                ->whereIn('status', ['failed', 'error'])
                ->count();
        }

        return [
            'present' => true,
            'count' => $summary['count'],
            'rows_sha256' => hash('sha256', json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'activity_aggregate' => true,
        ];
    }
}
