<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            INSERT INTO scheduled_jobs
                (name, description, job_type, command, cron_expression, enabled, run_in_background,
                 without_overlapping, timeout_minutes, category, source_module, created_at, updated_at)
            VALUES
                ('entity_resolution', 'Entity resolution pipeline — backfill embeddings for KG entities',
                 'command', 'entity:resolve --backfill --limit=50', '0 3 * * *', 1, 1,
                 1, 30, 'RAG', 'EntityResolutionService', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                description = VALUES(description),
                command = VALUES(command),
                cron_expression = VALUES(cron_expression),
                timeout_minutes = VALUES(timeout_minutes),
                updated_at = NOW()
        ");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM scheduled_jobs WHERE name = 'entity_resolution'");
    }
};
