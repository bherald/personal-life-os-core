<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add pipeline monitor job - runs every 30 min to check backlogs and self-correct
        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = ?", ['pipeline_monitor']);
        if (!$exists) {
            DB::insert("
                INSERT INTO scheduled_jobs (name, command, cron_expression, category, description, enabled, timeout_minutes, without_overlapping, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                'pipeline_monitor',
                'pipeline:monitor --aggressive',
                '*/30 * * * *',
                'E13-FileRegistry',
                'Monitor enrichment pipelines, fix stalls, trigger catch-up runs when behind',
                1,
                25,  // timeout before next run at 30 min
                1,   // no overlapping
            ]);
        }

        // Also boost File Catalog Sync to 10000 (was partially applied with wrong name)
        DB::update("
            UPDATE scheduled_jobs
            SET command = 'file-catalog:sync --full --limit=10000'
            WHERE name = 'File Catalog Sync'
              AND command LIKE '%file-catalog:sync%'
        ");
    }

    public function down(): void
    {
        DB::delete("DELETE FROM scheduled_jobs WHERE name = ?", ['pipeline_monitor']);
        DB::update("
            UPDATE scheduled_jobs
            SET command = 'file-catalog:sync --full --limit=500'
            WHERE name = 'File Catalog Sync'
        ");
    }
};
