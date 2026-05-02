<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'ops_host_baseline_jobs_heavy_window')
            ->update([
                'command' => 'ops:host-baseline jobs --repeat=3 --interval=900',
                'updated_at' => now(),
            ]);

        DB::table('scheduled_jobs')
            ->where('name', 'research_analyst_agent')
            ->update([
                'notes' => json_encode([
                    'notify' => true,
                    'max_iterations' => 4,
                    'runtime' => [
                        'runtime_mode' => 'agent_loop',
                        'resource_profile' => 'default',
                        'backlog_metric' => 'research_analysis',
                        'report_category' => 'Research',
                    ],
                ], JSON_UNESCAPED_SLASHES),
                'timeout_minutes' => 90,
                'timeout_locked' => 1,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'research_analyst_agent')
            ->update([
                'notes' => json_encode([
                    'notify' => true,
                    'runtime' => [
                        'runtime_mode' => 'agent_loop',
                        'resource_profile' => 'default',
                        'backlog_metric' => 'research_analysis',
                        'report_category' => 'Research',
                    ],
                ], JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
