<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            "ADD COLUMN risk_level ENUM('read','write','destructive','blocked') NOT NULL DEFAULT 'read' AFTER permissions",
            "ADD COLUMN category VARCHAR(50) DEFAULT NULL AFTER risk_level",
            "ADD COLUMN requires_confirmation TINYINT(1) NOT NULL DEFAULT 0 AFTER category",
            "ADD COLUMN max_calls_per_run INT UNSIGNED DEFAULT NULL AFTER requires_confirmation",
            "ADD COLUMN mcp_server VARCHAR(50) DEFAULT NULL AFTER max_calls_per_run",
            "ADD COLUMN mcp_tool VARCHAR(100) DEFAULT NULL AFTER mcp_server",
            "ADD COLUMN max_tokens_per_call INT UNSIGNED DEFAULT NULL AFTER mcp_tool",
        ];

        foreach ($columns as $col) {
            try {
                DB::statement("ALTER TABLE agent_tool_registry {$col}");
            } catch (\Exception $e) {
                // Column already exists (Duplicate column name) — skip
            }
        }

        // Add indexes
        try {
            DB::statement("ALTER TABLE agent_tool_registry ADD INDEX idx_risk_level (risk_level)");
        } catch (\Exception $e) {
            // Index already exists
        }

        try {
            DB::statement("ALTER TABLE agent_tool_registry ADD INDEX idx_mcp_server (mcp_server)");
        } catch (\Exception $e) {
            // Index already exists
        }
    }

    public function down(): void
    {
        $columns = ['risk_level', 'category', 'requires_confirmation', 'max_calls_per_run', 'mcp_server', 'mcp_tool', 'max_tokens_per_call'];
        foreach ($columns as $col) {
            try {
                DB::statement("ALTER TABLE agent_tool_registry DROP COLUMN {$col}");
            } catch (\Exception $e) {
                // Column doesn't exist
            }
        }

        try {
            DB::statement("ALTER TABLE agent_tool_registry DROP INDEX idx_risk_level");
        } catch (\Exception $e) {}

        try {
            DB::statement("ALTER TABLE agent_tool_registry DROP INDEX idx_mcp_server");
        } catch (\Exception $e) {}
    }
};
