<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds parent_id column for semantic chunking support.
     * Chunks of a document link to the first chunk (parent).
     */
    public function up(): void
    {
        // Use raw SQL for PostgreSQL RAG database
        DB::connection('pgsql_rag')->statement('
            ALTER TABLE rag_documents
            ADD COLUMN IF NOT EXISTS parent_id BIGINT NULL
            REFERENCES rag_documents(id) ON DELETE CASCADE
        ');

        // Index for efficient child lookup
        DB::connection('pgsql_rag')->statement('
            CREATE INDEX IF NOT EXISTS idx_rag_documents_parent_id
            ON rag_documents(parent_id)
            WHERE parent_id IS NOT NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection('pgsql_rag')->statement('
            DROP INDEX IF EXISTS idx_rag_documents_parent_id
        ');

        DB::connection('pgsql_rag')->statement('
            ALTER TABLE rag_documents
            DROP COLUMN IF EXISTS parent_id
        ');
    }
};
