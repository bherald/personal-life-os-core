<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N130: Fix video MIME types + add Nextcloud maintenance scheduled job.
 *
 * 22 .mov files stored as application/octet-stream (browser can't play)
 * 25 .mp4 files stored as audio/mp4 (browser treats as audio-only)
 * Scheduled weekly Nextcloud maintenance (Sunday 3:00 AM)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fix incorrect MIME types for video files
        DB::update("UPDATE file_registry SET mime_type = 'video/quicktime' WHERE extension = 'mov' AND mime_type = 'application/octet-stream'");
        DB::update("UPDATE file_registry SET mime_type = 'video/mp4' WHERE extension = 'mp4' AND mime_type = 'audio/mp4'");

        // Add Nextcloud maintenance scheduled job — Sunday 3:00 AM
        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE command = 'nextcloud:maintenance --full'");
        if (!$exists) {
            DB::insert("INSERT INTO scheduled_jobs (name, description, job_type, command, cron_expression, enabled, run_in_background, without_overlapping, stall_exempt, timeout_minutes, timeout_locked, category, source_module, notes, created_at, updated_at)
                VALUES (?, ?, 'command', ?, ?, 1, 1, 1, 1, 30, 0, ?, ?, ?, NOW(), NOW())", [
                'nextcloud_maintenance',
                'Weekly Nextcloud Docker maintenance — file scan, cleanup, repair, DB checks, trashbin, previews',
                'nextcloud:maintenance --full',
                '0 3 * * 0',
                'infrastructure',
                'nextcloud',
                'N130: Runs all Nextcloud occ maintenance tasks. Stall-exempt because file scan on 293GB can be slow.',
            ]);
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM scheduled_jobs WHERE command = 'nextcloud:maintenance --full'");
    }
};
