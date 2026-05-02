<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Create webhook_triggers table
        DB::statement("
            CREATE TABLE webhook_triggers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                workflow_id INT UNSIGNED NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                secret_key VARCHAR(64) NOT NULL,
                allowed_ips JSON NULL,
                input_schema JSON NULL,
                last_triggered_at TIMESTAMP NULL,
                trigger_count INT UNSIGNED DEFAULT 0,
                rate_limit INT UNSIGNED DEFAULT 60 COMMENT 'Max requests per minute',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_webhook_triggers_workflow_id (workflow_id),
                INDEX idx_webhook_triggers_token (token),
                INDEX idx_webhook_triggers_is_active (is_active),
                CONSTRAINT fk_webhook_triggers_workflow FOREIGN KEY (workflow_id)
                    REFERENCES workflows(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create webhook_trigger_logs table
        DB::statement("
            CREATE TABLE webhook_trigger_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                trigger_id INT UNSIGNED NOT NULL,
                request_ip VARCHAR(45) NOT NULL,
                request_headers JSON NULL,
                request_body JSON NULL,
                workflow_run_id INT UNSIGNED NULL,
                status ENUM('success', 'rejected', 'error') NOT NULL,
                error_message TEXT NULL,
                response_time_ms INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_webhook_trigger_logs_trigger_id (trigger_id),
                INDEX idx_webhook_trigger_logs_status (status),
                INDEX idx_webhook_trigger_logs_created_at (created_at),
                CONSTRAINT fk_webhook_trigger_logs_trigger FOREIGN KEY (trigger_id)
                    REFERENCES webhook_triggers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_trigger_logs');
        Schema::dropIfExists('webhook_triggers');
    }
};
