<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const WORKLIST_LIMIT = 200;

    public function up(): void
    {
        if (! Schema::hasTable('review_type_registry')) {
            return;
        }

        $registryFacePath = "COALESCE(NULLIF(fr.current_path, ''), NULLIF(fr.original_path, ''))";
        $genealogyMediaPath = "COALESCE(NULLIF(m.nextcloud_path, ''), NULLIF(m.original_path, ''))";
        $mediaPath = "COALESCE(NULLIF(m.nextcloud_path, ''), NULLIF(m.original_path, ''), NULLIF(fr.current_path, ''), NULLIF(fr.original_path, ''))";
        $visibleEvidencePredicate = "((
                fr.id IS NOT NULL
                AND {$registryFacePath} IS NOT NULL
            ) OR (
                m.file_exists = 1
                AND {$genealogyMediaPath} IS NOT NULL
            ))";
        $visibleEvidenceExistsPredicate = "(EXISTS (
                SELECT 1
                FROM file_registry_faces frf_count
                JOIN file_registry fr_count
                  ON fr_count.id = frf_count.file_registry_id
                 AND fr_count.status = 'active'
                WHERE frf_count.id = f.file_registry_face_id
                  AND COALESCE(NULLIF(fr_count.current_path, ''), NULLIF(fr_count.original_path, '')) IS NOT NULL
            ) OR EXISTS (
                SELECT 1
                FROM genealogy_media m_count
                WHERE m_count.id = f.media_id
                  AND m_count.file_exists = 1
                  AND COALESCE(NULLIF(m_count.nextcloud_path, ''), NULLIF(m_count.original_path, '')) IS NOT NULL
            ))";

        DB::table('review_type_registry')
            ->where('name', 'face')
            ->update([
                'fetch_sql' => 'SELECT f.id, f.tree_id, f.media_id, f.file_registry_face_id, f.face_name, f.suggested_person_id, f.match_type, f.confidence_score AS confidence, f.face_region, f.match_details, f.created_at, CONCAT(p.given_name, \' \', p.surname) as suggested_person_name, '.$mediaPath.' as media_path, fr.asset_uuid as asset_uuid, p.birth_date as suggested_person_birth_date, p.death_date as suggested_person_death_date, CONCAT(\'Face: \', COALESCE(f.face_name, \'Unknown\')) AS title, CONCAT(\'Suggested: \', COALESCE(CONCAT(p.given_name, \' \', p.surname), \'New face\')) AS summary, CASE WHEN '.$visibleEvidencePredicate.' THEN 1 ELSE 0 END AS image_available FROM genealogy_face_match_queue f LEFT JOIN genealogy_persons p ON p.id = f.suggested_person_id LEFT JOIN genealogy_media m ON m.id = f.media_id LEFT JOIN file_registry_faces frf ON frf.id = f.file_registry_face_id LEFT JOIN file_registry fr ON fr.id = frf.file_registry_id AND fr.status = \'active\' WHERE f.status = \'pending\' AND f.match_type != \'no_match\' AND f.suggested_person_id IS NOT NULL AND '.$visibleEvidencePredicate.' ORDER BY f.confidence_score DESC, f.created_at ASC LIMIT '.self::WORKLIST_LIMIT,
                'count_sql' => 'SELECT COUNT(*) as total FROM (SELECT f.id FROM genealogy_face_match_queue f WHERE f.status = \'pending\' AND f.match_type != \'no_match\' AND f.suggested_person_id IS NOT NULL AND '.$visibleEvidenceExistsPredicate.' ORDER BY f.confidence_score DESC, f.created_at ASC LIMIT '.self::WORKLIST_LIMIT.') face_review_worklist',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('review_type_registry')) {
            return;
        }

        $previousMigration = include database_path('migrations/2026_07_02_164000_simplify_face_review_registry_visible_evidence_sql.php');
        $previousMigration->up();
    }
};
