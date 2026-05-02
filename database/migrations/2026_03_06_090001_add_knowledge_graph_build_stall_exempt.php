<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N77: Add knowledge_graph_build to stall_exempt.
 *
 * knowledge_graph_build makes LLM HTTP calls for entity extraction on each
 * document. The PHP process accumulates <10s CPU time even over 6-hour runs.
 * Stall detection was killing the job mid-run every ~30 minutes.
 *
 * Also sets stall_exempt on raptor_build (verified it needs this too).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update("
            UPDATE scheduled_jobs
            SET stall_exempt = 1
            WHERE name = 'knowledge_graph_build'
        ");
    }

    public function down(): void
    {
        DB::update("
            UPDATE scheduled_jobs
            SET stall_exempt = 0
            WHERE name = 'knowledge_graph_build'
        ");
    }
};
