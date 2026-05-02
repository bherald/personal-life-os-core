<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * N75: Add stall_exempt flag to scheduled_jobs.
 *
 * I/O-heavy jobs (LLM HTTP calls, Tika, shell_exec) accumulate negligible PHP CPU time
 * even while actively working. The 30-min/<10s-CPU stall detector fires false positives
 * on these jobs, killing them mid-run.
 *
 * stall_exempt = 1 tells detectStalledProcesses() to skip this job entirely.
 * The pcntl_alarm() hard timeout still enforces the per-job timeout_minutes ceiling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_jobs', function (Blueprint $table) {
            $table->tinyInteger('stall_exempt')->default(0)->after('without_overlapping')
                ->comment('Skip CPU-based stall detection for I/O-bound jobs');
        });

        // Mark known I/O-bound LLM jobs as exempt
        DB::update("
            UPDATE scheduled_jobs
            SET stall_exempt = 1
            WHERE name IN ('file_enrich_ai', 'file_enrich_faces', 'raptor_build', 'rag_sentence_indexing')
        ");
    }

    public function down(): void
    {
        Schema::table('scheduled_jobs', function (Blueprint $table) {
            $table->dropColumn('stall_exempt');
        });
    }
};
