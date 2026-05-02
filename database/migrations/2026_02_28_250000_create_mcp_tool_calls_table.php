<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE mcp_tool_calls (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tool_name VARCHAR(150) NOT NULL,
                mcp_server VARCHAR(50) NULL,
                mcp_tool VARCHAR(100) NULL,
                agent_id VARCHAR(100) NULL,
                session_id VARCHAR(100) NULL,
                caller VARCHAR(50) NOT NULL DEFAULT 'agent' COMMENT 'agent, api, manual',
                success TINYINT(1) NOT NULL DEFAULT 1,
                duration_ms INT UNSIGNED NULL,
                error_message TEXT NULL,
                params_summary VARCHAR(500) NULL COMMENT 'sanitized param keys/types, not values',
                result_size INT UNSIGNED NULL COMMENT 'bytes of serialized result',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_tool_name (tool_name),
                INDEX idx_mcp_server (mcp_server),
                INDEX idx_agent_id (agent_id),
                INDEX idx_created_at (created_at),
                INDEX idx_success (success),
                INDEX idx_caller (caller)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tool_calls');
    }
};
