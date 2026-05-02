<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Add items_processed to scheduled_job_runs for structured batch tracking
        try {
            DB::statement("ALTER TABLE scheduled_job_runs ADD COLUMN items_processed INT UNSIGNED NULL AFTER duration_seconds");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column name')) {
                throw $e;
            }
        }

        // Add timeout_locked to scheduled_jobs for human-overridden timeouts
        try {
            DB::statement("ALTER TABLE scheduled_jobs ADD COLUMN timeout_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER timeout_minutes");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column name')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        try {
            DB::statement("ALTER TABLE scheduled_job_runs DROP COLUMN items_processed");
        } catch (\Exception $e) {
            // ignore
        }
        try {
            DB::statement("ALTER TABLE scheduled_jobs DROP COLUMN timeout_locked");
        } catch (\Exception $e) {
            // ignore
        }
    }
};
