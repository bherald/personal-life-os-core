<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N51: Register retroactive proposal generation as a scheduled job.
 * Weekly scan for orphaned genealogy findings from agent episodes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::insert(
            "INSERT IGNORE INTO scheduled_jobs
                (name, description, job_type, command, cron_expression, enabled, timeout_minutes, category, created_at, updated_at)
             VALUES (?, ?, 'command', ?, ?, 1, ?, ?, NOW(), NOW())",
            [
                'retroactive_proposals',
                'Weekly scan for orphaned genealogy findings in agent episodes — resubmits to review queue',
                'agent:retroactive-proposals --days=30 --limit=200',
                '0 6 * * 0',
                30,
                'Genealogy',
            ]
        );
    }

    public function down(): void
    {
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'retroactive_proposals'");
    }
};
