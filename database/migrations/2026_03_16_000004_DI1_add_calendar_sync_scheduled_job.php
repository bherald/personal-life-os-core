<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DI-1: Add scheduled job for calendar sync + RAG indexing.
 * Runs every 2 hours to keep calendar_events in sync with Nextcloud
 * and index new/updated events into RAG.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'calendar_sync_rag'");
        if ($exists) {
            return;
        }

        DB::insert("INSERT INTO scheduled_jobs
            (name, description, job_type, command, cron_expression, enabled, run_in_background, without_overlapping, timeout_minutes, category, source_module, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())", [
            'calendar_sync_rag',
            'Sync Nextcloud calendar events to MySQL and index to RAG',
            'job_class',
            'App\\Jobs\\CalendarSyncRAGJob',
            '0 */2 * * *', // Every 2 hours
            1, // enabled
            1, // run_in_background
            1, // without_overlapping
            15, // 15 min timeout
            'data_ingestion',
            'calendar',
        ]);
    }

    public function down(): void
    {
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'calendar_sync_rag'");
    }
};
