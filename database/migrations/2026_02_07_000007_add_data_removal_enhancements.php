<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data removal enhancements:
     * - Proof archive for removal confirmations
     * - Broker health monitoring
     * - Removal effectiveness tracking
     * - Relisting detection columns
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS removal_proof_archive (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                removal_request_id INT UNSIGNED NOT NULL,
                proof_type ENUM('screenshot', 'email', 'confirmation_code', 'api_response') NOT NULL,
                file_path VARCHAR(500) NULL,
                content_hash VARCHAR(64) NULL,
                metadata JSON NULL,
                captured_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_proof_request (removal_request_id),
                INDEX idx_proof_type (proof_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS broker_health_checks (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                data_broker_id INT UNSIGNED NOT NULL,
                check_type ENUM('optout_page', 'form_validation', 'api_response') NOT NULL,
                status ENUM('healthy', 'degraded', 'broken', 'changed') NOT NULL DEFAULT 'healthy',
                response_code INT NULL,
                response_time_ms INT NULL,
                details JSON NULL,
                checked_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_health_broker (data_broker_id),
                INDEX idx_health_status (status),
                INDEX idx_health_checked (checked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS removal_effectiveness (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                data_broker_id INT UNSIGNED NOT NULL,
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                requests_submitted INT UNSIGNED NOT NULL DEFAULT 0,
                requests_confirmed INT UNSIGNED NOT NULL DEFAULT 0,
                requests_failed INT UNSIGNED NOT NULL DEFAULT 0,
                avg_days_to_removal DECIMAL(5,1) NULL,
                relisting_count INT UNSIGNED NOT NULL DEFAULT 0,
                success_rate DECIMAL(5,2) NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_effectiveness_broker (data_broker_id),
                INDEX idx_effectiveness_period (period_start, period_end)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Add columns to data_brokers
        try {
            DB::statement("ALTER TABLE data_brokers ADD COLUMN health_status VARCHAR(20) NULL DEFAULT 'unknown'");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE data_brokers ADD COLUMN last_health_check TIMESTAMP NULL");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE data_brokers ADD COLUMN optout_page_hash VARCHAR(64) NULL");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE data_brokers ADD COLUMN badbool_id VARCHAR(50) NULL");
        } catch (\Exception $e) {
        }

        // Add columns to removal_requests
        try {
            DB::statement("ALTER TABLE removal_requests ADD COLUMN relisting_detected_at TIMESTAMP NULL");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE removal_requests ADD COLUMN relisting_count INT UNSIGNED NOT NULL DEFAULT 0");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE removal_requests ADD COLUMN last_verification_at TIMESTAMP NULL");
        } catch (\Exception $e) {
        }
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS removal_effectiveness");
        DB::statement("DROP TABLE IF EXISTS broker_health_checks");
        DB::statement("DROP TABLE IF EXISTS removal_proof_archive");

        try {
            DB::statement("ALTER TABLE data_brokers DROP COLUMN health_status");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE data_brokers DROP COLUMN last_health_check");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE data_brokers DROP COLUMN optout_page_hash");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE data_brokers DROP COLUMN badbool_id");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE removal_requests DROP COLUMN relisting_detected_at");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE removal_requests DROP COLUMN relisting_count");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE removal_requests DROP COLUMN last_verification_at");
        } catch (\Exception $e) {
        }
    }
};
