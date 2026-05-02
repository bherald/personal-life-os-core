<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N140 — Add enrichment tracking columns to genealogy_media.
 *
 * enrichment_status: pipeline state for the fact-extraction pass
 * enrichment_error:  last error message if status = 'failed'
 * enriched_at:       timestamp when enrichment completed (NULL = not yet run)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE genealogy_media
            ADD COLUMN enrichment_status ENUM('pending','processing','completed','failed','skipped') NULL
                AFTER analysis_status,
            ADD COLUMN enrichment_error TEXT NULL
                AFTER enrichment_status,
            ADD COLUMN enriched_at TIMESTAMP NULL
                AFTER enrichment_error,
            ADD INDEX idx_genealogy_media_enrichment (enrichment_status, media_type, file_exists)
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE genealogy_media DROP INDEX idx_genealogy_media_enrichment");
        DB::statement("ALTER TABLE genealogy_media DROP COLUMN enriched_at, DROP COLUMN enrichment_error, DROP COLUMN enrichment_status");
    }
};
