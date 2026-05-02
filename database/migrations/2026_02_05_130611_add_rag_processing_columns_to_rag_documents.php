<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Using raw SQL for PostgreSQL RAG database per project standards
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE rag_documents
            ADD COLUMN IF NOT EXISTS kg_extracted_at TIMESTAMP NULL,
            ADD COLUMN IF NOT EXISTS sentence_indexed_at TIMESTAMP NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE rag_documents
            DROP COLUMN IF EXISTS kg_extracted_at,
            DROP COLUMN IF EXISTS sentence_indexed_at
        ");
    }
};
