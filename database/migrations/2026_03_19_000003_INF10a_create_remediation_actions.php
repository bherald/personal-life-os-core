<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INF-10a: Remediation Actions Registry.
 *
 * Maps finding types to executable remediation actions with risk classification.
 * Foundation for the Closed-Loop Self-Healing Framework.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('remediation_actions')) {
            return;
        }

        DB::statement("
            CREATE TABLE remediation_actions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                finding_type VARCHAR(100) NOT NULL COMMENT 'The type of finding this remediates (e.g. circuit_breaker_open, stalled_job)',
                action_type ENUM('artisan_command', 'service_method', 'sql_update') NOT NULL,
                action_target VARCHAR(500) NOT NULL COMMENT 'Command name, Class::method, or SQL template',
                action_params JSON COMMENT 'Default parameters for the action',
                risk_level ENUM('read', 'write', 'destructive') NOT NULL DEFAULT 'read',
                description VARCHAR(500) NOT NULL COMMENT 'Human-readable description shown in Review Hub',
                requires_confirmation TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Show confirmation dialog in UI',
                cooldown_minutes INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Min minutes between executions',
                last_executed_at TIMESTAMP NULL,
                execution_count INT UNSIGNED NOT NULL DEFAULT 0,
                success_count INT UNSIGNED NOT NULL DEFAULT 0,
                failure_count INT UNSIGNED NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE INDEX idx_remediation_finding_type (finding_type),
                INDEX idx_remediation_active_risk (is_active, risk_level)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('remediation_actions');
    }
};
