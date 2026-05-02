<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Genealogy agent research queue + split scheduled jobs.
 *
 * Problem: Single genealogy_agent_research job processed multiple persons per run,
 * causing LLM context contamination and 50% timeout failure rate. The 2.5 min/entity
 * estimate was wildly wrong (actual ~15 min/person).
 *
 * Fix:
 * 1. Create genealogy_research_queue table for decoupled assess/research workflow
 * 2. Add genealogy_agent_assess job (daily, populates queue from priority data)
 * 3. Add genealogy_agent_research_queue job (every 2h, single person from queue)
 * 4. Disable legacy combined job
 *
 * The adaptive timeout mechanism (config/agents.php + SchedulerRunCommand) allows
 * the research job to extend its deadline when productive, up to max_timeout_minutes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Create research queue table
        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_research_queue (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tree_id INT UNSIGNED NOT NULL,
                person_id INT UNSIGNED NOT NULL,
                person_name VARCHAR(255) NOT NULL,
                priority_score DECIMAL(5,3) NOT NULL DEFAULT 0,
                priority_reason TEXT NULL,
                status ENUM('pending','in_progress','completed','skipped','failed') DEFAULT 'pending',
                assessed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                started_at TIMESTAMP NULL,
                completed_at TIMESTAMP NULL,
                session_id VARCHAR(36) NULL,
                findings_count INT UNSIGNED DEFAULT 0,
                review_items_count INT UNSIGNED DEFAULT 0,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status_priority (status, priority_score DESC),
                INDEX idx_tree_status (tree_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 2. Disable legacy combined job
        DB::update("
            UPDATE scheduled_jobs
            SET enabled = 0, updated_at = NOW()
            WHERE name = 'genealogy_agent_research'
        ");

        // 3. Add assess-only job (daily 4 AM, lightweight — no agent loop, just queue population)
        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'genealogy_agent_assess' LIMIT 1");
        if (!$exists) {
            DB::insert("
                INSERT INTO scheduled_jobs
                (name, description, job_type, command, cron_expression, enabled,
                 run_in_background, without_overlapping, stall_exempt, timeout_minutes,
                 notes, category, source_module, next_run_at, created_at, updated_at)
                VALUES (
                    'genealogy_agent_assess',
                    'Genealogy assess: populate research queue with priority-ranked persons from coverage data',
                    'agent_task',
                    'genealogy-researcher',
                    '0 4 * * *',
                    1, 1, 1, 1, 30,
                    ?,
                    'Agents', 'genealogy',
                    DATE_ADD(CURDATE(), INTERVAL 1 DAY) + INTERVAL 4 HOUR,
                    NOW(), NOW()
                )
            ", [json_encode(['mode' => 'assess'])]);
        }

        // 4. Add single-person research job (every 2 hours, adaptive timeout 45→120 min)
        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'genealogy_agent_research_queue' LIMIT 1");
        if (!$exists) {
            DB::insert("
                INSERT INTO scheduled_jobs
                (name, description, job_type, command, cron_expression, enabled,
                 run_in_background, without_overlapping, stall_exempt, timeout_minutes,
                 notes, category, source_module, next_run_at, created_at, updated_at)
                VALUES (
                    'genealogy_agent_research_queue',
                    'Genealogy single-person research: pulls from queue, runs research+analyze+report with adaptive timeout',
                    'agent_task',
                    'genealogy-researcher',
                    '0 */2 * * *',
                    1, 1, 1, 1, 45,
                    ?,
                    'Agents', 'genealogy',
                    DATE_ADD(NOW(), INTERVAL 2 HOUR),
                    NOW(), NOW()
                )
            ", [json_encode(['mode' => 'research'])]);
        }
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS genealogy_research_queue");

        // Re-enable legacy job
        DB::update("
            UPDATE scheduled_jobs SET enabled = 1, updated_at = NOW()
            WHERE name = 'genealogy_agent_research'
        ");

        // Remove new jobs
        DB::delete("
            DELETE FROM scheduled_jobs
            WHERE name IN ('genealogy_agent_assess', 'genealogy_agent_research_queue')
        ");
    }
};
