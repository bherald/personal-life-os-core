<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::statement("
                CREATE TABLE agent_review_queue (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    agent_id VARCHAR(100) NOT NULL,
                    review_type VARCHAR(50) NOT NULL COMMENT 'finding, action, suggestion, alert',
                    title VARCHAR(500) NOT NULL,
                    summary TEXT NOT NULL,
                    details JSON NULL COMMENT 'Full context: evidence, sources, confidence scores',
                    confidence DECIMAL(3,2) NULL COMMENT '0.00-1.00 agent confidence score',
                    priority TINYINT UNSIGNED DEFAULT 0 COMMENT '0=normal, 1=high, 2=urgent',
                    status VARCHAR(20) DEFAULT 'pending' COMMENT 'pending, approved, rejected, expired',
                    reviewer_notes TEXT NULL COMMENT 'Human notes on approval/rejection',
                    reviewed_at TIMESTAMP NULL,
                    token VARCHAR(64) NULL COMMENT 'Unique token for Pushover approve/deny URLs',
                    expires_at TIMESTAMP NULL COMMENT 'Auto-expire pending items after TTL',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_review_agent (agent_id),
                    INDEX idx_review_status (status),
                    INDEX idx_review_priority (priority DESC),
                    INDEX idx_review_token (token),
                    INDEX idx_review_created (created_at)
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
        DB::statement("DROP TABLE IF EXISTS agent_review_queue");
    }
};
