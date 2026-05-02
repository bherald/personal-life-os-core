<?php

namespace App\Contracts;

/**
 * Interface for domain services that process structured proposals from hybrid workflow LLM output.
 *
 * When a hybrid workflow agent produces structured data (e.g., proposed_relationships, proposed_marriages),
 * the engine delegates to a registered WorkflowProposalHandler to process domain-specific proposals.
 * This keeps the agent engine domain-agnostic.
 */
interface WorkflowProposalHandler
{
    /**
     * Process proposals from a hybrid workflow's final structured output.
     *
     * @param array $finalData The full decoded JSON from the LLM's final phase response
     * @param string $agentId The agent that generated the proposals
     * @param array $context Runtime context (tree_id, session_id, etc.)
     * @return string Markdown report text summarizing what was processed
     */
    public function processProposals(array $finalData, string $agentId, array $context): string;
}
