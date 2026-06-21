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
                'max_parallel' => 2,
                'resource_profile' => 'genealogy_llm',
                'backlog_metric' => 'genealogy_queue',
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

    public function down(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_agent_research_queue')
            ->update([
                'max_parallel' => 1,
                'resource_profile' => 'default',
                'backlog_metric' => 'genealogy_queue',
                'notes' => json_encode([
                    'runtime' => [
                        'runtime_mode' => 'queue_research',
                        'resource_profile' => 'default',
                        'backlog_metric' => 'genealogy_queue',
                        'report_category' => 'Genealogy',
                    ],
                    'legacy_note' => 'Queue-driven records research - processes pending research queue items',
                ], JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
