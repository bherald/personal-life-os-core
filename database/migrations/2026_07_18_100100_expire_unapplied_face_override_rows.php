<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ORIGINAL_NOTE = 'Operator override 2026-07-18: exact-name match above 94% confidence.';

    private const EXPIRY_NOTE = 'Operator override 2026-07-18 could not apply: referenced face evidence no longer exists; stale queue row expired.';

    public function up(): void
    {
        if (! Schema::hasTable('genealogy_face_match_queue') || ! Schema::hasTable('file_registry_faces')) {
            return;
        }

        $ids = DB::table('genealogy_face_match_queue as q')
            ->leftJoin('file_registry_faces as frf', 'frf.id', '=', 'q.file_registry_face_id')
            ->where('q.status', 'approved')
            ->where('q.review_notes', self::ORIGINAL_NOTE)
            ->whereNotNull('q.file_registry_face_id')
            ->whereNull('frf.id')
            ->pluck('q.id');

        if ($ids->isNotEmpty()) {
            DB::table('genealogy_face_match_queue')->whereIn('id', $ids)->update([
                'status' => 'ignored',
                'review_notes' => self::EXPIRY_NOTE,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('genealogy_face_match_queue')) {
            return;
        }

        DB::table('genealogy_face_match_queue')
            ->where('status', 'ignored')
            ->where('review_notes', self::EXPIRY_NOTE)
            ->update([
                'status' => 'approved',
                'review_notes' => self::ORIGINAL_NOTE,
                'updated_at' => now(),
            ]);
    }
};
