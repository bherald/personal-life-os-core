<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * AG-8: Agent Constitution
 *
 * Loads and enforces the PLOS Agent Constitution — a set of explicit
 * behavioral principles applied to every agent run. Inspired by
 * Constitutional AI (Anthropic, 2022).
 *
 * Two enforcement mechanisms:
 *   1. Prompt injection — constitution text prepended to system prompt
 *      so the LLM self-governs before tool calls.
 *   2. Pre-tool hard check — deterministic rule evaluation before each
 *      tool execution (tree_id isolation, warn-listed tools).
 *
 * Per-agent extensions: SKILL.md may include a `constitution_rules:` list
 * of additional {id, principle} objects appended to the global rules.
 *
 * Lifecycle: stateless singleton (all state from config + skillConfig).
 */
class AgentConstitutionService
{
    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Load the applicable rule set for this agent run.
     *
     * @param  string $agentId
     * @param  array  $skillConfig  Parsed SKILL.md config
     * @return array  Flat array of rule objects
     */
    public function getRules(string $agentId, array $skillConfig = []): array
    {
        $rules = config('agent_constitution.rules', []);

        // Per-agent extensions from SKILL.md `constitution_rules:` block
        $agentRules = $skillConfig['constitution_rules'] ?? [];
        if (!empty($agentRules) && is_array($agentRules)) {
            foreach ($agentRules as $extra) {
                if (!empty($extra['id']) && !empty($extra['principle'])) {
                    $rules[] = [
                        'id'         => $extra['id'],
                        'name'       => $extra['name'] ?? $extra['id'],
                        'principle'  => $extra['principle'],
                        'deny_tools' => $extra['deny_tools'] ?? [],
                        'warn_tools' => $extra['warn_tools'] ?? [],
                    ];
                }
            }
        }

        return $rules;
    }

    /**
     * Build the constitution fragment for injection into the system prompt.
     *
     * Returns a compact multi-line block; empty string if no rules.
     */
    public function buildSystemPromptFragment(array $rules): string
    {
        if (empty($rules)) {
            return '';
        }

        $lines = ['## Agent Constitution (Binding Principles)', ''];
        $lines[] = 'You must follow these principles in every action you take:';
        $lines[] = '';

        foreach ($rules as $rule) {
            $lines[] = "**{$rule['id']} — {$rule['name']}:** {$rule['principle']}";
        }

        $lines[] = '';
        $lines[] = 'If you are uncertain whether an action violates these principles, '
                 . 'pause, explain your concern, and ask for clarification rather than proceeding.';

        return implode("\n", $lines);
    }

    /**
     * Evaluate a pending tool call against the constitution.
     *
     * Checks:
     *   - C4 tree isolation: warn if tool params contain a tree_id that
     *     differs from the active tree_id in context.
     *   - warn_tools lists: warn if the tool appears in any rule's warn_tools.
     *   - deny_tools lists: deny if the tool appears in any rule's deny_tools.
     *
     * @param  string $toolName
     * @param  array  $toolParams
     * @param  array  $rules       From getRules()
     * @param  array  $context     Runtime context (tree_id, agent_id, iteration)
     * @return array{action: string, rule_id: string, reason: string}
     */
    public function evaluateTool(string $toolName, array $toolParams, array $rules, array $context = []): array
    {
        // Check deny_tools across all rules
        foreach ($rules as $rule) {
            if (!empty($rule['deny_tools']) && in_array($toolName, $rule['deny_tools'], true)) {
                return $this->deny(
                    $rule['id'],
                    "Tool '{$toolName}' is prohibited by constitutional rule {$rule['id']} ({$rule['name']}). "
                    . $rule['principle']
                );
            }
        }

        // C4: Tree isolation — if tool params include tree_id, it must match active tree
        $activeTreeId = $context['tree_id'] ?? null;
        if ($activeTreeId !== null && isset($toolParams['tree_id'])) {
            $requestedTreeId = (int) $toolParams['tree_id'];
            if ($requestedTreeId !== (int) $activeTreeId) {
                Log::warning('AgentConstitution: C4 tree isolation violation attempt', [
                    'agent_id'          => $context['agent_id'] ?? '?',
                    'tool'              => $toolName,
                    'active_tree_id'    => $activeTreeId,
                    'requested_tree_id' => $requestedTreeId,
                    'iteration'         => $context['iteration'] ?? 0,
                ]);
                return $this->deny(
                    'C4',
                    "C4 — Tree Isolation: You attempted to access tree_id {$requestedTreeId} "
                    . "but the active tree is {$activeTreeId}. "
                    . "All genealogy operations must be scoped to tree_id {$activeTreeId}."
                );
            }
        }

        // Check warn_tools across all rules
        foreach ($rules as $rule) {
            if (!empty($rule['warn_tools']) && in_array($toolName, $rule['warn_tools'], true)) {
                return $this->warn(
                    $rule['id'],
                    "Tool '{$toolName}' requires attention under constitutional rule "
                    . "{$rule['id']} ({$rule['name']}): {$rule['principle']}"
                );
            }
        }

        return $this->allow();
    }

    // =========================================================================
    // Result factories
    // =========================================================================

    private function allow(): array
    {
        return ['action' => 'allow', 'rule_id' => '', 'reason' => ''];
    }

    private function warn(string $ruleId, string $reason): array
    {
        return ['action' => 'warn', 'rule_id' => $ruleId, 'reason' => $reason];
    }

    private function deny(string $ruleId, string $reason): array
    {
        return ['action' => 'deny', 'rule_id' => $ruleId, 'reason' => $reason];
    }
}
