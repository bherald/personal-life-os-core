<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * S20: Adaptive Mode Selection — tracking table + agent tools.
     *
     * Records every adaptive mode selection and its outcome for continuous learning.
     * AdaptiveModeService queries this + agent_benchmarks + speculative_executions
     * to pick the optimal workflow_mode per agent+task.
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS adaptive_mode_selections (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                agent_id VARCHAR(100) NOT NULL,
                session_id VARCHAR(64) NULL,
                task_description TEXT NULL,
                task_key VARCHAR(100) NULL,
                selected_mode ENUM('agentic','hybrid','deterministic') NOT NULL,
                confidence DECIMAL(4,3) NOT NULL DEFAULT 0.000,
                reasoning TEXT NULL,
                was_fallback TINYINT(1) NOT NULL DEFAULT 0,
                fallback_reason VARCHAR(255) NULL,

                -- Outcome tracking (filled after execution completes)
                outcome_success TINYINT(1) NULL,
                outcome_duration_ms INT UNSIGNED NULL,
                outcome_tokens INT UNSIGNED NULL,
                outcome_accuracy TINYINT UNSIGNED NULL,
                outcome_completeness TINYINT UNSIGNED NULL,
                outcome_relevance TINYINT UNSIGNED NULL,

                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                INDEX idx_agent_task (agent_id, task_key),
                INDEX idx_agent_mode (agent_id, selected_mode),
                INDEX idx_created (created_at),
                INDEX idx_fallback (was_fallback)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Register adaptive mode agent tools
        $this->insertTool(
            'adaptive_mode_stats',
            'App\\Services\\AdaptiveModeService',
            'adaptiveModeStats',
            'View adaptive mode selection history and accuracy statistics for any agent. Shows which modes were selected, confidence levels, and how actual outcomes compared to predictions.',
            json_encode([
                ['name' => 'agent_id', 'type' => 'string', 'required' => false, 'description' => 'Agent ID to get stats for. Defaults to current agent.'],
            ]),
            'Selection accuracy, mode distribution, outcome comparisons',
            json_encode(['system:read']),
            'read',
            'agent',
            3
        );

        $this->insertTool(
            'adaptive_mode_recommend',
            'App\\Services\\AdaptiveModeService',
            'adaptiveModeRecommend',
            'Get mode recommendation for a specific agent and task without executing. Shows the scoring breakdown and confidence level for each mode.',
            json_encode([
                ['name' => 'agent_id', 'type' => 'string', 'required' => true, 'description' => 'Agent ID to get recommendation for'],
                ['name' => 'task', 'type' => 'string', 'required' => false, 'description' => 'Task description for task-specific recommendation'],
            ]),
            'Recommended mode with confidence, scoring breakdown per mode',
            json_encode(['system:read']),
            'read',
            'agent',
            3
        );

        $this->insertTool(
            'adaptive_mode_override',
            'App\\Services\\AdaptiveModeService',
            'adaptiveModeOverride',
            'Suggest a mode override for the next N runs of a specific agent. Override is stored and expires after the specified number of runs. Use when benchmark data suggests the current adaptive selection is suboptimal.',
            json_encode([
                ['name' => 'agent_id', 'type' => 'string', 'required' => true, 'description' => 'Agent ID to override mode for'],
                ['name' => 'mode', 'type' => 'string', 'required' => true, 'description' => 'Mode to force: agentic, hybrid, or deterministic'],
                ['name' => 'runs', 'type' => 'integer', 'required' => false, 'description' => 'Number of runs for override (default 5, max 20)'],
                ['name' => 'reason', 'type' => 'string', 'required' => false, 'description' => 'Reason for override'],
            ]),
            'Confirmation of override with expiry run count',
            json_encode(['system:write']),
            'write',
            'agent',
            2
        );
    }

    private function insertTool(
        string $name,
        string $serviceClass,
        string $method,
        string $description,
        string $parameters,
        string $returnsDescription,
        string $permissions,
        string $riskLevel,
        string $category,
        ?int $maxCallsPerRun
    ): void {
        try {
            DB::insert("
                INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, returns_description, permissions, risk_level, category, max_calls_per_run, source)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'config')
            ", [
                $name, $serviceClass, $method, $description, $parameters,
                $returnsDescription, $permissions, $riskLevel, $category, $maxCallsPerRun,
            ]);
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate entry')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ('adaptive_mode_stats', 'adaptive_mode_recommend', 'adaptive_mode_override')");
        Schema::dropIfExists('adaptive_mode_selections');
    }
};
