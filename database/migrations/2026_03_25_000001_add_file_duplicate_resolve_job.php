<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Check if job already exists
        $exists = DB::selectOne(
            "SELECT id FROM scheduled_jobs WHERE name = ?",
            ['file_duplicate_resolve']
        );

        if (!$exists) {
            DB::insert(
                "INSERT INTO scheduled_jobs
                    (name, description, job_type, command, cron_expression, enabled, run_in_background,
                     without_overlapping, timeout_minutes, category, source_module, created_at, updated_at)
                 VALUES (?, ?, 'command', ?, ?, 0, 1, 1, 30, 'files', 'file_management', NOW(), NOW())",
                [
                    'file_duplicate_resolve',
                    'Automated FTM duplicate resolution with AI audit safety gate. Processes byte-identical duplicates within Family Tree Maker folder. Random-samples and verifies before batch resolution.',
                    'files:resolve-duplicates --folder-filter="Family Tree Maker" --sample-size=30 --min-accuracy=95',
                    '0 3 * * 0',
                ]
            );
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM scheduled_jobs WHERE name = ?", ['file_duplicate_resolve']);
    }
};
