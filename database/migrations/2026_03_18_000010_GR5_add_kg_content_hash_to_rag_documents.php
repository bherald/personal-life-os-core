<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GR-5: Add kg_content_hash to rag_documents (PostgreSQL)
 *
 * Enables diff-based KG backfill: documents are re-processed only when
 * their content_hash has changed since the last KG extraction, rather
 * than being permanently skipped once kg_extracted_at is stamped.
 */
return new class extends Migration
{
    public function getConnection(): string
    {
        return 'pgsql_rag';
    }

    public function up(): void
    {
        DB::connection('pgsql_rag')->statement(
            "ALTER TABLE rag_documents ADD COLUMN IF NOT EXISTS kg_content_hash VARCHAR(64)"
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_rag')->statement(
            "ALTER TABLE rag_documents DROP COLUMN IF EXISTS kg_content_hash"
        );
    }
};
