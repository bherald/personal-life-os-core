<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;

/**
 * READ SEAM for the generated-proposals packet-level review endpoint.
 *
 * Loads persisted proposal rows for a single packet's saved
 * proposal_generation_state, preserving generation order (via FIELD()) and
 * exposing the unified_id format ('change:{id}' / 'proposal:{id}') used by
 * the canonical unified review endpoints.
 *
 * Ownership is implicit: only IDs listed in proposal_generation_state are
 * loaded, so callers cannot retrieve proposals from other packets or runs.
 *
 * Use this service for:
 *   GET /intake-runs/{runKey}/generated-proposals
 *
 * For run-scoped or tree-scoped proposal display queues, use
 * GenealogyProposalReviewQueueService instead.
 */
class GenealogyIntakeGeneratedProposalQueryService
{
    private function queueService(): GenealogyProposalReviewQueueService
    {
        return new GenealogyProposalReviewQueueService;
    }

    /**
     * Load persisted proposal rows for a packet's saved proposal_generation_state.
     */
    public function buildPacketProposalReview(array $run, array $packet): array
    {
        $treeId = (int) ($run['tree_id'] ?? 0);
        $state = (array) ($packet['proposal_generation_state'] ?? []);
        $personProposalIds = array_values(array_filter(array_map('intval', (array) ($state['person_proposal_ids'] ?? []))));
        $relationshipProposalIds = array_values(array_filter(array_map('intval', (array) ($state['relationship_proposal_ids'] ?? []))));

        $personChanges = $this->loadPersonChanges($treeId, $personProposalIds);
        $relationships = $this->loadRelationships($treeId, $relationshipProposalIds);

        return [
            'packet' => [
                'packet_key' => (string) ($packet['packet_key'] ?? ''),
                'packet_label' => (string) ($packet['packet_label'] ?? ''),
            ],
            'generation_state' => $state,
            'counts' => [
                'person_changes' => count($personChanges),
                'relationships' => count($relationships),
                'total' => count($personChanges) + count($relationships),
            ],
            'person_changes' => $personChanges,
            'relationships' => $relationships,
        ];
    }

    private function loadPersonChanges(int $treeId, array $proposalIds): array
    {
        if ($treeId < 1 || $proposalIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($proposalIds), '?'));
        $params = array_merge([$treeId], $proposalIds);

        $rows = DB::select(
            "SELECT pc.id, pc.tree_id, pc.person_id, pc.change_type, pc.field_name, pc.current_value, pc.proposed_value,
                    pc.evidence_sources, pc.evidence_summary, pc.confidence, pc.status, pc.created_at,
                    CONCAT(COALESCE(p.given_name, ''), CASE WHEN p.surname IS NOT NULL AND p.surname <> '' THEN CONCAT(' ', p.surname) ELSE '' END) AS person_full_name
             FROM genealogy_proposed_changes pc
             LEFT JOIN genealogy_persons p ON p.id = pc.person_id
             WHERE pc.tree_id = ? AND pc.id IN ($placeholders)
             ORDER BY FIELD(pc.id, ".implode(',', array_fill(0, count($proposalIds), '?')).')',
            array_merge($params, $proposalIds)
        );

        $queueService = $this->queueService();

        return array_map(function ($row) use ($queueService) {
            $evidenceSources = json_decode((string) ($row->evidence_sources ?? '[]'), true);
            $formatted = $queueService->formatPersonChangeRow($row);

            return [
                'proposal_id' => (int) ($formatted['id'] ?? 0),
                'proposal_type' => 'person_change',
                'unified_id' => 'change:'.(int) ($formatted['id'] ?? 0),
                'person_id' => (int) ($formatted['person_id'] ?? 0),
                'person_name' => ($formatted['person_display_name'] ?? null) ?: null,
                'change_type' => (string) ($formatted['change_type'] ?? ''),
                'field_name' => $formatted['field_name'] ?? null,
                'current_value' => $formatted['current_value_excerpt'] ?? null,
                'proposed_value' => $formatted['proposed_value_excerpt'] ?? null,
                'evidence_summary' => $formatted['evidence_summary'] ?? '',
                'evidence_sources' => is_array($evidenceSources) ? $evidenceSources : [],
                'confidence' => isset($formatted['confidence']) ? (float) $formatted['confidence'] : null,
                'status' => (string) ($formatted['status'] ?? ''),
                'created_at' => (string) ($formatted['created_at'] ?? ''),
            ];
        }, $rows);
    }

    private function loadRelationships(int $treeId, array $proposalIds): array
    {
        if ($treeId < 1 || $proposalIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($proposalIds), '?'));
        $params = array_merge([$treeId], $proposalIds);

        $rows = DB::select(
            "SELECT pr.id, pr.tree_id, pr.person_id, pr.relationship_type, pr.related_person_id, pr.proposal_mode, pr.proposed_name, pr.proposed_given_name, pr.proposed_surname,
                    pr.proposed_sex, pr.proposed_birth_date, pr.proposed_birth_place, pr.proposed_death_date, pr.proposed_death_place,
                    pr.proposed_marriage_date, pr.proposed_marriage_place, pr.proposed_notes,
                    pr.applied_person_id, pr.applied_family_id, pr.applied_at,
                    pr.evidence_sources, pr.evidence_summary, pr.confidence, pr.agent_id, pr.status, pr.created_at,
                    CONCAT(COALESCE(p.given_name, ''), CASE WHEN p.surname IS NOT NULL AND p.surname <> '' THEN CONCAT(' ', p.surname) ELSE '' END) AS person_full_name,
                    CONCAT(COALESCE(rp.given_name, ''), CASE WHEN rp.surname IS NOT NULL AND rp.surname <> '' THEN CONCAT(' ', rp.surname) ELSE '' END) AS related_person_full_name
             FROM genealogy_proposed_relationships pr
             LEFT JOIN genealogy_persons p ON p.id = pr.person_id
             LEFT JOIN genealogy_persons rp ON rp.id = pr.related_person_id
             WHERE pr.tree_id = ? AND pr.id IN ($placeholders)
             ORDER BY FIELD(pr.id, ".implode(',', array_fill(0, count($proposalIds), '?')).')',
            array_merge($params, $proposalIds)
        );

        $queueService = $this->queueService();

        return array_map(function ($row) use ($queueService) {
            $evidenceSources = json_decode((string) ($row->evidence_sources ?? '[]'), true);
            $formatted = $queueService->formatRelationshipRow($row);

            return [
                'proposal_id' => (int) ($formatted['id'] ?? 0),
                'proposal_type' => 'relationship',
                'unified_id' => 'proposal:'.(int) ($formatted['id'] ?? 0),
                'person_id' => (int) ($formatted['person_id'] ?? 0),
                'person_name' => ($formatted['person_display_name'] ?? null) ?: null,
                'proposal_mode' => (string) ($formatted['proposal_mode'] ?? 'create_person'),
                'relationship_type' => (string) ($formatted['relationship_type'] ?? ''),
                'related_person_id' => $formatted['related_person_id'] ?? null,
                'related_person_name' => $formatted['related_person_display_name'] ?? null,
                'proposed_name' => $formatted['proposed_name'] ?? null,
                'proposed_given_name' => $formatted['proposed_given_name'] ?? null,
                'proposed_surname' => $formatted['proposed_surname'] ?? null,
                'proposed_sex' => $formatted['proposed_sex'] ?? null,
                'proposed_birth_date' => $formatted['proposed_birth_date'] ?? null,
                'proposed_birth_place' => $formatted['proposed_birth_place'] ?? null,
                'proposed_death_date' => $formatted['proposed_death_date'] ?? null,
                'proposed_death_place' => $formatted['proposed_death_place'] ?? null,
                'proposed_notes' => ($row->proposed_notes ?? null) !== null ? (string) $row->proposed_notes : null,
                'evidence_summary' => $formatted['evidence_summary'] ?? '',
                'evidence_sources' => is_array($evidenceSources) ? $evidenceSources : [],
                'confidence' => isset($formatted['confidence']) ? (float) $formatted['confidence'] : null,
                'status' => (string) ($formatted['status'] ?? ''),
                'created_at' => (string) ($formatted['created_at'] ?? ''),
            ];
        }, $rows);
    }
}
