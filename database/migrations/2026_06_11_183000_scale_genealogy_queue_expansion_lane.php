<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_agent_research_queue')
            ->update([
                'cron_expression' => '0 * * * *',
                'max_parallel' => 3,
                'resource_profile' => 'genealogy_llm',
                'backlog_metric' => 'genealogy_queue',
                'next_run_at' => now()->addMinutes(10),
                'notes' => json_encode([
                    'runtime' => [
                        'runtime_mode' => 'queue_research',
                        'resource_profile' => 'genealogy_llm',
                        'backlog_metric' => 'genealogy_queue',
                        'parallel_min_backlog' => 2,
                        'report_category' => 'Genealogy',
                    ],
                    'legacy_note' => 'Queue-driven records research - processes pending research queue items',
                    'expansion_lane' => [
                        'enabled_at' => '2026-06-11',
                        'scope' => 'bounded source-backed queue research for FT4 expansion',
                        'operator_approved_media_capture' => true,
                        'canonical_write_gate' => 'review-backed genealogy write paths only',
                        'excluded' => [
                            'face identity automation',
                            'weak or uncited fact canonicalization',
                            'retired broad Genea scheduler lanes',
                        ],
                    ],
                ], JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_agent_research_queue')
            ->update([
                'cron_expression' => '0 */2 * * *',
                'max_parallel' => 2,
                'resource_profile' => 'genealogy_llm',
                'backlog_metric' => 'genealogy_queue',
                'next_run_at' => now()->addHours(2),
                'notes' => json_encode([
                    'runtime' => [
                        'runtime_mode' => 'queue_research',
                        'resource_profile' => 'genealogy_llm',
                        'backlog_metric' => 'genealogy_queue',
                        'parallel_min_backlog' => 2,
                        'report_category' => 'Genealogy',
                    ],
                    'legacy_note' => 'Queue-driven records research - processes pending research queue items',
                ], JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
