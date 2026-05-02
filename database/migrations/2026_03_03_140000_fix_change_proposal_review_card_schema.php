<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N48b: Fix change_proposal review type to use card-wrapped ui_schema.
 *
 * DynamicReviewCard requires ui_schema.card.{header,body,footer} structure.
 * The change_proposal type had header/body/footer at root level (no card wrapper),
 * causing blank list items. Also fixes title mapping — was using title_expr
 * which mapItems doesn't evaluate; now computed as SQL alias in fetch_sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        $uiSchema = json_encode([
            'card' => [
                'header' => [
                    ['type' => 'badge', 'class' => 'bg-ops-sky', 'source' => 'change_type'],
                    ['type' => 'text', 'class' => 'font-semibold text-ops-peach flex-1', 'source' => 'person_name'],
                    ['type' => 'confidence', 'source' => 'confidence'],
                ],
                'body' => [
                    ['type' => 'text', 'class' => 'text-sm text-ops-text-muted', 'source' => 'summary'],
                ],
                'footer' => [
                    ['type' => 'timestamp', 'label' => 'Created', 'source' => 'created_at'],
                ],
            ],
            'detail' => [
                ['type' => 'badge', 'label' => 'Change Type', 'source' => 'change_type'],
                ['type' => 'text', 'label' => 'Person', 'source' => 'person_name'],
                ['type' => 'text', 'label' => 'Field', 'source' => 'field_name'],
                ['type' => 'text', 'label' => 'Current Value', 'source' => 'current_value'],
                ['type' => 'text', 'label' => 'Proposed Value', 'source' => 'proposed_value'],
                ['type' => 'json', 'label' => 'Evidence Sources', 'source' => 'evidence_sources', 'collapsible' => true],
            ],
        ]);

        $fieldMapping = json_encode([
            'id' => 'id',
            'title' => 'title',
            'summary' => 'evidence_summary',
            'tree_id' => 'tree_id',
            'person_id' => 'person_id',
            'confidence' => 'confidence',
            'created_at' => 'created_at',
            'field_name' => 'field_name',
            'change_type' => 'change_type',
            'person_name' => 'person_name',
            'current_value' => 'current_value',
            'proposed_value' => 'proposed_value',
            'evidence_sources_json' => 'evidence_sources',
            'unified_id_template' => 'change_proposal:{{id}}',
        ]);

        $fetchSql = "SELECT pc.id, pc.tree_id, pc.person_id, pc.change_type, pc.field_name, "
            . "pc.current_value, pc.proposed_value, pc.evidence_sources, pc.evidence_summary, "
            . "pc.confidence, pc.created_at, "
            . "CONCAT(p.given_name, ' ', p.surname) as person_name, "
            . "CONCAT(pc.change_type, ': ', COALESCE(pc.field_name, CONCAT(p.given_name, ' ', p.surname))) as title "
            . "FROM genealogy_proposed_changes pc "
            . "LEFT JOIN genealogy_persons p ON p.id = pc.person_id "
            . "WHERE pc.status = 'pending' ORDER BY pc.confidence DESC, pc.created_at ASC LIMIT 100";

        DB::update(
            "UPDATE review_type_registry SET ui_schema = ?, field_mapping = ?, fetch_sql = ? WHERE name = 'change_proposal'",
            [$uiSchema, $fieldMapping, $fetchSql]
        );
    }

    public function down(): void
    {
        // Restore original (broken) schema — not worth preserving
    }
};
