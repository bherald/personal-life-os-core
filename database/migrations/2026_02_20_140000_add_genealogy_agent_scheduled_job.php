<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Skip if scheduled_jobs table doesn't exist (dev environment)
        if (!Schema::hasTable('scheduled_jobs')) {
            return;
        }

        // Extend job_type enum to include agent_task
        try {
            DB::statement("ALTER TABLE scheduled_jobs MODIFY COLUMN job_type ENUM('command','workflow','job_class','agent_task') NOT NULL DEFAULT 'command'");
        } catch (\Throwable $e) {
            // May already be extended
        }

        // Add the genealogy researcher agent as a scheduled job
        // job_type = 'agent_task': ScheduledJobService routes to AgentLoopService
        // command = skill name, notes = JSON params
        try {
            DB::insert("
                INSERT INTO scheduled_jobs
                (name, description, job_type, command, cron_expression, enabled, run_in_background,
                 without_overlapping, timeout_minutes, notes, category, source_module, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                'genealogy_agent_research',
                'AI genealogy researcher agent - autonomous record hint generation and evaluation',
                'agent_task',
                'genealogy-researcher',
                '0 5 * * *', // 5 AM daily
                0, // Disabled by default — enable after verifying tree_id in notes
                true,
                true,
                30, // 30 minute timeout
                json_encode([
                    'task' => 'Perform daily autonomous research: check for new hints, evaluate pending hints, generate hints for persons missing data, report findings.',
                    'tree_id' => 1, // Default tree — update to actual tree ID
                    'notify' => true,
                ]),
                'Genealogy',
                'agent',
            ]);
        } catch (\Throwable $e) {
            // Ignore duplicate on re-run
            if (!str_contains($e->getMessage(), 'Duplicate entry')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('scheduled_jobs')) {
            return;
        }
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'genealogy_agent_research'");
    }
};
