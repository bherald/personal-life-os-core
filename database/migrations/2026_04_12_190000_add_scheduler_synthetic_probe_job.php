<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = ?", ['scheduler_synthetic_probe']);

        if (! $exists) {
            DB::insert(
                "INSERT INTO scheduled_jobs
                (name, description, job_type, command, cron_expression, enabled, run_in_background,
                 without_overlapping, stall_exempt, timeout_minutes, timeout_locked,
                 category, source_module, created_at, updated_at)
                 VALUES (?, ?, 'command', ?, ?, 1, 1, 1, 0, 5, 1, ?, ?, NOW(), NOW())",
                [
                    'scheduler_synthetic_probe',
                    'Lightweight synthetic scheduler proof job. Updates a heartbeat-style marker via scheduled execution.',
                    'ops:scheduler-synthetic-probe',
                    '*/15 * * * *',
                    'Ops',
                    'Ops',
                ]
            );
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM scheduled_jobs WHERE name = ?", ['scheduler_synthetic_probe']);
    }
};
