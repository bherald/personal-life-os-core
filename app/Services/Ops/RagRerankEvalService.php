<?php

namespace App\Services\Ops;

use App\Services\OfflineRerankService;
use App\Services\RerankerSidecarService;

class RagRerankEvalService
{
    public function __construct(
        private readonly OfflineRerankService $reranker,
        private readonly RerankerSidecarService $sidecar,
        private readonly ?RagClaimGroundingService $grounding = null,
    ) {}

    /**
     * @param  array<string,array<string,mixed>>|null  $cases
     * @param  array<string,float|int>|null  $thresholds
     * @return array<string,mixed>
     */
    public function evaluate(
        bool $useEmbeddings = false,
        ?array $cases = null,
        ?array $thresholds = null,
        bool $useRerankerSidecar = false,
        ?array $claimCandidate = null,
    ): array {
        $cases ??= (array) config('rag_rerank_eval.cases', []);
        $thresholds = $this->thresholds($thresholds ?? (array) config('rag_rerank_eval.thresholds', []));
        $promotionThresholds = $this->thresholds((array) config('rag_rerank_eval.promotion_thresholds', []));
        $queryInstruction = trim((string) config(
            $useRerankerSidecar
                ? 'rag_rerank_eval.cross_encoder_instruction'
                : 'rag_rerank_eval.embedding_query_instruction',
            '',
        ));
        $results = [];

        foreach ($cases as $name => $case) {
            if (! is_array($case)) {
                $results[] = $this->invalidCase((string) $name);

                continue;
            }

            $results[] = $this->evaluateCase(
                (string) $name,
                $case,
                $useEmbeddings,
                $queryInstruction,
                $useRerankerSidecar,
            );
        }

        $valid = array_values(array_filter($results, static fn (array $case): bool => $case['rank'] !== null));
        $caseCount = count($results);
        $mrr = $caseCount > 0 ? array_sum(array_column($results, 'reciprocal_rank')) / $caseCount : 0.0;
        $hitAtOne = $caseCount > 0 ? array_sum(array_column($results, 'hit_at_1')) / $caseCount : 0.0;
        $ndcgAtThree = $caseCount > 0 ? array_sum(array_column($results, 'ndcg_at_3')) / $caseCount : 0.0;
        $recallAtThree = $caseCount > 0 ? array_sum(array_column($results, 'recall_at_3')) / $caseCount : 0.0;
        $precisionAtThree = $caseCount > 0 ? array_sum(array_column($results, 'precision_at_3')) / $caseCount : 0.0;
        $mapAtThree = $caseCount > 0 ? array_sum(array_column($results, 'average_precision_at_3')) / $caseCount : 0.0;
        $hardNegativeAtOne = array_sum(array_column($results, 'hard_negative_at_1'));
        $providerPasses = count(array_filter(
            $results,
            static fn (array $case): bool => ($case['provider_status'] ?? null) === 'pass'
        ));
        $candidateMode = $useEmbeddings || $useRerankerSidecar;
        $metrics = [
            'mrr' => round($mrr, 4),
            'hit_at_1' => round($hitAtOne, 4),
            'ndcg_at_3' => round($ndcgAtThree, 4),
            'recall_at_3' => round($recallAtThree, 4),
            'precision_at_3' => round($precisionAtThree, 4),
            'map_at_3' => round($mapAtThree, 4),
            'hard_negative_at_1_rate' => $caseCount > 0 ? round($hardNegativeAtOne / $caseCount, 4) : 0.0,
        ];
        $grounding = ($this->grounding ?? new RagClaimGroundingService)->assess($results, $cases, $claimCandidate);
        $groundingPassed = (bool) data_get($grounding, 'retriever.passed', false);
        $groundingConfigured = (bool) data_get($grounding, 'retriever.configured', false);
        $rankingPassed = $caseCount > 0
            && count($valid) === $caseCount
            && $this->metricsMeetThresholds($metrics, $thresholds);
        $providerPassed = ! $candidateMode || $providerPasses === $caseCount;
        $passed = $rankingPassed && $providerPassed && $groundingPassed;
        $promotionReady = $candidateMode
            && $providerPassed
            && $groundingPassed
            && $groundingConfigured
            && count($valid) === $caseCount
            && $this->metricsMeetThresholds($metrics, $promotionThresholds);

        return [
            'schema' => (string) config('rag_rerank_eval.schema', 'plos.rag_rerank_eval.v2'),
            'status' => $passed ? 'pass' : 'fail',
            'mode' => $useRerankerSidecar
                ? 'cross_encoder_candidate'
                : ($useEmbeddings ? 'embedding_candidate' : 'lexical_baseline'),
            'generated_at' => now('UTC')->toIso8601String(),
            'metrics' => $metrics,
            'thresholds' => $thresholds,
            'promotion_thresholds' => $promotionThresholds,
            'summary' => [
                'cases' => $caseCount,
                'ranked_cases' => count($valid),
                'ranking_passed' => $rankingPassed,
                'provider_passed' => $providerPassed,
                'grounding_retriever_passed' => $groundingPassed,
                'grounding_retriever_configured' => $groundingConfigured,
                'promotion_ready' => $promotionReady,
                'provider_cases_passed' => $providerPasses,
                'embedding_cases_passed' => $useEmbeddings ? $providerPasses : 0,
                'provider_name' => $this->singleResultValue($results, 'provider_name'),
                'provider_model' => $this->singleResultValue($results, 'provider_model'),
                'provider_revision' => $this->singleResultValue($results, 'provider_revision'),
                'provider_device' => $this->singleResultValue($results, 'provider_device'),
                'provider_identity_sha256' => $this->singleResultValue($results, 'provider_identity_sha256'),
                'provider_dimension' => ($dimension = $this->singleResultValue($results, 'provider_dimension')) !== null
                    ? (int) $dimension
                    : null,
                'hard_negative_at_1' => $hardNegativeAtOne,
                'duration_ms' => array_sum(array_column($results, 'duration_ms')),
            ],
            'cases' => $results,
            'grounding' => $grounding,
            'posture' => [
                'read_only' => true,
                'fixture_text_included' => false,
                'query_text_included' => false,
                'query_instruction_included' => false,
                'query_instruction_applied' => $candidateMode && $queryInstruction !== '',
                'reranker_sidecar_used' => $useRerankerSidecar,
                'embedding_space_identity_required' => true,
                'production_vectors_changed' => false,
                'model_promotion_allowed' => false,
            ],
        ];
    }

    /** @param list<array<string,mixed>> $results */
    private function singleResultValue(array $results, string $key): ?string
    {
        $values = array_values(array_unique(array_filter(array_map(
            static fn (array $result): string => trim((string) ($result[$key] ?? '')),
            $results,
        ))));

        return count($values) === 1 ? $values[0] : null;
    }

    /** @param array<string,float> $metrics @param array<string,float> $thresholds */
    private function metricsMeetThresholds(array $metrics, array $thresholds): bool
    {
        return $metrics['mrr'] >= $thresholds['min_mrr']
            && $metrics['hit_at_1'] >= $thresholds['min_hit_at_1']
            && $metrics['ndcg_at_3'] >= $thresholds['min_ndcg_at_3']
            && $metrics['recall_at_3'] >= $thresholds['min_recall_at_3']
            && $metrics['precision_at_3'] >= $thresholds['min_precision_at_3']
            && $metrics['map_at_3'] >= $thresholds['min_map_at_3']
            && $metrics['hard_negative_at_1_rate'] <= $thresholds['max_hard_negative_at_1_rate'];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function evaluateCase(
        string $name,
        array $case,
        bool $useEmbeddings,
        string $queryInstruction,
        bool $useRerankerSidecar,
    ): array {
        $query = trim((string) ($case['query'] ?? ''));
        $documents = is_array($case['documents'] ?? null) ? array_values($case['documents']) : [];
        $relevance = is_array($case['relevance'] ?? null) ? $case['relevance'] : [];
        $hardNegatives = is_array($case['hard_negative_ids'] ?? null)
            ? array_values(array_unique(array_map('strval', $case['hard_negative_ids'])))
            : [];
        if (! $this->caseIsValid($query, $documents, $relevance, $hardNegatives)) {
            return $this->invalidCase($name);
        }

        $started = microtime(true);
        $result = $useRerankerSidecar
            ? $this->sidecar->rerank($query, $documents, $queryInstruction)
            : $this->reranker->rerank($query, $documents, [
                'limit' => count($documents),
                'use_embeddings' => $useEmbeddings,
                'query_instruction' => $queryInstruction,
            ]);
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $rankedIds = array_values(array_map('strval', array_column((array) ($result['results'] ?? []), 'id')));
        $rank = $this->firstRelevantRank($rankedIds, $relevance);

        return [
            'case' => $this->safeName($name),
            'query_sha256' => hash('sha256', $query),
            'rank' => $rank,
            'reciprocal_rank' => $rank === null ? 0.0 : round(1 / $rank, 4),
            'hit_at_1' => $rank === 1 ? 1 : 0,
            'ndcg_at_3' => round($this->ndcg($rankedIds, $relevance, 3), 4),
            'recall_at_3' => round($this->recallAtK($rankedIds, $relevance, 3), 4),
            'precision_at_3' => round($this->precisionAtK($rankedIds, $relevance, 3), 4),
            'average_precision_at_3' => round($this->averagePrecisionAtK($rankedIds, $relevance, 3), 4),
            'hard_negative_at_1' => in_array($rankedIds[0] ?? '', $hardNegatives, true) ? 1 : 0,
            'relevant_count' => count(array_filter($relevance, static fn (mixed $grade): bool => (float) $grade > 0)),
            'top_ids' => array_slice($rankedIds, 0, 3),
            'rerank_mode' => (string) ($result['mode'] ?? 'none'),
            'embedding_status' => (string) ($result['embedding_status'] ?? 'unknown'),
            'provider_status' => $useRerankerSidecar
                ? (string) ($result['status'] ?? 'unknown')
                : ($useEmbeddings ? (string) ($result['embedding_status'] ?? 'unknown') : 'not_required'),
            'provider_name' => (string) ($useRerankerSidecar ? 'reranker_sidecar' : ($result['embedding_provider'] ?? '')),
            'provider_model' => (string) ($useRerankerSidecar ? ($result['model'] ?? '') : ($result['embedding_model'] ?? '')),
            'provider_revision' => (string) ($useRerankerSidecar ? ($result['revision'] ?? '') : ($result['embedding_revision'] ?? '')),
            'provider_device' => (string) ($result['device'] ?? ''),
            'provider_identity_sha256' => (string) ($result['embedding_identity_sha256'] ?? ''),
            'provider_dimension' => isset($result['embedding_dimension']) ? (int) $result['embedding_dimension'] : null,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $documents
     * @param  array<string,mixed>  $relevance
     * @param  list<string>  $hardNegatives
     */
    private function caseIsValid(string $query, array $documents, array $relevance, array $hardNegatives): bool
    {
        if ($query === '' || $documents === [] || $relevance === []) {
            return false;
        }

        $documentIds = array_values(array_filter(array_map(
            static fn (mixed $document): string => is_array($document) ? trim((string) ($document['id'] ?? '')) : '',
            $documents,
        )));
        $relevantIds = array_keys(array_filter($relevance, static fn (mixed $grade): bool => (float) $grade > 0));

        return count($documentIds) === count($documents)
            && count(array_unique($documentIds)) === count($documentIds)
            && array_diff($relevantIds, $documentIds) === []
            && array_diff($hardNegatives, $documentIds) === []
            && array_intersect($relevantIds, $hardNegatives) === [];
    }

    /**
     * @param  list<string>  $rankedIds
     * @param  array<string,mixed>  $relevance
     */
    private function firstRelevantRank(array $rankedIds, array $relevance): ?int
    {
        foreach ($rankedIds as $index => $id) {
            if ((float) ($relevance[$id] ?? 0) > 0) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $rankedIds
     * @param  array<string,mixed>  $relevance
     */
    private function ndcg(array $rankedIds, array $relevance, int $limit): float
    {
        $dcg = 0.0;
        foreach (array_slice($rankedIds, 0, $limit) as $index => $id) {
            $grade = max(0.0, (float) ($relevance[$id] ?? 0));
            $dcg += ((2 ** $grade) - 1) / log($index + 2, 2);
        }

        $ideal = array_values(array_map(static fn (mixed $grade): float => max(0.0, (float) $grade), $relevance));
        rsort($ideal, SORT_NUMERIC);
        $idcg = 0.0;
        foreach (array_slice($ideal, 0, $limit) as $index => $grade) {
            $idcg += ((2 ** $grade) - 1) / log($index + 2, 2);
        }

        return $idcg > 0 ? $dcg / $idcg : 0.0;
    }

    /** @param list<string> $rankedIds @param array<string,mixed> $relevance */
    private function recallAtK(array $rankedIds, array $relevance, int $limit): float
    {
        $relevant = array_keys(array_filter($relevance, static fn (mixed $grade): bool => (float) $grade > 0));
        if ($relevant === []) {
            return 0.0;
        }

        return count(array_intersect(array_slice($rankedIds, 0, $limit), $relevant)) / count($relevant);
    }

    /** @param list<string> $rankedIds @param array<string,mixed> $relevance */
    private function precisionAtK(array $rankedIds, array $relevance, int $limit): float
    {
        $top = array_slice($rankedIds, 0, $limit);
        if ($top === []) {
            return 0.0;
        }

        $hits = count(array_filter($top, static fn (string $id): bool => (float) ($relevance[$id] ?? 0) > 0));

        return $hits / $limit;
    }

    /** @param list<string> $rankedIds @param array<string,mixed> $relevance */
    private function averagePrecisionAtK(array $rankedIds, array $relevance, int $limit): float
    {
        $relevantCount = count(array_filter($relevance, static fn (mixed $grade): bool => (float) $grade > 0));
        if ($relevantCount === 0) {
            return 0.0;
        }

        $hits = 0;
        $precisionSum = 0.0;
        foreach (array_slice($rankedIds, 0, $limit) as $index => $id) {
            if ((float) ($relevance[$id] ?? 0) <= 0) {
                continue;
            }
            $hits++;
            $precisionSum += $hits / ($index + 1);
        }

        return $precisionSum / min($relevantCount, $limit);
    }

    /** @return array<string,float> */
    private function thresholds(array $thresholds): array
    {
        return [
            'min_mrr' => max(0.0, min(1.0, (float) ($thresholds['min_mrr'] ?? 1.0))),
            'min_hit_at_1' => max(0.0, min(1.0, (float) ($thresholds['min_hit_at_1'] ?? 1.0))),
            'min_ndcg_at_3' => max(0.0, min(1.0, (float) ($thresholds['min_ndcg_at_3'] ?? 1.0))),
            'min_recall_at_3' => max(0.0, min(1.0, (float) ($thresholds['min_recall_at_3'] ?? 1.0))),
            'min_precision_at_3' => max(0.0, min(1.0, (float) ($thresholds['min_precision_at_3'] ?? 0.0))),
            'min_map_at_3' => max(0.0, min(1.0, (float) ($thresholds['min_map_at_3'] ?? 1.0))),
            'max_hard_negative_at_1_rate' => max(0.0, min(1.0, (float) ($thresholds['max_hard_negative_at_1_rate'] ?? 0.0))),
        ];
    }

    /** @return array<string,mixed> */
    private function invalidCase(string $name): array
    {
        return [
            'case' => $this->safeName($name),
            'query_sha256' => null,
            'rank' => null,
            'reciprocal_rank' => 0.0,
            'hit_at_1' => 0,
            'ndcg_at_3' => 0.0,
            'recall_at_3' => 0.0,
            'precision_at_3' => 0.0,
            'average_precision_at_3' => 0.0,
            'hard_negative_at_1' => 0,
            'relevant_count' => 0,
            'top_ids' => [],
            'rerank_mode' => 'invalid',
            'embedding_status' => 'skipped',
            'provider_status' => 'skipped',
            'provider_name' => '',
            'provider_model' => '',
            'provider_revision' => '',
            'provider_device' => '',
            'provider_identity_sha256' => '',
            'provider_dimension' => null,
            'duration_ms' => 0,
        ];
    }

    private function safeName(string $name): string
    {
        return preg_match('/^[a-z][a-z0-9_]{1,80}$/', $name) === 1 ? $name : 'invalid_case';
    }
}
