<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Folder Rules Engine tables
     *
     * Rule-based file organization with condition/action JSON patterns.
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS file_organization_rules (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                description TEXT NULL,
                priority INT NOT NULL DEFAULT 100,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                conditions JSON NOT NULL,
                actions JSON NOT NULL,
                match_mode ENUM('all', 'any') NOT NULL DEFAULT 'all',
                scope_path VARCHAR(500) NULL,
                last_matched_at TIMESTAMP NULL,
                match_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_rules_priority (priority),
                INDEX idx_rules_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS file_organization_rule_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                rule_id INT UNSIGNED NOT NULL,
                file_registry_id INT UNSIGNED NULL,
                asset_uuid CHAR(36) NULL,
                action_type VARCHAR(50) NOT NULL,
                action_details JSON NULL,
                status ENUM('success', 'failed', 'dry_run') NOT NULL DEFAULT 'success',
                error_message TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_rule_log_rule (rule_id),
                INDEX idx_rule_log_file (file_registry_id),
                INDEX idx_rule_log_created (created_at),
                INDEX idx_rule_log_status (status),
                CONSTRAINT fk_rule_log_rule FOREIGN KEY (rule_id) REFERENCES file_organization_rules(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS file_organization_rule_log");
        DB::statement("DROP TABLE IF EXISTS file_organization_rules");
    }
};
