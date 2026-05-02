<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Knowledge Curator agent - every 6 hours
        try {
            DB::insert("INSERT INTO scheduled_jobs (name, description, command, cron_expression, job_type, enabled, category, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'agent_task', 0, ?, ?, NOW(), NOW())", [
                'knowledge_curator_agent',
                'Knowledge base curator: RAG stats, RAPTOR coverage, quality metrics, pipeline health',
                'knowledge-curator',
                '0 */6 * * *',
                'Agent',
                json_encode(['notify' => true]),
            ]);
        } catch (\Throwable $e) {
            // Job may already exist
        }

        // System Guardian agent - every 30 minutes
        try {
            DB::insert("INSERT INTO scheduled_jobs (name, description, command, cron_expression, job_type, enabled, category, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'agent_task', 0, ?, ?, NOW(), NOW())", [
                'system_guardian_agent',
                'System health monitor: infrastructure, AI services, workflows, alerts, queue depth',
                'system-guardian',
                '*/30 * * * *',
                'Agent',
                json_encode(['notify' => true]),
            ]);
        } catch (\Throwable $e) {
            // Job may already exist
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM scheduled_jobs WHERE name IN ('knowledge_curator_agent', 'system_guardian_agent')");
    }
};
