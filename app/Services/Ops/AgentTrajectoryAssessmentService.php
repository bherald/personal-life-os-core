<?php

namespace App\Services\Ops;

class AgentTrajectoryAssessmentService
{
    public const SCHEMA = 'plos.agent_trajectory_assessment.v3';

    public const PREVIOUS_SCHEMA = 'plos.agent_trajectory_assessment.v2';

    public const LEGACY_SCHEMA = 'plos.agent_trajectory_assessment.v1';

    private const INTEGRITY_CHECKS = [
        'protected_file_integrity',
        'change_scope',
    ];

    private const INSTRUCTION_CHECKS = [
        'response_contains',
        'protected_file_integrity',
        'change_scope',
        'required_change',
        'apply_patch_payloads',
        'command_execution',
    ];

    private const VERIFICATION_CHECKS = [
        'verify_command',
        'hidden_verifier',
        'json_decode',
        'json_schema',
        'json_path',
    ];

    public function __construct(
        private readonly AgentTrajectoryDiagnosticService $diagnostics
    ) {}

    /**
     * Build a content-free assessment from an agent run and evaluator-owned checks.
     *
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    public function assess(array $run): array
    {
        $toolCalls = $this->toolCalls($run['tool_calls'] ?? []);
        $reportedToolCount = max(0, (int) ($run['tool_call_count'] ?? count($toolCalls)));
        $toolCount = max($reportedToolCount, count($toolCalls));
        $checks = $this->checks($run['checks'] ?? []);
        $failedChecks = array_values(array_map(
            static fn (array $check): string => $check['name'],
            array_filter($checks, static fn (array $check): bool => ! $check['passed'])
        ));

        $integrityStatus = $this->checkGroupStatus($checks, self::INTEGRITY_CHECKS);
        $instructionStatus = $this->checkGroupStatus($checks, self::INSTRUCTION_CHECKS);
        $verificationStatus = $this->checkGroupStatus($checks, self::VERIFICATION_CHECKS);
        $executionSuccess = (bool) ($run['execution_success'] ?? false);
        $taskSuccess = (bool) ($run['task_success'] ?? false);
        $terminalState = $this->safeToken($run['terminal_state'] ?? null);
        $iterations = max(0, (int) ($run['iterations'] ?? 0));
        $maxIterations = max(0, (int) ($run['max_iterations'] ?? 0));
        $trajectoryDiagnostics = $this->diagnostics->analyze($run['tool_calls'] ?? []);

        $failedToolSteps = [];
        $subsequentSuccessesAfterFailure = 0;
        $lastFailureIndex = null;
        $pairFingerprints = [];
        foreach ($toolCalls as $index => $call) {
            if (! $call['success']) {
                $failedToolSteps[] = $index + 1;
                $lastFailureIndex = $index;
            } elseif ($lastFailureIndex !== null && $index > $lastFailureIndex) {
                $subsequentSuccessesAfterFailure++;
            }

            if ($call['pair_fingerprint'] !== null) {
                $pairFingerprints[] = $call['pair_fingerprint'];
            }
        }

        $repeatedPairCount = 0;
        for ($index = 1, $count = count($pairFingerprints); $index < $count; $index++) {
            if (hash_equals($pairFingerprints[$index - 1], $pairFingerprints[$index])) {
                $repeatedPairCount++;
            }
        }

        $limitations = [];
        if ($verificationStatus === 'not_configured') {
            $limitations[] = 'formal_verification_missing';
        }
        if ($instructionStatus === 'not_configured') {
            $limitations[] = 'instruction_compliance_not_measured';
        }
        if ($toolCalls === [] && $toolCount > 0) {
            $limitations[] = 'tool_sequence_unavailable';
        } elseif ($toolCount > count($toolCalls)) {
            $limitations[] = 'tool_sequence_truncated';
        }

        $invariantViolations = [];
        if (($run['require_tool_execution'] ?? false) && $toolCount === 0) {
            $invariantViolations[] = 'required_tool_execution_missing';
        }
        if ($terminalState === 'stuck') {
            $invariantViolations[] = 'terminal_stuck_state';
        }
        if (! $taskSuccess && $maxIterations > 0 && $iterations >= $maxIterations) {
            $invariantViolations[] = 'iteration_budget_exhausted';
        }
        if ($failedToolSteps !== [] && $subsequentSuccessesAfterFailure === 0) {
            $invariantViolations[] = 'tool_failure_without_subsequent_success';
        }

        $outcome = $this->outcome(
            $executionSuccess,
            $taskSuccess,
            $terminalState,
            $integrityStatus,
            $instructionStatus,
            $verificationStatus,
            $invariantViolations
        );
        $promotionEligible = $outcome === 'verified_success';
        $recoveryGuidance = $this->recoveryGuidance(
            $outcome,
            $terminalState,
            $failedChecks[0] ?? null,
            $failedToolSteps[0] ?? null,
            array_values(array_unique($invariantViolations))
        );

        return [
            'schema' => self::SCHEMA,
            'outcome' => $outcome,
            'promotion_eligible' => $promotionEligible,
            'execution' => [
                'success' => $executionSuccess,
                'task_success_claim' => $taskSuccess,
                'terminal_state' => $terminalState,
            ],
            'formal_verification' => [
                'status' => $verificationStatus,
                'check_count' => $this->configuredCheckCount($checks, self::VERIFICATION_CHECKS),
            ],
            'instruction_compliance' => [
                'status' => $instructionStatus,
                'check_count' => $this->configuredCheckCount($checks, self::INSTRUCTION_CHECKS),
            ],
            'trajectory' => [
                'tool_calls' => $toolCount,
                'unique_tools' => count(array_unique(array_column($toolCalls, 'tool'))),
                'failed_tool_calls' => count($failedToolSteps),
                'failed_tool_steps' => $failedToolSteps,
                'subsequent_successes_after_failure' => $subsequentSuccessesAfterFailure,
                'repeated_adjacent_action_observation_pairs' => $repeatedPairCount,
                'iterations' => $iterations,
                'max_iterations' => $maxIterations > 0 ? $maxIterations : null,
                'iteration_utilization' => $maxIterations > 0
                    ? round(min(1, $iterations / $maxIterations), 4)
                    : null,
            ],
            'trajectory_diagnostics' => $trajectoryDiagnostics,
            'failure_localization' => [
                'category' => $promotionEligible ? null : $outcome,
                'first_failed_check' => $failedChecks[0] ?? null,
                'first_failed_tool_step' => $failedToolSteps[0] ?? null,
            ],
            'recovery_guidance' => $recoveryGuidance,
            'invariant_violations' => array_values(array_unique($invariantViolations)),
            'limitations' => $limitations,
            'evidence' => [
                'check_count' => count($checks),
                'failed_check_count' => count($failedChecks),
                'failed_check_names' => $failedChecks,
                'content_included' => false,
            ],
        ];
    }

    /**
     * @return list<array{tool:string,success:bool,pair_fingerprint:?string}>
     */
    private function toolCalls(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $calls = [];
        foreach ($raw as $call) {
            if (! is_array($call)) {
                continue;
            }

            $tool = $this->safeToken($call['tool'] ?? null);
            if ($tool === null) {
                continue;
            }

            $fingerprint = $this->safeFingerprint($call['pair_fingerprint'] ?? null);
            $calls[] = [
                'tool' => $tool,
                'success' => (bool) ($call['success'] ?? false),
                'pair_fingerprint' => $fingerprint,
            ];
        }

        return $calls;
    }

    /**
     * @return list<array{name:string,passed:bool}>
     */
    private function checks(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $checks = [];
        foreach ($raw as $check) {
            if (! is_array($check)) {
                continue;
            }

            $name = $this->safeToken($check['name'] ?? null);
            if ($name !== null) {
                $checks[] = [
                    'name' => $name,
                    'passed' => (bool) ($check['passed'] ?? false),
                ];
            }
        }

        return $checks;
    }

    /**
     * @param  list<array{name:string,passed:bool}>  $checks
     * @param  list<string>  $names
     */
    private function checkGroupStatus(array $checks, array $names): string
    {
        $configured = array_values(array_filter(
            $checks,
            static fn (array $check): bool => in_array($check['name'], $names, true)
        ));
        if ($configured === []) {
            return 'not_configured';
        }

        return in_array(false, array_column($configured, 'passed'), true) ? 'failed' : 'passed';
    }

    /**
     * @param  list<array{name:string,passed:bool}>  $checks
     * @param  list<string>  $names
     */
    private function configuredCheckCount(array $checks, array $names): int
    {
        return count(array_filter(
            $checks,
            static fn (array $check): bool => in_array($check['name'], $names, true)
        ));
    }

    /**
     * @param  list<string>  $violations
     */
    private function outcome(
        bool $executionSuccess,
        bool $taskSuccess,
        ?string $terminalState,
        string $integrityStatus,
        string $instructionStatus,
        string $verificationStatus,
        array $violations
    ): string {
        if ($integrityStatus === 'failed') {
            return 'benchmark_integrity_failure';
        }
        if ($terminalState === 'stuck') {
            return 'stuck_loop';
        }
        if (in_array('required_tool_execution_missing', $violations, true)) {
            return 'tool_protocol_failure';
        }
        if ($instructionStatus === 'failed') {
            return 'instruction_failure';
        }
        if ($verificationStatus === 'failed') {
            return 'verification_failure';
        }
        if (! $executionSuccess || ! $taskSuccess) {
            return in_array('tool_failure_without_subsequent_success', $violations, true)
                ? 'tool_execution_failure'
                : 'execution_failure';
        }
        if ($verificationStatus === 'not_configured') {
            return 'unverified_completion';
        }

        return 'verified_success';
    }

    /**
     * @param  list<string>  $invariantViolations
     * @return array<string,mixed>
     */
    private function recoveryGuidance(
        string $outcome,
        ?string $terminalState,
        ?string $firstFailedCheck,
        ?int $firstFailedToolStep,
        array $invariantViolations
    ): array {
        $plans = [
            'benchmark_integrity_failure' => [
                'scope_or_protected_artifact_violation',
                'restore_declared_scope_then_rerun_same_verifiers',
            ],
            'stuck_loop' => [
                'repeated_or_budget_exhausted_trajectory',
                'replan_from_last_distinct_evidence_within_existing_budget',
            ],
            'tool_protocol_failure' => [
                'required_tool_protocol_not_entered',
                'enter_required_tool_protocol_then_verify',
            ],
            'instruction_failure' => [
                'declared_instruction_contract_failed',
                'repair_failed_instruction_contract_then_verify',
            ],
            'verification_failure' => [
                'evaluator_owned_verification_failed',
                'repair_first_failed_check_then_rerun_same_verifiers',
            ],
            'tool_execution_failure' => [
                'tool_failure_without_recovery',
                'change_strategy_after_failed_tool_then_verify',
            ],
            'execution_failure' => [
                'execution_failed_without_formal_success',
                'inspect_failure_anchor_then_retry_under_existing_policy',
            ],
        ];

        $plan = $plans[$outcome] ?? null;
        $grounded = $firstFailedCheck !== null
            || $firstFailedToolStep !== null
            || $terminalState === 'stuck'
            || $invariantViolations !== [];
        $eligible = is_array($plan) && $grounded;
        $reasons = [];
        if (! $eligible) {
            $reasons[] = match (true) {
                $outcome === 'verified_success' => 'recovery_not_required',
                ! $grounded => 'failure_anchor_missing',
                default => 'unsupported_failure_outcome',
            };
        }

        return [
            'schema' => 'plos.agent_recovery_guidance.v1',
            'eligible' => $eligible,
            'anchor' => [
                'outcome' => $outcome,
                'first_failed_check' => $firstFailedCheck,
                'first_failed_tool_step' => $firstFailedToolStep,
                'terminal_state' => $terminalState,
                'invariant_violations' => $invariantViolations,
            ],
            'guidance' => [
                'diagnosis_code' => $eligible ? $plan[0] : null,
                'action_code' => $eligible ? $plan[1] : null,
                'scope' => $eligible ? 'agent_behavior_only' : null,
            ],
            'gate' => [
                'passed' => $eligible,
                'reasons' => $reasons,
                'evidence_grounded' => $grounded,
                'actionable' => $eligible,
                'changes_agent_policy' => false,
                'changes_toolset' => false,
                'changes_execution_budget' => false,
                'autonomous_retry_authority' => false,
                'promotion_authority' => false,
            ],
            'posture' => [
                'content_included' => false,
                'model_judge_used' => false,
                'advisory_side_channel_only' => true,
            ],
        ];
    }

    private function safeToken(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && preg_match('/\A[a-zA-Z0-9_.:-]{1,100}\z/D', $value) === 1
            ? $value
            : null;
    }

    private function safeFingerprint(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1 ? $value : null;
    }
}
