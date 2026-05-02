<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'ops_host_baseline_jobs')
            ->where('enabled', 0)
            ->update([
                'last_run_status' => null,
                'last_run_output' => 'Superseded by ops_host_baseline_jobs_heavy_window; historical failed runs remain in scheduled_job_runs.',
                'next_run_at' => null,
                'fail_count' => 0,
                'notes' => DB::raw("CONCAT(COALESCE(notes, ''), ' | Superseded 2026-04-24: replaced by ops_host_baseline_jobs_heavy_window; retained disabled for history only.')"),
                'updated_at' => DB::raw('NOW()'),
            ]);
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'ops_host_baseline_jobs')
            ->where('enabled', 0)
            ->where('last_run_output', 'Superseded by ops_host_baseline_jobs_heavy_window; historical failed runs remain in scheduled_job_runs.')
            ->update([
                'last_run_status' => 'failed',
                'next_run_at' => '2026-04-23 08:30:00',
                'fail_count' => 2,
                'last_run_output' => 'Exception: Not enough arguments (missing: "scenario").',
                'updated_at' => DB::raw('NOW()'),
            ]);
    }
};
