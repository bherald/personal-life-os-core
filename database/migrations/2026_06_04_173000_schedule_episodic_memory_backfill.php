<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_NAME = 'episodic_memory_backfill_recent';

    private const COMMAND = 'episodic:memory --backfill --days=2 --limit=100 --confirm --json --compact';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => self::JOB_NAME],
            [
                'description' => 'Bounded recent episodic-memory backfill for scheduled agent runs that keep live distillation off the cron critical path.',
                'job_type' => 'command',
                'command' => self::COMMAND,
                'cron_expression' => '12 */6 * * *',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 20,
                'timeout_locked' => 0,
                'max_parallel' => 1,
                'running_count' => 0,
                'fail_count' => 0,
                'last_run_output' => null,
                'notes' => 'Writes mechanical agent_episode_summaries only for sessions that lack summaries; compact JSON output avoids progress-bar noise. Embedding generation remains non-fatal per command behavior.',
                'category' => 'Maintenance',
                'source_module' => 'Ops',
                'runtime_mode' => 'maintenance',
                'workload_family' => 'ops',
                'resource_profile' => 'db',
                'stall_policy' => 'strict',
                'backlog_metric' => 'episodic_memory',
                'notification_mode' => 'digest',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
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
};
