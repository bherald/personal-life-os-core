<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create agent_tool_registry table
        DB::statement("
            CREATE TABLE IF NOT EXISTS agent_tool_registry (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL UNIQUE,
                service_class VARCHAR(255) NOT NULL,
                method VARCHAR(100) NOT NULL,
                description TEXT NOT NULL,
                parameters JSON DEFAULT NULL,
                returns_description VARCHAR(500) DEFAULT NULL,
                permissions JSON DEFAULT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                risk_level ENUM('read','write','destructive','blocked') NOT NULL DEFAULT 'read',
                category VARCHAR(50) DEFAULT NULL,
                requires_confirmation TINYINT(1) NOT NULL DEFAULT 0,
                max_calls_per_run INT UNSIGNED DEFAULT NULL,
                mcp_server VARCHAR(50) DEFAULT NULL,
                mcp_tool VARCHAR(100) DEFAULT NULL,
                max_tokens_per_call INT UNSIGNED DEFAULT NULL,
                source ENUM('config', 'manual', 'agent_proposed') NOT NULL DEFAULT 'config',
                proposed_by VARCHAR(100) DEFAULT NULL COMMENT 'Agent ID that proposed this tool',
                approved_by VARCHAR(100) DEFAULT NULL COMMENT 'User or agent that approved',
                approved_at TIMESTAMP NULL DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_enabled (enabled),
                INDEX idx_source (source),
                INDEX idx_risk_level (risk_level),
                INDEX idx_mcp_server (mcp_server)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Seed from config/agent_tools.php
        $tools = config('agent_tools', []);
        foreach ($tools as $name => $def) {
            try {
                DB::insert("
                    INSERT INTO agent_tool_registry
                    (name, service_class, method, description, parameters, returns_description, permissions, source)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'config')
                ", [
                    $name,
                    $def['service'] ?? '',
                    $def['method'] ?? '',
                    $def['description'] ?? '',
                    json_encode($def['parameters'] ?? []),
                    $def['returns'] ?? null,
                    json_encode($def['permissions'] ?? []),
                ]);
            } catch (\Exception $e) {
                // Skip duplicates (idempotent)
            }
        }
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS agent_tool_registry");
    }
};
