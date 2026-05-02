<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * AG-7: Chain-of-Thought Auditing
 *
 * Audits LLM reasoning before tool execution to catch unsafe, unsupported,
 * or confused reasoning chains. Inspired by LlamaFirewall (Meta FAIR).
 *
 * Three-tier response:
 *   pass  — execute normally (vast majority of calls)
 *   warn  — log anomaly but proceed
 *   block — reject tool call, inject feedback so LLM can self-correct
 *
 * Scope: agentic loop only. Deterministic and hybrid workflows are
 * framework-driven and do not need CoT auditing.
 */
class AgentCoTAuditService
{
    /**
     * Keywords indicating evidence-grounded reasoning.
     * A write operation needs ≥2 of these to pass.
     */
    private const EVIDENCE_KEYWORDS = [
        'source', 'record', 'found', 'shows', 'evidence', 'confirms',
        'indicates', 'document', 'citation', 'reference', 'states',
        'according', 'based on', 'from the', 'retrieved', 'search result',
        'result shows', 'data shows', 'returned', 'obituary', 'census',
        'certificate', 'military', 'newspaper', 'birth record', 'death record',
    ];

    /**
     * Phrases that indicate uncertain, unsupported assertions.
     * Dangerous when combined with write operations.
     */
    private const UNCERTAIN_PHRASES = [
        'i think', 'i believe', 'i assume', 'i guess',
        'probably', 'likely that', 'might be', 'may be',
        'perhaps', 'possibly', 'should be', 'could be',
        'i expect', 'i suspect', 'presumably',
    ];

    /**
     * Tools that modify state — require stronger evidence justification.
     */
    private const HIGH_RISK_TOOLS = [
        'proposeChange', 'propose_change',
        'submitForReview', 'submit_for_review',
        'sourceCreate', 'source_create',
        'personUpdate', 'person_update',
        'createRelationship', 'create_relationship',
    ];

    /**
     * Minimum evidence keyword count required before proposing changes.
     */
    private const MIN_EVIDENCE_COUNT = 2;

    /**
     * Reasoning length below this is considered "thin" for write operations.
     */
    private const MIN_REASONING_LENGTH = 150;

    /**
     * Audit LLM reasoning before a tool call executes.
     *
     * @param string $agentId      Agent identifier
     * @param string $reasoning    Full LLM response (reasoning + tool call block)
     * @param string $toolName     Tool about to be executed
     * @param array  $toolParams   Parameters for the tool
     * @param array  $priorCalls   Recent tool call history [{tool, params}]
     * @param array  $skillConfig  Agent skill config (may contain cot_audit settings)
     * @return array{action: string, reason: string, risk: string}
     */
    public function audit(
        string $agentId,
        string $reasoning,
        string $toolName,
        array $toolParams,
        array $priorCalls = [],
        array $skillConfig = []
    ): array {
        // Read-only tools always pass — no audit overhead
        if (!$this->isHighRisk($toolName)) {
            // Still check repetition to catch stuck loops
            $repetition = $this->checkRepetition($toolName, $toolParams, $priorCalls);
            if ($repetition['action'] !== 'pass') {
                return $repetition;
            }
            return $this->pass();
        }

        // High-risk tool: apply full rule set
        $normalizedReasoning = strtolower($reasoning);

        // Rule 1: Thin reasoning + write operation
        $reasoningLen = strlen($reasoning);
        if ($reasoningLen < self::MIN_REASONING_LENGTH) {
            return $this->block(
                "Reasoning too brief ({$reasoningLen} chars) for write operation '{$toolName}'. " .
                "Expand your reasoning with specific evidence before proposing changes.",
                'thin_reasoning'
            );
        }

        // Rule 2: proposeChange / submitForReview require evidence grounding
        if ($this->isProposalTool($toolName)) {
            $evidenceCount = $this->countEvidenceKeywords($normalizedReasoning);

            if ($evidenceCount < self::MIN_EVIDENCE_COUNT) {
                return $this->block(
                    "Insufficient evidence grounding for '{$toolName}' " .
                    "({$evidenceCount}/" . self::MIN_EVIDENCE_COUNT . " evidence keywords found). " .
                    "Cite specific sources, records, or search results before proposing changes.",
                    'insufficient_evidence'
                );
            }
        }

        // Rule 3: Uncertain language + write operation (no evidence override)
        $hasUncertain = $this->hasUncertainLanguage($normalizedReasoning);
        $evidenceCount ??= $this->countEvidenceKeywords($normalizedReasoning);

        if ($hasUncertain && $evidenceCount < self::MIN_EVIDENCE_COUNT) {
            return $this->block(
                "Uncertain language detected ('I think/probably/might be') in reasoning for '{$toolName}' " .
                "with no supporting evidence. Use a research tool to verify the claim before proposing.",
                'uncertain_assertion'
            );
        }

        // Rule 4: Same tool + same key param repeated in last 3 calls
        $repetition = $this->checkRepetition($toolName, $toolParams, $priorCalls);
        if ($repetition['action'] !== 'pass') {
            return $repetition;
        }

        return $this->pass();
    }

    /**
     * Whether to skip auditing for a given agent (e.g., diagnostic/test agents).
     * Checks skill config for `cot_audit: disabled`.
     */
    public function isDisabledForAgent(array $skillConfig): bool
    {
        $setting = $skillConfig['cot_audit'] ?? 'enabled';
        return $setting === 'disabled';
    }

    // =========================================================================
    // Private rule implementations
    // =========================================================================

    private function checkRepetition(string $toolName, array $toolParams, array $priorCalls): array
    {
        if (count($priorCalls) < 3) {
            return $this->pass();
        }

        // Look at last 3 calls for same tool
        $recent = array_slice($priorCalls, -3);
        $sameToolCalls = array_filter($recent, fn($c) => ($c['tool'] ?? '') === $toolName);

        if (count($sameToolCalls) < 3) {
            return $this->pass();
        }

        // All 3 recent calls were this same tool — check if params are nearly identical
        $keyParam = $this->extractKeyParam($toolName, $toolParams);
        if ($keyParam === null) {
            return $this->pass();
        }

        $repeatCount = 0;
        foreach ($sameToolCalls as $call) {
            $priorKeyParam = $this->extractKeyParam($toolName, $call['params'] ?? []);
            if ($priorKeyParam !== null && $priorKeyParam === $keyParam) {
                $repeatCount++;
            }
        }

        if ($repeatCount >= 3) {
            return $this->warn(
                "Tool '{$toolName}' called 3+ times with the same key parameter ('{$keyParam}'). " .
                "Consider trying a different search term, tool, or approach.",
                'repetition_loop'
            );
        }

        return $this->pass();
    }

    private function isHighRisk(string $toolName): bool
    {
        return in_array($toolName, self::HIGH_RISK_TOOLS, true);
    }

    private function isProposalTool(string $toolName): bool
    {
        return in_array($toolName, [
            'proposeChange', 'propose_change',
            'submitForReview', 'submit_for_review',
        ], true);
    }

    private function countEvidenceKeywords(string $text): int
    {
        $count = 0;
        foreach (self::EVIDENCE_KEYWORDS as $keyword) {
            if (str_contains($text, $keyword)) {
                $count++;
            }
        }
        return $count;
    }

    private function hasUncertainLanguage(string $text): bool
    {
        foreach (self::UNCERTAIN_PHRASES as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract the "key parameter" for repetition detection.
     * For search tools: the query string. For others: first string param.
     */
    private function extractKeyParam(string $toolName, array $params): ?string
    {
        // Common search/query param names
        foreach (['query', 'search', 'term', 'name', 'surname', 'given_name', 'keyword'] as $key) {
            if (!empty($params[$key]) && is_string($params[$key])) {
                return strtolower(trim($params[$key]));
            }
        }

        // Fallback: first string value
        foreach ($params as $val) {
            if (is_string($val) && strlen($val) > 2) {
                return strtolower(trim($val));
            }
        }

        return null;
    }

    // =========================================================================
    // Result factories
    // =========================================================================

    private function pass(): array
    {
        return ['action' => 'pass', 'reason' => '', 'risk' => 'none'];
    }

    private function warn(string $reason, string $risk): array
    {
        return ['action' => 'warn', 'reason' => $reason, 'risk' => $risk];
    }

    private function block(string $reason, string $risk): array
    {
        return ['action' => 'block', 'reason' => $reason, 'risk' => $risk];
    }
}
