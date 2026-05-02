<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N58: Review queue data quality cleanup
 *   - Filter no_match faces from face review type (1,940 unactionable items)
 *     They belong in the Unidentified Faces browser (N63), not the review queue.
 *   - Broken source_add proposals (6 rows) are deleted directly on prod.
 *
 * N59: Enrich review queue fetch_sql with anchor person vitals
 *   - proposal: add anchor person birth_date/death_date to SELECT
 *   - change_proposal: add anchor person birth_date/death_date to SELECT
 *   - face: add suggested person birth_date/death_date to SELECT
 *   - All three: update field_mapping + ui_schema to surface dates in sidebar
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── N58a: Filter no_match faces from review queue ────────────────────
        DB::table('review_type_registry')->where('name', 'face')->update([
            'fetch_sql' => "SELECT f.id, f.tree_id, f.media_id, f.face_name, f.suggested_person_id, f.match_type, f.confidence_score, f.face_region, f.match_details, f.created_at, CONCAT(p.given_name, ' ', p.surname) as suggested_person_name, COALESCE(m.nextcloud_path, m.original_path) as media_path, p.birth_date as suggested_person_birth_date, p.death_date as suggested_person_death_date FROM genealogy_face_match_queue f LEFT JOIN genealogy_persons p ON p.id = f.suggested_person_id LEFT JOIN genealogy_media m ON m.id = f.media_id WHERE f.status = 'pending' AND f.match_type != 'no_match' ORDER BY f.confidence_score DESC, f.created_at ASC LIMIT 100",
            'count_sql' => "SELECT COUNT(*) as total FROM genealogy_face_match_queue WHERE status = 'pending' AND match_type != 'no_match'",
        ]);

        // ── N59b: Enrich face field_mapping with suggested person vitals ─────
        $faceMapping = DB::table('review_type_registry')
            ->where('name', 'face')
            ->value('field_mapping');
        $faceMap = json_decode($faceMapping, true);
        $faceMap['suggested_person_birth_date'] = 'suggested_person_birth_date';
        $faceMap['suggested_person_death_date'] = 'suggested_person_death_date';
        DB::table('review_type_registry')->where('name', 'face')->update([
            'field_mapping' => json_encode($faceMap),
        ]);

        // Update face ui_schema to show suggested person vitals in card body
        $faceUiSchema = DB::table('review_type_registry')
            ->where('name', 'face')
            ->value('ui_schema');
        $faceUi = json_decode($faceUiSchema, true);
        // Add birth/death row after "Suggested" person name in card body
        $faceUi['card']['body'][] = [
            'type' => 'text',
            'label' => 'Lived',
            'source' => 'suggested_person_birth_date',
            'source2' => 'suggested_person_death_date',
            'format' => 'date_range',
            'class' => 'text-xs text-ops-text-muted',
        ];
        DB::table('review_type_registry')->where('name', 'face')->update([
            'ui_schema' => json_encode($faceUi),
        ]);

        // ── N59a: Enrich proposal fetch_sql with anchor person vitals ────────
        DB::table('review_type_registry')->where('name', 'proposal')->update([
            'fetch_sql' => "SELECT pr.id, pr.tree_id, pr.person_id, pr.relationship_type, pr.proposed_name, pr.proposed_given_name, pr.proposed_surname, pr.proposed_sex, pr.proposed_birth_date, pr.proposed_birth_place, pr.evidence_sources, pr.evidence_summary, pr.confidence, pr.created_at, CONCAT(p.given_name, ' ', p.surname) as person_name, p.birth_date as anchor_birth_date, p.death_date as anchor_death_date FROM genealogy_proposed_relationships pr LEFT JOIN genealogy_persons p ON p.id = pr.person_id WHERE pr.status = 'pending' ORDER BY pr.confidence DESC, pr.created_at ASC LIMIT 100",
        ]);

        $proposalMapping = DB::table('review_type_registry')
            ->where('name', 'proposal')
            ->value('field_mapping');
        $proposalMap = json_decode($proposalMapping, true);
        $proposalMap['anchor_birth_date'] = 'anchor_birth_date';
        $proposalMap['anchor_death_date'] = 'anchor_death_date';
        DB::table('review_type_registry')->where('name', 'proposal')->update([
            'field_mapping' => json_encode($proposalMap),
        ]);

        // Add anchor person dates to proposal ui_schema header
        $proposalUiSchema = DB::table('review_type_registry')
            ->where('name', 'proposal')
            ->value('ui_schema');
        $proposalUi = json_decode($proposalUiSchema, true);
        // Insert anchor vitals after person_name in header
        $proposalUi['card']['header'][] = [
            'type' => 'text',
            'label' => 'Lived',
            'source' => 'anchor_birth_date',
            'source2' => 'anchor_death_date',
            'format' => 'date_range',
            'class' => 'text-xs text-ops-text-muted',
        ];
        DB::table('review_type_registry')->where('name', 'proposal')->update([
            'ui_schema' => json_encode($proposalUi),
        ]);

        // ── N59a: Enrich change_proposal fetch_sql with anchor person vitals ─
        DB::table('review_type_registry')->where('name', 'change_proposal')->update([
            'fetch_sql' => "SELECT pc.id, pc.tree_id, pc.person_id, pc.change_type, pc.field_name, pc.current_value, pc.proposed_value, pc.evidence_sources, pc.evidence_summary, pc.confidence, pc.created_at, CONCAT(p.given_name, ' ', p.surname) as person_name, p.birth_date as anchor_birth_date, p.death_date as anchor_death_date, CONCAT(pc.change_type, ': ', COALESCE(pc.field_name, CONCAT(p.given_name, ' ', p.surname))) as title FROM genealogy_proposed_changes pc LEFT JOIN genealogy_persons p ON p.id = pc.person_id WHERE pc.status = 'pending' ORDER BY pc.confidence DESC, pc.created_at ASC LIMIT 100",
        ]);

        $changeMapping = DB::table('review_type_registry')
            ->where('name', 'change_proposal')
            ->value('field_mapping');
        $changeMap = json_decode($changeMapping, true);
        $changeMap['anchor_birth_date'] = 'anchor_birth_date';
        $changeMap['anchor_death_date'] = 'anchor_death_date';
        DB::table('review_type_registry')->where('name', 'change_proposal')->update([
            'field_mapping' => json_encode($changeMap),
        ]);

        // Add anchor person dates to change_proposal detail panel
        $changeUiSchema = DB::table('review_type_registry')
            ->where('name', 'change_proposal')
            ->value('ui_schema');
        $changeUi = json_decode($changeUiSchema, true);
        // Prepend person vitals to detail panel (after person name entry)
        $personVitals = [
            'type' => 'text',
            'label' => 'Person Vitals',
            'source' => 'anchor_birth_date',
            'source2' => 'anchor_death_date',
            'format' => 'date_range',
            'class' => 'text-xs text-ops-sky',
        ];
        // Insert after the Person row (index 1)
        array_splice($changeUi['detail'], 2, 0, [$personVitals]);
        DB::table('review_type_registry')->where('name', 'change_proposal')->update([
            'ui_schema' => json_encode($changeUi),
        ]);
    }

    public function down(): void
    {
        // Restore face fetch_sql without no_match filter and without person vitals
        DB::table('review_type_registry')->where('name', 'face')->update([
            'fetch_sql' => "SELECT f.id, f.tree_id, f.media_id, f.face_name, f.suggested_person_id, f.match_type, f.confidence_score, f.face_region, f.match_details, f.created_at, CONCAT(p.given_name, ' ', p.surname) as suggested_person_name, COALESCE(m.nextcloud_path, m.original_path) as media_path FROM genealogy_face_match_queue f LEFT JOIN genealogy_persons p ON p.id = f.suggested_person_id LEFT JOIN genealogy_media m ON m.id = f.media_id WHERE f.status = 'pending' ORDER BY f.confidence_score DESC, f.created_at ASC LIMIT 100",
            'count_sql' => "SELECT COUNT(*) as total FROM genealogy_face_match_queue WHERE status = 'pending'",
        ]);

        // Restore proposal fetch_sql without anchor person vitals
        DB::table('review_type_registry')->where('name', 'proposal')->update([
            'fetch_sql' => "SELECT pr.id, pr.tree_id, pr.person_id, pr.relationship_type, pr.proposed_name, pr.proposed_given_name, pr.proposed_surname, pr.proposed_sex, pr.proposed_birth_date, pr.proposed_birth_place, pr.evidence_sources, pr.evidence_summary, pr.confidence, pr.created_at, CONCAT(p.given_name, ' ', p.surname) as person_name FROM genealogy_proposed_relationships pr LEFT JOIN genealogy_persons p ON p.id = pr.person_id WHERE pr.status = 'pending' ORDER BY pr.confidence DESC, pr.created_at ASC LIMIT 100",
        ]);

        // Restore change_proposal fetch_sql without anchor person vitals
        DB::table('review_type_registry')->where('name', 'change_proposal')->update([
            'fetch_sql' => "SELECT pc.id, pc.tree_id, pc.person_id, pc.change_type, pc.field_name, pc.current_value, pc.proposed_value, pc.evidence_sources, pc.evidence_summary, pc.confidence, pc.created_at, CONCAT(p.given_name, ' ', p.surname) as person_name, CONCAT(pc.change_type, ': ', COALESCE(pc.field_name, CONCAT(p.given_name, ' ', p.surname))) as title FROM genealogy_proposed_changes pc LEFT JOIN genealogy_persons p ON p.id = pc.person_id WHERE pc.status = 'pending' ORDER BY pc.confidence DESC, pc.created_at ASC LIMIT 100",
        ]);
    }
};
