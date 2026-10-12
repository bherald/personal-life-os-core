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

        $registryFacePath = "COALESCE(NULLIF(fr.current_path, ''), NULLIF(fr.original_path, ''))";
        $registryMediaPath = "COALESCE(NULLIF(fr2.current_path, ''), NULLIF(fr2.original_path, ''))";
        $genealogyMediaPath = "COALESCE(NULLIF(m.nextcloud_path, ''), NULLIF(m.original_path, ''))";
        $mediaPath = "COALESCE(NULLIF(m.nextcloud_path, ''), NULLIF(m.original_path, ''), NULLIF(fr.current_path, ''), NULLIF(fr.original_path, ''), NULLIF(fr2.current_path, ''), NULLIF(fr2.original_path, ''))";

        $visibleEvidencePredicate = "((
                fr.id IS NOT NULL
                AND {$registryFacePath} IS NOT NULL
            ) OR (
                fr2.id IS NOT NULL
                AND {$registryMediaPath} IS NOT NULL
            ) OR (
                m.file_exists = 1
                AND {$genealogyMediaPath} IS NOT NULL
            ))";

        DB::table('review_type_registry')
            ->where('name', 'face')
            ->update([
                'fetch_sql' => "SELECT DISTINCT f.id, f.tree_id, f.media_id, f.file_registry_face_id, f.face_name, f.suggested_person_id, f.match_type, f.confidence_score AS confidence, f.face_region, f.match_details, f.created_at, CONCAT(p.given_name, ' ', p.surname) as suggested_person_name, {$mediaPath} as media_path, COALESCE(fr.asset_uuid, fr2.asset_uuid) as asset_uuid, p.birth_date as suggested_person_birth_date, p.death_date as suggested_person_death_date, CONCAT('Face: ', COALESCE(f.face_name, 'Unknown')) AS title, CONCAT('Suggested: ', COALESCE(CONCAT(p.given_name, ' ', p.surname), 'New face')) AS summary, CASE WHEN {$visibleEvidencePredicate} THEN 1 ELSE 0 END AS image_available FROM genealogy_face_match_queue f LEFT JOIN genealogy_persons p ON p.id = f.suggested_person_id LEFT JOIN genealogy_media m ON m.id = f.media_id LEFT JOIN file_registry_faces frf ON frf.id = f.file_registry_face_id LEFT JOIN file_registry fr ON fr.id = frf.file_registry_id AND fr.status = 'active' LEFT JOIN file_registry fr2 ON fr2.status = 'active' AND (fr2.current_path = m.nextcloud_path OR fr2.original_path = m.original_path OR fr2.current_path = m.original_path OR fr2.original_path = m.nextcloud_path) WHERE f.status = 'pending' AND f.match_type != 'no_match' AND f.suggested_person_id IS NOT NULL AND {$visibleEvidencePredicate} ORDER BY f.confidence_score DESC, f.created_at ASC",
                'count_sql' => "SELECT COUNT(DISTINCT f.id) as total FROM genealogy_face_match_queue f LEFT JOIN genealogy_media m ON m.id = f.media_id LEFT JOIN file_registry_faces frf ON frf.id = f.file_registry_face_id LEFT JOIN file_registry fr ON fr.id = frf.file_registry_id AND fr.status = 'active' LEFT JOIN file_registry fr2 ON fr2.status = 'active' AND (fr2.current_path = m.nextcloud_path OR fr2.original_path = m.original_path OR fr2.current_path = m.original_path OR fr2.original_path = m.nextcloud_path) WHERE f.status = 'pending' AND f.match_type != 'no_match' AND f.suggested_person_id IS NOT NULL AND {$visibleEvidencePredicate}",
                'field_mapping' => json_encode([
                    'id' => 'id',
                    'tree_id' => 'tree_id',
                    'media_id' => 'media_id',
                    'file_registry_face_id' => 'file_registry_face_id',
                    'face_name' => 'face_name',
                    'image_url' => '/api/media/face-match-crop/{{id}}',
                    'image_available' => 'image_available',
                    'created_at' => 'created_at',
                    'match_type' => 'match_type',
                    'media_path' => 'media_path',
                    'asset_uuid' => 'asset_uuid',
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
                'fetch_sql' => "SELECT f.id, f.tree_id, f.media_id, f.face_name, f.suggested_person_id, f.match_type, f.confidence_score AS confidence, f.face_region, f.match_details, f.created_at, CONCAT(p.given_name, ' ', p.surname) as suggested_person_name, COALESCE(m.nextcloud_path, m.original_path) as media_path, p.birth_date as suggested_person_birth_date, p.death_date as suggested_person_death_date, CONCAT('Face: ', COALESCE(f.face_name, 'Unknown')) AS title, CONCAT('Suggested: ', COALESCE(CONCAT(p.given_name, ' ', p.surname), 'New face')) AS summary FROM genealogy_face_match_queue f LEFT JOIN genealogy_persons p ON p.id = f.suggested_person_id LEFT JOIN genealogy_media m ON m.id = f.media_id WHERE f.status = 'pending' AND f.match_type != 'no_match' ORDER BY f.confidence_score DESC, f.created_at ASC",
                'count_sql' => "SELECT COUNT(*) as total FROM genealogy_face_match_queue WHERE status = 'pending' AND match_type != 'no_match'",
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
};
