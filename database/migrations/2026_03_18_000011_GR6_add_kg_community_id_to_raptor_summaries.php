<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GR-6: Add kg_community_id to raptor_summaries (PostgreSQL)
 *
 * Bridge column linking each RAPTOR summary to its dominant KG community.
 * Populated by `rag:link-raptor-communities` after community detection runs.
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
            "ALTER TABLE raptor_summaries ADD COLUMN IF NOT EXISTS kg_community_id BIGINT"
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_rag')->statement(
            "ALTER TABLE raptor_summaries DROP COLUMN IF EXISTS kg_community_id"
        );
    }
};
