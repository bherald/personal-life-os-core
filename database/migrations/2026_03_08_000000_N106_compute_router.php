<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // CREATE compute_instances table
        DB::statement("
            CREATE TABLE IF NOT EXISTS compute_instances (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                instance_id VARCHAR(50) NOT NULL UNIQUE,
                host VARCHAR(100) NOT NULL,
                ssh_user VARCHAR(50) DEFAULT NULL,
                is_local TINYINT(1) NOT NULL DEFAULT 0,
                gpu_model VARCHAR(100) DEFAULT NULL,
                gpu_vram_mb INT UNSIGNED DEFAULT NULL,
                python_path VARCHAR(255) NOT NULL DEFAULT 'python3',
                scripts_path VARCHAR(255) NOT NULL,
                capabilities JSON NOT NULL,
                priority TINYINT UNSIGNED NOT NULL DEFAULT 50,
                health_score TINYINT UNSIGNED NOT NULL DEFAULT 100,
                circuit_state ENUM('closed','open','half_open') NOT NULL DEFAULT 'closed',
                circuit_opened_at TIMESTAMP NULL DEFAULT NULL,
                circuit_retry_at TIMESTAMP NULL DEFAULT NULL,
                max_concurrent TINYINT UNSIGNED NOT NULL DEFAULT 1,
                config JSON DEFAULT NULL,
                avg_execution_ms DECIMAL(10,2) DEFAULT NULL,
                total_executions INT UNSIGNED NOT NULL DEFAULT 0,
                total_failures INT UNSIGNED NOT NULL DEFAULT 0,
                consecutive_failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
                success_rate DECIMAL(5,2) DEFAULT NULL,
                shares_gpu_with_llm TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_healthy TINYINT(1) NOT NULL DEFAULT 1,
                last_health_check TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_capability_active (is_active, is_healthy),
                INDEX idx_circuit (circuit_state)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $localComputeHost = trim((string) env('PLOS_LOCAL_COMPUTE_HOST', '127.0.0.1')) ?: '127.0.0.1';
        $localComputeUser = trim((string) env('PLOS_LOCAL_COMPUTE_USER', '')) ?: null;
        $localComputeScripts = trim((string) env('PLOS_LOCAL_COMPUTE_SCRIPTS_PATH', '')) ?: base_path('scripts');

        // Seed local GPU worker (shares GPU with Ollama by default)
        DB::insert('
            INSERT INTO compute_instances
            (instance_id, host, ssh_user, is_local, gpu_model, gpu_vram_mb, python_path, scripts_path, capabilities, priority, max_concurrent, config, shares_gpu_with_llm)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ', [
            'gpu_local',
            $localComputeHost,
            $localComputeUser,
            1,
            'GTX 1060',
            6144,
            'python3',
            $localComputeScripts,
            json_encode(['gpu_compute', 'htr', 'face_detection', 'face_clustering', 'community_detection', 'nlp']),
            20,
            1,
            json_encode(['whisper_model' => 'small']),
            1, // shares_gpu_with_llm — Ollama runs on same GTX 1060
        ]);

        // Secondary GPU hosts can be added here if Python execution is configured.
        // LLM-only routing is handled by the llm_instances table.

        // Seed local CPU-only tasks
        DB::insert('
            INSERT INTO compute_instances
            (instance_id, host, ssh_user, is_local, gpu_model, gpu_vram_mb, python_path, scripts_path, capabilities, priority, max_concurrent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ', [
            'cpu_local',
            $localComputeHost,
            $localComputeUser,
            1,
            null,
            null,
            'python3',
            $localComputeScripts,
            json_encode(['nlp', 'community_detection']),
            30,
            3,
        ]);

        // Register agent tools
        DB::insert('
            INSERT INTO agent_tool_registry (name, service_class, method, description, parameters, returns_description, risk_level, category, enabled, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE description = VALUES(description), method = VALUES(method)
        ', [
            'compute_status',
            'App\\Services\\ComputeRouterService',
            'getStatus',
            'Get status of all compute instances including GPU availability, circuit state, and health scores',
            json_encode([]),
            'Array with instance statuses, circuit states, and health metrics',
            'read',
            'system',
            1,
            'manual',
        ]);

        DB::insert('
            INSERT INTO agent_tool_registry (name, service_class, method, description, parameters, returns_description, risk_level, category, enabled, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE description = VALUES(description), method = VALUES(method)
        ', [
            'compute_health_check',
            'App\\Services\\ComputeRouterService',
            'healthCheckAll',
            'Run health checks on all compute instances — verifies GPU availability and SSH connectivity',
            json_encode([]),
            'Array of health check results per instance',
            'read',
            'system',
            1,
            'manual',
        ]);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS compute_instances');
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ('compute_status', 'compute_health_check')");
    }
};
