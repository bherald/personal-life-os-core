<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds columns for Contextual Retrieval support.
     * context_prefix stores the generated context for each chunk.
     * contextualized_at tracks when context was generated.
     */
    public function up(): void
    {
        // Use raw SQL for PostgreSQL RAG database
        DB::connection('pgsql_rag')->statement('
            ALTER TABLE rag_documents
            ADD COLUMN IF NOT EXISTS context_prefix TEXT NULL
        ');

        DB::connection('pgsql_rag')->statement('
            ALTER TABLE rag_documents
            ADD COLUMN IF NOT EXISTS contextualized_at TIMESTAMP NULL
        ');

        // Index for finding documents needing contextualization
        DB::connection('pgsql_rag')->statement('
            CREATE INDEX IF NOT EXISTS idx_rag_documents_contextualized_at
            ON rag_documents(contextualized_at)
            WHERE contextualized_at IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection('pgsql_rag')->statement('
            DROP INDEX IF EXISTS idx_rag_documents_contextualized_at
        ');

        DB::connection('pgsql_rag')->statement('
            ALTER TABLE rag_documents
            DROP COLUMN IF EXISTS context_prefix
        ');

        DB::connection('pgsql_rag')->statement('
            ALTER TABLE rag_documents
            DROP COLUMN IF EXISTS contextualized_at
        ');
    }
};
