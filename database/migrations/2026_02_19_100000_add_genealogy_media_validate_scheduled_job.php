<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add scheduled job for genealogy media file existence validation.
 *
 * Runs weekly to check all file_exists=1 records against disk,
 * marking missing files and cleaning up orphaned person_media links.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Skip if scheduled_jobs table doesn't exist (dev environment)
        if (!Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $existing = DB::selectOne(
            "SELECT id FROM scheduled_jobs WHERE name = 'genealogy_media_validate'"
        );

        if (!$existing) {
            DB::insert("
                INSERT INTO scheduled_jobs (
                    name, job_type, command, cron_expression,
                    enabled, run_in_background, description, category,
                    created_at, updated_at
                ) VALUES (
                    'genealogy_media_validate',
                    'command',
                    'genealogy:media-validate --tree-id=4 --batch=500',
                    '0 5 * * 0',
                    1,
                    1,
                    'Validate genealogy media files exist on disk, clean up missing links',
                    'genealogy',
                    NOW(),
                    NOW()
                )
            ");
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('scheduled_jobs')) {
            return;
        }
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'genealogy_media_validate'");
    }
};
