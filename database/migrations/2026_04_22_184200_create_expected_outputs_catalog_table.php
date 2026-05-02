<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * APL #8B layers 4 + 6 — expected-outputs catalog.
 *
 * Layer 4 (data-freshness): "expected daily workflow outputs actually produced"
 * Layer 6 (notification-delivery): "Pushover delivery failures + silent
 * suppression patterns"
 *
 * One row per expected output. The `watchdog:data-freshness` artisan
 * command iterates enabled rows, runs each check through a safe
 * parametric handler (no raw SQL from operator edits), and reports
 * drift. No mutations — report-only.
 *
 * check_type handlers (initial set):
 *   - table_row_recent    → {table, column, max_age_minutes} — any row
 *                          in the table with column >= NOW() - window?
 *   - job_run_recent      → {job_name, status_in, max_age_minutes} —
 *                          any scheduled_job_runs row for that job name
 *                          with given status within the window?
 *   - log_pattern_recent  → {pattern, window_minutes} — log line matching
 *                          the regex within the window?
 *
 * Table exists on MySQL only; no pgsql_rag coupling.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tableExists('expected_outputs_catalog')) {
            return;
        }

        DB::statement("
            CREATE TABLE expected_outputs_catalog (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                scheduled_job_id INT UNSIGNED NULL,
                job_name VARCHAR(100) NULL,
                expected_item VARCHAR(255) NOT NULL,
                check_type ENUM('table_row_recent', 'job_run_recent', 'log_pattern_recent') NOT NULL,
                check_params JSON NOT NULL,
                freshness_window_minutes INT UNSIGNED NOT NULL,
                severity ENUM('info', 'warn', 'critical') NOT NULL DEFAULT 'warn',
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                notes TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_enabled_severity (enabled, severity),
                INDEX idx_job_name (job_name),
                INDEX idx_scheduled_job_id (scheduled_job_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        if ($this->tableExists('expected_outputs_catalog')) {
            DB::statement('DROP TABLE expected_outputs_catalog');
        }
    }

    private function tableExists(string $table): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        return (int) ($row->n ?? 0) > 0;
    }
};
