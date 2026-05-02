<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RAG-3: Add sparse embedding column for SPLADE three-way hybrid search.
 * Uses pgvector 0.5.1+ native sparsevec type.
 */
return new class extends Migration {
    public function up(): void
    {
        // sparsevec(30522) = BERT vocab size used by SPLADE model
        DB::connection('pgsql_rag')->statement(
            "ALTER TABLE rag_documents ADD COLUMN IF NOT EXISTS sparse_embedding sparsevec(30522)"
        );

        // Track which docs have been SPLADE-encoded
        DB::connection('pgsql_rag')->statement(
            "ALTER TABLE rag_documents ADD COLUMN IF NOT EXISTS splade_indexed_at TIMESTAMP NULL"
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_rag')->statement(
            "ALTER TABLE rag_documents DROP COLUMN IF EXISTS sparse_embedding"
        );
        DB::connection('pgsql_rag')->statement(
            "ALTER TABLE rag_documents DROP COLUMN IF EXISTS splade_indexed_at"
        );
    }
};
