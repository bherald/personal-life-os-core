<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Speculative Executions Table
     *
     * Tracks parallel execution of the same task through 2 different workflow modes.
     * An LLM-as-judge arbitrates to pick the winning result.
     * Part of S19: Speculative Execution (Tier 4 Dynamic Intelligence).
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE speculative_executions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

                -- Run identification
                spec_run_id VARCHAR(64) NOT NULL UNIQUE,
                agent_id VARCHAR(100) NOT NULL,
                task_description TEXT NOT NULL,
                task_key VARCHAR(100) NULL,

                -- Branch tracking
                branch_a_mode ENUM('agentic', 'hybrid', 'deterministic') NOT NULL,
                branch_b_mode ENUM('agentic', 'hybrid', 'deterministic') NOT NULL,
                branch_a_session_id VARCHAR(100) NULL,
                branch_b_session_id VARCHAR(100) NULL,
                branch_a_job_id VARCHAR(100) NULL,
                branch_b_job_id VARCHAR(100) NULL,

                -- Status tracking
                status ENUM('pending', 'running', 'arbitrating', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
                branch_a_status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
                branch_b_status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',

                -- Results
                winner ENUM('branch_a', 'branch_b', 'tie') NULL,
                winning_mode ENUM('agentic', 'hybrid', 'deterministic') NULL,
                arbitration_reasoning TEXT NULL,
                quality_uplift_pct DECIMAL(5,2) NULL,

                -- Cost tracking
                branch_a_tokens INT UNSIGNED DEFAULT 0,
                branch_b_tokens INT UNSIGNED DEFAULT 0,
                branch_a_duration_ms INT UNSIGNED DEFAULT 0,
                branch_b_duration_ms INT UNSIGNED DEFAULT 0,
                arbitration_tokens INT UNSIGNED DEFAULT 0,
                total_cost_tokens INT UNSIGNED DEFAULT 0,

                -- Trigger context
                trigger_type ENUM('agent_request', 'variance_detected', 'manual', 'benchmark') NOT NULL,
                trigger_context JSON NULL,

                -- Benchmark cross-reference
                branch_a_benchmark_id BIGINT UNSIGNED NULL,
                branch_b_benchmark_id BIGINT UNSIGNED NULL,

                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMP NULL,

                INDEX idx_agent (agent_id),
                INDEX idx_status (status),
                INDEX idx_trigger (trigger_type),
                INDEX idx_created (created_at)
            )
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS speculative_executions");
    }
};
