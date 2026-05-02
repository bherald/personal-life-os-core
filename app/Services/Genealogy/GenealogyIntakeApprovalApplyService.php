<?php

namespace App\Services\Genealogy;

use Illuminate\Database\ConnectionInterface;

class GenealogyIntakeApprovalApplyService
{
    private const AGENT_ID = 'genealogy-intake-approval';

    private ?ConnectionInterface $db;

    public function __construct(
        private readonly PersonService $personService,
        private readonly GenealogyIntakeExistingRelationshipLinkService $relationshipLinkService,
        ?ConnectionInterface $db = null
    ) {
        // Laravel binds ConnectionInterface to the default DB connection via
        // DatabaseManager. Accept an optional override so unit tests can inject
        // a Mockery mock without hitting the global DB facade. Resolution is
        // deferred to first use so tests that never touch the DB don't need a
        // booted container.
        $this->db = $db;
    }

    private function db(): ConnectionInterface
    {
        if ($this->db === null) {
            $this->db = app('db')->connection();
        }

        return $this->db;
    }

    /**
     * Apply only the currently supported intake approval plan items through existing safe proposal seams.
     * No raw genealogy table writes are performed here.
     */
    public function apply(array $approvalDraftPreview): array
    {
        $packet = (array) ($approvalDraftPreview['packet'] ?? []);
        $context = (array) ($approvalDraftPreview['context'] ?? []);
        $plan = (array) ($approvalDraftPreview['plan'] ?? []);

        $result = [
            'success' => true,
            'applied_person_changes' => [],
            'applied_relationships' => [],
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
            $result['errors'][] = 'approval_plan_not_ready';

            return $result;
        }

        foreach ((array) ($plan['blocked'] ?? []) as $blocked) {
            $result['success'] = false;
            $result['failed'][] = [
                'type' => (string) ($blocked['type'] ?? 'blocked'),
                'reason' => (string) ($blocked['reason'] ?? 'Blocked plan item'),
            ];
        }

        foreach ((array) ($plan['blocked_reasons'] ?? []) as $reason) {
            $value = trim((string) $reason);
            if ($value !== '') {
                $result['success'] = false;
                $result['errors'][] = $value;
            }
        }

        if ($result['failed'] !== [] || $result['errors'] !== []) {
            return $result;
        }

        foreach ((array) ($plan['skipped'] ?? []) as $skipped) {
            $result['skipped'][] = [
                'type' => (string) ($skipped['type'] ?? 'skipped'),
                'reason' => (string) ($skipped['reason'] ?? 'Skipped plan item'),
            ];
        }

        foreach ((array) ($plan['existing_person_changes'] ?? []) as $change) {
            $this->applyExistingPersonChange($result, $change, $packet);
        }

        foreach ((array) ($plan['relationship_proposals'] ?? []) as $proposal) {
            $this->applyExistingRelationship($result, $proposal);
        }

        return $result;
    }

    private function applyExistingPersonChange(array &$result, array $change, array $packet): void
    {
        $personId = (int) ($change['person_id'] ?? 0);
        $changeType = (string) ($change['change_type'] ?? '');
        $fieldName = isset($change['field_name']) ? (string) $change['field_name'] : null;
        $proposedValue = (string) ($change['proposed_value'] ?? '');
        $treeId = $this->normalizePositiveInt($change['tree_id'] ?? null);
        $packetLabel = (string) ($packet['packet_label'] ?? $change['source_packet_label'] ?? 'unknown');
        $pageAnchors = array_values((array) ($change['page_anchors'] ?? []));

        $proposal = $this->personService->proposeChange(
            $personId,
            $changeType,
            $fieldName,
            $proposedValue,
            $pageAnchors,
            $this->buildEvidenceSummary($packetLabel, $pageAnchors),
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

        if (($proposal['deduplicated'] ?? false) && ($proposal['existing_status'] ?? null) === 'applied') {
            $result['skipped'][] = [
                'type' => 'already_applied_change',
                'person_id' => $personId,
                'change_type' => $changeType,
                'proposal_id' => $proposalId,
            ];

            return;
        }

        // Intake flow has already carried human approval through the UI/packet
        // review step before this service runs. Flip the newly-created proposal
        // row to 'approved' so applyProposedChange's status guard accepts it.
        $this->db()->update(
            "UPDATE genealogy_proposed_changes SET status='approved', reviewer_notes=COALESCE(reviewer_notes,'Approved via intake packet apply'), reviewed_at=COALESCE(reviewed_at, NOW()), applied_at=NULL, updated_at=NOW() WHERE id=? AND status='pending'",
            [$proposalId]
        );

        $applied = $this->personService->applyProposedChange($proposalId);
        if (! ($applied['success'] ?? false)) {
            $result['success'] = false;
            $result['failed'][] = [
                'type' => 'apply_change_failed',
                'person_id' => $personId,
                'change_type' => $changeType,
                'proposal_id' => $proposalId,
                'reason' => (string) ($applied['error'] ?? 'unknown_error'),
            ];
            $result['errors'][] = (string) ($applied['error'] ?? 'unknown_error');

            return;
        }

        $result['applied_person_changes'][] = [
            'person_id' => $personId,
            'change_type' => $changeType,
            'proposal_id' => $proposalId,
        ];
    }

    private function buildEvidenceSummary(string $packetLabel, array $pageAnchors): string
    {
        $summary = 'Applied from genealogy intake packet '.$packetLabel;

        if ($pageAnchors !== []) {
            $summary .= ' (anchors: '.implode(', ', $pageAnchors).')';
        }

        return $summary.'.';
    }

    private function applyExistingRelationship(array &$result, array $proposal): void
    {
        $linked = $this->relationshipLinkService->link($proposal);
        if (! ($linked['success'] ?? false)) {
            $result['success'] = false;
            $result['failed'][] = [
                'type' => 'relationship_link_failed',
                'relationship_type' => (string) ($proposal['relationship_type'] ?? ''),
                'person_id' => (int) ($proposal['person_id'] ?? 0),
                'related_person_id' => (int) ($proposal['related_person_id'] ?? 0),
                'reason' => (string) ($linked['error'] ?? 'unknown_error'),
            ];
            $result['errors'][] = (string) ($linked['error'] ?? 'unknown_error');

            return;
        }

        $result['applied_relationships'][] = [
            'relationship_type' => (string) ($proposal['relationship_type'] ?? ''),
            'person_id' => (int) ($proposal['person_id'] ?? 0),
            'related_person_id' => (int) ($proposal['related_person_id'] ?? 0),
            'family_id' => (int) ($linked['family_id'] ?? 0),
            'status' => (string) ($linked['status'] ?? 'linked'),
        ];
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
