<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE genealogy_face_match_queue MODIFY media_id INT UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::table('genealogy_face_match_queue')
            ->whereNull('media_id')
            ->where('match_type', 'candidate_decision')
            ->delete();

        DB::statement('ALTER TABLE genealogy_face_match_queue MODIFY media_id INT UNSIGNED NOT NULL');
    }
};
