<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DI-9: Add rag_indexed_at tracking to genealogy_places and genealogy_sources
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE genealogy_places ADD COLUMN rag_indexed_at TIMESTAMP NULL DEFAULT NULL");
        DB::statement("ALTER TABLE genealogy_sources ADD COLUMN rag_indexed_at TIMESTAMP NULL DEFAULT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE genealogy_places DROP COLUMN IF EXISTS rag_indexed_at");
        DB::statement("ALTER TABLE genealogy_sources DROP COLUMN IF EXISTS rag_indexed_at");
    }
};
