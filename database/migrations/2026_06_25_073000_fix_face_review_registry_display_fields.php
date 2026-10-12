<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('review_type_registry')) {
            return;
        }

        DB::table('review_type_registry')
            ->where('name', 'face')
            ->update([
                'fetch_sql' => "SELECT f.id, f.tree_id, f.media_id, f.face_name, f.suggested_person_id, f.match_type, f.confidence_score AS confidence, f.face_region, f.match_details, f.created_at, CONCAT(p.given_name, ' ', p.surname) as suggested_person_name, COALESCE(m.nextcloud_path, m.original_path) as media_path, p.birth_date as suggested_person_birth_date, p.death_date as suggested_person_death_date, CONCAT('Face: ', COALESCE(f.face_name, 'Unknown')) AS title, CONCAT('Suggested: ', COALESCE(CONCAT(p.given_name, ' ', p.surname), 'New face')) AS summary FROM genealogy_face_match_queue f LEFT JOIN genealogy_persons p ON p.id = f.suggested_person_id LEFT JOIN genealogy_media m ON m.id = f.media_id WHERE f.status = 'pending' AND f.match_type != 'no_match' ORDER BY f.confidence_score DESC, f.created_at ASC",
                'field_mapping' => json_encode([
                    'id' => 'id',
                    'tree_id' => 'tree_id',
                    'media_id' => 'media_id',
                    'face_name' => 'face_name',
                    'image_url' => '/api/media/face-match-crop/{{id}}',
                    'created_at' => 'created_at',
                    'match_type' => 'match_type',
                    'media_path' => 'media_path',
                    'title' => 'title',
                    'summary' => 'summary',
                    'confidence' => 'confidence',
                    'face_region_json' => 'face_region',
                    'suggested_person_id' => 'suggested_person_id',
                    'unified_id_template' => 'face:{{id}}',
                    'suggested_person_name' => 'suggested_person_name',
                    'suggested_person_birth_date' => 'suggested_person_birth_date',
                    'suggested_person_death_date' => 'suggested_person_death_date',
                ], JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('review_type_registry')) {
            return;
        }

        DB::table('review_type_registry')
            ->where('name', 'face')
            ->update([
                'fetch_sql' => "SELECT f.id, f.tree_id, f.media_id, f.face_name, f.suggested_person_id, f.match_type, f.confidence_score, f.face_region, f.match_details, f.created_at, CONCAT(p.given_name, ' ', p.surname) as suggested_person_name, COALESCE(m.nextcloud_path, m.original_path) as media_path, p.birth_date as suggested_person_birth_date, p.death_date as suggested_person_death_date FROM genealogy_face_match_queue f LEFT JOIN genealogy_persons p ON p.id = f.suggested_person_id LEFT JOIN genealogy_media m ON m.id = f.media_id WHERE f.status = 'pending' AND f.match_type != 'no_match' ORDER BY f.confidence_score DESC, f.created_at ASC",
                'field_mapping' => json_encode([
                    'id' => 'id',
                    'tree_id' => 'tree_id',
                    'media_id' => 'media_id',
                    'face_name' => 'face_name',
                    'image_url' => '/api/media/face-match-crop/{{id}}',
                    'created_at' => 'created_at',
                    'match_type' => 'match_type',
                    'media_path' => 'media_path',
                    'title_expr' => "CONCAT('Face: ', COALESCE(face_name, 'Unknown'))",
                    'summary_expr' => "CONCAT('Suggested: ', COALESCE(suggested_person_name, 'New face'))",
                    'confidence_expr' => 'confidence_score / 100',
                    'face_region_json' => 'face_region',
                    'suggested_person_id' => 'suggested_person_id',
                    'unified_id_template' => 'face:{{id}}',
                    'suggested_person_name' => 'suggested_person_name',
                    'suggested_person_birth_date' => 'suggested_person_birth_date',
                    'suggested_person_death_date' => 'suggested_person_death_date',
                ], JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
