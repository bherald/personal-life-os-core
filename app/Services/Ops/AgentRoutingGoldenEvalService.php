<?php

namespace App\Services\Ops;

use App\Services\AgentRoutingShadowService;

final class AgentRoutingGoldenEvalService
{
    public const SCHEMA = 'plos.agent_routing_golden_eval.v2';

    public function __construct(private readonly AgentRoutingShadowService $selector) {}

    /**
     * @param  array<string,array<string,mixed>>  $agents
     * @param  array<string,array<string,mixed>>|null  $cases
     * @return array<string,mixed>
     */
    public function evaluate(array $agents, ?array $cases = null): array
    {
        $cases ??= array_merge(
            (array) config('agent_routing_eval.cases', []),
            (array) config('agent_routing_eval.challenger_cases', [])
        );
        $results = [];

        foreach ($cases as $name => $case) {
            $results[] = is_array($case)
                ? $this->evaluateCase((string) $name, $case, $agents)
                : $this->invalidCase((string) $name, 'invalid_case_definition');
        }

        $caseCount = count($results);
        $passed = count(array_filter($results, static fn (array $result): bool => $result['passed']));
        $topOneHits = count(array_filter($results, static fn (array $result): bool => $result['expected_rank'] === 1));
        $mrr = $caseCount === 0 ? 0.0 : array_sum(array_column($results, 'reciprocal_rank')) / $caseCount;
        $metrics = [
            'top_1_accuracy' => $caseCount === 0 ? 0.0 : round($topOneHits / $caseCount, 4),
            'mrr' => round($mrr, 4),
            'hard_negative_ahead_count' => array_sum(array_column($results, 'hard_negative_ahead_count')),
        ];
        $thresholds = [
            'minimum_top_1_accuracy' => (float) config('agent_routing_eval.thresholds.minimum_top_1_accuracy', 1.0),
            'minimum_mrr' => (float) config('agent_routing_eval.thresholds.minimum_mrr', 1.0),
            'maximum_hard_negative_ahead_count' => (int) config('agent_routing_eval.thresholds.maximum_hard_negative_ahead_count', 0),
        ];
        $thresholdChecks = [
            'top_1_accuracy' => $metrics['top_1_accuracy'] >= $thresholds['minimum_top_1_accuracy'],
            'mrr' => $metrics['mrr'] >= $thresholds['minimum_mrr'],
            'hard_negative_ahead_count' => $metrics['hard_negative_ahead_count'] <= $thresholds['maximum_hard_negative_ahead_count'],
        ];

        return [
            'schema' => self::SCHEMA,
            'status' => $caseCount > 0 && $passed === $caseCount && ! in_array(false, $thresholdChecks, true)
                ? 'pass'
                : 'fail',
            'generated_at' => now('UTC')->toIso8601String(),
            'summary' => [
                'cases' => $caseCount,
                'passed' => $passed,
                'failed' => $caseCount - $passed,
                'checks' => array_sum(array_column($results, 'check_count')) + count($thresholdChecks),
            ],
            'metrics' => $metrics,
            'thresholds' => $thresholds,
            'threshold_checks' => $thresholdChecks,
            'cases' => $results,
            'posture' => [
                'mode' => 'shadow',
                'production_agent_catalog_exercised' => count(array_filter(
                    $results,
                    static fn (array $result): bool => $result['catalog_source'] === 'production'
                )) > 0,
                'synthetic_challenger_catalog_exercised' => count(array_filter(
                    $results,
                    static fn (array $result): bool => $result['catalog_source'] === 'synthetic_challenger'
                )) > 0,
                'selector_methods_exercised' => array_values(array_unique(array_column($results, 'selector_method'))),
                'changes_routing_authority' => false,
                'raw_task_content_included' => false,
                'llm_calls' => false,
                'network_calls' => false,
                'database_writes' => false,
                'compression' => false,
                'precompaction' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function compactPayload(array $payload): array
    {
        return [
            'schema' => $payload['schema'] ?? self::SCHEMA,
            'status' => $payload['status'] ?? 'fail',
            'compact' => true,
            'generated_at' => $payload['generated_at'] ?? null,
            'summary' => $payload['summary'] ?? [],
            'metrics' => $payload['metrics'] ?? [],
            'thresholds' => $payload['thresholds'] ?? [],
            'threshold_checks' => $payload['threshold_checks'] ?? [],
            'failed_cases' => array_values(array_map(
                static fn (array $case): array => [
                    'case' => $case['case'],
                    'selector_method' => $case['selector_method'],
                    'violation_codes' => $case['violation_codes'],
                ],
                array_filter((array) ($payload['cases'] ?? []), static fn (array $case): bool => ! $case['passed'])
            )),
            'posture' => $payload['posture'] ?? [],
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @param  array<string,array<string,mixed>>  $agents
     * @return array<string,mixed>
     */
    private function evaluateCase(string $name, array $case, array $agents): array
    {
        $query = trim((string) ($case['task'] ?? ''));
        $expectedAgentId = trim((string) ($case['expected_agent_id'] ?? ''));
        $selectorMethod = trim((string) ($case['selector_method'] ?? AgentRoutingShadowService::METHOD_FIELDED_BM25));
        $caseAgents = is_array($case['catalog'] ?? null) ? $case['catalog'] : $agents;
        $catalogSource = is_array($case['catalog'] ?? null) ? 'synthetic_challenger' : 'production';
        $hardNegatives = array_values(array_filter(
            (array) ($case['hard_negative_agent_ids'] ?? []),
            static fn (mixed $id): bool => is_string($id) && $id !== ''
        ));
        $topK = max(1, min(10, (int) ($case['top_k'] ?? 5)));

        if (
            $query === ''
            || $expectedAgentId === ''
            || ! isset($caseAgents[$expectedAgentId])
            || ! in_array($selectorMethod, [
                AgentRoutingShadowService::METHOD_FIELDED_BM25,
                AgentRoutingShadowService::METHOD_CHARACTER_NGRAM,
            ], true)
        ) {
            return $this->invalidCase($name, 'invalid_fixture_contract', $selectorMethod, $catalogSource);
        }

        $selection = $this->selector->selectByMethod($selectorMethod, $query, $caseAgents, null, $topK);
        $ranked = $selection['candidate_agent_ids'];
        $expectedIndex = array_search($expectedAgentId, $ranked, true);
        $expectedRank = $expectedIndex === false ? null : $expectedIndex + 1;
        $hardNegativeAhead = count(array_filter(
            $hardNegatives,
            static function (string $id) use ($ranked, $expectedRank): bool {
                $index = array_search($id, $ranked, true);

                return $index !== false && ($expectedRank === null || $index + 1 < $expectedRank);
            }
        ));
        $checks = [
            ['code' => 'selector_schema', 'passed' => $selection['schema'] === AgentRoutingShadowService::SCHEMA],
            ['code' => 'selector_method', 'passed' => $selection['method'] === $selectorMethod],
            ['code' => 'expected_agent_ranked_first', 'passed' => $expectedRank === 1],
            ['code' => 'hard_negative_ordering', 'passed' => $hardNegativeAhead === 0],
            ['code' => 'raw_task_excluded', 'passed' => $selection['raw_task_included'] === false],
            ['code' => 'routing_authority_unchanged', 'passed' => $selection['changes_routing_authority'] === false],
        ];
        if (($case['baseline_expected_empty'] ?? false) === true) {
            $baseline = $this->selector->selectByMethod(
                AgentRoutingShadowService::METHOD_FIELDED_BM25,
                $query,
                $caseAgents,
                null,
                $topK
            );
            $checks[] = [
                'code' => 'distinct_from_bm25_baseline',
                'passed' => $baseline['candidate_agent_ids'] === [],
            ];
        }
        $violations = array_values(array_column(array_filter(
            $checks,
            static fn (array $check): bool => ! $check['passed']
        ), 'code'));

        return [
            'case' => $this->safeName($name),
            'selector_method' => $selectorMethod,
            'catalog_source' => $catalogSource,
            'passed' => $violations === [],
            'expected_agent_id' => $expectedAgentId,
            'expected_rank' => $expectedRank,
            'reciprocal_rank' => $expectedRank === null ? 0.0 : round(1 / $expectedRank, 4),
            'returned_candidates' => count($ranked),
            'query_term_count' => $selection['query_term_count'],
            'query_truncated' => $selection['query_truncated'],
            'candidate_set_truncated' => $selection['candidate_set_truncated'],
            'hard_negative_ahead_count' => $hardNegativeAhead,
            'check_count' => count($checks),
            'violation_codes' => $violations,
        ];
    }

    /** @return array<string,mixed> */
    private function invalidCase(
        string $name,
        string $code,
        string $selectorMethod = AgentRoutingShadowService::METHOD_FIELDED_BM25,
        string $catalogSource = 'production'
    ): array {
        return [
            'case' => $this->safeName($name),
            'selector_method' => $selectorMethod,
            'catalog_source' => $catalogSource,
            'passed' => false,
            'expected_agent_id' => null,
            'expected_rank' => null,
            'reciprocal_rank' => 0.0,
            'returned_candidates' => 0,
            'query_term_count' => 0,
            'query_truncated' => false,
            'candidate_set_truncated' => false,
            'hard_negative_ahead_count' => 0,
            'check_count' => 1,
            'violation_codes' => [$code],
        ];
    }

    private function safeName(string $name): string
    {
        $safe = preg_replace('/[^a-z0-9_-]+/i', '_', $name);

        return substr((string) $safe, 0, 80);
    }
}
