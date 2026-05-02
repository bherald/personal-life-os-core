<?php

namespace App\Services\Genealogy;

class GenealogyReviewPacketApplyPreviewService
{
    public function __construct(
        private readonly GenealogyFamilyRemediationPreviewService $familyRemediationPreview = new GenealogyFamilyRemediationPreviewService,
    ) {}

    public function preview(array $packet): array
    {
        $operations = [];

        foreach ($this->remediationInputs($packet) as $index => $remediation) {
            $operation = $this->familyRemediationPreview->preview($remediation, $index);
            if ($operation !== null) {
                $operations[] = $operation;
            }
        }

        foreach ($this->proposalInputs($packet) as $index => $proposal) {
            $operation = $this->operationForProposal($proposal, $packet, $index);
            if ($operation !== null) {
                $operations[] = $operation;
            }
        }

        return [
            'status' => 'preview_only',
            'mutates_accepted_facts' => false,
            'accepted_fact_mutations' => [],
            'operation_count' => count($operations),
            'operations' => $operations,
            'summary' => $this->summary($operations),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function remediationInputs(array $packet): array
    {
        $inputs = [];

        foreach (['remediation', 'remediation_packet'] as $key) {
            $value = $packet[$key] ?? null;
            if (is_array($value)) {
                $inputs[] = array_merge($packet, $value);
            }
        }

        if ($this->scalarText($packet, ['operation_type', 'operation', 'type']) === 'family_duplicate_mark') {
            $inputs[] = $packet;
        }

        return $inputs;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function proposalInputs(array $packet): array
    {
        $inputs = [];

        foreach (['proposals', 'claims', 'extracted_claims', 'facts'] as $key) {
            $value = $packet[$key] ?? null;
            if (! is_array($value) || $value === []) {
                continue;
            }

            if (! $this->isList($value)) {
                $inputs[] = $value;

                continue;
            }

            foreach ($value as $item) {
                if (is_array($item)) {
                    $inputs[] = $item;
                }
            }
        }

        if ($inputs === [] && $this->scalarText($packet, ['claim', 'extracted_claim', 'claim_text', 'statement']) !== '') {
            $inputs[] = $packet;
        }

        return $inputs;
    }

    private function operationForProposal(array $proposal, array $packet, int $index): ?array
    {
        $proposal = array_merge((array) ($proposal['proposal'] ?? []), $proposal);
        $remediationPreview = $this->familyRemediationPreview->preview(array_merge($packet, $proposal), $index);
        if ($remediationPreview !== null) {
            return $remediationPreview;
        }

        $personId = $this->personId($proposal, $packet);
        $evidenceSummary = $this->scalarText($proposal, ['evidence_summary', 'summary', 'claim', 'claim_text', 'statement', 'extracted_claim']);

        if ($this->scalarText($proposal, ['relationship_type']) !== '' || $this->scalarText($proposal, ['proposed_name']) !== '') {
            return [
                'index' => $index,
                'operation' => 'would_create_pending_relationship_proposal',
                'target_table' => 'genealogy_proposed_relationships',
                'person_id' => $personId,
                'relationship_type' => $this->scalarText($proposal, ['relationship_type']) ?: null,
                'proposed_name' => $this->scalarText($proposal, ['proposed_name', 'related_person_name', 'value']) ?: null,
                'evidence_summary' => $evidenceSummary ?: null,
                'status' => 'pending',
            ];
        }

        if ($this->scalarText($proposal, ['field_name', 'change_type', 'proposed_value']) !== '') {
            return [
                'index' => $index,
                'operation' => 'would_create_pending_person_change',
                'target_table' => 'genealogy_proposed_changes',
                'person_id' => $personId,
                'change_type' => $this->scalarText($proposal, ['change_type']) ?: 'fact_update',
                'field_name' => $this->scalarText($proposal, ['field_name']) ?: null,
                'current_value' => $this->scalarText($proposal, ['current_value']) ?: null,
                'proposed_value' => $this->scalarText($proposal, ['proposed_value', 'value', 'claim', 'claim_text', 'statement']) ?: null,
                'evidence_summary' => $evidenceSummary ?: null,
                'status' => 'pending',
            ];
        }

        if ($evidenceSummary !== '') {
            return [
                'index' => $index,
                'operation' => 'would_hold_for_operator_review',
                'target_table' => null,
                'person_id' => $personId,
                'claim' => $evidenceSummary,
                'status' => 'pending_review',
            ];
        }

        return null;
    }

    private function personId(array $proposal, array $packet): ?int
    {
        foreach ([
            $proposal['person_id'] ?? null,
            $proposal['target_person_id'] ?? null,
            $packet['person_id'] ?? null,
            $packet['target_person_id'] ?? null,
            $packet['identity']['person_id'] ?? null,
            $packet['identity']['target_person_id'] ?? null,
        ] as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    private function scalarText(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @param  array<int, array<string, mixed>>  $operations
     */
    private function summary(array $operations): array
    {
        $counts = [
            'pending_person_changes' => 0,
            'pending_relationship_proposals' => 0,
            'remediation_previews' => 0,
            'blocked_remediation_previews' => 0,
            'operator_review_only' => 0,
        ];

        foreach ($operations as $operation) {
            if (($operation['target_table'] ?? null) === 'genealogy_proposed_changes') {
                $counts['pending_person_changes']++;
            } elseif (($operation['target_table'] ?? null) === 'genealogy_proposed_relationships') {
                $counts['pending_relationship_proposals']++;
            } elseif (($operation['operation_type'] ?? null) === 'family_duplicate_mark') {
                $counts['remediation_previews']++;
                if (($operation['status'] ?? null) === 'blocked') {
                    $counts['blocked_remediation_previews']++;
                }
            } else {
                $counts['operator_review_only']++;
            }
        }

        return $counts;
    }
}
