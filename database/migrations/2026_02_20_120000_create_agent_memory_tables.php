<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Agent episodes - timestamped interaction logs (episodic memory)
        try {
            DB::statement("
                CREATE TABLE agent_episodes (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    agent_id VARCHAR(100) NOT NULL,
                    session_id VARCHAR(100) NULL,
                    event_type VARCHAR(50) NOT NULL COMMENT 'task_started, task_completed, finding, error, handoff, observation',
                    summary TEXT NOT NULL,
                    details JSON NULL,
                    tokens_used INT UNSIGNED DEFAULT 0,
                    duration_ms INT UNSIGNED DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_agent_episodes_agent (agent_id),
                    INDEX idx_agent_episodes_session (session_id),
                    INDEX idx_agent_episodes_type (event_type),
                    INDEX idx_agent_episodes_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'already exists')) {
                throw $e;
            }
        }

        // Agent procedures - successful action sequences as templates (procedural memory)
        try {
            DB::statement("
                CREATE TABLE agent_procedures (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    agent_id VARCHAR(100) NOT NULL,
                    name VARCHAR(200) NOT NULL,
                    trigger_pattern VARCHAR(500) NOT NULL COMMENT 'When to use this procedure',
                    action_sequence JSON NOT NULL COMMENT 'Steps: [{tool, params, expected_output}]',
                    success_rate DECIMAL(5,4) DEFAULT 1.0000,
                    times_used INT UNSIGNED DEFAULT 0,
                    times_succeeded INT UNSIGNED DEFAULT 0,
                    last_used_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_agent_procedures_agent (agent_id),
                    INDEX idx_agent_procedures_success (success_rate DESC)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'already exists')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS agent_procedures");
        DB::statement("DROP TABLE IF EXISTS agent_episodes");
    }
};
