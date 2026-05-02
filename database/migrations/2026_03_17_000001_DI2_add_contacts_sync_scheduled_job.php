<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DI-2: Add contacts_sync_rag scheduled job
 *
 * Runs every 6 hours to sync Nextcloud contacts → MySQL → RAG.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Check if job already exists (idempotent)
        $existing = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'contacts_sync_rag'");
        if ($existing) {
            return;
        }

        DB::insert("
            INSERT INTO scheduled_jobs
                (name, command, description, cron_expression, enabled, timeout_minutes,
                 category, job_type, without_overlapping, run_in_background,
                 created_at, updated_at)
            VALUES
                (?, ?, ?, ?, 1, 15,
                 ?, 'job_class', 1, 0,
                 NOW(), NOW())
        ", [
            'contacts_sync_rag',
            'App\\Jobs\\ContactsSyncRAGJob',
            'DI-2: Sync Nextcloud contacts to MySQL + RAG index',
            '0 */6 * * *',
            'data_integration',
        ]);
    }

    public function down(): void
    {
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'contacts_sync_rag'");
    }
};
