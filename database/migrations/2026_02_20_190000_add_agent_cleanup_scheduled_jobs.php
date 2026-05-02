<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure scheduled_jobs table exists (created pre-migration on prod, may be missing on dev)
        DB::statement("
            CREATE TABLE IF NOT EXISTS scheduled_jobs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE COMMENT 'Unique job identifier',
                description TEXT COMMENT 'Human-readable description',
                job_type ENUM('command','workflow','job_class','agent_task') NOT NULL DEFAULT 'command',
                command VARCHAR(500) NOT NULL COMMENT 'Artisan command / workflow name / job class',
                cron_expression VARCHAR(100) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                run_in_background TINYINT(1) NOT NULL DEFAULT 1,
                without_overlapping TINYINT(1) NOT NULL DEFAULT 1,
                timeout_minutes INT UNSIGNED DEFAULT 60,
                last_run_at TIMESTAMP NULL DEFAULT NULL,
                last_completed_at TIMESTAMP NULL DEFAULT NULL,
                last_run_status ENUM('success','failed','running','timeout') DEFAULT NULL,
                last_run_output TEXT,
                last_pid INT UNSIGNED DEFAULT NULL,
                max_parallel TINYINT UNSIGNED NOT NULL DEFAULT 1,
                running_pids JSON DEFAULT NULL,
                running_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
                next_run_at TIMESTAMP NULL DEFAULT NULL,
                run_count INT UNSIGNED NOT NULL DEFAULT 0,
                fail_count INT UNSIGNED NOT NULL DEFAULT 0,
                notes TEXT,
                category VARCHAR(100) DEFAULT NULL,
                source_module VARCHAR(100) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_enabled_next_run (enabled, next_run_at),
                INDEX idx_category (category),
                INDEX idx_source_module (source_module),
                INDEX idx_job_type (job_type),
                INDEX idx_last_run_status (last_run_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $jobs = [
            [
                'name' => 'agent_expire_reviews',
                'description' => 'Expire pending agent review queue items past their TTL',
                'command' => "php artisan tinker --execute=\"echo app(App\\Services\\AgentLoopService::class)->expirePendingReviews() . ' expired';\"",
                'cron_expression' => '0 * * * *',
                'job_type' => 'command',
                'category' => 'Maintenance',
                'enabled' => 1,
                'notes' => 'Runs hourly to mark expired review items (48hr default TTL)',
            ],
            [
                'name' => 'agent_cleanup_messages',
                'description' => 'Delete expired agent-to-agent messages',
                'command' => "php artisan tinker --execute=\"echo app(App\\Services\\AgentLoopService::class)->cleanupExpiredMessages() . ' deleted';\"",
                'cron_expression' => '30 * * * *',
                'job_type' => 'command',
                'category' => 'Maintenance',
                'enabled' => 1,
                'notes' => 'Runs hourly at :30 to purge expired inter-agent messages (24hr default TTL)',
            ],
        ];

        foreach ($jobs as $job) {
            $existing = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = ?", [$job['name']]);
            if (!$existing) {
                DB::insert(
                    "INSERT INTO scheduled_jobs (name, description, command, cron_expression, job_type, category, enabled, notes, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [$job['name'], $job['description'], $job['command'], $job['cron_expression'], $job['job_type'], $job['category'], $job['enabled'], $job['notes']]
                );
            }
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM scheduled_jobs WHERE name IN ('agent_expire_reviews', 'agent_cleanup_messages')");
    }
};
