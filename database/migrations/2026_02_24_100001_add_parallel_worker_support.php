<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Parallel worker columns on scheduled_jobs
        $columns = [
            "max_parallel TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER last_pid",
            "running_pids JSON NULL AFTER max_parallel",
            "running_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER running_pids",
        ];

        foreach ($columns as $col) {
            try {
                DB::statement("ALTER TABLE scheduled_jobs ADD COLUMN {$col}");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'Duplicate column name')) {
                    throw $e;
                }
            }
        }

        // Worker ID on scheduled_job_runs
        try {
            DB::statement("ALTER TABLE scheduled_job_runs ADD COLUMN worker_id VARCHAR(36) NULL AFTER pid");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column name')) {
                throw $e;
            }
        }

        // Claim columns on file_registry for parallel worker file claiming
        try {
            DB::statement("ALTER TABLE file_registry ADD COLUMN claim_worker VARCHAR(36) NULL");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column name')) {
                throw $e;
            }
        }

        try {
            DB::statement("ALTER TABLE file_registry ADD COLUMN claim_expires_at TIMESTAMP NULL");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column name')) {
                throw $e;
            }
        }

        // Index for claim lookups
        try {
            DB::statement("CREATE INDEX idx_file_registry_claim ON file_registry (claim_worker, claim_expires_at)");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate key name')) {
                throw $e;
            }
        }

        // Set max_parallel = 2 for face and RAG jobs
        DB::update("UPDATE scheduled_jobs SET max_parallel = 2 WHERE command LIKE '%files:enrich --type=faces%'");
        DB::update("UPDATE scheduled_jobs SET max_parallel = 2 WHERE command LIKE '%file-catalog:sync --rag-sync%'");
        DB::update("UPDATE scheduled_jobs SET max_parallel = 2 WHERE command LIKE '%files:enrich --type=ai%'");
    }

    public function down(): void
    {
        $scheduledJobCols = ['max_parallel', 'running_pids', 'running_count'];
        foreach ($scheduledJobCols as $col) {
            try {
                DB::statement("ALTER TABLE scheduled_jobs DROP COLUMN {$col}");
            } catch (\Exception $e) {
                // Column may not exist
            }
        }

        try {
            DB::statement("ALTER TABLE scheduled_job_runs DROP COLUMN worker_id");
        } catch (\Exception $e) {
            // ignore
        }

        try {
            DB::statement("DROP INDEX idx_file_registry_claim ON file_registry");
        } catch (\Exception $e) {
            // ignore
        }

        foreach (['claim_worker', 'claim_expires_at'] as $col) {
            try {
                DB::statement("ALTER TABLE file_registry DROP COLUMN {$col}");
            } catch (\Exception $e) {
                // ignore
            }
        }
    }
};
