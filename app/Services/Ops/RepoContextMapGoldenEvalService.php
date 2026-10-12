<?php

namespace App\Services\Ops;

use App\Services\RepoDevMCPService;
use Throwable;

class RepoContextMapGoldenEvalService
{
    public function __construct(private readonly RepoDevMCPService $repoDev) {}

    /**
     * @param  array<string,array<string,mixed>>|null  $cases
     * @return array<string,mixed>
     */
    public function evaluate(?array $cases = null): array
    {
        $cases ??= (array) config('repo_context_eval.cases', []);
        $thresholds = (array) config('repo_context_eval.thresholds', []);
        $results = [];

        foreach ($cases as $name => $case) {
            $results[] = is_array($case)
                ? $this->evaluateCase((string) $name, $case)
                : $this->invalidCase((string) $name, 'invalid_case_definition');
        }

        $caseCount = count($results);
        $passed = count(array_filter($results, static fn (array $result): bool => (bool) ($result['passed'] ?? false)));
        $mrr = $caseCount === 0 ? 0.0 : array_sum(array_column($results, 'reciprocal_rank')) / $caseCount;
        $meanRecall = $caseCount === 0 ? 0.0 : array_sum(array_column($results, 'recall_at_k')) / $caseCount;
        $meanPrecision = $caseCount === 0 ? 0.0 : array_sum(array_column($results, 'precision_at_k')) / $caseCount;
        $meanContextEfficiency = $caseCount === 0 ? 0.0 : array_sum(array_column($results, 'context_efficiency')) / $caseCount;
        $casePassRate = $caseCount === 0 ? 0.0 : $passed / $caseCount;
        $searchSpaceComplete = count(array_filter(
            $results,
            static fn (array $result): bool => (bool) ($result['search_space_complete'] ?? false)
        ));
        $searchSpaceCompleteRate = $caseCount === 0 ? 0.0 : $searchSpaceComplete / $caseCount;
        $metrics = [
            'case_pass_rate' => round($casePassRate, 4),
            'mrr' => round($mrr, 4),
            'mean_recall_at_k' => round($meanRecall, 4),
            'mean_precision_at_k' => round($meanPrecision, 4),
            'mean_context_efficiency' => round($meanContextEfficiency, 4),
            'hard_negative_ahead_count' => array_sum(array_column($results, 'hard_negative_ahead_count')),
            'search_space_complete_rate' => round($searchSpaceCompleteRate, 4),
            'partial_search_space_cases' => $caseCount - $searchSpaceComplete,
        ];
        $thresholdChecks = [
            'case_pass_rate' => $metrics['case_pass_rate'] >= (float) ($thresholds['minimum_case_pass_rate'] ?? 1.0),
            'mrr' => $metrics['mrr'] >= (float) ($thresholds['minimum_mrr'] ?? 0.5),
            'mean_recall_at_k' => $metrics['mean_recall_at_k'] >= (float) ($thresholds['minimum_mean_recall_at_k'] ?? 0.5),
            'mean_precision_at_k' => $metrics['mean_precision_at_k'] >= (float) ($thresholds['minimum_mean_precision_at_k'] ?? 0.25),
            'mean_context_efficiency' => $metrics['mean_context_efficiency'] >= (float) ($thresholds['minimum_mean_context_efficiency'] ?? 0.25),
            'search_space_complete_rate' => $metrics['search_space_complete_rate'] >= (float) ($thresholds['minimum_search_space_complete_rate'] ?? 1.0),
        ];

        return [
            'schema' => (string) config('repo_context_eval.schema', 'plos.repo_context_map_golden_eval.v1'),
            'status' => $caseCount > 0 && $passed === $caseCount && ! in_array(false, $thresholdChecks, true)
                ? 'pass'
                : 'fail',
            'generated_at' => now('UTC')->toIso8601String(),
            'summary' => [
                'cases' => $caseCount,
                'passed' => $passed,
                'failed' => $caseCount - $passed,
                'checks' => array_sum(array_column($results, 'check_count')) + count($thresholdChecks),
                'violations' => array_sum(array_map(
                    static fn (array $result): int => count((array) ($result['violation_codes'] ?? [])),
                    $results
                )) + count(array_filter($thresholdChecks, static fn (bool $passed): bool => ! $passed)),
            ],
            'metrics' => $metrics,
            'thresholds' => [
                'minimum_case_pass_rate' => (float) ($thresholds['minimum_case_pass_rate'] ?? 1.0),
                'minimum_mrr' => (float) ($thresholds['minimum_mrr'] ?? 0.5),
                'minimum_mean_recall_at_k' => (float) ($thresholds['minimum_mean_recall_at_k'] ?? 0.5),
                'minimum_mean_precision_at_k' => (float) ($thresholds['minimum_mean_precision_at_k'] ?? 0.25),
                'minimum_mean_context_efficiency' => (float) ($thresholds['minimum_mean_context_efficiency'] ?? 0.25),
                'minimum_search_space_complete_rate' => (float) ($thresholds['minimum_search_space_complete_rate'] ?? 1.0),
            ],
            'threshold_checks' => $thresholdChecks,
            'cases' => $results,
            'posture' => [
                'deterministic_fixtures' => true,
                'production_repo_context_service_exercised' => true,
                'relevant_file_recall_scored' => true,
                'irrelevant_file_precision_scored' => true,
                'bounded_context_efficiency_scored' => true,
                'hard_distractor_ordering_scored' => true,
                'oversized_files_metadata_only' => true,
                'search_space_completeness_scored' => true,
                'runtime_deadline_enforced' => true,
                'partial_results_after_deadline_rejected' => true,
                'llm_calls' => false,
                'network_calls' => false,
                'database_reads' => false,
                'database_writes' => false,
                'raw_task_content_included' => false,
                'source_bodies_included' => false,
                'prompt_compression' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function compactPayload(array $payload): array
    {
        $cases = array_values(array_filter((array) ($payload['cases'] ?? []), 'is_array'));

        return [
            'schema' => $payload['schema'] ?? 'plos.repo_context_map_golden_eval.v1',
            'status' => $payload['status'] ?? 'fail',
            'compact' => true,
            'generated_at' => $payload['generated_at'] ?? null,
            'summary' => $payload['summary'] ?? [],
            'metrics' => $payload['metrics'] ?? [],
            'thresholds' => $payload['thresholds'] ?? [],
            'threshold_checks' => $payload['threshold_checks'] ?? [],
            'failed_cases' => array_values(array_map(
                static fn (array $case): array => [
                    'case' => $case['case'] ?? 'unknown',
                    'violation_codes' => $case['violation_codes'] ?? [],
                ],
                array_filter($cases, static fn (array $case): bool => ! (bool) ($case['passed'] ?? false))
            )),
            'partial_coverage_cases' => array_values(array_map(
                static fn (array $case): array => [
                    'case' => $case['case'] ?? 'unknown',
                    'coverage_status' => $case['coverage_status'] ?? 'unavailable',
                    'coverage_reason_codes' => $case['coverage_reason_codes'] ?? [],
                ],
                array_filter($cases, static fn (array $case): bool => ! (bool) ($case['search_space_complete'] ?? false))
            )),
            'posture' => $payload['posture'] ?? [],
        ];
    }

    /** @param array<string,mixed> $case @return array<string,mixed> */
    private function evaluateCase(string $name, array $case): array
    {
        $task = trim((string) ($case['task'] ?? ''));
        $topK = (int) ($case['top_k'] ?? 10);
        $primary = trim((string) ($case['primary_file'] ?? ''));
        $relevant = $this->validPaths((array) ($case['relevant_files'] ?? []));
        $hardNegatives = $this->validPaths((array) ($case['hard_negative_files'] ?? []));
        $minimumRelevant = (int) ($case['minimum_relevant'] ?? count($relevant));
        if ($task === '' || $topK < 1 || $topK > 20 || $primary === '' || $relevant === []
            || ! in_array($primary, $relevant, true) || $minimumRelevant < 1 || $minimumRelevant > count($relevant)
        ) {
            return $this->invalidCase($name, 'invalid_fixture_contract');
        }

        try {
            $result = $this->repoDev->contextMap($task, '.', $topK);
        } catch (Throwable) {
            return $this->invalidCase($name, 'context_map_execution_failed');
        }

        $rankedPaths = array_values(array_filter(array_map(
            static fn (mixed $file): string => is_array($file) ? (string) ($file['path'] ?? '') : '',
            (array) ($result['files'] ?? [])
        )));
        $ranks = [];
        foreach ($rankedPaths as $index => $path) {
            $ranks[$path] = $index + 1;
        }

        $relevantFound = count(array_intersect($relevant, $rankedPaths));
        $returnedCount = count($rankedPaths);
        $primaryRank = $ranks[$primary] ?? null;
        $hardNegativeAhead = count(array_filter(
            $hardNegatives,
            static fn (string $path): bool => isset($ranks[$path])
                && ($primaryRank === null || $ranks[$path] < $primaryRank)
        ));
        $coverage = is_array($result['coverage'] ?? null) ? $result['coverage'] : [];
        $coverageStatus = (string) ($coverage['status'] ?? 'unavailable');
        $searchSpaceComplete = ($coverage['search_space_complete'] ?? false) === true;
        $coverageReasonCodes = array_values(array_filter(
            (array) ($coverage['reason_codes'] ?? []),
            static fn (mixed $code): bool => is_string($code) && preg_match('/^[a-z0-9_]+$/', $code) === 1
        ));
        $checks = [
            ['code' => 'context_map_schema', 'passed' => ($result['schema'] ?? null) === 'plos.repo_context_map.v1'],
            ['code' => 'primary_file_ranked', 'passed' => $primaryRank !== null],
            ['code' => 'minimum_relevant_recall', 'passed' => $relevantFound >= $minimumRelevant],
            ['code' => 'hard_negative_ordering', 'passed' => $hardNegativeAhead === 0],
            ['code' => 'source_bodies_excluded', 'passed' => ($result['posture']['source_bodies_included'] ?? true) === false],
            ['code' => 'coverage_evidence_reported', 'passed' => in_array($coverageStatus, ['complete', 'degraded', 'incomplete'], true)],
            ['code' => 'runtime_deadline_enforced', 'passed' => ($result['posture']['runtime_deadline_enforced'] ?? false) === true],
            ['code' => 'partial_results_after_deadline_rejected', 'passed' => ($result['posture']['partial_results_returned_after_deadline'] ?? true) === false],
        ];
        $violations = array_values(array_map(
            static fn (array $check): string => $check['code'],
            array_filter($checks, static fn (array $check): bool => ! $check['passed'])
        ));

        return [
            'case' => $this->safeName($name),
            'passed' => $violations === [],
            'top_k' => $topK,
            'relevant_expected' => count($relevant),
            'relevant_found' => $relevantFound,
            'recall_at_k' => round($relevantFound / count($relevant), 4),
            'returned_files' => $returnedCount,
            'irrelevant_returned' => max(0, $returnedCount - $relevantFound),
            'precision_at_k' => round($relevantFound / $topK, 4),
            'context_efficiency' => $returnedCount === 0 ? 0.0 : round($relevantFound / $returnedCount, 4),
            'primary_rank' => $primaryRank,
            'reciprocal_rank' => $primaryRank === null ? 0.0 : round(1 / $primaryRank, 4),
            'hard_negative_ahead_count' => $hardNegativeAhead,
            'coverage_status' => $coverageStatus,
            'search_space_complete' => $searchSpaceComplete,
            'coverage_reason_codes' => $coverageReasonCodes,
            'check_count' => count($checks),
            'violation_codes' => $violations,
        ];
    }

    /** @return list<string> */
    private function validPaths(array $paths): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $path): string => is_string($path) ? trim($path) : '',
            $paths
        ), static fn (string $path): bool => $path !== ''
            && ! str_starts_with($path, '/')
            && preg_match('#(^|/)\.\.(/|$)#', $path) !== 1)));
    }

    /** @return array<string,mixed> */
    private function invalidCase(string $name, string $code): array
    {
        return [
            'case' => $this->safeName($name),
            'passed' => false,
            'top_k' => 0,
            'relevant_expected' => 0,
            'relevant_found' => 0,
            'recall_at_k' => 0.0,
            'returned_files' => 0,
            'irrelevant_returned' => 0,
            'precision_at_k' => 0.0,
            'context_efficiency' => 0.0,
            'primary_rank' => null,
            'reciprocal_rank' => 0.0,
            'hard_negative_ahead_count' => 0,
            'coverage_status' => 'unavailable',
            'search_space_complete' => false,
            'coverage_reason_codes' => ['coverage_unavailable'],
            'check_count' => 1,
            'violation_codes' => [$code],
        ];
    }

    private function safeName(string $name): string
    {
        return preg_match('/^[a-z][a-z0-9_]{1,80}$/', $name) === 1 ? $name : 'invalid_case';
    }
}
