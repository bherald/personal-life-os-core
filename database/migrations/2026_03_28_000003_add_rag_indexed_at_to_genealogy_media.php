<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add rag_indexed_at to genealogy_media for tracking RAG indexing status.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::selectOne("
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'genealogy_media'
              AND COLUMN_NAME = 'rag_indexed_at'
            LIMIT 1
        ");

        if (!$exists) {
            DB::statement("ALTER TABLE genealogy_media ADD COLUMN rag_indexed_at TIMESTAMP NULL AFTER enrichment_status");
        }
    }

    public function down(): void
    {
        $exists = DB::selectOne("
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'genealogy_media'
              AND COLUMN_NAME = 'rag_indexed_at'
            LIMIT 1
        ");

        if ($exists) {
            DB::statement("ALTER TABLE genealogy_media DROP COLUMN rag_indexed_at");
        }
    }
};
