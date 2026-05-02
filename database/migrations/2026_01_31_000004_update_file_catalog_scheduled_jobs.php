<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Update scheduled jobs for File Catalog refactoring
 *
 * - Removes file_execute_actions job (no longer used)
 * - Updates file registry jobs to use new catalog sync command
 * - Adds nightly file catalog sync job
 */
return new class extends Migration
{
    public function up(): void
    {
        // Remove file_execute_actions job (Windows file organizer - deleted)
        DB::delete("
            DELETE FROM scheduled_jobs
            WHERE name LIKE '%execute%action%'
               OR command LIKE '%execute-actions%'
        ");

        // Remove old bundle detection jobs (now integrated into catalog sync)
        DB::delete("
            DELETE FROM scheduled_jobs
            WHERE name LIKE '%bundle%'
               OR command LIKE '%bundle%'
        ");

        // Update existing file_registry_scan job or create new catalog sync
        $existing = DB::selectOne("
            SELECT id FROM scheduled_jobs
            WHERE name LIKE '%registry%scan%'
               OR command LIKE 'files:registry%--scan%'
        ");

        if ($existing) {
            // Update to new catalog sync command
            DB::update("
                UPDATE scheduled_jobs
                SET name = 'File Catalog Sync',
                    job_type = 'command',
                    command = 'file-catalog:sync --full',
                    cron_expression = '0 2 * * *',
                    description = 'Nightly file catalog sync: scan new files, verify existing, sync to RAG',
                    updated_at = NOW()
                WHERE id = ?
            ", [$existing->id]);
        } else {
            // Create new job
            DB::insert("
                INSERT INTO scheduled_jobs (
                    name, job_type, command, cron_expression,
                    enabled, run_in_background, description,
                    created_at, updated_at
                ) VALUES (
                    'File Catalog Sync',
                    'artisan',
                    'file-catalog:sync --full',
                    '0 2 * * *',
                    1,
                    1,
                    'Nightly file catalog sync: scan new files, verify existing, sync to RAG',
                    NOW(),
                    NOW()
                )
            ");
        }

        // Update file_registry_verify to use simplified command
        DB::update("
            UPDATE scheduled_jobs
            SET command = 'files:registry --verify',
                description = 'Verify registered files still exist in Nextcloud',
                updated_at = NOW()
            WHERE name LIKE '%registry%verify%'
               OR command LIKE '%registry%--verify%'
        ");
    }

    public function down(): void
    {
        // Restore file_execute_actions job
        DB::insert("
            INSERT INTO scheduled_jobs (
                name, job_type, command, cron_expression,
                enabled, run_in_background, description,
                created_at, updated_at
            ) VALUES (
                'Execute File Actions',
                'artisan',
                'files:execute-actions --execute',
                '30 5 * * *',
                0,
                1,
                'Execute approved file actions',
                NOW(),
                NOW()
            )
        ");

        // Restore file_registry_bundles job
        DB::insert("
            INSERT INTO scheduled_jobs (
                name, job_type, command, cron_expression,
                enabled, run_in_background, description,
                created_at, updated_at
            ) VALUES (
                'File Registry Bundle Detection',
                'artisan',
                'files:registry --scan-bundles=/Library --propose --limit=100',
                '0 3 * * 0,1,3,5',
                0,
                1,
                'Detect bundles and create proposals',
                NOW(),
                NOW()
            )
        ");

        // Restore file_catalog_sync to file_registry_scan
        DB::update("
            UPDATE scheduled_jobs
            SET name = 'File Registry Scan',
                command = 'files:registry --scan=/Library --limit=500',
                cron_expression = '0 2 * * 0,1,3,5',
                description = 'Scan configured Nextcloud library for new files',
                updated_at = NOW()
            WHERE name = 'File Catalog Sync'
        ");
    }
};
