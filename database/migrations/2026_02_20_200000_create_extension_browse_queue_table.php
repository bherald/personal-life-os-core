<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS extension_browse_queue (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                url VARCHAR(2048) NOT NULL,
                domain VARCHAR(255) NOT NULL,
                purpose VARCHAR(100) NOT NULL DEFAULT 'general',
                status ENUM('pending', 'in_progress', 'completed', 'failed', 'skipped') NOT NULL DEFAULT 'pending',
                context JSON NULL,
                result JSON NULL,
                priority INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMP NULL,
                INDEX idx_browse_queue_status (status),
                INDEX idx_browse_queue_domain (domain),
                INDEX idx_browse_queue_priority (priority DESC, created_at ASC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS extension_browse_queue");
    }
};
