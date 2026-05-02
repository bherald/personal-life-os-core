<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;

class GenealogyIntakeProposalGenerationPersistenceService
{
    private const AGENT_ID = 'genealogy-intake-proposal-generation';

    private const LINK_EXISTING_PROPOSAL_MODE = 'link_existing';

    public function __construct(
        private readonly PersonService $personService
    ) {}

    /**
     * Persist proposal rows from a ready approval-draft preview.
     * This service only materializes pending genealogy proposal records.
     * It does not apply them to the tree.
     */
    public function persist(array $approvalDraftPreview): array
    {
        $packet = (array) ($approvalDraftPreview['packet'] ?? []);
        $context = (array) ($approvalDraftPreview['context'] ?? []);
        $plan = (array) ($approvalDraftPreview['plan'] ?? []);

        $result = [
            'success' => true,
            'persisted_person_changes' => [],
            'persisted_relationships' => [],
            'failed' => [],
            'skipped' => [],
            'errors' => [],
            'audit' => [
                'packet_key' => (string) ($packet['packet_key'] ?? ''),
                'packet_label' => (string) ($packet['packet_label'] ?? 'unknown'),
                'approved_sections' => array_values((array) ($context['approved_sections'] ?? [])),
            ],
        ];

        if (! (bool) ($plan['ready'] ?? false)) {
            $result['success'] = false;
            $result['errors'][] = 'proposal_plan_not_ready';

            foreach ((array) ($plan['blocked_reasons'] ?? []) as $reason) {
                $value = trim((string) $reason);
                if ($value !== '') {
                    $result['errors'][] = $value;
                }
            }

            return $result;
        }

        foreach ((array) ($plan['blocked'] ?? []) as $blocked) {
            $result['success'] = false;
            $result['failed'][] = [
                'type' => (string) ($blocked['type'] ?? 'blocked'),
                'reason' => (string) ($blocked['reason'] ?? 'Blocked plan item'),
                'section' => isset($blocked['section']) ? (string) $blocked['section'] : null,
            ];
        }

        if ($result['failed'] !== []) {
            foreach ((array) ($plan['blocked_reasons'] ?? []) as $reason) {
                $value = trim((string) $reason);
                if ($value !== '') {
                    $result['errors'][] = $value;
                }
            }

            return $result;
        }

        foreach ((array) ($plan['skipped'] ?? []) as $skipped) {
            $result['skipped'][] = [
                'type' => (string) ($skipped['type'] ?? 'skipped'),
                'reason' => (string) ($skipped['reason'] ?? 'Skipped plan item'),
                'section' => isset($skipped['section']) ? (string) $skipped['section'] : null,
            ];
        }

        foreach ((array) ($plan['existing_person_changes'] ?? []) as $change) {
            $this->persistExistingPersonChange($result, $change, $packet);
        }

        foreach ((array) ($plan['relationship_proposals'] ?? []) as $proposal) {
            $this->persistExistingRelationshipProposal($result, $proposal, $packet);
        }

        return $result;
    }

    private function persistExistingPersonChange(array &$result, array $change, array $packet): void
    {
        $personId = (int) ($change['person_id'] ?? 0);
        $changeType = (string) ($change['change_type'] ?? '');
        $fieldName = isset($change['field_name']) ? (string) $change['field_name'] : null;
        $proposedValue = (string) ($change['proposed_value'] ?? '');
        $treeId = $this->normalizePositiveInt($change['tree_id'] ?? null);
        $packetKey = (string) ($packet['packet_key'] ?? $change['source_packet_key'] ?? '');
        $packetLabel = (string) ($packet['packet_label'] ?? $change['source_packet_label'] ?? 'unknown');
        $pageAnchors = array_values((array) ($change['page_anchors'] ?? []));

        $proposal = $this->personService->proposeChange(
            $personId,
            $changeType,
            $fieldName,
            $proposedValue,
            $pageAnchors,
            $this->buildEvidenceSummary($packetKey, $packetLabel, $changeType, $pageAnchors),
            0.95,
            self::AGENT_ID,
            $treeId
        );

        if (! ($proposal['success'] ?? false)) {
            $result['success'] = false;
            $result['failed'][] = [
                'type' => 'propose_change_failed',
                'person_id' => $personId,
                'change_type' => $changeType,
                'reason' => (string) ($proposal['error'] ?? 'unknown_error'),
            ];
            $result['errors'][] = (string) ($proposal['error'] ?? 'unknown_error');

            return;
        }

        $proposalId = (int) ($proposal['proposal_id'] ?? 0);
        if ($proposalId < 1) {
            $result['success'] = false;
            $result['failed'][] = [
                'type' => 'missing_proposal_id',
                'person_id' => $personId,
                'change_type' => $changeType,
                'reason' => 'Proposal was created without a usable proposal_id.',
            ];
            $result['errors'][] = 'missing_proposal_id';

            return;
        }

        $result['persisted_person_changes'][] = [
            'person_id' => $personId,
            'change_type' => $changeType,
            'proposal_id' => $proposalId,
            'deduplicated' => (bool) ($proposal['deduplicated'] ?? false),
            'existing_status' => isset($proposal['existing_status']) ? (string) $proposal['existing_status'] : null,
        ];
    }

    private function buildEvidenceSummary(string $packetKey, string $packetLabel, string $changeType, array $pageAnchors): string
    {
        $summary = 'Persisted from genealogy intake packet '.$packetLabel;

        if ($packetKey !== '') {
            $summary .= ' ['.$packetKey.']';
        }

        $summary .= ' as '.$changeType;

        if ($pageAnchors !== []) {
            $summary .= ' (anchors: '.implode(', ', $pageAnchors).')';
        }

        return $summary.'.';
    }

    private function persistExistingRelationshipProposal(array &$result, array $proposal, array $packet): void
    {
        $treeId = $this->normalizePositiveInt($proposal['tree_id'] ?? null);
        $personId = $this->normalizePositiveInt($proposal['person_id'] ?? null);
        $relatedPersonId = $this->normalizePositiveInt($proposal['related_person_id'] ?? null);
        $relationshipType = trim((string) ($proposal['relationship_type'] ?? ''));
        $packetKey = (string) ($packet['packet_key'] ?? $proposal['source_packet_key'] ?? '');
        $packetLabel = (string) ($packet['packet_label'] ?? $proposal['source_packet_label'] ?? 'unknown');
        $pageAnchors = array_values((array) ($proposal['page_anchors'] ?? []));
        $evidenceSummary = trim((string) ($proposal['evidence_summary'] ?? ''));

        if ($treeId === null || $personId === null || $relatedPersonId === null || $relationshipType === '') {
            $result['success'] = false;
            $result['failed'][] = [
                'type' => 'invalid_relationship_proposal',
                'reason' => 'tree_id, person_id, related_person_id, and relationship_type are required.',
            ];
            $result['errors'][] = 'invalid_relationship_proposal';

            return;
        }

        $relatedPerson = $this->loadPersonSnapshot($treeId, $relatedPersonId);
        if ($relatedPerson === null) {
            $result['success'] = false;
            $result['failed'][] = [
                'type' => 'related_person_not_found',
                'relationship_type' => $relationshipType,
                'related_person_id' => $relatedPersonId,
                'reason' => 'Related person was not found in the target tree.',
            ];
            $result['errors'][] = 'related_person_not_found';

            return;
        }

        $existing = DB::selectOne(
            "SELECT id, status FROM genealogy_proposed_relationships
             WHERE tree_id = ? AND person_id = ? AND relationship_type = ? AND related_person_id = ? AND proposal_mode = ?
               AND status IN ('pending', 'pending_review', 'approved', 'applied')
             LIMIT 1",
            [$treeId, $personId, $relationshipType, $relatedPersonId, self::LINK_EXISTING_PROPOSAL_MODE]
        );

        if ($existing) {
            $result['persisted_relationships'][] = [
                'person_id' => $personId,
                'relationship_type' => $relationshipType,
                'related_person_id' => $relatedPersonId,
                'proposal_id' => (int) $existing->id,
                'deduplicated' => true,
                'existing_status' => (string) ($existing->status ?? ''),
            ];

            return;
        }

        DB::insert(
            "INSERT INTO genealogy_proposed_relationships
                (tree_id, person_id, relationship_type, related_person_id, proposal_mode,
                 proposed_name, proposed_given_name, proposed_surname, proposed_sex,
                 proposed_birth_date, proposed_birth_place, proposed_death_date, proposed_death_place,
                 evidence_sources, evidence_summary, confidence, agent_id, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())",
            [
                $treeId,
                $personId,
                $relationshipType,
                $relatedPersonId,
                self::LINK_EXISTING_PROPOSAL_MODE,
                $this->buildPersonName($relatedPerson),
                $relatedPerson->given_name ?? null,
                $relatedPerson->surname ?? null,
                $relatedPerson->sex ?? null,
                $relatedPerson->birth_date ?? null,
                $relatedPerson->birth_place ?? null,
                $relatedPerson->death_date ?? null,
                $relatedPerson->death_place ?? null,
                $pageAnchors === [] ? null : json_encode($pageAnchors),
                $evidenceSummary !== ''
                    ? $evidenceSummary
                    : $this->buildEvidenceSummary($packetKey, $packetLabel, 'relationship_'.$relationshipType, $pageAnchors),
                0.95,
                self::AGENT_ID,
            ]
        );

        $proposalId = (int) DB::getPdo()->lastInsertId();
        $result['persisted_relationships'][] = [
            'person_id' => $personId,
            'relationship_type' => $relationshipType,
            'related_person_id' => $relatedPersonId,
            'proposal_id' => $proposalId,
            'deduplicated' => false,
            'existing_status' => null,
        ];
    }

    private function loadPersonSnapshot(int $treeId, int $personId): ?object
    {
        return DB::selectOne(
            'SELECT id, tree_id, given_name, surname, sex, birth_date, birth_place, death_date, death_place
             FROM genealogy_persons
             WHERE tree_id = ? AND id = ?
             LIMIT 1',
            [$treeId, $personId]
        );
    }

    private function buildPersonName(object $person): string
    {
        $fullName = trim(implode(' ', array_filter([
            trim((string) ($person->given_name ?? '')),
            trim((string) ($person->surname ?? '')),
        ])));

        return $fullName !== '' ? $fullName : 'Existing person #'.(int) ($person->id ?? 0);
    }

    private function normalizePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
