<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::statement("
                CREATE TABLE agent_messages (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    from_agent VARCHAR(100) NOT NULL,
                    to_agent VARCHAR(100) DEFAULT '*' COMMENT 'Target agent or * for broadcast',
                    message_type VARCHAR(50) NOT NULL COMMENT 'alert, finding, status_change, request, info',
                    subject VARCHAR(500) NOT NULL,
                    body TEXT NOT NULL,
                    metadata JSON NULL COMMENT 'Structured data payload',
                    priority TINYINT UNSIGNED DEFAULT 0 COMMENT '0=normal, 1=high, 2=urgent',
                    acknowledged_by JSON NULL COMMENT 'Array of agent IDs that have read this',
                    expires_at TIMESTAMP NULL COMMENT 'Auto-expire after TTL',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_msg_to (to_agent),
                    INDEX idx_msg_from (from_agent),
                    INDEX idx_msg_type (message_type),
                    INDEX idx_msg_created (created_at),
                    INDEX idx_msg_priority (priority DESC)
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
        DB::statement("DROP TABLE IF EXISTS agent_messages");
    }
};
