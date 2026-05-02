<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Bump rag_sentence_indexing batch from 50 → 200 to match N79 raptor_build bump
        DB::statement("
            UPDATE scheduled_jobs
            SET command = 'rag:build-sentences --limit=200'
            WHERE name = 'rag_sentence_indexing'
              AND command LIKE 'rag:build-sentences%'
        ");
    }

    public function down(): void
    {
        DB::statement("
            UPDATE scheduled_jobs
            SET command = 'rag:build-sentences --limit=50'
            WHERE name = 'rag_sentence_indexing'
              AND command LIKE 'rag:build-sentences%'
        ");
    }
};
