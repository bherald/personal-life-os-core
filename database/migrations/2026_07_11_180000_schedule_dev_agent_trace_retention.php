<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_NAME = 'dev_agent_trace_retention';

    private const COMMAND = 'ops:dev-agent-trace-retention --retention-days=14 --max-files=100 --execute --confirm --json --compact';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $now = now();
        $values = [
            'description' => 'Bounded retention cleanup for strict date-named dev-agent trace files inside storage/app.',
            'job_type' => 'command',
            'command' => self::COMMAND,
            'cron_expression' => '47 3 * * *',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'stall_exempt' => 0,
            'timeout_minutes' => 5,
            'timeout_locked' => 0,
            'max_parallel' => 1,
            'running_count' => 0,
            'fail_count' => 0,
            'category' => 'Maintenance',
            'source_module' => 'Ops',
            'runtime_mode' => 'maintenance',
            'workload_family' => 'ops',
            'resource_profile' => 'db',
            'stall_policy' => 'strict',
            'backlog_metric' => 'dev_agent_trace_retention',
            'notification_mode' => 'digest',
            'notes' => json_encode([
                'runtime' => [
                    'retention_days' => 14,
                    'max_files' => 100,
                    'selection' => 'strict YYYY-MM-DD.ndjson files older than the UTC cutoff',
                ],
                'safety' => [
                    'dry_run_default' => true,
                    'execute_requires_confirm' => true,
                    'storage_app_scope_only' => true,
                    'raw_trace_content_in_output' => false,
                ],
            ], JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];
        $values = $this->existingColumns($values);

        $existing = DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->first(['id']);

        if ($existing !== null) {
            DB::table('scheduled_jobs')
                ->where('id', $existing->id)
                ->update($values);

            return;
        }

        DB::table('scheduled_jobs')->insert($this->existingColumns(array_merge(
            ['name' => self::JOB_NAME, 'created_at' => $now],
            $values
        )));
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->where('command', self::COMMAND)
            ->delete();
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function existingColumns(array $values): array
    {
        return array_filter(
            $values,
            static fn (string $column): bool => Schema::hasColumn('scheduled_jobs', $column),
            ARRAY_FILTER_USE_KEY
        );
    }
};
