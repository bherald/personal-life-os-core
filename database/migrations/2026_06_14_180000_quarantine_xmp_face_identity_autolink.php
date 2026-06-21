<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::selectOne(
            'SELECT id FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
            ['faces', 'xmp_identity_trust_mode']
        );

        $description = 'Controls whether embedded XMP/EXIF face names can create trusted genealogy identity links. Values: review_only (default quarantine), trusted_auto_link (legacy auto-link), trusted_standalone (only standalone named faces may auto-verify).';

        if ($existing === null) {
            DB::insert(
                'INSERT INTO system_configs (section, config_key, config_value, data_type, description, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                ['faces', 'xmp_identity_trust_mode', 'review_only', 'string', $description]
            );
        } else {
            DB::update(
                'UPDATE system_configs
                 SET config_value = ?, data_type = ?, description = ?, updated_at = NOW()
                 WHERE section = ? AND config_key = ?',
                ['review_only', 'string', $description, 'faces', 'xmp_identity_trust_mode']
            );
        }

        $note = '[2026-06-14 Codex containment] Paused pending face metadata corruption audit after operator-confirmed face identity labels and FT links were wrong. Do not re-enable writeback, genealogy face sync, or reclustering until XMP/file_registry_faces/genealogy_person_media lineage is audited.';

        DB::update(
            "UPDATE scheduled_jobs
             SET enabled = 0,
                 running_count = 0,
                 notes = CASE
                    WHEN notes IS NULL OR notes = '' THEN ?
                    WHEN notes NOT LIKE ? THEN CONCAT(notes, '\n', ?)
                    ELSE notes
                 END,
                 updated_at = NOW()
             WHERE name IN ('file_exif_writeback', 'genealogy_face_sync', 'genealogy_face_sync_101', 'face_recluster', 'face_recluster_full')",
            [$note, '%face metadata corruption audit%', $note]
        );
    }

    public function down(): void
    {
        DB::delete(
            'DELETE FROM system_configs WHERE section = ? AND config_key = ? AND config_value = ?',
            ['faces', 'xmp_identity_trust_mode', 'review_only']
        );
    }
};
