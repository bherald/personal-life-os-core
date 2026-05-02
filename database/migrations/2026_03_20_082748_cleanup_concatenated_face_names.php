<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Clean up concatenated face_name values in both tables.
 * Bug: multiple person names were joined with commas into a single field.
 * These entries are duplicates of existing correct single-name entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Queue cleanup already ran from first migration attempt — verify
        $remaining = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM genealogy_face_match_queue WHERE face_name LIKE '%,%'"
        );
        if ($remaining->cnt > 0) {
            $deleted = DB::delete(
                "DELETE FROM genealogy_face_match_queue WHERE face_name LIKE '%,%'"
            );
            Log::info("Migration: Deleted {$deleted} concatenated face_name entries from queue");
        }

        // Delete concatenated person_name rows from source table.
        // These are duplicates — the individual name already exists as a separate row.
        $deleted = DB::delete(
            "DELETE FROM file_registry_faces WHERE person_name LIKE '%,%'"
        );
        Log::info("Migration: Deleted {$deleted} concatenated person_name entries from file_registry_faces");
    }

    public function down(): void
    {
        // Non-reversible data cleanup — bad data cannot be restored
    }
};
