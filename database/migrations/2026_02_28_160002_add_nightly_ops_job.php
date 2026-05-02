<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::insert(
            "INSERT INTO scheduled_jobs (name, command, cron_expression, category, enabled, timeout_minutes, description, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                'nightly_ops',
                'ops:nightly',
                '0 22 * * *',
                'Maintenance',
                1,
                5,
                'Nightly ops health summary via Pushover',
            ]
        );
    }

    public function down(): void
    {
        DB::delete("DELETE FROM scheduled_jobs WHERE name = ?", ['nightly_ops']);
    }
};
