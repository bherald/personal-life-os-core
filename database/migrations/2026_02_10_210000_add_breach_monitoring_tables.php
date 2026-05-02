<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Breach monitoring for HIBP integration:
     * - breach_records: stores detected breaches per subject
     * - Adds last_breach_check to data_subjects
     */
    public function up(): void
    {
        // Create breach_records table
        DB::statement("
            CREATE TABLE IF NOT EXISTS breach_records (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                subject_id INT UNSIGNED NOT NULL,
                email VARCHAR(255) NOT NULL,
                breach_name VARCHAR(255) NOT NULL,
                breach_date DATE NULL,
                added_date DATETIME NULL,
                data_classes JSON NULL,
                is_verified TINYINT(1) NOT NULL DEFAULT 0,
                is_fabricated TINYINT(1) NOT NULL DEFAULT 0,
                is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_breach_subject (subject_id),
                INDEX idx_breach_email (email),
                INDEX idx_breach_name (breach_name),
                INDEX idx_breach_date (breach_date),
                UNIQUE KEY uk_breach_subject_name (subject_id, breach_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Add last_breach_check to data_subjects if column doesn't exist
        try {
            DB::statement("ALTER TABLE data_subjects ADD COLUMN last_breach_check TIMESTAMP NULL");
        } catch (\Exception $e) {
            // Column may already exist
        }
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS breach_records");

        try {
            DB::statement("ALTER TABLE data_subjects DROP COLUMN last_breach_check");
        } catch (\Exception $e) {
        }
    }
};
