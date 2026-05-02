<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add columns for media face sync tracking
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add last_face_sync_at to genealogy_media for tracking when faces were synced from source
        try {
            DB::statement("
                ALTER TABLE genealogy_media
                ADD COLUMN last_face_sync_at TIMESTAMP NULL COMMENT 'When faces were last synced from file_registry'
            ");
        } catch (\Exception $e) {
            // Column may already exist
        }

        // Add file_registry_face_id to queue for linking back to face record
        try {
            DB::statement("
                ALTER TABLE genealogy_face_match_queue
                ADD COLUMN file_registry_face_id BIGINT UNSIGNED NULL COMMENT 'Links to file_registry_faces'
            ");
        } catch (\Exception $e) {
            // Column may already exist
        }

        // Add index on file_registry_face_id
        try {
            DB::statement("
                CREATE INDEX idx_queue_face_id ON genealogy_face_match_queue (file_registry_face_id)
            ");
        } catch (\Exception $e) {
            // Index may already exist
        }
    }

    public function down(): void
    {
        try {
            DB::statement("ALTER TABLE genealogy_media DROP COLUMN last_face_sync_at");
        } catch (\Exception $e) {
        }

        try {
            DB::statement("ALTER TABLE genealogy_face_match_queue DROP INDEX idx_queue_face_id");
        } catch (\Exception $e) {
        }

        try {
            DB::statement("ALTER TABLE genealogy_face_match_queue DROP COLUMN file_registry_face_id");
        } catch (\Exception $e) {
        }
    }
};
