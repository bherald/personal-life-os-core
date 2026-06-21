<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => 'genealogy_supervisor'],
            [
                'description' => 'Coordinate FT4 Genea queue refills, bounded worker starts, media RAG catch-up, and review blocker counts.',
                'job_type' => 'command',
                'command' => 'genealogy:supervisor --tree=4 --json --compact',
                'cron_expression' => '*/15 * * * *',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 10,
                'max_parallel' => 1,
                'category' => 'Genealogy',
                'source_module' => 'Genealogy',
                'runtime_mode' => 'supervisor',
                'workload_family' => 'genealogy',
                'resource_profile' => 'db',
                'stall_policy' => 'strict',
                'backlog_metric' => 'genealogy_queue',
                'notification_mode' => 'digest',
                'notes' => json_encode([
                    'runtime' => [
                        'runtime_mode' => 'supervisor',
                        'workload_family' => 'genealogy',
                        'resource_profile' => 'db',
                        'backlog_metric' => 'genealogy_queue',
                        'report_category' => 'Genealogy',
                    ],
                    'supervises' => [
                        'genealogy_agent_assess',
                        'genealogy_agent_research_queue',
                        'genealogy:media-rag-index',
                    ],
                    'safety' => [
                        'canonical_writes' => false,
                        'face_identity_automation' => false,
                        'operator_review_required' => true,
                    ],
                ], JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_supervisor')
            ->delete();
    }
};
