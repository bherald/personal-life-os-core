<?php

namespace App\Services;

use App\Services\Ops\AgentTrajectoryAssessmentService;
use DateTimeImmutable;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class OfflineCodingEvalReceiptService
{
    public const SCHEMA = 'plos.offline_coding_eval_receipt.v3';

    public const PREVIOUS_SCHEMA = 'plos.offline_coding_eval_receipt.v2';

    public const LEGACY_SCHEMA = 'plos.offline_coding_eval_receipt.v1';

    public const COMPARISON_SCHEMA = 'plos.offline_coding_eval_comparison.v2';

    public const HARNESS_IDENTITY_SCHEMA = 'plos.offline_coding_eval_harness_identity.v1';

    public const HARNESS_FINGERPRINT_SCOPE = 'evaluator_connector_workspace_receipt_assessment_config';

    private const MAX_FILE_BYTES = 1_048_576;

    private const HARNESS_SOURCES = [
        'app/Console/Commands/CodexOfflineEvalCommand.php',
        'app/Services/CodexExecConnectorService.php',
        'app/Services/OfflineCodingEvalReceiptService.php',
        'app/Services/OfflineCodingEvalWorkspaceService.php',
        'app/Services/Ops/AgentTrajectoryAssessmentService.php',
        'config/offline_coding_eval.php',
    ];

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    public function build(array $payload, array $cases): array
    {
        if (($payload['execute'] ?? false) !== true || ! is_array($payload['results'] ?? null)) {
            throw new InvalidArgumentException('Only an executed offline coding eval can produce a receipt.');
        }

        $runId = $this->safeToken($payload['run_id'] ?? null, 100);
        if ($runId === null) {
            throw new InvalidArgumentException('Executed offline coding eval is missing a valid run id.');
        }

        $contracts = [];
        foreach ($cases as $case) {
            $name = $this->safeToken($case['case'] ?? null, 120);
            if ($name === null || isset($contracts[$name])) {
                throw new InvalidArgumentException('Offline coding eval case identifiers must be valid and unique.');
            }
            $contracts[$name] = $this->caseContractHash($case);
        }

        $receiptCases = [];
        foreach ($payload['results'] as $result) {
            if (! is_array($result)) {
                continue;
            }
            $name = $this->safeToken($result['case'] ?? null, 120);
            if ($name === null || ! isset($contracts[$name])) {
                throw new InvalidArgumentException('Offline coding eval result does not match the selected case contract.');
            }
            $receiptCases[] = [
                'case' => $name,
                'contract_sha256' => $contracts[$name],
                'passed' => ($result['passed'] ?? false) === true,
                'duration_ms' => $this->nonNegativeInt($result['duration_ms'] ?? null),
                'checks' => $this->checks($result['checks'] ?? []),
                'trajectory_assessment' => $this->assessment($result['trajectory_assessment'] ?? null),
                'execution_identity' => $this->executionIdentity($result['execution_identity'] ?? null),
            ];
        }
        usort($receiptCases, static fn (array $left, array $right): int => $left['case'] <=> $right['case']);

        if (count($receiptCases) !== count($contracts)) {
            throw new InvalidArgumentException('Offline coding eval receipt requires exactly one result for every selected case.');
        }
        if (count(array_unique(array_column($receiptCases, 'case'))) !== count($receiptCases)) {
            throw new InvalidArgumentException('Offline coding eval receipt contains duplicate case results.');
        }

        $configuration = $this->productConfiguration($payload['product_configuration'] ?? null);
        $harnessIdentity = $this->harnessIdentity();
        $receipt = [
            'schema' => self::SCHEMA,
            'generated_at' => now()->toIso8601String(),
            'run_id' => $runId,
            'status' => ($payload['status'] ?? null) === 'pass' ? 'pass' : 'fail',
            'suite' => [
                'case_count' => count($receiptCases),
                'contract_sha256' => $this->hash(array_map(
                    static fn (array $case): array => [
                        'case' => $case['case'],
                        'contract_sha256' => $case['contract_sha256'],
                    ],
                    $receiptCases
                )),
            ],
            'product_configuration' => $configuration,
            'product_configuration_sha256' => $this->hash($configuration),
            'harness_identity' => $harnessIdentity,
            'harness_identity_sha256' => $this->hash($harnessIdentity),
            'summary' => [
                'passed' => count(array_filter($receiptCases, static fn (array $case): bool => $case['passed'])),
                'failed' => count(array_filter($receiptCases, static fn (array $case): bool => ! $case['passed'])),
                'promotion_eligible' => count(array_filter(
                    $receiptCases,
                    static fn (array $case): bool => ($case['trajectory_assessment']['promotion_eligible'] ?? false) === true
                )),
            ],
            'cases' => $receiptCases,
            'posture' => [
                'content_included' => false,
                'prompt_included' => false,
                'response_included' => false,
                'error_text_included' => false,
                'workspace_path_included' => false,
                'diagnostics_included' => false,
                'llm_judge_used' => false,
                'formal_verification_authoritative' => true,
                'harness_identity_required_for_regression_gate' => true,
                'verifier_image_identity_required_for_docker_fallback' => true,
            ],
        ];
        $receipt['integrity'] = [
            'algorithm' => 'sha256',
            'receipt_sha256' => $this->hash($receipt),
        ];

        return $receipt;
    }

    /** @param array<string,mixed> $receipt */
    public function write(array $receipt, string $path): void
    {
        $path = trim($path);
        if ($path === '') {
            throw new InvalidArgumentException('Receipt path is required.');
        }
        $this->validateReceipt($receipt);

        File::ensureDirectoryExists(dirname($path), 0700, true);
        try {
            $json = json_encode(
                $receipt,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Offline coding eval receipt cannot be encoded.', previous: $e);
        }
        if (file_put_contents($path, $json."\n", LOCK_EX) === false) {
            throw new RuntimeException('Unable to write offline coding eval receipt.');
        }
        chmod($path, 0600);
    }

    /** @return array<string,mixed> */
    public function read(string $path): array
    {
        $path = trim($path);
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Offline coding eval receipt is not a readable file.');
        }
        $size = filesize($path);
        if ($size === false || $size < 2 || $size > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('Offline coding eval receipt must be between 2 bytes and 1 MiB.');
        }

        try {
            $receipt = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Offline coding eval receipt is not valid JSON.', previous: $e);
        }
        if (! is_array($receipt)) {
            throw new InvalidArgumentException('Offline coding eval receipt root must be an object.');
        }
        $this->validateReceipt($receipt);

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $baseline
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function compare(array $baseline, array $candidate, bool $allowConfigurationChange = false): array
    {
        $this->validateReceipt($baseline);
        $this->validateReceipt($candidate);

        $errors = [];
        $baselineSchema = (string) ($baseline['schema'] ?? '');
        $candidateSchema = (string) ($candidate['schema'] ?? '');
        if ($baselineSchema !== $candidateSchema) {
            $errors[] = 'receipt_schema_mismatch';
        }

        $harnessSchemas = [self::SCHEMA, self::PREVIOUS_SCHEMA];
        $harnessIdentityReady = in_array($baselineSchema, $harnessSchemas, true)
            && in_array($candidateSchema, $harnessSchemas, true);
        $harnessIdentityMatches = $harnessIdentityReady
            && hash_equals(
                (string) ($baseline['harness_identity_sha256'] ?? ''),
                (string) ($candidate['harness_identity_sha256'] ?? '')
            );
        if (! $harnessIdentityReady) {
            $errors[] = 'harness_identity_missing';
        } elseif (! $harnessIdentityMatches) {
            $errors[] = 'harness_identity_mismatch';
        }

        $baselineCases = $this->caseMap($baseline['cases'] ?? []);
        $candidateCases = $this->caseMap($candidate['cases'] ?? []);
        if (array_keys($baselineCases) !== array_keys($candidateCases)) {
            $errors[] = 'case_set_mismatch';
        }

        $contractMismatch = [];
        foreach (array_intersect(array_keys($baselineCases), array_keys($candidateCases)) as $case) {
            if (! hash_equals(
                (string) ($baselineCases[$case]['contract_sha256'] ?? ''),
                (string) ($candidateCases[$case]['contract_sha256'] ?? '')
            )) {
                $contractMismatch[] = $case;
            }
        }
        if ($contractMismatch !== []) {
            $errors[] = 'case_contract_mismatch';
        }

        $configurationMatches = hash_equals(
            (string) ($baseline['product_configuration_sha256'] ?? ''),
            (string) ($candidate['product_configuration_sha256'] ?? '')
        );
        if (! $configurationMatches && ! $allowConfigurationChange) {
            $errors[] = 'product_configuration_mismatch';
        }

        $baselineEvidence = $this->evidenceCoverage($baselineCases);
        $candidateEvidence = $this->evidenceCoverage($candidateCases);
        $evidenceReady = $baselineEvidence['ready'] && $candidateEvidence['ready'];

        $regressions = [];
        $improvements = [];
        if ($errors === []) {
            foreach ($baselineCases as $case => $baselineCase) {
                $candidateCase = $candidateCases[$case];
                $caseRegressions = $this->caseRegressions($baselineCase, $candidateCase);
                $caseImprovements = $this->caseRegressions($candidateCase, $baselineCase);
                if ($caseRegressions !== []) {
                    $regressions[$case] = $caseRegressions;
                }
                if ($caseImprovements !== []) {
                    $improvements[$case] = $caseImprovements;
                }
            }
        }

        $regressionGateEligible = $errors === [] && $configurationMatches && $evidenceReady;
        $status = match (true) {
            $errors !== [] => 'not_comparable',
            ! $evidenceReady => 'insufficient_evidence',
            ! $configurationMatches => 'configuration_comparison',
            $regressions !== [] => 'regression',
            default => 'pass',
        };

        return [
            'schema' => self::COMPARISON_SCHEMA,
            'status' => $status,
            'comparison' => [
                'baseline_run_id' => $baseline['run_id'],
                'candidate_run_id' => $candidate['run_id'],
                'baseline_schema' => $baselineSchema,
                'candidate_schema' => $candidateSchema,
                'case_count' => count($baselineCases),
                'suite_contract_match' => ($baseline['suite']['contract_sha256'] ?? null)
                    === ($candidate['suite']['contract_sha256'] ?? null),
                'product_configuration_match' => $configurationMatches,
                'configuration_change_allowed' => $allowConfigurationChange,
                'harness_identity_available' => $harnessIdentityReady,
                'harness_identity_match' => $harnessIdentityMatches,
            ],
            'evidence' => [
                'baseline' => $baselineEvidence,
                'candidate' => $candidateEvidence,
                'ready' => $evidenceReady,
            ],
            'summary' => [
                'baseline_passed' => (int) ($baseline['summary']['passed'] ?? 0),
                'candidate_passed' => (int) ($candidate['summary']['passed'] ?? 0),
                'baseline_promotion_eligible' => (int) ($baseline['summary']['promotion_eligible'] ?? 0),
                'candidate_promotion_eligible' => (int) ($candidate['summary']['promotion_eligible'] ?? 0),
                'regressed_cases' => count($regressions),
                'improved_cases' => count($improvements),
            ],
            'regressions' => $regressions,
            'improvements' => $improvements,
            'errors' => array_values(array_unique($errors)),
            'gate' => [
                'eligible' => $regressionGateEligible,
                'pass' => $regressionGateEligible && $regressions === [],
                'reason' => match (true) {
                    $errors !== [] => 'receipts_not_comparable',
                    ! $evidenceReady => 'formal_trajectory_evidence_incomplete',
                    ! $configurationMatches => 'configuration_comparison_is_not_same_product_regression_evidence',
                    $regressions !== [] => 'deterministic_regression_detected',
                    default => 'no_deterministic_regression_detected',
                },
            ],
            'posture' => [
                'read_only' => true,
                'content_included' => false,
                'llm_calls' => false,
                'database_reads' => false,
                'database_writes' => false,
                'promotion_authority' => false,
                'formal_verification_authoritative' => true,
                'harness_identity_required_for_regression_gate' => true,
            ],
        ];
    }

    /** @param array<string,mixed> $comparison @return array<string,mixed> */
    public function compact(array $comparison): array
    {
        $codes = [];
        foreach ((array) ($comparison['regressions'] ?? []) as $caseCodes) {
            foreach ((array) $caseCodes as $code) {
                if (is_string($code)) {
                    $codes[$code] = ($codes[$code] ?? 0) + 1;
                }
            }
        }

        return [
            'schema' => $comparison['schema'] ?? self::COMPARISON_SCHEMA,
            'status' => $comparison['status'] ?? 'unknown',
            'comparison' => $comparison['comparison'] ?? [],
            'evidence' => $comparison['evidence'] ?? [],
            'summary' => $comparison['summary'] ?? [],
            'regression_code_counts' => $codes,
            'errors' => $comparison['errors'] ?? [],
            'gate' => $comparison['gate'] ?? [],
            'posture' => $comparison['posture'] ?? [],
        ];
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    public function summary(array $receipt, string $path): array
    {
        return [
            'schema' => self::SCHEMA,
            'path' => $path,
            'mode' => 'write',
            'file_mode' => '0600',
            'run_id' => $receipt['run_id'],
            'suite_contract_sha256' => $receipt['suite']['contract_sha256'],
            'harness_identity_sha256' => $receipt['harness_identity_sha256'],
            'receipt_sha256' => $receipt['integrity']['receipt_sha256'],
            'case_count' => $receipt['suite']['case_count'],
            'content_included' => false,
        ];
    }

    /** @param array<string,mixed> $receipt */
    private function validateReceipt(array $receipt): void
    {
        $schema = $receipt['schema'] ?? null;
        if (! in_array($schema, [self::SCHEMA, self::PREVIOUS_SCHEMA, self::LEGACY_SCHEMA], true)) {
            throw new InvalidArgumentException('Unsupported or invalid offline coding eval receipt.');
        }
        $currentSchema = $schema === self::SCHEMA;
        $hasHarnessIdentity = in_array($schema, [self::SCHEMA, self::PREVIOUS_SCHEMA], true);
        $expectedRootKeys = [
            'schema', 'generated_at', 'run_id', 'status', 'suite', 'product_configuration',
            'product_configuration_sha256', 'summary', 'cases', 'posture', 'integrity',
        ];
        if ($hasHarnessIdentity) {
            $expectedRootKeys[] = 'harness_identity';
            $expectedRootKeys[] = 'harness_identity_sha256';
        }
        $this->assertExactKeys($receipt, $expectedRootKeys, 'receipt');
        if (! is_array($receipt['cases'] ?? null)
            || ! array_is_list($receipt['cases'])
            || count($receipt['cases']) === 0
            || count($receipt['cases']) > 1000
            || ! $this->validTimestamp($receipt['generated_at'] ?? null)
            || $this->safeToken($receipt['run_id'] ?? null, 100) === null
            || ! in_array($receipt['status'] ?? null, ['pass', 'fail'], true)) {
            throw new InvalidArgumentException('Unsupported or invalid offline coding eval receipt.');
        }

        $requiredBlocks = ['suite', 'product_configuration', 'summary', 'posture', 'integrity'];
        if ($hasHarnessIdentity) {
            $requiredBlocks[] = 'harness_identity';
        }
        foreach ($requiredBlocks as $block) {
            if (! is_array($receipt[$block] ?? null)) {
                throw new InvalidArgumentException("Offline coding eval receipt {$block} block is invalid.");
            }
        }
        $this->assertExactKeys($receipt['suite'], ['case_count', 'contract_sha256'], 'suite');
        $productConfigurationKeys = [
            'unit_measured', 'agent_product', 'profile', 'model', 'reasoning_effort', 'runtime_model_attestation',
        ];
        if ($currentSchema) {
            $productConfigurationKeys[] = 'verifier_image_identity';
        }
        $this->assertExactKeys($receipt['product_configuration'], $productConfigurationKeys, 'product_configuration');
        $this->assertExactKeys($receipt['summary'], ['passed', 'failed', 'promotion_eligible'], 'summary');
        $expectedPostureKeys = [
            'content_included', 'prompt_included', 'response_included', 'error_text_included',
            'workspace_path_included', 'diagnostics_included', 'llm_judge_used',
            'formal_verification_authoritative',
        ];
        if ($currentSchema) {
            $expectedPostureKeys[] = 'harness_identity_required_for_regression_gate';
            $expectedPostureKeys[] = 'verifier_image_identity_required_for_docker_fallback';
        } elseif ($hasHarnessIdentity) {
            $expectedPostureKeys[] = 'harness_identity_required_for_regression_gate';
        }
        $this->assertExactKeys($receipt['posture'], $expectedPostureKeys, 'posture');
        $this->assertExactKeys($receipt['integrity'], ['algorithm', 'receipt_sha256'], 'integrity');

        $configuration = $this->productConfiguration($receipt['product_configuration'], $currentSchema);
        if ($configuration !== $receipt['product_configuration']
            || ! $this->validHash($receipt['product_configuration_sha256'] ?? null)
            || ! hash_equals($this->hash($configuration), (string) $receipt['product_configuration_sha256'])) {
            throw new InvalidArgumentException('Offline coding eval receipt product configuration is invalid.');
        }

        if ($hasHarnessIdentity) {
            $this->validateHarnessIdentity(
                $receipt['harness_identity'],
                $receipt['harness_identity_sha256'] ?? null
            );
        }

        $caseContracts = [];
        $caseNames = [];
        foreach ($receipt['cases'] as $index => $case) {
            if (! is_array($case)) {
                throw new InvalidArgumentException("Offline coding eval receipt case {$index} is invalid.");
            }
            $this->assertExactKeys($case, [
                'case', 'contract_sha256', 'passed', 'duration_ms', 'checks',
                'trajectory_assessment', 'execution_identity',
            ], "cases.{$index}");
            $name = $this->safeToken($case['case'] ?? null, 120);
            if ($name === null
                || ! $this->validHash($case['contract_sha256'] ?? null)
                || ! is_bool($case['passed'] ?? null)
                || ! $this->isNullableNonNegativeInt($case['duration_ms'])
                || ! is_array($case['checks'] ?? null)
                || ! array_is_list($case['checks'])
                || ! is_array($case['trajectory_assessment'] ?? null)) {
                throw new InvalidArgumentException("Offline coding eval receipt case {$index} has invalid values.");
            }
            foreach ($case['checks'] as $checkIndex => $check) {
                if (! is_array($check)) {
                    throw new InvalidArgumentException("Offline coding eval receipt check {$checkIndex} is invalid.");
                }
                $this->assertExactKeys($check, ['name', 'passed'], "cases.{$index}.checks.{$checkIndex}");
                if ($this->safeToken($check['name'] ?? null, 120) === null || ! is_bool($check['passed'] ?? null)) {
                    throw new InvalidArgumentException("Offline coding eval receipt check {$checkIndex} has invalid values.");
                }
            }
            $this->validateAssessment($case['trajectory_assessment'], "cases.{$index}.trajectory_assessment");
            if ($case['execution_identity'] !== null) {
                if (! is_array($case['execution_identity'])) {
                    throw new InvalidArgumentException("Offline coding eval receipt execution identity {$index} is invalid.");
                }
                $this->assertExactKeys($case['execution_identity'], [
                    'dispatch_matches_request', 'runtime_model_attested', 'attestation_scope',
                ], "cases.{$index}.execution_identity");
                if (! is_bool($case['execution_identity']['dispatch_matches_request'] ?? null)
                    || ! is_bool($case['execution_identity']['runtime_model_attested'] ?? null)
                    || $this->safeToken($case['execution_identity']['attestation_scope'] ?? null, 120) === null) {
                    throw new InvalidArgumentException("Offline coding eval receipt execution identity {$index} has invalid values.");
                }
            }
            $caseNames[] = $name;
            $caseContracts[] = ['case' => $name, 'contract_sha256' => $case['contract_sha256']];
        }
        $sortedNames = $caseNames;
        sort($sortedNames, SORT_STRING);
        if ($caseNames !== $sortedNames || count(array_unique($caseNames)) !== count($caseNames)) {
            throw new InvalidArgumentException('Offline coding eval receipt cases must be unique and sorted.');
        }
        if (($receipt['suite']['case_count'] ?? null) !== count($receipt['cases'])
            || ! $this->validHash($receipt['suite']['contract_sha256'] ?? null)
            || ! hash_equals($this->hash($caseContracts), (string) $receipt['suite']['contract_sha256'])) {
            throw new InvalidArgumentException('Offline coding eval receipt suite contract is invalid.');
        }
        $expectedSummary = [
            'passed' => count(array_filter($receipt['cases'], static fn (array $case): bool => $case['passed'])),
            'failed' => count(array_filter($receipt['cases'], static fn (array $case): bool => ! $case['passed'])),
            'promotion_eligible' => count(array_filter(
                $receipt['cases'],
                static fn (array $case): bool => ($case['trajectory_assessment']['promotion_eligible'] ?? false) === true
            )),
        ];
        if ($receipt['summary'] !== $expectedSummary) {
            throw new InvalidArgumentException('Offline coding eval receipt summary is inconsistent.');
        }
        $expectedStatus = $expectedSummary['failed'] === 0 ? 'pass' : 'fail';
        if ($receipt['status'] !== $expectedStatus) {
            throw new InvalidArgumentException('Offline coding eval receipt status is inconsistent.');
        }

        $expectedPosture = [
            'content_included' => false,
            'prompt_included' => false,
            'response_included' => false,
            'error_text_included' => false,
            'workspace_path_included' => false,
            'diagnostics_included' => false,
            'llm_judge_used' => false,
            'formal_verification_authoritative' => true,
        ];
        if ($currentSchema) {
            $expectedPosture['harness_identity_required_for_regression_gate'] = true;
            $expectedPosture['verifier_image_identity_required_for_docker_fallback'] = true;
        } elseif ($hasHarnessIdentity) {
            $expectedPosture['harness_identity_required_for_regression_gate'] = true;
        }
        if ($receipt['posture'] !== $expectedPosture) {
            throw new InvalidArgumentException('Offline coding eval receipt does not declare the required content-free posture.');
        }

        $recorded = $receipt['integrity']['receipt_sha256'] ?? null;
        if (($receipt['integrity']['algorithm'] ?? null) !== 'sha256' || ! $this->validHash($recorded)) {
            throw new InvalidArgumentException('Offline coding eval receipt integrity value is invalid.');
        }
        $unsigned = $receipt;
        unset($unsigned['integrity']);
        if (! hash_equals($this->hash($unsigned), $recorded)) {
            throw new InvalidArgumentException('Offline coding eval receipt integrity mismatch.');
        }
    }

    /** @param array<string,mixed> $assessment */
    private function validateAssessment(array $assessment, string $path): void
    {
        if ($assessment === []) {
            return;
        }
        $this->assertExactKeys($assessment, [
            'schema', 'outcome', 'promotion_eligible', 'formal_verification',
            'instruction_compliance', 'trajectory', 'failure_localization',
            'invariant_violations', 'limitations', 'evidence',
        ], $path);
        foreach (['formal_verification', 'instruction_compliance', 'trajectory', 'failure_localization', 'evidence'] as $block) {
            if (! is_array($assessment[$block] ?? null)) {
                throw new InvalidArgumentException("Offline coding eval receipt {$path}.{$block} is invalid.");
            }
        }
        $this->assertExactKeys($assessment['formal_verification'], ['status', 'check_count'], "{$path}.formal_verification");
        $this->assertExactKeys($assessment['instruction_compliance'], ['status', 'check_count'], "{$path}.instruction_compliance");
        $this->assertExactKeys($assessment['trajectory'], [
            'tool_calls', 'unique_tools', 'failed_tool_calls', 'subsequent_successes_after_failure',
            'repeated_adjacent_action_observation_pairs', 'iterations', 'max_iterations',
        ], "{$path}.trajectory");
        $this->assertExactKeys($assessment['failure_localization'], [
            'category', 'first_failed_check', 'first_failed_tool_step',
        ], "{$path}.failure_localization");
        $this->assertExactKeys($assessment['evidence'], [
            'check_count', 'failed_check_count', 'failed_check_names', 'content_included',
        ], "{$path}.evidence");
        if (! in_array($assessment['schema'] ?? null, [AgentTrajectoryAssessmentService::LEGACY_SCHEMA, null], true)
            || ! is_bool($assessment['promotion_eligible'] ?? null)
            || ($assessment['outcome'] !== null && $this->safeToken($assessment['outcome'], 100) === null)
            || ! is_array($assessment['invariant_violations'] ?? null)
            || ! is_array($assessment['limitations'] ?? null)
            || $this->tokenList($assessment['invariant_violations']) !== $assessment['invariant_violations']
            || $this->tokenList($assessment['limitations']) !== $assessment['limitations']
            || ! is_array($assessment['evidence']['failed_check_names'] ?? null)
            || $this->tokenList($assessment['evidence']['failed_check_names']) !== $assessment['evidence']['failed_check_names']
            || ($assessment['evidence']['content_included'] ?? null) !== false) {
            throw new InvalidArgumentException("Offline coding eval receipt {$path} contains invalid assessment values.");
        }

        foreach (['formal_verification', 'instruction_compliance'] as $block) {
            if (! $this->isNullableToken($assessment[$block]['status'], 50)
                || ! $this->isNullableNonNegativeInt($assessment[$block]['check_count'])) {
                throw new InvalidArgumentException("Offline coding eval receipt {$path}.{$block} contains invalid values.");
            }
        }
        foreach ($assessment['trajectory'] as $value) {
            if (! $this->isNullableNonNegativeInt($value)) {
                throw new InvalidArgumentException("Offline coding eval receipt {$path}.trajectory contains invalid values.");
            }
        }
        if (! $this->isNullableToken($assessment['failure_localization']['category'], 100)
            || ! $this->isNullableToken($assessment['failure_localization']['first_failed_check'], 120)
            || ! $this->isNullableNonNegativeInt($assessment['failure_localization']['first_failed_tool_step'])
            || ! $this->isNullableNonNegativeInt($assessment['evidence']['check_count'])
            || ! $this->isNullableNonNegativeInt($assessment['evidence']['failed_check_count'])) {
            throw new InvalidArgumentException("Offline coding eval receipt {$path} contains invalid evidence values.");
        }
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private function assertExactKeys(array $value, array $expected, string $path): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException("Offline coding eval receipt {$path} has unexpected or missing fields.");
        }
    }

    private function validHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private function validTimestamp(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $timestamp = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);

        return $timestamp !== false && $timestamp->format(DATE_ATOM) === $value;
    }

    private function isNullableToken(mixed $value, int $maxLength): bool
    {
        return $value === null || $this->safeToken($value, $maxLength) !== null;
    }

    private function isNullableNonNegativeInt(mixed $value): bool
    {
        return $value === null || (is_int($value) && $value >= 0);
    }

    /** @param array<string,mixed> $case */
    private function caseContractHash(array $case): string
    {
        unset($case['summary'], $case['cwd']);

        return $this->hash($case);
    }

    /** @return list<array{name:string,passed:bool}> */
    private function checks(mixed $checks): array
    {
        if (! is_array($checks)) {
            return [];
        }
        $result = [];
        foreach ($checks as $check) {
            if (! is_array($check)) {
                continue;
            }
            $name = $this->safeToken($check['name'] ?? null, 120);
            if ($name !== null) {
                $result[] = ['name' => $name, 'passed' => ($check['passed'] ?? false) === true];
            }
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function assessment(mixed $assessment): array
    {
        if (! is_array($assessment)) {
            return [];
        }
        $trajectory = is_array($assessment['trajectory'] ?? null) ? $assessment['trajectory'] : [];
        $evidence = is_array($assessment['evidence'] ?? null) ? $assessment['evidence'] : [];
        $failure = is_array($assessment['failure_localization'] ?? null)
            ? $assessment['failure_localization']
            : [];

        return [
            'schema' => in_array($assessment['schema'] ?? null, [
                AgentTrajectoryAssessmentService::SCHEMA,
                AgentTrajectoryAssessmentService::PREVIOUS_SCHEMA,
                AgentTrajectoryAssessmentService::LEGACY_SCHEMA,
            ], true)
                ? AgentTrajectoryAssessmentService::LEGACY_SCHEMA
                : null,
            'outcome' => $this->safeToken($assessment['outcome'] ?? null, 100),
            'promotion_eligible' => ($assessment['promotion_eligible'] ?? false) === true,
            'formal_verification' => $this->statusBlock($assessment['formal_verification'] ?? null),
            'instruction_compliance' => $this->statusBlock($assessment['instruction_compliance'] ?? null),
            'trajectory' => [
                'tool_calls' => $this->nonNegativeInt($trajectory['tool_calls'] ?? null),
                'unique_tools' => $this->nonNegativeInt($trajectory['unique_tools'] ?? null),
                'failed_tool_calls' => $this->nonNegativeInt($trajectory['failed_tool_calls'] ?? null),
                'subsequent_successes_after_failure' => $this->nonNegativeInt($trajectory['subsequent_successes_after_failure'] ?? null),
                'repeated_adjacent_action_observation_pairs' => $this->nonNegativeInt($trajectory['repeated_adjacent_action_observation_pairs'] ?? null),
                'iterations' => $this->nonNegativeInt($trajectory['iterations'] ?? null),
                'max_iterations' => $this->nonNegativeInt($trajectory['max_iterations'] ?? null),
            ],
            'failure_localization' => [
                'category' => $this->safeToken($failure['category'] ?? null, 100),
                'first_failed_check' => $this->safeToken($failure['first_failed_check'] ?? null, 120),
                'first_failed_tool_step' => $this->nonNegativeInt($failure['first_failed_tool_step'] ?? null),
            ],
            'invariant_violations' => $this->tokenList($assessment['invariant_violations'] ?? []),
            'limitations' => $this->tokenList($assessment['limitations'] ?? []),
            'evidence' => [
                'check_count' => $this->nonNegativeInt($evidence['check_count'] ?? null),
                'failed_check_count' => $this->nonNegativeInt($evidence['failed_check_count'] ?? null),
                'failed_check_names' => $this->tokenList($evidence['failed_check_names'] ?? []),
                'content_included' => false,
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function executionIdentity(mixed $identity): ?array
    {
        if (! is_array($identity)) {
            return null;
        }

        return [
            'dispatch_matches_request' => ($identity['dispatch_matches_request'] ?? false) === true,
            'runtime_model_attested' => ($identity['runtime_model_attested'] ?? false) === true,
            'attestation_scope' => $this->safeToken($identity['attestation_scope'] ?? null, 120),
        ];
    }

    /** @return array<string,mixed> */
    private function harnessIdentity(): array
    {
        $context = hash_init('sha256');
        $sources = self::HARNESS_SOURCES;
        sort($sources, SORT_STRING);

        foreach ($sources as $relativePath) {
            $absolutePath = base_path($relativePath);
            if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
                throw new RuntimeException("Offline coding eval harness source is unavailable: {$relativePath}");
            }
            hash_update($context, $relativePath."\0");
            $contents = file_get_contents($absolutePath);
            if ($contents === false) {
                throw new RuntimeException("Offline coding eval harness source cannot be read: {$relativePath}");
            }
            hash_update($context, $contents);
        }

        return [
            'schema' => self::HARNESS_IDENTITY_SCHEMA,
            'fingerprint_sha256' => hash_final($context),
            'source_count' => count($sources),
            'fingerprint_scope' => self::HARNESS_FINGERPRINT_SCOPE,
            'runtime_family' => 'php-'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            'content_included' => false,
        ];
    }

    private function validateHarnessIdentity(array $identity, mixed $identityHash): void
    {
        $this->assertExactKeys($identity, [
            'schema', 'fingerprint_sha256', 'source_count', 'fingerprint_scope',
            'runtime_family', 'content_included',
        ], 'harness_identity');
        if (($identity['schema'] ?? null) !== self::HARNESS_IDENTITY_SCHEMA
            || ! $this->validHash($identity['fingerprint_sha256'] ?? null)
            || ($identity['source_count'] ?? null) !== count(self::HARNESS_SOURCES)
            || ($identity['fingerprint_scope'] ?? null) !== self::HARNESS_FINGERPRINT_SCOPE
            || ! is_string($identity['runtime_family'] ?? null)
            || preg_match('/\Aphp-[0-9]+\.[0-9]+\z/D', $identity['runtime_family']) !== 1
            || ($identity['content_included'] ?? null) !== false
            || ! $this->validHash($identityHash)
            || ! hash_equals($this->hash($identity), (string) $identityHash)) {
            throw new InvalidArgumentException('Offline coding eval receipt harness identity is invalid.');
        }
    }

    /** @return array<string,mixed> */
    private function productConfiguration(mixed $configuration, bool $requireVerifierIdentity = true): array
    {
        if (! is_array($configuration)) {
            throw new InvalidArgumentException('Offline coding eval product configuration is missing.');
        }

        $portable = [
            'unit_measured' => $this->safeToken($configuration['unit_measured'] ?? null, 120),
            'agent_product' => $this->safeToken($configuration['agent_product'] ?? null, 120),
            'profile' => $this->safeToken($configuration['profile'] ?? null, 120),
            'model' => $this->safeToken($configuration['model'] ?? null, 160),
            'reasoning_effort' => $this->safeToken($configuration['reasoning_effort'] ?? null, 50),
            'runtime_model_attestation' => $this->safeToken($configuration['runtime_model_attestation'] ?? null, 160),
        ];
        if ($requireVerifierIdentity) {
            $identity = $configuration['verifier_image_identity'] ?? null;
            $portable['verifier_image_identity'] = is_string($identity)
                && ($identity === 'unavailable' || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $identity) === 1)
                    ? $identity
                    : null;
        }
        if (in_array(null, $portable, true)) {
            throw new InvalidArgumentException('Offline coding eval product configuration contains an invalid identifier.');
        }

        return $portable;
    }

    /** @return array{status:?string,check_count:?int} */
    private function statusBlock(mixed $block): array
    {
        return [
            'status' => is_array($block) ? $this->safeToken($block['status'] ?? null, 50) : null,
            'check_count' => is_array($block) ? $this->nonNegativeInt($block['check_count'] ?? null) : null,
        ];
    }

    /** @return list<string> */
    private function tokenList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => $this->safeToken($value, 120),
            $values
        ))));
    }

    /** @return array<string,array<string,mixed>> */
    private function caseMap(mixed $cases): array
    {
        if (! is_array($cases)) {
            return [];
        }
        $map = [];
        foreach ($cases as $case) {
            if (! is_array($case)) {
                continue;
            }
            $name = $this->safeToken($case['case'] ?? null, 120);
            if ($name === null || isset($map[$name])) {
                throw new InvalidArgumentException('Offline coding eval receipt case identifiers are invalid or duplicated.');
            }
            $map[$name] = $case;
        }
        ksort($map, SORT_STRING);

        return $map;
    }

    /** @param array<string,array<string,mixed>> $cases @return array{ready:bool,cases:int,assessed:int,formally_configured:int,promotion_eligible:int} */
    private function evidenceCoverage(array $cases): array
    {
        $assessed = 0;
        $formal = 0;
        $promotion = 0;
        foreach ($cases as $case) {
            $assessment = is_array($case['trajectory_assessment'] ?? null) ? $case['trajectory_assessment'] : [];
            if (($assessment['schema'] ?? null) === AgentTrajectoryAssessmentService::LEGACY_SCHEMA) {
                $assessed++;
            }
            if (in_array($assessment['formal_verification']['status'] ?? null, ['passed', 'failed'], true)) {
                $formal++;
            }
            if (($assessment['promotion_eligible'] ?? false) === true) {
                $promotion++;
            }
        }
        $count = count($cases);

        return [
            'ready' => $count > 0 && $assessed === $count && $formal === $count,
            'cases' => $count,
            'assessed' => $assessed,
            'formally_configured' => $formal,
            'promotion_eligible' => $promotion,
        ];
    }

    /** @param array<string,mixed> $baseline @param array<string,mixed> $candidate @return list<string> */
    private function caseRegressions(array $baseline, array $candidate): array
    {
        $codes = [];
        if (($baseline['passed'] ?? false) === true && ($candidate['passed'] ?? false) !== true) {
            $codes[] = 'case_pass_lost';
        }
        $baselineAssessment = is_array($baseline['trajectory_assessment'] ?? null) ? $baseline['trajectory_assessment'] : [];
        $candidateAssessment = is_array($candidate['trajectory_assessment'] ?? null) ? $candidate['trajectory_assessment'] : [];
        if (($baselineAssessment['promotion_eligible'] ?? false) === true
            && ($candidateAssessment['promotion_eligible'] ?? false) !== true) {
            $codes[] = 'promotion_eligibility_lost';
        }
        if (($baselineAssessment['formal_verification']['status'] ?? null) === 'passed'
            && ($candidateAssessment['formal_verification']['status'] ?? null) !== 'passed') {
            $codes[] = 'formal_verification_regressed';
        }
        if (($baselineAssessment['instruction_compliance']['status'] ?? null) === 'passed'
            && ($candidateAssessment['instruction_compliance']['status'] ?? null) !== 'passed') {
            $codes[] = 'instruction_compliance_regressed';
        }
        foreach ([
            'failed_tool_calls' => 'failed_tool_calls_increased',
            'repeated_adjacent_action_observation_pairs' => 'repeated_action_pairs_increased',
        ] as $metric => $code) {
            if ((int) ($candidateAssessment['trajectory'][$metric] ?? 0)
                > (int) ($baselineAssessment['trajectory'][$metric] ?? 0)) {
                $codes[] = $code;
            }
        }
        if (count((array) ($candidateAssessment['invariant_violations'] ?? []))
            > count((array) ($baselineAssessment['invariant_violations'] ?? []))) {
            $codes[] = 'invariant_violations_increased';
        }

        return $codes;
    }

    private function safeToken(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== ''
            && mb_strlen($value) <= $maxLength
            && preg_match('/\A[a-zA-Z0-9_.:\/-]+\z/D', $value) === 1
                ? $value
                : null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $integer = (int) $value;

        return $integer >= 0 ? $integer : null;
    }

    private function hash(mixed $value): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Offline coding eval evidence cannot be encoded canonically.', previous: $e);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
