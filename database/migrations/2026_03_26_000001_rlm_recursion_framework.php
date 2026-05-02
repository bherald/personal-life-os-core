<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. recursion_config — per-service recursion settings with kill switch
        DB::statement("
            CREATE TABLE IF NOT EXISTS recursion_config (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                service_name VARCHAR(100) NOT NULL UNIQUE,
                enabled BOOLEAN NOT NULL DEFAULT FALSE,
                max_depth TINYINT UNSIGNED NOT NULL DEFAULT 1,
                max_tokens INT UNSIGNED NOT NULL DEFAULT 30000,
                max_time_seconds INT UNSIGNED NOT NULL DEFAULT 300,
                max_cost_usd DECIMAL(8,4) NOT NULL DEFAULT 0.5000,
                novelty_threshold DECIMAL(5,4) NOT NULL DEFAULT 0.1500,
                repetition_threshold DECIMAL(5,4) NOT NULL DEFAULT 0.9000,
                decay_window TINYINT UNSIGNED NOT NULL DEFAULT 3,
                move_on_mode ENUM('graceful','hard') NOT NULL DEFAULT 'graceful',
                strategies JSON NOT NULL,
                sub_call_model_role VARCHAR(20) NOT NULL DEFAULT 'fast',
                synthesis_model_role VARCHAR(20) NOT NULL DEFAULT 'quality',
                disabled_reason VARCHAR(255) NULL,
                disabled_at TIMESTAMP NULL,
                notes TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 2. agent_recursion_calls — per sub-call tracking
        DB::statement("
            CREATE TABLE IF NOT EXISTS agent_recursion_calls (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                session_id BIGINT UNSIGNED NULL,
                service_name VARCHAR(100) NULL,
                parent_call_id BIGINT UNSIGNED NULL,
                depth INT UNSIGNED NOT NULL DEFAULT 0,
                strategy VARCHAR(50) NOT NULL,
                input_summary TEXT NULL,
                output_summary TEXT NULL,
                novelty_score DECIMAL(5,4) NULL,
                tokens_used INT UNSIGNED DEFAULT 0,
                context_window_size INT UNSIGNED DEFAULT 0,
                provider_used VARCHAR(50) NULL,
                model_role VARCHAR(20) NULL,
                time_seconds DECIMAL(8,2) DEFAULT 0,
                cost_usd DECIMAL(8,4) DEFAULT 0,
                move_on_triggered BOOLEAN DEFAULT FALSE,
                move_on_reason VARCHAR(100) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMP NULL,
                INDEX idx_arc_session (session_id),
                INDEX idx_arc_service (service_name),
                INDEX idx_arc_parent (parent_call_id),
                INDEX idx_arc_depth (depth),
                INDEX idx_arc_created (created_at),
                FOREIGN KEY (session_id) REFERENCES agent_sessions(id) ON DELETE SET NULL,
                FOREIGN KEY (parent_call_id) REFERENCES agent_recursion_calls(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 3. recursion_effectiveness — aggregated per session/service run
        DB::statement("
            CREATE TABLE IF NOT EXISTS recursion_effectiveness (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                session_id BIGINT UNSIGNED NULL,
                service_name VARCHAR(100) NULL,
                max_depth_reached INT UNSIGNED NOT NULL DEFAULT 0,
                total_sub_calls INT UNSIGNED NOT NULL DEFAULT 0,
                total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                total_time_seconds DECIMAL(8,2) NOT NULL DEFAULT 0,
                total_cost_usd DECIMAL(8,4) NOT NULL DEFAULT 0,
                avg_novelty_score DECIMAL(5,4) NULL,
                avg_context_window INT UNSIGNED NULL,
                move_on_count INT UNSIGNED DEFAULT 0,
                primary_move_on_reason VARCHAR(100) NULL,
                quality_improvement_estimate DECIMAL(5,4) NULL,
                local_provider_pct DECIMAL(5,2) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_re_session (session_id),
                INDEX idx_re_service (service_name),
                INDEX idx_re_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 4. Master kill switch in system_configs
        $exists = DB::selectOne(
            "SELECT id FROM system_configs WHERE section = 'recursion' AND config_key = 'master_enabled' LIMIT 1"
        );
        if (!$exists) {
            DB::insert(
                "INSERT INTO system_configs (section, config_key, config_value, data_type, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
                ['recursion', 'master_enabled', 'false', 'boolean', 'Master kill switch for RLM recursion framework. Set to false to disable all recursion.']
            );
        }

        // 5. Seed pilot service configs (both disabled until Phase 2)
        DB::insert(
            "INSERT IGNORE INTO recursion_config (service_name, enabled, max_depth, max_tokens, max_time_seconds, max_cost_usd, strategies, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            ['iterative_retrieval', false, 1, 30000, 300, 0.50, '["quality_gate_retry"]', 'Phase 2 pilot — IterativeRetrievalService']
        );
        DB::insert(
            "INSERT IGNORE INTO recursion_config (service_name, enabled, max_depth, max_tokens, max_time_seconds, max_cost_usd, strategies, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            ['research-ops', false, 1, 50000, 300, 0.50, '["partition_map"]', 'Phase 3 pilot — research-ops agent']
        );
    }

    public function down(): void
    {
        // Remove seed data
        DB::delete("DELETE FROM system_configs WHERE section = 'recursion' AND config_key = 'master_enabled'");

        Schema::dropIfExists('recursion_effectiveness');
        Schema::dropIfExists('agent_recursion_calls');
        Schema::dropIfExists('recursion_config');
    }
};
