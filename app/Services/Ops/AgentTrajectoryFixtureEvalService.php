<?php

namespace App\Services\Ops;

use App\Services\AgentTrajectoryService;

class AgentTrajectoryFixtureEvalService
{
    public const SCHEMA = 'plos.agent_trajectory_fixture_eval.v1';

    private const MODES = ['strict', 'unordered', 'superset', 'subset'];

    private const SCOPES = ['contracts', 'tools'];

    private const MAX_CASES = 200;

    private const MAX_REPEATS = 20;

    /**
     * @param  list<array<string, mixed>>  $actualFixtures
     * @param  array<string, mixed>  $referenceFixture
     * @return array<string, mixed>
     */
    public function evaluate(array $actualFixtures, array $referenceFixture, string $mode = 'strict', string $scope = 'contracts'): array
    {
        $mode = strtolower(trim($mode));
        $scope = strtolower(trim($scope));
        $violations = [];

        if (! in_array($mode, self::MODES, true)) {
            $violations[] = 'unsupported_match_mode';
        }
        if (! in_array($scope, self::SCOPES, true)) {
            $violations[] = 'unsupported_comparison_scope';
        }
        if ($actualFixtures === []) {
            $violations[] = 'actual_fixture_required';
        }
        if (count($actualFixtures) > self::MAX_REPEATS) {
            $violations[] = 'repeat_limit_exceeded';
        }

        $reference = $this->normalizeFixture($referenceFixture, $scope, 'reference', $violations);
        $actuals = [];
        foreach (array_slice($actualFixtures, 0, self::MAX_REPEATS) as $index => $fixture) {
            $actuals[] = $this->normalizeFixture($fixture, $scope, 'actual_'.($index + 1), $violations);
        }

        if ($violations !== []) {
            return $this->invalidPayload($mode, $scope, $violations);
        }

        $repeats = [];
        foreach ($actuals as $index => $actual) {
            $repeats[] = $this->compare($actual, $reference, $mode, $index + 1);
        }

        $passed = count(array_filter($repeats, static fn (array $repeat): bool => $repeat['matched']));
        $repeatCount = count($repeats);
        $allMatch = $repeatCount > 0 && $passed === $repeatCount;

        return [
            'schema' => self::SCHEMA,
            'status' => $allMatch ? 'match' : 'mismatch',
            'mode' => $mode,
            'scope' => $scope,
            'read_only' => true,
            'all_repeats_match' => $allMatch,
            'repeat_count' => $repeatCount,
            'passed_repeat_count' => $passed,
            'failed_repeat_count' => $repeatCount - $passed,
            'consistency_rate' => $repeatCount > 0 ? round($passed / $repeatCount, 4) : 0.0,
            'repeats' => $repeats,
            'violations' => [],
            'posture' => $this->posture(),
        ];
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @param  list<string>  $violations
     * @return list<string>
     */
    private function normalizeFixture(array $fixture, string $scope, string $label, array &$violations): array
    {
        $allowedFixtureFields = [
            'success', 'schema', 'source_schema', 'trust_boundary', 'scenario',
            'source_hash', 'summary', 'cases', 'omitted_fields', 'result_text',
        ];
        if (array_diff(array_keys($fixture), $allowedFixtureFields) !== []) {
            $violations[] = $label.'_unexpected_fixture_fields';
        }
        if (($fixture['schema'] ?? null) !== AgentTrajectoryService::FIXTURE_SCHEMA) {
            $violations[] = $label.'_invalid_schema';

            return [];
        }
        if (($fixture['trust_boundary'] ?? null) !== 'sanitized_regression_fixture_no_raw_private_content') {
            $violations[] = $label.'_invalid_trust_boundary';
        }
        if (data_get($fixture, 'summary.raw_text_included') !== false) {
            $violations[] = $label.'_raw_text_posture_invalid';
        }

        $cases = $fixture['cases'] ?? null;
        if (! is_array($cases) || ! array_is_list($cases) || $cases === []) {
            $violations[] = $label.'_cases_required';

            return [];
        }
        if (count($cases) > self::MAX_CASES) {
            $violations[] = $label.'_case_limit_exceeded';

            return [];
        }

        $normalized = [];
        foreach ($cases as $case) {
            if (! is_array($case)) {
                $violations[] = $label.'_case_invalid';

                continue;
            }
            $allowedCaseFields = [
                'case_key', 'step_type', 'tool_name_hash', 'gate', 'risk_level',
                'expected_outcome', 'error_class', 'source_type', 'has_duration',
                'has_review_result',
            ];
            if (array_diff(array_keys($case), $allowedCaseFields) !== []) {
                $violations[] = $label.'_case_unexpected_fields';
            }
            $stepType = $this->token($case['step_type'] ?? null);
            if ($stepType === null) {
                $violations[] = $label.'_case_step_type_invalid';

                continue;
            }

            $toolHash = $case['tool_name_hash'] ?? null;
            if ($toolHash !== null && (! is_string($toolHash) || preg_match('/^sha256:[a-f0-9]{64}$/', $toolHash) !== 1)) {
                $violations[] = $label.'_case_tool_hash_invalid';

                continue;
            }
            if ($scope === 'tools' && $stepType !== 'tool_call') {
                continue;
            }
            if ($scope === 'tools' && $toolHash === null) {
                $violations[] = $label.'_tool_case_hash_required';

                continue;
            }

            $contract = $scope === 'tools'
                ? ['tool_name_hash' => $toolHash]
                : [
                    'step_type' => $stepType,
                    'tool_name_hash' => $toolHash,
                    'gate' => $this->nullableToken($case['gate'] ?? null, $label, 'gate', $violations),
                    'risk_level' => $this->nullableToken($case['risk_level'] ?? null, $label, 'risk_level', $violations),
                    'expected_outcome' => $this->nullableToken($case['expected_outcome'] ?? null, $label, 'expected_outcome', $violations),
                    'error_class' => $this->nullableToken($case['error_class'] ?? null, $label, 'error_class', $violations),
                    'source_type' => $this->nullableToken($case['source_type'] ?? null, $label, 'source_type', $violations),
                    'has_review_result' => $case['has_review_result'] ?? null,
                ];

            if ($scope === 'contracts' && ! is_bool($contract['has_review_result'])) {
                $violations[] = $label.'_case_review_flag_invalid';

                continue;
            }

            $encoded = json_encode($contract, JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                $violations[] = $label.'_case_encoding_failed';

                continue;
            }
            $normalized[] = 'sha256:'.hash('sha256', $encoded);
        }

        if ($normalized === []) {
            $violations[] = $label.'_normalized_cases_required';
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $actual
     * @param  list<string>  $reference
     * @return array<string, mixed>
     */
    private function compare(array $actual, array $reference, string $mode, int $index): array
    {
        $actualCounts = array_count_values($actual);
        $referenceCounts = array_count_values($reference);
        $missing = $this->multisetDifference($referenceCounts, $actualCounts);
        $unexpected = $this->multisetDifference($actualCounts, $referenceCounts);
        $missingCount = array_sum($missing);
        $unexpectedCount = array_sum($unexpected);
        $sameMultiset = $missingCount === 0 && $unexpectedCount === 0;
        $matched = match ($mode) {
            'strict' => $actual === $reference,
            'unordered' => $sameMultiset,
            'superset' => $missingCount === 0,
            'subset' => $unexpectedCount === 0,
        };

        return [
            'repeat' => $index,
            'matched' => $matched,
            'actual_case_count' => count($actual),
            'reference_case_count' => count($reference),
            'matched_case_count' => $this->matchedCount($actualCounts, $referenceCounts),
            'missing_case_count' => $missingCount,
            'unexpected_case_count' => $unexpectedCount,
            'order_mismatch' => $mode === 'strict' && $sameMultiset && $actual !== $reference,
            'missing_case_digests' => array_slice(array_keys($missing), 0, 20),
            'unexpected_case_digests' => array_slice(array_keys($unexpected), 0, 20),
        ];
    }

    /** @param array<string,int> $left @param array<string,int> $right @return array<string,int> */
    private function multisetDifference(array $left, array $right): array
    {
        $difference = [];
        foreach ($left as $digest => $count) {
            $remaining = $count - ($right[$digest] ?? 0);
            if ($remaining > 0) {
                $difference[$digest] = $remaining;
            }
        }

        return $difference;
    }

    /** @param array<string,int> $actual @param array<string,int> $reference */
    private function matchedCount(array $actual, array $reference): int
    {
        $matched = 0;
        foreach ($actual as $digest => $count) {
            $matched += min($count, $reference[$digest] ?? 0);
        }

        return $matched;
    }

    /** @param list<string> $violations */
    private function nullableToken(mixed $value, string $label, string $field, array &$violations): ?string
    {
        if ($value === null) {
            return null;
        }
        $token = $this->token($value);
        if ($token === null) {
            $violations[] = $label.'_case_'.$field.'_invalid';
        }

        return $token;
    }

    private function token(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 120) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_.:-]+$/', $value) === 1 ? $value : null;
    }

    /** @param list<string> $violations @return array<string,mixed> */
    private function invalidPayload(string $mode, string $scope, array $violations): array
    {
        return [
            'schema' => self::SCHEMA,
            'status' => 'invalid',
            'mode' => $mode,
            'scope' => $scope,
            'read_only' => true,
            'all_repeats_match' => false,
            'repeat_count' => 0,
            'passed_repeat_count' => 0,
            'failed_repeat_count' => 0,
            'consistency_rate' => 0.0,
            'repeats' => [],
            'violations' => array_values(array_unique($violations)),
            'posture' => $this->posture(),
        ];
    }

    /** @return array<string,mixed> */
    private function posture(): array
    {
        return [
            'deterministic' => true,
            'no_agent_reexecution' => true,
            'raw_text_included' => false,
            'raw_tool_names_included' => false,
            'database_access' => false,
            'network_access' => false,
            'llm_access' => false,
            'repeat_gate' => 'all_repeats_must_match',
        ];
    }
}
