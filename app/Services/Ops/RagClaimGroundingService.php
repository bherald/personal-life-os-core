<?php

namespace App\Services\Ops;

class RagClaimGroundingService
{
    public const SCHEMA = 'plos.rag_claim_grounding.v1';

    public const CANDIDATE_SCHEMA = 'plos.rag_claim_candidate.v1';

    /**
     * @param  list<array<string,mixed>>  $rankedCases
     * @param  array<string,array<string,mixed>>  $sourceCases
     * @param  array<string,mixed>|null  $candidate
     * @return array<string,mixed>
     */
    public function assess(array $rankedCases, array $sourceCases, ?array $candidate = null): array
    {
        $retrieverThresholds = $this->retrieverThresholds();
        $generatorThresholds = $this->generatorThresholds();
        $rankedByCase = collect($rankedCases)->keyBy('case');
        $caseContracts = [];
        $configuredCases = 0;
        $invalidFixtures = [];
        $claimTotal = 0;
        $claimCovered = 0;
        $contextTotal = 0;
        $contextRelevant = 0;

        foreach ($sourceCases as $caseId => $case) {
            if (! is_array($case) || ! array_key_exists('claims', $case)) {
                continue;
            }

            $configuredCases++;
            $claims = $this->normalizeClaims((array) $case['claims'], $case);
            if ($claims === null) {
                $invalidFixtures[] = $this->safeId((string) $caseId);

                continue;
            }

            $ranked = $rankedByCase->get((string) $caseId);
            $topIds = is_array($ranked) && is_array($ranked['top_ids'] ?? null)
                ? array_values(array_unique(array_map('strval', $ranked['top_ids'])))
                : [];
            $supportIds = array_values(array_unique(array_merge(...array_values($claims))));
            $coveredIds = array_keys(array_filter(
                $claims,
                static fn (array $supports): bool => array_intersect($supports, $topIds) !== [],
            ));
            $relevantTopIds = array_values(array_intersect($topIds, $supportIds));

            $claimTotal += count($claims);
            $claimCovered += count($coveredIds);
            $contextTotal += count($topIds);
            $contextRelevant += count($relevantTopIds);
            $caseContracts[(string) $caseId] = [
                'claims' => $claims,
                'top_ids' => $topIds,
                'relevant_top_ids' => $relevantTopIds,
                'retriever' => [
                    'claim_count' => count($claims),
                    'covered_claim_count' => count($coveredIds),
                    'claim_recall' => $this->ratio(count($coveredIds), count($claims)),
                    'context_precision' => $this->ratio(count($relevantTopIds), count($topIds)),
                    'covered_claim_ids' => $coveredIds,
                    'missing_claim_ids' => array_values(array_diff(array_keys($claims), $coveredIds)),
                ],
            ];
        }

        $configured = $configuredCases > 0;
        $claimRecall = $this->ratio($claimCovered, $claimTotal);
        $contextPrecision = $this->ratio($contextRelevant, $contextTotal);
        $retrieverPassed = $configured
            && $invalidFixtures === []
            && count($caseContracts) === $configuredCases
            && $claimRecall >= $retrieverThresholds['min_claim_recall_at_3']
            && $contextPrecision >= $retrieverThresholds['min_context_precision_at_3'];

        $retriever = [
            'status' => ! $configured ? 'not_configured' : ($retrieverPassed ? 'pass' : 'fail'),
            'configured' => $configured,
            'configured_cases' => $configuredCases,
            'evaluated_cases' => count($caseContracts),
            'invalid_fixture_ids' => $invalidFixtures,
            'metrics' => [
                'claim_recall_at_3' => $claimRecall,
                'context_precision_at_3' => $contextPrecision,
            ],
            'thresholds' => $retrieverThresholds,
            'passed' => ! $configured || $retrieverPassed,
        ];

        return [
            'schema' => self::SCHEMA,
            'candidate_schema' => self::CANDIDATE_SCHEMA,
            'retriever' => $retriever,
            'generator' => $this->assessGenerator($candidate, $caseContracts, $generatorThresholds, $retrieverPassed),
            'cases' => array_map(static fn (array $contract): array => $contract['retriever'], $caseContracts),
            'posture' => [
                'read_only' => true,
                'deterministic' => true,
                'llm_judge_used' => false,
                'network_used' => false,
                'fixture_text_included' => false,
                'response_text_accepted' => false,
                'claim_and_document_ids_only' => true,
                'production_vectors_changed' => false,
                'model_or_route_promotion_applied' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $candidate
     * @param  array<string,array<string,mixed>>  $caseContracts
     * @param  array<string,float>  $thresholds
     * @return array<string,mixed>
     */
    private function assessGenerator(?array $candidate, array $caseContracts, array $thresholds, bool $retrieverPassed): array
    {
        if ($candidate === null) {
            return [
                'status' => 'not_evaluated',
                'candidate_valid' => null,
                'metrics' => null,
                'thresholds' => $thresholds,
                'generation_ready' => false,
                'invalid_reasons' => [],
            ];
        }

        $candidateCases = is_array($candidate['cases'] ?? null) ? $candidate['cases'] : null;
        $invalidReasons = [];
        if (($candidate['schema'] ?? null) !== self::CANDIDATE_SCHEMA) {
            $invalidReasons[] = 'candidate_schema_mismatch';
        }
        if ($candidateCases === null) {
            $invalidReasons[] = 'candidate_cases_missing';
            $candidateCases = [];
        }

        $unknownCaseIds = array_values(array_diff(array_keys($candidateCases), array_keys($caseContracts)));
        if ($unknownCaseIds !== []) {
            $invalidReasons[] = 'unknown_candidate_cases';
        }

        $expectedClaims = 0;
        $knownClaims = 0;
        $responseClaims = 0;
        $faithfulClaims = 0;
        $unknownClaims = 0;
        $unsupportedCitations = 0;
        $relevantContextIds = [];
        $utilizedContextIds = [];

        foreach ($caseContracts as $caseId => $contract) {
            $claims = $contract['claims'];
            $topIds = $contract['top_ids'];
            $relevantTopIds = $contract['relevant_top_ids'];
            $expectedClaims += count($claims);
            foreach ($relevantTopIds as $id) {
                $relevantContextIds[$caseId.'|'.$id] = true;
            }

            $entries = $candidateCases[$caseId] ?? [];
            if (! is_array($entries) || ! array_is_list($entries)) {
                $invalidReasons[] = 'invalid_candidate_case:'.$this->safeId((string) $caseId);

                continue;
            }

            $seen = [];
            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    $invalidReasons[] = 'invalid_candidate_claim:'.$this->safeId((string) $caseId);

                    continue;
                }

                $claimId = $this->safeId((string) ($entry['claim_id'] ?? ''));
                $citedIds = $this->normalizeIdList($entry['support_ids'] ?? null);
                if ($claimId === 'invalid' || in_array('invalid', $citedIds, true) || isset($seen[$claimId])) {
                    $invalidReasons[] = 'invalid_or_duplicate_claim:'.$this->safeId((string) $caseId);

                    continue;
                }
                $seen[$claimId] = true;
                $responseClaims++;

                if (! isset($claims[$claimId])) {
                    $unknownClaims++;

                    continue;
                }

                $knownClaims++;
                $allowedCitations = array_values(array_intersect($claims[$claimId], $topIds));
                $faithful = $citedIds !== [] && array_diff($citedIds, $allowedCitations) === [];
                if (! $faithful) {
                    $unsupportedCitations++;

                    continue;
                }

                $faithfulClaims++;
                foreach ($citedIds as $id) {
                    $utilizedContextIds[$caseId.'|'.$id] = true;
                }
            }
        }

        $candidateValid = $invalidReasons === [];
        $precision = $this->ratio($knownClaims, $responseClaims);
        $recall = $this->ratio($knownClaims, $expectedClaims);
        $f1 = ($precision + $recall) > 0 ? round((2 * $precision * $recall) / ($precision + $recall), 4) : 0.0;
        $metrics = [
            'claim_precision' => $precision,
            'claim_recall' => $recall,
            'claim_f1' => $f1,
            'faithfulness' => $this->ratio($faithfulClaims, $responseClaims),
            'hallucination_rate' => $this->ratio($unknownClaims, $responseClaims),
            'unsupported_citation_rate' => $this->ratio($unsupportedCitations, $responseClaims),
            'context_utilization' => $this->ratio(count($utilizedContextIds), count($relevantContextIds)),
        ];
        $generationReady = $candidateValid
            && $retrieverPassed
            && $metrics['claim_precision'] >= $thresholds['min_claim_precision']
            && $metrics['claim_recall'] >= $thresholds['min_claim_recall']
            && $metrics['faithfulness'] >= $thresholds['min_faithfulness']
            && $metrics['hallucination_rate'] <= $thresholds['max_hallucination_rate']
            && $metrics['unsupported_citation_rate'] <= $thresholds['max_unsupported_citation_rate']
            && $metrics['context_utilization'] >= $thresholds['min_context_utilization'];

        return [
            'status' => $generationReady ? 'pass' : 'fail',
            'candidate_valid' => $candidateValid,
            'metrics' => $metrics,
            'thresholds' => $thresholds,
            'generation_ready' => $generationReady,
            'invalid_reasons' => array_values(array_unique($invalidReasons)),
            'counts' => [
                'expected_claims' => $expectedClaims,
                'response_claims' => $responseClaims,
                'known_claims' => $knownClaims,
                'faithful_claims' => $faithfulClaims,
                'unknown_claims' => $unknownClaims,
                'unsupported_citations' => $unsupportedCitations,
            ],
        ];
    }

    /** @param array<string,mixed> $case @return array<string,list<string>>|null */
    private function normalizeClaims(array $claims, array $case): ?array
    {
        $documents = is_array($case['documents'] ?? null) ? $case['documents'] : [];
        $documentIds = array_values(array_map(
            static fn (mixed $document): string => is_array($document) ? (string) ($document['id'] ?? '') : '',
            $documents,
        ));
        $relevance = is_array($case['relevance'] ?? null) ? $case['relevance'] : [];
        $normalized = [];

        foreach ($claims as $claimId => $supportIds) {
            $safeClaimId = $this->safeId((string) $claimId);
            $supports = $this->normalizeIdList($supportIds);
            if ($safeClaimId === 'invalid'
                || $supports === []
                || in_array('invalid', $supports, true)
                || array_diff($supports, $documentIds) !== []
                || array_filter($supports, static fn (string $id): bool => (float) ($relevance[$id] ?? 0) <= 0) !== []) {
                return null;
            }
            $normalized[$safeClaimId] = $supports;
        }

        return $normalized === [] ? null : $normalized;
    }

    /** @return array<string,float> */
    private function retrieverThresholds(): array
    {
        return [
            'min_claim_recall_at_3' => $this->clamp(config('rag_rerank_eval.grounding.retriever.min_claim_recall_at_3', 0.75)),
            'min_context_precision_at_3' => $this->clamp(config('rag_rerank_eval.grounding.retriever.min_context_precision_at_3', 0.5)),
        ];
    }

    /** @return array<string,float> */
    private function generatorThresholds(): array
    {
        return [
            'min_claim_precision' => $this->clamp(config('rag_rerank_eval.grounding.generator.min_claim_precision', 1.0)),
            'min_claim_recall' => $this->clamp(config('rag_rerank_eval.grounding.generator.min_claim_recall', 1.0)),
            'min_faithfulness' => $this->clamp(config('rag_rerank_eval.grounding.generator.min_faithfulness', 1.0)),
            'max_hallucination_rate' => $this->clamp(config('rag_rerank_eval.grounding.generator.max_hallucination_rate', 0.0)),
            'max_unsupported_citation_rate' => $this->clamp(config('rag_rerank_eval.grounding.generator.max_unsupported_citation_rate', 0.0)),
            'min_context_utilization' => $this->clamp(config('rag_rerank_eval.grounding.generator.min_context_utilization', 0.75)),
        ];
    }

    private function ratio(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator, 4) : 0.0;
    }

    private function clamp(mixed $value): float
    {
        return max(0.0, min(1.0, (float) $value));
    }

    private function safeId(string $id): string
    {
        return preg_match('/^[a-z][a-z0-9_]{1,100}$/', $id) === 1 ? $id : 'invalid';
    }

    /** @return list<string> */
    private function normalizeIdList(mixed $ids): array
    {
        if (! is_array($ids) || ! array_is_list($ids)) {
            return [];
        }

        return array_values(array_unique(array_map(
            fn (mixed $id): string => is_string($id) ? $this->safeId($id) : 'invalid',
            $ids,
        )));
    }
}
