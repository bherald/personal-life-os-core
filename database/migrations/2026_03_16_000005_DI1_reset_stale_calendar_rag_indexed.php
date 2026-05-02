<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DI-1: Reset rag_indexed_at for calendar events that were marked as indexed
 * but have no corresponding RAG documents (stale from prior incomplete indexing).
 */
return new class extends Migration
{
    public function up(): void
    {
        $reset = DB::update("UPDATE calendar_events SET rag_indexed_at = NULL WHERE rag_indexed_at IS NOT NULL");
        if ($reset > 0) {
            \Illuminate\Support\Facades\Log::info("DI-1: Reset rag_indexed_at on {$reset} calendar events for re-indexing");
        }
    }

    public function down(): void
    {
        // No-op — events will be re-indexed by the job
    }
};
