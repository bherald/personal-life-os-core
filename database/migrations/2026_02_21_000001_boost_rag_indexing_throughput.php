<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ===== FILE CATALOG SYNC (RAG indexing) =====
        // Was: --limit=500 → 10000. With 90/10 index/orphan split, indexes ~9000/run
        DB::update("
            UPDATE scheduled_jobs
            SET command = 'file-catalog:sync --full --limit=10000'
            WHERE name = 'file_catalog_sync'
              AND command LIKE '%file-catalog:sync%'
        ");

        // Add dedicated hourly RAG bulk indexing catch-up job
        // At 5000/hour, clears 229K backlog in ~2 days
        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = ?", ['rag_file_bulk_index']);
        if (!$exists) {
            DB::insert("
                INSERT INTO scheduled_jobs (name, command, cron_expression, category, description, enabled, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                'rag_file_bulk_index',
                'file-catalog:sync --rag-sync --limit=5000',
                '15 * * * *',
                'E13-FileRegistry',
                'Aggressive RAG indexing catch-up: 5000 files/hour until backlog cleared',
                1,
            ]);
        }

        // ===== THUMBNAILS =====
        // Was: 50/2hrs = 600/day → 500/hour = 12,000/day. Clears 174K in ~15 days
        DB::update("
            UPDATE scheduled_jobs
            SET command = 'files:thumbnails --generate --limit=500',
                cron_expression = '5 * * * *'
            WHERE name = 'file_thumbnails_generate'
        ");

        // ===== EXIF EXTRACTION =====
        // Was: 200/4hrs = 1200/day → 1000/2hrs = 12,000/day. Clears 52K in ~5 days
        DB::update("
            UPDATE scheduled_jobs
            SET command = 'files:enrich --type=exif --limit=1000',
                cron_expression = '30 */2 * * *'
            WHERE name = 'file_enrich_exif'
        ");

        // ===== FACE SCANNING =====
        // Was: 100/6hrs = 400/day → 300/4hrs = 1800/day. Clears 52K in ~29 days
        // (GPU-bound: conservative increase, staggered from AI)
        DB::update("
            UPDATE scheduled_jobs
            SET command = 'files:enrich --type=faces --limit=300',
                cron_expression = '0 */4 * * *'
            WHERE name = 'file_enrich_faces'
        ");

        // ===== AI ANALYSIS =====
        // Was: 30/2hrs = 360/day → 100/2hrs = 1200/day. Clears 150K in ~125 days
        // (GPU-bound with LLaVA: moderate increase, staggered 1hr after faces)
        DB::update("
            UPDATE scheduled_jobs
            SET command = 'files:enrich --type=ai --limit=100',
                cron_expression = '0 1,3,5,7,9,11,13,15,17,19,21,23 * * *'
            WHERE name = 'file_enrich_ai'
        ");

        // ===== EXIF WRITEBACK =====
        // Was: 100/day → 500/day. More realistic for actual files needing writeback
        DB::update("
            UPDATE scheduled_jobs
            SET command = 'files:enrich --type=writeback --limit=500'
            WHERE name = 'file_exif_writeback'
        ");
    }

    public function down(): void
    {
        DB::update("UPDATE scheduled_jobs SET command = 'file-catalog:sync --full' WHERE name = 'file_catalog_sync'");
        DB::delete("DELETE FROM scheduled_jobs WHERE name = ?", ['rag_file_bulk_index']);
        DB::update("UPDATE scheduled_jobs SET command = 'files:thumbnails --generate --limit=50', cron_expression = '0 */2 * * *' WHERE name = 'file_thumbnails_generate'");
        DB::update("UPDATE scheduled_jobs SET command = 'files:enrich --type=exif --limit=200', cron_expression = '30 */4 * * *' WHERE name = 'file_enrich_exif'");
        DB::update("UPDATE scheduled_jobs SET command = 'files:enrich --type=faces --limit=100', cron_expression = '0 */6 * * *' WHERE name = 'file_enrich_faces'");
        DB::update("UPDATE scheduled_jobs SET command = 'files:enrich --type=ai --limit=30', cron_expression = '0 */2 * * *' WHERE name = 'file_enrich_ai'");
        DB::update("UPDATE scheduled_jobs SET command = 'files:enrich --type=writeback --limit=100' WHERE name = 'file_exif_writeback'");
    }
};
