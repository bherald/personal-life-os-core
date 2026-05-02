<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SB-4: Register framework currency check as weekly scheduled job.
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
                'framework_currency_check',
                'Weekly scan for AI/tech advances — HuggingFace trending, GitHub releases, Ollama models',
                'framework:currency-check --notify',
                '0 9 * * 1',
                15,
                'Maintenance',
            ]
        );
    }

    public function down(): void
    {
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'framework_currency_check'");
    }
};
