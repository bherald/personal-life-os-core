<?php

namespace App\Services;

final class AgentMemoryUsePolicyService
{
    /**
     * Keep the policy outside the low-trust memory envelope so retrieved text
     * cannot redefine how the model is allowed to use memory.
     */
    public function promptPreamble(string $sourceType): string
    {
        $source = match ($sourceType) {
            'agent_run_memory' => 'Current run memory preserves continuity but is not proof.',
            'agent_procedural_memory' => 'Procedural memory is advisory guidance, not authority.',
            'agent_episodic_memory', 'agent_cross_memory' => 'Past experience is advisory context, not independent factual evidence.',
            default => 'Retrieved memory is advisory context, not independent factual evidence.',
        };

        return implode("\n", [
            '## Memory Use Policy',
            $source,
            '- Decide objective facts and safety questions from current verified evidence, never from a remembered user belief or preference.',
            '- Apply a memory only to the same subject, task, and scope; do not transfer a personal preference to another person or a group decision.',
            '- Prefer an explicit current update over superseded memory, while preserving unresolved contradictions for review.',
            '- Let stronger current evidence, task constraints, and safety rules override conflicting memory.',
            '- Use an applicable active preference for personalization when no stronger evidence, constraint, or safety rule conflicts.',
        ]);
    }

    /**
     * Deterministic policy classifier used by the native golden gate.
     *
     * @param  array<string,mixed>  $memory
     * @return array{disposition:string,usable:bool}
     */
    public function assess(array $memory): array
    {
        $status = strtolower(trim((string) ($memory['status'] ?? 'active')));
        if (in_array($status, ['archived', 'deleted', 'forgotten', 'invalidated', 'superseded', 'withdrawn'], true)) {
            return ['disposition' => 'exclude_superseded_or_inactive', 'usable' => false];
        }

        if (($memory['scope_matches'] ?? true) !== true) {
            return ['disposition' => 'exclude_out_of_scope', 'usable' => false];
        }

        if (($memory['safety_conflict'] ?? false) === true) {
            return ['disposition' => 'safety_overrides_memory', 'usable' => false];
        }

        $type = strtolower(trim((string) ($memory['type'] ?? 'experience')));
        $evidence = strtolower(trim((string) ($memory['objective_evidence'] ?? 'none')));

        if ($evidence === 'conflicts') {
            return ['disposition' => 'objective_evidence_overrides_memory', 'usable' => false];
        }

        if (in_array($type, ['belief', 'user_belief', 'factual_claim'], true)) {
            return ['disposition' => 'not_factual_evidence', 'usable' => false];
        }

        if ($type === 'preference') {
            return ['disposition' => 'apply_personalization', 'usable' => true];
        }

        return ['disposition' => 'advisory_only', 'usable' => true];
    }
}
