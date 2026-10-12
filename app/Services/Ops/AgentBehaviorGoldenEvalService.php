<?php

namespace App\Services\Ops;

use App\Services\AgentCoTAuditService;
use App\Services\AgentExecutionContextService;
use App\Services\AgentGuardrailService;
use App\Services\AgentMemoryRecallPolicyService;
use App\Services\AgentMemoryUsePolicyService;
use App\Services\AgentToolRegistryService;
use App\Services\EvidenceVerdictPolicyService;
use App\Services\OfflinePolicyService;

class AgentBehaviorGoldenEvalService
{
    public function __construct(
        private readonly AgentCoTAuditService $cotAudit,
        private readonly AgentGuardrailService $guardrails,
        private readonly AgentExecutionContextService $executionContext,
        private readonly AgentMemoryRecallPolicyService $memoryRecallPolicy,
        private readonly AgentMemoryUsePolicyService $memoryUsePolicy,
        private readonly AgentProcedureIntegrityService $procedureIntegrity,
        private readonly AgentToolRegistryService $tools,
        private readonly OfflinePolicyService $offlinePolicy,
        private readonly EvidenceVerdictPolicyService $verdictPolicy,
    ) {}

    /**
     * @param  array<string,array<string,mixed>>|null  $cases
     * @return array<string,mixed>
     */
    public function evaluate(?array $cases = null): array
    {
        $cases ??= (array) config('agent_eval.cases', []);
        $results = [];

        foreach ($cases as $name => $case) {
            $results[] = is_array($case)
                ? $this->evaluateCase((string) $name, $case)
                : $this->invalidCase((string) $name, 'invalid_case_definition');
        }

        $failed = array_values(array_filter(
            $results,
            static fn (array $result): bool => ! (bool) ($result['passed'] ?? false)
        ));

        return [
            'schema' => (string) config('agent_eval.schema', 'plos.agent_behavior_golden_eval.v1'),
            'status' => $cases !== [] && $failed === [] ? 'pass' : 'fail',
            'generated_at' => now('UTC')->toIso8601String(),
            'summary' => [
                'cases' => count($results),
                'passed' => count($results) - count($failed),
                'failed' => count($failed),
                'checks' => array_sum(array_column($results, 'check_count')),
                'violations' => array_sum(array_map(
                    static fn (array $result): int => count((array) ($result['violation_codes'] ?? [])),
                    $results
                )),
                'competencies' => count(array_unique(array_column($results, 'competency'))),
            ],
            'cases' => $results,
            'posture' => [
                'deterministic_fixtures' => true,
                'production_services_exercised' => true,
                'llm_calls' => false,
                'network_calls' => false,
                'database_reads' => false,
                'database_writes' => false,
                'raw_fixture_content_included' => false,
                'procedure_aware_trajectory_checks' => true,
                'milestone_minefield_checks' => true,
                'milestone_dependency_dag_checks' => true,
                'invalid_dependency_definition_checks' => true,
                'exact_tool_argument_checks' => true,
                'tool_perturbation_recovery_checks' => true,
                'explicit_implicit_failure_checks' => true,
                'transient_permanent_failure_checks' => true,
                'memory_lifecycle_checks' => true,
                'memory_contradiction_preservation_checks' => true,
                'memory_limited_context_outcome_diversity_checks' => true,
                'memory_abstention_checks' => true,
                'memory_selective_forgetting_checks' => true,
                'memory_objective_fact_checks' => true,
                'memory_scope_control_checks' => true,
                'memory_evidence_conflict_checks' => true,
                'memory_valid_update_checks' => true,
                'memory_personalization_checks' => true,
                'sensitive_action_evidence_lineage_checks' => true,
            ],
        ];
    }

    /** @param array<string,mixed> $payload */
    public function compactPayload(array $payload): array
    {
        $cases = array_values(array_filter((array) ($payload['cases'] ?? []), 'is_array'));

        return [
            'schema' => $payload['schema'] ?? 'plos.agent_behavior_golden_eval.v1',
            'status' => $payload['status'] ?? 'fail',
            'compact' => true,
            'generated_at' => $payload['generated_at'] ?? null,
            'summary' => $payload['summary'] ?? [],
            'failed_cases' => array_values(array_map(
                static fn (array $case): array => [
                    'case' => $case['case'] ?? 'unknown',
                    'competency' => $case['competency'] ?? 'unknown',
                    'violation_codes' => $case['violation_codes'] ?? [],
                ],
                array_filter($cases, static fn (array $case): bool => ! (bool) ($case['passed'] ?? false))
            )),
            'posture' => $payload['posture'] ?? [],
        ];
    }

    /** @param array<string,mixed> $case */
    private function evaluateCase(string $name, array $case): array
    {
        $competency = (string) ($case['competency'] ?? '');
        $input = is_array($case['input'] ?? null) ? $case['input'] : [];
        $expect = is_array($case['expect'] ?? null) ? $case['expect'] : [];
        if ($competency === '' || $input === [] || $expect === []) {
            return $this->invalidCase($name, 'missing_competency_input_or_expectation');
        }

        return match ($competency) {
            'prompt_injection' => $this->evaluatePromptInjection($name, $input, $expect),
            'structured_output' => $this->evaluateStructuredOutput($name, $input, $expect),
            'tool_selection' => $this->evaluateToolSelection($name, $input, $expect),
            'evidence_verdict' => $this->evaluateEvidenceVerdict($name, $input, $expect),
            'memory_retrieval' => $this->evaluateMemoryRetrieval($name, $input, $expect),
            'memory_use' => $this->evaluateMemoryUse($name, $input, $expect),
            'evidence_lineage' => $this->evaluateEvidenceLineage($name, $input, $expect),
            'execution_context' => $this->evaluateExecutionContext($name, $input, $expect),
            'tool_trajectory' => $this->evaluateToolTrajectory($name, $input, $expect),
            default => $this->invalidCase($name, 'unknown_competency'),
        };
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $expect */
    private function evaluatePromptInjection(string $name, array $input, array $expect): array
    {
        $text = (string) ($input['text'] ?? '');
        $result = $this->guardrails->detectContentContamination($text);
        $sanitized = $this->guardrails->sanitizeUntrustedText($text);
        $checks = [];
        $this->check($checks, 'clean_state', (bool) ($result['clean'] ?? true) === (bool) ($expect['clean'] ?? false));
        $this->check($checks, 'severity', (string) ($result['severity'] ?? '') === (string) ($expect['severity'] ?? ''));
        foreach ((array) ($expect['threats'] ?? []) as $threat) {
            $this->check($checks, 'threat:'.$this->safeCode((string) $threat), in_array($threat, (array) ($result['threats'] ?? []), true));
        }
        foreach ((array) ($expect['sanitized_forbidden_fragments'] ?? []) as $index => $fragment) {
            $this->check($checks, 'sanitized_forbidden_fragment:'.(int) $index, ! str_contains($sanitized, (string) $fragment));
        }

        return $this->caseResult($name, 'prompt_injection', $checks);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $expect */
    private function evaluateStructuredOutput(string $name, array $input, array $expect): array
    {
        $result = $this->guardrails->validateOutput(
            $input['output'] ?? null,
            (string) ($input['expected_type'] ?? 'text'),
            (array) ($input['required_keys'] ?? [])
        );
        $checks = [];
        $this->check($checks, 'validity', (bool) ($result['valid'] ?? false) === (bool) ($expect['valid'] ?? true));
        foreach ((array) ($expect['issue_fragments'] ?? []) as $index => $fragment) {
            $found = collect((array) ($result['issues'] ?? []))->contains(
                static fn (mixed $issue): bool => is_string($issue) && str_contains($issue, (string) $fragment)
            );
            $this->check($checks, 'issue_fragment:'.(int) $index, $found);
        }

        return $this->caseResult($name, 'structured_output', $checks);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $expect */
    private function evaluateToolSelection(string $name, array $input, array $expect): array
    {
        $parsed = $this->tools->parseToolCall((string) ($input['response'] ?? ''));
        $context = (array) ($input['context'] ?? []);
        $context['_audit'] = false;
        $tool = is_string($parsed['tool'] ?? null) ? $parsed['tool'] : '';
        $decision = $this->offlinePolicy->evaluateOperation(
            'tool:'.$tool,
            $context,
            (string) ($input['profile'] ?? 'offline_review')
        );
        $checks = [];
        $this->check($checks, 'tool_call_present', (bool) ($parsed['has_tool_call'] ?? false));
        $this->check($checks, 'exact_tool', $tool === (string) ($expect['tool'] ?? ''));
        $this->check($checks, 'policy_allowed', $decision->allowed === (bool) ($expect['allowed'] ?? false));
        $this->check($checks, 'confirmation', $decision->requiresConfirmation === (bool) ($expect['requires_confirmation'] ?? false));
        if (array_key_exists('tool_class', $expect)) {
            $this->check($checks, 'tool_class', $decision->toolClass === (string) $expect['tool_class']);
        }
        foreach ((array) ($expect['param_keys'] ?? []) as $key) {
            $this->check($checks, 'param_key:'.$this->safeCode((string) $key), array_key_exists((string) $key, (array) ($parsed['params'] ?? [])));
        }
        if (array_key_exists('params', $expect)) {
            $this->check(
                $checks,
                'exact_params',
                is_array($expect['params'])
                    && $this->normalizeParameters((array) ($parsed['params'] ?? []))
                        === $this->normalizeParameters($expect['params'])
            );
        }

        return $this->caseResult($name, 'tool_selection', $checks);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $expect */
    private function evaluateEvidenceVerdict(string $name, array $input, array $expect): array
    {
        $result = $this->verdictPolicy->determine(
            (int) ($input['supporting'] ?? 0),
            (int) ($input['contradicting'] ?? 0),
            (int) ($input['neutral'] ?? 0),
            array_key_exists('factuality', $input) && $input['factuality'] !== null ? (float) $input['factuality'] : null
        );
        $checks = [];
        $this->check($checks, 'verdict', $result['verdict'] === (string) ($expect['verdict'] ?? ''));
        if (array_key_exists('confidence', $expect)) {
            $this->check($checks, 'confidence', $result['confidence'] === (float) $expect['confidence']);
        }

        return $this->caseResult($name, 'evidence_verdict', $checks);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $expect */
    private function evaluateMemoryRetrieval(string $name, array $input, array $expect): array
    {
        $mode = (string) ($input['mode'] ?? '');
        $checks = [];

        if ($mode === 'rank') {
            $candidates = array_values(array_filter((array) ($input['candidates'] ?? []), 'is_array'));
            $ranked = $this->memoryRecallPolicy->rank(
                (string) ($input['query'] ?? ''),
                $candidates,
                max(1, min(count($candidates), (int) ($input['limit'] ?? count($candidates))))
            );
            $rankedIds = array_column($ranked, 'id');
            $expectedCount = array_key_exists('candidate_count', $expect)
                ? (int) $expect['candidate_count']
                : count($candidates);
            $this->check($checks, 'candidate_count', count($ranked) === $expectedCount);
            if (array_key_exists('top_id', $expect)) {
                $this->check($checks, 'top_candidate', ($ranked[0]['id'] ?? null) === $expect['top_id']);
            }
            if (array_key_exists('abstained', $expect)) {
                $this->check($checks, 'abstained', ($ranked === []) === (bool) $expect['abstained']);
            }
            foreach ((array) ($expect['included_ids'] ?? []) as $id) {
                $this->check($checks, 'included_id:'.$this->safeCode((string) $id), in_array($id, $rankedIds, true));
            }
            foreach ((array) ($expect['excluded_ids'] ?? []) as $id) {
                $this->check($checks, 'excluded_id:'.$this->safeCode((string) $id), ! in_array($id, $rankedIds, true));
            }
            $this->check(
                $checks,
                'descending_score',
                count($ranked) < 2 || (float) $ranked[0]['score'] > (float) $ranked[1]['score']
            );

            return $this->caseResult($name, 'memory_retrieval', $checks);
        }

        if ($mode === 'gate') {
            $decision = $this->memoryRecallPolicy->decideGate((array) ($input['signals'] ?? []));
            foreach (['procedural', 'episodic', 'cross_agent'] as $key) {
                if (array_key_exists($key, $expect)) {
                    $this->check($checks, 'gate_'.$key, $decision[$key] === (bool) $expect[$key]);
                }
            }

            return $this->caseResult($name, 'memory_retrieval', $checks);
        }

        return $this->invalidCase($name, 'unknown_memory_retrieval_mode');
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $expect */
    private function evaluateMemoryUse(string $name, array $input, array $expect): array
    {
        $result = $this->memoryUsePolicy->assess($input);
        $checks = [];
        $this->check(
            $checks,
            'memory_disposition',
            $result['disposition'] === (string) ($expect['disposition'] ?? '')
        );
        $this->check(
            $checks,
            'memory_usable',
            $result['usable'] === (bool) ($expect['usable'] ?? false)
        );

        return $this->caseResult($name, 'memory_use', $checks);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $expect */
    private function evaluateEvidenceLineage(string $name, array $input, array $expect): array
    {
        $result = $this->cotAudit->audit(
            'golden-eval',
            (string) ($input['reasoning'] ?? ''),
            (string) ($input['tool'] ?? ''),
            (array) ($input['params'] ?? []),
            (array) ($input['prior_calls'] ?? [])
        );
        $checks = [];
        $this->check($checks, 'lineage_action', $result['action'] === (string) ($expect['action'] ?? ''));
        $this->check($checks, 'lineage_risk', $result['risk'] === (string) ($expect['risk'] ?? ''));

        return $this->caseResult($name, 'evidence_lineage', $checks);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $expect */
    private function evaluateExecutionContext(string $name, array $input, array $expect): array
    {
        $result = $this->executionContext->build(
            (string) ($input['agent_id'] ?? ''),
            (string) ($input['session_id'] ?? ''),
            (array) ($input['options'] ?? []),
            (array) ($input['delegation_governance'] ?? [])
        );
        $checks = [];
        foreach ($expect as $key => $value) {
            $this->check($checks, 'context_'.$this->safeCode((string) $key), ($result[$key] ?? null) === $value);
        }

        return $this->caseResult($name, 'execution_context', $checks);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $expect */
    private function evaluateToolTrajectory(string $name, array $input, array $expect): array
    {
        $steps = array_values(array_filter((array) ($input['trajectory'] ?? []), 'is_array'));
        $observations = [];

        foreach ($steps as $step) {
            $parsed = $this->tools->parseToolCall((string) ($step['response'] ?? ''));
            $tool = is_string($parsed['tool'] ?? null) ? $parsed['tool'] : '';
            $context = is_array($step['context'] ?? null) ? $step['context'] : [];
            $context['_audit'] = false;
            $decision = $this->offlinePolicy->evaluateOperation(
                'tool:'.$tool,
                $context,
                (string) ($step['profile'] ?? 'offline_review')
            );
            $paramKeys = array_keys((array) ($parsed['params'] ?? []));
            sort($paramKeys, SORT_STRING);
            $observations[] = [
                'tool' => $tool,
                'allowed' => $decision->allowed,
                'requires_confirmation' => $decision->requiresConfirmation,
                'param_keys' => $paramKeys,
                'params' => (array) ($parsed['params'] ?? []),
                'outcome' => $this->normalizeOutcome($step['outcome'] ?? null),
                'failure_recognized' => (bool) ($step['failure_recognized'] ?? false),
            ];
        }

        $result = $this->procedureIntegrity->evaluate(
            $observations,
            array_values((array) ($input['milestones'] ?? [])),
            array_values((array) ($input['minefields'] ?? [])),
            (bool) ($input['task_success'] ?? false)
        );
        $recovery = is_array($input['perturbation'] ?? null)
            ? $this->evaluateToolRecovery(
                $observations,
                $input['perturbation'],
                (bool) ($input['task_success'] ?? false)
            )
            : [];
        $result = array_merge($result, $recovery);
        $checks = [];
        $this->check($checks, 'trajectory_steps', $steps !== [] && count($observations) === count($steps));
        foreach ([
            'procedure_passed',
            'task_success',
            'corrupt_success',
            'milestone_count',
            'milestones_met',
            'missing_milestones',
            'minefields_triggered',
            'policy_violation_count',
            'invalid_definition_count',
            'recovery_definition_valid',
            'perturbation_kind',
            'perturbation_persistence',
            'perturbation_hit',
            'failure_recognized',
            'recovered',
            'recovery_passed',
            'recovery_strategy',
            'recovery_call_count',
            'blind_retry_count',
        ] as $key) {
            if (array_key_exists($key, $expect)) {
                $this->check(
                    $checks,
                    'trajectory_'.$this->safeCode($key),
                    ($result[$key] ?? null) === $expect[$key]
                );
            }
        }

        return $this->caseResult($name, 'tool_trajectory', $checks);
    }

    /**
     * @param  list<array<string,mixed>>  $observations
     * @param  array<string,mixed>  $perturbation
     * @return array<string,mixed>
     */
    private function evaluateToolRecovery(array $observations, array $perturbation, bool $taskSuccess): array
    {
        $kind = (string) ($perturbation['kind'] ?? '');
        $persistence = (string) ($perturbation['persistence'] ?? '');
        $stepIndex = filter_var($perturbation['step_index'] ?? null, FILTER_VALIDATE_INT);
        $maxRecoveryCalls = filter_var($perturbation['max_recovery_calls'] ?? null, FILTER_VALIDATE_INT);
        $allowAbstention = (bool) ($perturbation['allow_abstention'] ?? true);
        $definitionValid = in_array($kind, ['explicit', 'implicit'], true)
            && in_array($persistence, ['transient', 'permanent'], true)
            && $stepIndex !== false
            && $stepIndex >= 0
            && array_key_exists($stepIndex, $observations)
            && $maxRecoveryCalls !== false
            && $maxRecoveryCalls >= 1;

        if (! $definitionValid) {
            return [
                'recovery_definition_valid' => false,
                'perturbation_kind' => $kind,
                'perturbation_persistence' => $persistence,
                'perturbation_hit' => false,
                'failure_recognized' => false,
                'recovered' => false,
                'recovery_passed' => false,
                'recovery_strategy' => 'none',
                'recovery_call_count' => 0,
                'blind_retry_count' => 0,
            ];
        }

        $affected = $observations[$stepIndex];
        $affectedTool = (string) ($affected['tool'] ?? '');
        $expectedOutcome = $kind.'_failure';
        $perturbationHit = ($affected['outcome'] ?? null) === $expectedOutcome;
        $failureRecognized = $perturbationHit
            && ($kind === 'explicit' || (bool) ($affected['failure_recognized'] ?? false));
        $suffix = array_slice($observations, $stepIndex + 1, preserve_keys: true);
        $resolutionIndex = null;
        $strategy = 'none';
        $blindRetries = 0;

        foreach ($suffix as $index => $observation) {
            $tool = (string) ($observation['tool'] ?? '');
            $outcome = (string) ($observation['outcome'] ?? 'unknown');
            $sameTool = $affectedTool !== '' && $tool === $affectedTool;

            if ($sameTool && ($persistence === 'permanent' || $outcome !== 'success')) {
                $blindRetries++;
            }

            if (! $failureRecognized) {
                continue;
            }
            if ($outcome === 'abstained' && $allowAbstention) {
                $resolutionIndex = $index;
                $strategy = 'abstain';
                break;
            }
            if ($outcome !== 'success') {
                continue;
            }
            if ($persistence === 'permanent' && $sameTool) {
                continue;
            }

            $resolutionIndex = $index;
            $strategy = $sameTool ? 'retry' : 'fallback';
            break;
        }

        if ($strategy === 'none' && $suffix !== []) {
            $strategy = collect($suffix)->contains(
                static fn (array $observation): bool => ($observation['tool'] ?? null) === $affectedTool
            ) ? 'retry' : 'fallback';
        }

        $recoveryCallCount = $resolutionIndex === null
            ? count($suffix)
            : $resolutionIndex - $stepIndex;
        $recovered = $resolutionIndex !== null
            && ($strategy === 'abstain' || $taskSuccess);
        $withinBudget = $recoveryCallCount <= $maxRecoveryCalls;

        return [
            'recovery_definition_valid' => true,
            'perturbation_kind' => $kind,
            'perturbation_persistence' => $persistence,
            'perturbation_hit' => $perturbationHit,
            'failure_recognized' => $failureRecognized,
            'recovered' => $recovered,
            'recovery_passed' => $perturbationHit && $failureRecognized && $recovered && $withinBudget,
            'recovery_strategy' => $strategy,
            'recovery_call_count' => $recoveryCallCount,
            'blind_retry_count' => $blindRetries,
        ];
    }

    private function normalizeOutcome(mixed $outcome): string
    {
        $outcome = is_string($outcome) ? strtolower(trim($outcome)) : '';

        return in_array($outcome, ['success', 'explicit_failure', 'implicit_failure', 'abstained'], true)
            ? $outcome
            : 'unknown';
    }

    /** @param array<mixed> $params @return array<mixed> */
    private function normalizeParameters(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = $this->normalizeParameters($value);
            }
        }
        if (! array_is_list($params)) {
            ksort($params, SORT_STRING);
        }

        return $params;
    }

    /** @param list<array{code:string,passed:bool}> $checks */
    private function check(array &$checks, string $code, bool $passed): void
    {
        $checks[] = ['code' => $code, 'passed' => $passed];
    }

    /** @param list<array{code:string,passed:bool}> $checks */
    private function caseResult(string $name, string $competency, array $checks): array
    {
        $violations = array_values(array_map(
            static fn (array $check): string => $check['code'],
            array_filter($checks, static fn (array $check): bool => ! $check['passed'])
        ));

        return [
            'case' => $this->safeName($name),
            'competency' => $competency,
            'passed' => $violations === [],
            'check_count' => count($checks),
            'violation_codes' => $violations,
        ];
    }

    private function invalidCase(string $name, string $code): array
    {
        return [
            'case' => $this->safeName($name),
            'competency' => 'invalid',
            'passed' => false,
            'check_count' => 1,
            'violation_codes' => [$code],
        ];
    }

    private function safeName(string $name): string
    {
        return preg_match('/^[a-z][a-z0-9_]{1,80}$/', $name) === 1 ? $name : 'invalid_case';
    }

    private function safeCode(string $code): string
    {
        $safe = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $code));

        return trim(substr($safe, 0, 80), '_') ?: 'unknown';
    }
}
