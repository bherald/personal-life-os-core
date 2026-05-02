<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Check if already exists
        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'ai_ops_agent'");
        if ($exists) {
            return;
        }

        DB::insert("
            INSERT INTO scheduled_jobs
            (name, description, command, cron_expression, job_type, enabled, category,
             timeout_minutes, run_in_background, without_overlapping, notes, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ", [
            'ai_ops_agent',
            'AI Operations oversight: pipeline throughput, capacity management, workload balancing, stall detection',
            'ai-ops',
            '*/15 * * * *',
            'agent_task',
            1,
            'Agent',
            10,
            1,
            1,
            json_encode(['notify' => true]),
        ]);
    }

    public function down(): void
    {
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'ai_ops_agent'");
    }
};
