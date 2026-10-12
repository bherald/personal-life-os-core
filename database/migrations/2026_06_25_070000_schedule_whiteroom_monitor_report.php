<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_NAME = 'whiteroom_monitor_due_report';

    private const COMMAND = 'whiteroom:monitor --format=json --compact --due --limit=25';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $this->upsertScheduledJob();
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->delete();
    }

    private function upsertScheduledJob(): void
    {
        $now = now();
        $values = [
            'description' => 'Daily compact due-item report for the whiteroom project monitor registry.',
            'job_type' => 'command',
            'command' => self::COMMAND,
            'cron_expression' => '42 7 * * *',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'stall_exempt' => 0,
            'timeout_minutes' => 5,
            'max_parallel' => 1,
            'category' => 'Maintenance',
            'source_module' => 'Research',
            'runtime_mode' => 'observe',
            'workload_family' => 'research',
            'resource_profile' => 'db',
            'stall_policy' => 'strict',
            'backlog_metric' => 'whiteroom_monitor_due',
            'notification_mode' => 'digest',
            'notes' => json_encode([
                'summary' => 'Observe-only due-item report for external project watchlist monitoring.',
                'policy_source' => 'config.whiteroom_projects.monitor_projects',
                'runtime' => [
                    'limit' => 25,
                    'due_only' => true,
                    'compact' => true,
                ],
                'safety' => [
                    'read_only' => true,
                    'dependency_change_approved' => false,
                    'source_reuse_approved' => false,
                    'routing_change_approved' => false,
                    'schema_change_approved' => false,
                    'unattended_browser_action_approved' => false,
                ],
            ], JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];

        $existing = DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->first(['id']);

        if ($existing !== null) {
            DB::table('scheduled_jobs')
                ->where('id', $existing->id)
                ->update($values);

            return;
        }

        DB::table('scheduled_jobs')->insert(array_merge(
            ['name' => self::JOB_NAME, 'created_at' => $now],
            $values
        ));
    }
};
