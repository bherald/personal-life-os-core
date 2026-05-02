<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure scheduled_job_runs table exists (created pre-migration on prod, may be missing on dev)
        DB::statement("
            CREATE TABLE IF NOT EXISTS scheduled_job_runs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                scheduled_job_id INT UNSIGNED NOT NULL,
                started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMP NULL DEFAULT NULL,
                status ENUM('running','success','failed','timeout') NOT NULL DEFAULT 'running',
                output TEXT COMMENT 'Command output or error message',
                duration_seconds DECIMAL(10,2) DEFAULT NULL,
                triggered_by ENUM('scheduler','manual','api') NOT NULL DEFAULT 'scheduler',
                INDEX idx_job_started (scheduled_job_id, started_at),
                INDEX idx_status (status),
                FOREIGN KEY (scheduled_job_id) REFERENCES scheduled_jobs(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Add PID tracking to scheduled_jobs (try/catch for idempotency)
        try {
            DB::statement("ALTER TABLE scheduled_jobs ADD COLUMN last_pid INT UNSIGNED NULL AFTER last_run_output");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column name')) {
                throw $e;
            }
        }

        // Add PID to scheduled_job_runs
        try {
            DB::statement("ALTER TABLE scheduled_job_runs ADD COLUMN pid INT UNSIGNED NULL AFTER triggered_by");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column name')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        try {
            DB::statement("ALTER TABLE scheduled_jobs DROP COLUMN last_pid");
        } catch (\Exception $e) {
            // Column may not exist
        }

        try {
            DB::statement("ALTER TABLE scheduled_job_runs DROP COLUMN pid");
        } catch (\Exception $e) {
            // Column may not exist
        }
    }
};
