<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_NAME = 'offline_improvement_review';

    private const COMMAND = 'codex:offline-improvement-review --json --compact --queue-review';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $now = now();
        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => self::JOB_NAME],
            [
                'description' => 'Daily review queue lift for saved offline Codex improvement proposals.',
                'job_type' => 'command',
                'command' => self::COMMAND,
                'cron_expression' => '41 6 * * *',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 5,
                'max_parallel' => 1,
                'category' => 'Maintenance',
                'source_module' => 'Agents',
                'runtime_mode' => 'maintenance',
                'workload_family' => 'agent_safety',
                'resource_profile' => 'db',
                'stall_policy' => 'strict',
                'backlog_metric' => 'offline_improvement_proposals',
                'notification_mode' => 'digest',
                'notes' => json_encode([
                    'summary' => 'Scans storage/app/offline-improvement-proposals and creates/reuses a single pending skill_optimization review item when saved offline lessons exist.',
                    'runtime' => [
                        'report_dir' => 'offline-improvement-proposals',
                        'compact' => true,
                        'queue_review' => true,
                        'dedup_key' => 'offline_improvement_review',
                    ],
                    'safety' => [
                        'autonomous_skill_mutation_allowed' => false,
                        'autonomous_memory_mutation_allowed' => false,
                        'operator_review_required' => true,
                    ],
                ], JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')->where('name', self::JOB_NAME)->delete();
    }
};
