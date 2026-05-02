<?php

namespace App\Services;

use App\Contracts\WorkflowProposalHandler;
use App\Services\Genealogy\FamilyService;
use App\Services\Genealogy\PersonService;
use Illuminate\Support\Facades\Log;

/**
 * Processes structured proposals from hybrid workflow LLM output.
 *
 * This service decouples the agent engine (AgentLoopService) from domain-specific
 * proposal handling. The engine calls this service; this service delegates to
 * domain services (FamilyService, etc.) based on the proposal type.
 *
 * Registered as tool 'apply_workflow_proposals' in agent_tool_registry.
 */
class AgentProposalService implements WorkflowProposalHandler
{
    /**
     * Process all proposals from a hybrid workflow's final structured output.
     *
     * Handles proposed_relationships and proposed_marriages from genealogy-researcher agent.
     * Extensible to other proposal types as new hybrid agents are added.
     */
    public function processProposals(array $finalData, string $agentId, array $context): string
    {
        $report = '';

        $report .= $this->processRelationshipProposals(
            $finalData['proposed_relationships'] ?? [],
            $agentId,
            $context['tree_id'] ?? null
        );

        $report .= $this->processMarriageProposals(
            $finalData['proposed_marriages'] ?? [],
            $agentId,
            $context['tree_id'] ?? null
        );

        $report .= $this->processChangeProposals(
            $finalData['proposed_changes'] ?? [],
            $agentId,
            $context['tree_id'] ?? null
        );

        return $report;
    }

    /**
     * Submit relationship proposals (parent, child, sibling) to FamilyService.
     */
    private function processRelationshipProposals(array $proposals, string $agentId, ?int $treeId): string
    {
        if (empty($proposals)) {
            return '';
        }

        $report = "### Proposed Relationships\n\n";

        try {
            $familyService = app(FamilyService::class);

            foreach ($proposals as $rel) {
                $propResult = $familyService->proposeRelationship(
                    personId: (int) ($rel['person_id'] ?? 0),
                    relationshipType: $rel['relationship_type'] ?? 'parent',
                    proposedName: $rel['proposed_name'] ?? 'Unknown',
                    proposedSex: $rel['proposed_sex'] ?? null,
                    proposedBirthDate: $rel['proposed_birth_date'] ?? null,
                    proposedBirthPlace: $rel['proposed_birth_place'] ?? null,
                    proposedDeathDate: $rel['proposed_death_date'] ?? null,
                    proposedDeathPlace: $rel['proposed_death_place'] ?? null,
                    evidenceSources: $rel['evidence_sources'] ?? null,
                    evidenceSummary: $rel['evidence_summary'] ?? '',
                    confidence: (float) ($rel['confidence'] ?? 0.5),
                    agentId: $agentId,
                    treeId: $treeId,
                );
                $status = $propResult['success'] ? 'QUEUED' : 'FAILED';
                $report .= "- [{$status}] {$rel['relationship_type']}: {$rel['proposed_name']} for person #{$rel['person_id']}"
                    . " (confidence: " . ($rel['confidence'] ?? '?') . ")\n";
            }
        } catch (\Throwable $e) {
            $report .= "- Error submitting proposals: {$e->getMessage()}\n";
            Log::error('AgentProposalService: Relationship proposal error', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);
        }

        return $report . "\n";
    }

    /**
     * Submit marriage/spouse proposals to FamilyService.
     */
    private function processMarriageProposals(array $proposals, string $agentId, ?int $treeId): string
    {
        if (empty($proposals)) {
            return '';
        }

        $report = "### Proposed Marriages\n\n";

        try {
            $familyService = app(FamilyService::class);

            foreach ($proposals as $mar) {
                $personId = (int) ($mar['person1_id'] ?? 0);
                $spouseName = $mar['person2_name'] ?? 'Unknown spouse';
                $propResult = $familyService->proposeRelationship(
                    personId: $personId,
                    relationshipType: 'spouse',
                    proposedName: $spouseName,
                    proposedBirthDate: null,
                    proposedBirthPlace: null,
                    proposedDeathDate: null,
                    proposedDeathPlace: null,
                    evidenceSources: null,
                    evidenceSummary: ($mar['evidence_summary'] ?? '')
                        . (($mar['marriage_date'] ?? null) ? " Marriage date: {$mar['marriage_date']}" : '')
                        . (($mar['marriage_place'] ?? null) ? " Place: {$mar['marriage_place']}" : '')
                        . (($mar['divorce_date'] ?? null) ? " Divorced: {$mar['divorce_date']}" : ''),
                    confidence: (float) ($mar['confidence'] ?? 0.5),
                    agentId: $agentId,
                    treeId: $treeId,
                );
                $status = $propResult['success'] ? 'QUEUED' : 'FAILED';
                $report .= "- [{$status}] Spouse: {$spouseName} for person #{$personId}"
                    . (($mar['marriage_date'] ?? null) ? " (married {$mar['marriage_date']})" : '')
                    . (($mar['divorce_date'] ?? null) ? " (divorced {$mar['divorce_date']})" : '')
                    . "\n";
            }
        } catch (\Throwable $e) {
            $report .= "- Error submitting marriage proposals: {$e->getMessage()}\n";
            Log::error('AgentProposalService: Marriage proposal error', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);
        }

        return $report . "\n";
    }

    /**
     * Submit change proposals (fact updates, events, sources) to PersonService.
     */
    public function processChangeProposals(array $proposals, string $agentId, ?int $treeId): string
    {
        if (empty($proposals)) {
            return '';
        }

        $report = "### Proposed Changes\n\n";

        try {
            $personService = app(PersonService::class);

            foreach ($proposals as $chg) {
                // Skip notes_append — these are applied via approveGenealogyFinding()
                // when the human approves the parent genealogy_finding review item.
                // Creating them here would produce duplicate standalone change_proposal items.
                if (($chg['change_type'] ?? '') === 'notes_append') {
                    $report .= "- [SKIP] notes_append for person #{$chg['person_id']} (applied via finding approval)\n";
                    continue;
                }

                $propResult = $personService->proposeChange(
                    personId: (int) ($chg['person_id'] ?? 0),
                    changeType: $chg['change_type'] ?? 'fact_update',
                    fieldName: $chg['field_name'] ?? null,
                    proposedValue: is_array($chg['proposed_value'] ?? null)
                        ? json_encode($chg['proposed_value'])
                        : ($chg['proposed_value'] ?? ''),
                    evidenceSources: $chg['evidence_sources'] ?? null,
                    evidenceSummary: $chg['evidence_summary'] ?? '',
                    confidence: (float) ($chg['confidence'] ?? 0.5),
                    agentId: $agentId,
                    treeId: $treeId,
                );
                $status = $propResult['success'] ? 'QUEUED' : 'FAILED';
                $dedup = !empty($propResult['deduplicated']) ? ' (dedup)' : '';
                $report .= "- [{$status}] {$chg['change_type']}: "
                    . ($chg['field_name'] ?? 'N/A') . " for person #{$chg['person_id']}"
                    . " (confidence: " . ($chg['confidence'] ?? '?') . "){$dedup}\n";
            }
        } catch (\Throwable $e) {
            $report .= "- Error submitting change proposals: {$e->getMessage()}\n";
            Log::error('AgentProposalService: Change proposal error', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);
        }

        return $report . "\n";
    }
}
