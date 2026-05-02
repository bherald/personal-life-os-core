<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N76: Add raptor_error_count to rag_documents.
 *
 * Tracks consecutive RAPTOR build failures per document. Documents that
 * fail 3+ times are permanently skipped by the batch job, preventing
 * problem files (e.g. "Chris' recipes" PDF) from consuming batch slots
 * every 8 hours indefinitely.
 *
 * Reset to 0 on successful build or when deleteHierarchy() is called
 * (--rebuild flag). --force flag bypasses the skip check entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE rag_documents
            ADD COLUMN IF NOT EXISTS raptor_error_count INTEGER NOT NULL DEFAULT 0
        ");
    }

    public function down(): void
    {
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE rag_documents DROP COLUMN IF EXISTS raptor_error_count
        ");
    }
};
