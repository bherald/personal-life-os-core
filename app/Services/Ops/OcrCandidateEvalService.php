<?php

namespace App\Services\Ops;

use Normalizer;

final class OcrCandidateEvalService
{
    public const ARTIFACT_SCHEMA = 'plos.ocr_candidate.v3';

    public const RESULT_SCHEMA = 'plos.ocr_candidate_eval.v3';

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public function evaluate(array $artifact): array
    {
        $violations = [];
        $this->validateEnvelope($artifact, $violations);
        $baselineIdentity = $this->identity('baseline', $artifact['baseline'] ?? null, $violations);
        $candidateIdentity = $this->identity('candidate', $artifact['candidate'] ?? null, $violations);
        $cases = $this->evaluateCases($artifact['cases'] ?? null, $violations);
        $thresholds = $this->thresholds();

        $caseIds = array_column($cases, 'case');
        if (count($caseIds) !== count(array_unique($caseIds))) {
            $violations[] = 'duplicate_case_id';
        }
        if (count($cases) < $thresholds['min_cases']) {
            $violations[] = 'insufficient_cases';
        }
        if (count(array_unique(array_column($cases, 'category'))) < $thresholds['min_categories']) {
            $violations[] = 'insufficient_categories';
        }
        $coveredCapabilities = [];
        foreach ($cases as $case) {
            $coveredCapabilities = array_merge($coveredCapabilities, (array) ($case['capabilities'] ?? []));
        }
        $coveredCapabilities = array_values(array_filter(
            $this->requiredCapabilities(),
            static fn (string $capability): bool => in_array($capability, $coveredCapabilities, true),
        ));
        $missingCapabilities = [];
        foreach ($this->requiredCapabilities() as $capability) {
            if (! in_array($capability, $coveredCapabilities, true)) {
                $violations[] = 'missing_capability:'.$capability;
                $missingCapabilities[] = $capability;
            }
        }
        if (! in_array(true, array_column($cases, 'blank_case'), true)) {
            $violations[] = 'blank_case_required';
        }
        if (! in_array(true, array_column($cases, 'multi_page_case'), true)) {
            $violations[] = 'multi_page_case_required';
        }
        if (! in_array(true, array_column($cases, 'reading_order_case'), true)) {
            $violations[] = 'reading_order_case_required';
        }
        if (($artifact['operator_review_required'] ?? null) !== true) {
            $violations[] = 'operator_review_not_required_by_artifact';
        }

        $baseline = $this->aggregate($cases, 'baseline');
        $candidate = $this->aggregate($cases, 'candidate');
        $baseline['capabilities'] = $this->capabilityMetrics($cases, 'baseline');
        $candidate['capabilities'] = $this->capabilityMetrics($cases, 'candidate');
        $deltas = $this->qualityDeltas($baseline, $candidate);
        $regressions = array_values(array_unique(array_merge(...array_map(
            static fn (array $case): array => $case['regressions'],
            $cases,
        ))));
        $violations = array_values(array_unique($violations));
        $caseQualityFailures = $this->caseQualityFailures($cases, $thresholds);
        $qualityPassed = $this->qualityPassed($candidate, $thresholds);
        $improved = $deltas['normalized_edit_similarity'] >= $thresholds['min_edit_similarity_improvement']
            || $deltas['unit_test_pass_rate'] >= $thresholds['min_unit_test_improvement']
            || $deltas['token_precision'] >= $thresholds['min_token_precision_improvement']
            || $deltas['reading_order_pass_rate'] >= $thresholds['min_reading_order_improvement'];
        $eligible = $violations === []
            && $regressions === []
            && $caseQualityFailures === []
            && $qualityPassed
            && $improved;

        return [
            'schema' => self::RESULT_SCHEMA,
            'status' => $eligible ? 'eligible_for_review' : ($violations === [] ? 'not_eligible' : 'invalid'),
            'read_only' => true,
            'generated_at' => now('UTC')->toIso8601String(),
            'candidate_id' => $this->safeString($artifact['candidate_id'] ?? null),
            'baseline_identity' => $baselineIdentity,
            'candidate_identity' => $candidateIdentity,
            'baseline' => $baseline,
            'candidate' => $candidate,
            'deltas' => $deltas,
            'thresholds' => $thresholds,
            'capability_coverage' => [
                'required' => $this->requiredCapabilities(),
                'covered' => $coveredCapabilities,
                'missing' => $missingCapabilities,
            ],
            'regressions' => $regressions,
            'case_quality_failures' => $caseQualityFailures,
            'violations' => $violations,
            'cases' => $cases,
            'promotion' => [
                'eligible_for_operator_review' => $eligible,
                'quality_thresholds_passed' => $qualityPassed,
                'case_quality_floors_passed' => $caseQualityFailures === [],
                'required_capabilities_covered' => $missingCapabilities === [],
                'measurable_improvement' => $improved,
                'autonomous_route_change_allowed' => false,
                'operator_review_required' => true,
            ],
            'posture' => [
                'deterministic' => true,
                'artifact_text_in_output' => false,
                'case_outputs_are_hashes_and_metrics_only' => true,
                'database_reads' => false,
                'database_writes' => false,
                'network_calls' => false,
                'llm_calls' => false,
                'ocr_runtime_calls' => false,
                'reading_order_checks' => true,
                'heterogeneous_capability_checks' => true,
                'absolute_per_case_quality_floors' => true,
                'production_ocr_route_changed' => false,
                'prompt_compression_changed' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function exampleArtifact(): array
    {
        return [
            'schema' => self::ARTIFACT_SCHEMA,
            'candidate_id' => 'synthetic-ocr-candidate-v3',
            'baseline' => [
                'engine' => 'synthetic-baseline',
                'version' => '1.0.0',
                'revision' => hash('sha256', 'synthetic-baseline'),
                'device' => 'cpu',
            ],
            'candidate' => [
                'engine' => 'synthetic-candidate',
                'version' => '2.0.0',
                'revision' => hash('sha256', 'synthetic-candidate'),
                'device' => 'gpu',
            ],
            'operator_review_required' => true,
            'cases' => [
                $this->exampleCase(
                    'old_register',
                    'old_scan',
                    ['recognition'],
                    'Mary Carter was born 12 March 1890 in Pine Township.',
                    'Mary Carler was born 12 March 1890 in Pine Township.',
                    'Mary Carter was born 12 March 1890 in Pine Township.',
                    ['Mary Carter', '12 March 1890'],
                    ['Mary Carler'],
                ),
                $this->exampleCase(
                    'household_table',
                    'table',
                    ['structure', 'spotting'],
                    "Name | Age\nJohn Miller | 42\nAnna Miller | 39",
                    "Name | Age\nJohn Miller | 24\nAnna Miller | 39",
                    "Name | Age\nJohn Miller | 42\nAnna Miller | 39",
                    ['John Miller | 42', 'Anna Miller | 39'],
                    orderPairs: [
                        ['before' => 'John Miller | 42', 'after' => 'Anna Miller | 39'],
                    ],
                ),
                $this->exampleCase(
                    'newspaper_columns',
                    'multi_column',
                    ['structure'],
                    'First article concludes here. Second article begins here.',
                    'Second article begins here. First article concludes here.',
                    'First article concludes here. Second article begins here.',
                    ['First article concludes here', 'Second article begins here'],
                    orderPairs: [
                        ['before' => 'First article concludes here', 'after' => 'Second article begins here'],
                    ],
                ),
                $this->exampleCase(
                    'estate_pages',
                    'long_document',
                    ['multi_page', 'recognition'],
                    'Page one names the executor. Page two lists the heirs.',
                    'Page one names the executor.',
                    'Page one names the executor. Page two lists the heirs.',
                    ['names the executor', 'lists the heirs'],
                    [],
                    expectedPages: 2,
                    baselinePages: 1,
                    candidatePages: 2,
                ),
                $this->exampleCase(
                    'blank_back',
                    'blank',
                    ['blank'],
                    '',
                    'Untitled document',
                    '',
                    [],
                    ['Untitled document'],
                ),
                $this->exampleCase(
                    'handwritten_note',
                    'handwriting',
                    ['handwriting', 'recognition'],
                    'Received from Jacob Heller the sum of twelve dollars.',
                    'Received from Jacob Heiler the sum of twelve dollars.',
                    'Received from Jacob Heller the sum of twelve dollars.',
                    ['Jacob Heller', 'twelve dollars'],
                ),
                $this->exampleCase(
                    'ledger_reasoning',
                    'reasoning',
                    ['reasoning'],
                    'Three shares at 15 dollars total 45 dollars.',
                    'Three shares at 15 dollars total 35 dollars.',
                    'Three shares at 15 dollars total 45 dollars.',
                    ['Three shares', '45 dollars'],
                    ['35 dollars'],
                ),
            ],
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function compactPayload(array $payload): array
    {
        return [
            'schema' => $payload['schema'] ?? self::RESULT_SCHEMA,
            'status' => $payload['status'] ?? 'invalid',
            'compact' => true,
            'read_only' => true,
            'generated_at' => $payload['generated_at'] ?? null,
            'candidate_id' => $payload['candidate_id'] ?? null,
            'baseline_identity' => $payload['baseline_identity'] ?? [],
            'candidate_identity' => $payload['candidate_identity'] ?? [],
            'baseline' => $this->compactAggregate((array) ($payload['baseline'] ?? [])),
            'candidate' => $this->compactAggregate((array) ($payload['candidate'] ?? [])),
            'deltas' => $payload['deltas'] ?? [],
            'thresholds' => $payload['thresholds'] ?? [],
            'capability_coverage' => $payload['capability_coverage'] ?? [],
            'regressions' => $payload['regressions'] ?? [],
            'case_quality_failures' => $payload['case_quality_failures'] ?? [],
            'violations' => $payload['violations'] ?? [],
            'promotion' => $payload['promotion'] ?? [],
            'posture' => $payload['posture'] ?? [],
            'source' => $payload['source'] ?? null,
        ];
    }

    /** @param array<string, mixed> $aggregate @return array<string, mixed> */
    private function compactAggregate(array $aggregate): array
    {
        unset($aggregate['capabilities']);

        return $aggregate;
    }

    /** @param list<string> $violations @param-out list<string> $violations */
    private function validateEnvelope(array $artifact, array &$violations): void
    {
        if ($this->unknownKeys($artifact, [
            'schema', 'candidate_id', 'baseline', 'candidate', 'operator_review_required', 'cases',
        ]) !== []) {
            $violations[] = 'unknown_artifact_field';
        }
        if (($artifact['schema'] ?? null) !== self::ARTIFACT_SCHEMA) {
            $violations[] = 'invalid_artifact_schema';
        }
        if (! $this->validIdentifier($artifact['candidate_id'] ?? null, 160)) {
            $violations[] = 'invalid_candidate_id';
        }
    }

    /** @param list<string> $violations @param-out list<string> $violations @return array<string, string|null> */
    private function identity(string $label, mixed $raw, array &$violations): array
    {
        if (! is_array($raw) || array_is_list($raw)) {
            $violations[] = "invalid_{$label}_identity";

            return ['engine' => null, 'version' => null, 'revision' => null, 'device' => null];
        }
        if ($this->unknownKeys($raw, ['engine', 'version', 'revision', 'device']) !== []) {
            $violations[] = "unknown_{$label}_identity_field";
        }
        foreach (['engine', 'version', 'revision', 'device'] as $key) {
            if (! $this->validIdentifier($raw[$key] ?? null, 200)) {
                $violations[] = "invalid_{$label}_{$key}";
            }
        }

        return [
            'engine' => $this->safeString($raw['engine'] ?? null),
            'version' => $this->safeString($raw['version'] ?? null),
            'revision' => $this->safeString($raw['revision'] ?? null),
            'device' => $this->safeString($raw['device'] ?? null),
        ];
    }

    /** @param list<string> $violations @param-out list<string> $violations @return list<array<string, mixed>> */
    private function evaluateCases(mixed $raw, array &$violations): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $violations[] = 'cases_must_be_a_list';

            return [];
        }
        $maxCases = max(1, (int) config('ocr_candidate_eval.limits.max_cases', 50));
        if (count($raw) > $maxCases) {
            $violations[] = 'too_many_cases';
            $raw = array_slice($raw, 0, $maxCases);
        }

        $results = [];
        foreach ($raw as $index => $case) {
            $normalized = $this->normalizeCase($case, $index, $violations);
            if ($normalized === null) {
                continue;
            }
            $baseline = $this->scoreVariant($normalized, 'baseline');
            $candidate = $this->scoreVariant($normalized, 'candidate');
            $regressions = $this->caseRegressions($normalized['id'], $baseline, $candidate);
            $results[] = [
                'case' => $normalized['id'],
                'category' => $normalized['category'],
                'capabilities' => $normalized['capabilities'],
                'evidence_sha256' => $normalized['evidence_sha256'],
                'expected_text_sha256' => hash('sha256', $normalized['expected_text']),
                'baseline_text_sha256' => hash('sha256', $normalized['baseline_text']),
                'candidate_text_sha256' => hash('sha256', $normalized['candidate_text']),
                'expected_pages' => $normalized['expected_pages'],
                'blank_case' => $normalized['expected_text'] === '',
                'multi_page_case' => $normalized['expected_pages'] > 1,
                'reading_order_case' => $normalized['order_pairs'] !== [],
                'baseline' => $baseline,
                'candidate' => $candidate,
                'deltas' => $this->qualityDeltas($baseline, $candidate),
                'regressions' => $regressions,
            ];
        }

        return $results;
    }

    /** @param list<string> $violations @param-out list<string> $violations @return array<string, mixed>|null */
    private function normalizeCase(mixed $raw, int $index, array &$violations): ?array
    {
        if (! is_array($raw) || array_is_list($raw)) {
            $violations[] = "invalid_case:{$index}";

            return null;
        }
        $allowed = [
            'id', 'category', 'capabilities', 'evidence_sha256', 'expected_text', 'baseline_text', 'candidate_text',
            'expected_pages', 'baseline_pages', 'candidate_pages', 'required_spans', 'forbidden_spans',
            'order_pairs', 'baseline_runtime', 'candidate_runtime',
        ];
        if ($this->unknownKeys($raw, $allowed) !== []) {
            $violations[] = "unknown_case_field:{$index}";
        }
        $id = $this->validIdentifier($raw['id'] ?? null, 100) ? (string) $raw['id'] : "invalid_case_{$index}";
        $category = $this->validIdentifier($raw['category'] ?? null, 80) ? (string) $raw['category'] : 'invalid';
        if ($id === "invalid_case_{$index}") {
            $violations[] = "invalid_case_id:{$index}";
        }
        if ($category === 'invalid') {
            $violations[] = "invalid_case_category:{$id}";
        }
        if (! $this->isSha256($raw['evidence_sha256'] ?? null)) {
            $violations[] = "invalid_case_evidence_sha256:{$id}";
        }

        $texts = [];
        $maxChars = max(1, (int) config('ocr_candidate_eval.limits.max_text_chars', 4000));
        foreach (['expected_text', 'baseline_text', 'candidate_text'] as $key) {
            if (! is_string($raw[$key] ?? null) || mb_strlen((string) $raw[$key]) > $maxChars) {
                $violations[] = "invalid_or_oversized_{$key}:{$id}";
                $texts[$key] = '';
            } else {
                $texts[$key] = $this->normalizeText((string) $raw[$key]);
            }
        }

        $pages = [];
        foreach (['expected_pages', 'baseline_pages', 'candidate_pages'] as $key) {
            $value = $raw[$key] ?? null;
            if (! is_int($value) || $value < 1 || $value > 1000) {
                $violations[] = "invalid_{$key}:{$id}";
                $value = 1;
            }
            $pages[$key] = $value;
        }

        $requiredSpans = $this->spans('required', $id, $raw['required_spans'] ?? null, $violations);
        $forbiddenSpans = $this->spans('forbidden', $id, $raw['forbidden_spans'] ?? null, $violations);
        $orderPairs = $this->orderPairs($id, $raw['order_pairs'] ?? [], $violations);
        $capabilities = $this->capabilities($id, $raw['capabilities'] ?? null, $violations);
        foreach ($orderPairs as $pair) {
            if (! $this->orderPairPasses($texts['expected_text'], $pair['before'], $pair['after'])) {
                $violations[] = "invalid_expected_order_pair:{$id}";
            }
        }
        if (in_array('blank', $capabilities, true) !== ($texts['expected_text'] === '')) {
            $violations[] = "capability_contract_mismatch:blank:{$id}";
        }
        if (in_array('multi_page', $capabilities, true) !== ($pages['expected_pages'] > 1)) {
            $violations[] = "capability_contract_mismatch:multi_page:{$id}";
        }
        if (in_array('spotting', $capabilities, true) && $requiredSpans === []) {
            $violations[] = "capability_contract_mismatch:spotting:{$id}";
        }
        if (in_array('structure', $capabilities, true) && $orderPairs === []) {
            $violations[] = "capability_contract_mismatch:structure:{$id}";
        }
        foreach (['recognition', 'handwriting', 'reasoning'] as $textCapability) {
            if (in_array($textCapability, $capabilities, true) && $texts['expected_text'] === '') {
                $violations[] = "capability_contract_mismatch:{$textCapability}:{$id}";
            }
        }

        return array_merge($texts, $pages, [
            'id' => $id,
            'category' => $category,
            'capabilities' => $capabilities,
            'evidence_sha256' => is_string($raw['evidence_sha256'] ?? null) ? $raw['evidence_sha256'] : null,
            'required_spans' => $requiredSpans,
            'forbidden_spans' => $forbiddenSpans,
            'order_pairs' => $orderPairs,
            'baseline_runtime' => $this->runtime('baseline', $id, $raw['baseline_runtime'] ?? null, $violations),
            'candidate_runtime' => $this->runtime('candidate', $id, $raw['candidate_runtime'] ?? null, $violations),
        ]);
    }

    /** @param list<string> $violations @param-out list<string> $violations @return list<string> */
    private function capabilities(string $id, mixed $raw, array &$violations): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || $raw === []) {
            $violations[] = "invalid_capabilities:{$id}";

            return [];
        }
        $max = max(1, (int) config('ocr_candidate_eval.limits.max_capabilities_per_case', 4));
        if (count($raw) > $max) {
            $violations[] = "too_many_capabilities:{$id}";
            $raw = array_slice($raw, 0, $max);
        }
        $allowed = $this->requiredCapabilities();
        $capabilities = [];
        foreach ($raw as $capability) {
            if (! is_string($capability) || ! in_array($capability, $allowed, true)) {
                $violations[] = "invalid_capability:{$id}";

                continue;
            }
            $capabilities[] = $capability;
        }
        if (count($capabilities) !== count(array_unique($capabilities))) {
            $violations[] = "duplicate_capability:{$id}";
        }

        return array_values(array_unique($capabilities));
    }

    /** @param list<string> $violations @param-out list<string> $violations @return list<string> */
    private function spans(string $type, string $id, mixed $raw, array &$violations): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $violations[] = "invalid_{$type}_spans:{$id}";

            return [];
        }
        $max = max(1, (int) config('ocr_candidate_eval.limits.max_spans_per_case', 25));
        $maxChars = max(1, (int) config('ocr_candidate_eval.limits.max_span_chars', 300));
        if (count($raw) > $max) {
            $violations[] = "too_many_{$type}_spans:{$id}";
            $raw = array_slice($raw, 0, $max);
        }
        $spans = [];
        foreach ($raw as $span) {
            if (! is_string($span) || trim($span) === '' || mb_strlen($span) > $maxChars) {
                $violations[] = "invalid_{$type}_span:{$id}";

                continue;
            }
            $spans[] = $this->normalizeText($span);
        }
        if (count($spans) !== count(array_unique($spans))) {
            $violations[] = "duplicate_{$type}_span:{$id}";
        }

        return array_values(array_unique($spans));
    }

    /** @param list<string> $violations @param-out list<string> $violations @return list<array{before:string,after:string}> */
    private function orderPairs(string $id, mixed $raw, array &$violations): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $violations[] = "invalid_order_pairs:{$id}";

            return [];
        }
        $max = max(1, (int) config('ocr_candidate_eval.limits.max_order_pairs_per_case', 25));
        $maxChars = max(1, (int) config('ocr_candidate_eval.limits.max_order_anchor_chars', 300));
        if (count($raw) > $max) {
            $violations[] = "too_many_order_pairs:{$id}";
            $raw = array_slice($raw, 0, $max);
        }

        $pairs = [];
        $keys = [];
        foreach ($raw as $pair) {
            if (! is_array($pair) || array_is_list($pair) || $this->unknownKeys($pair, ['before', 'after']) !== []) {
                $violations[] = "invalid_order_pair:{$id}";

                continue;
            }
            $before = $pair['before'] ?? null;
            $after = $pair['after'] ?? null;
            if (! is_string($before) || ! is_string($after)
                || trim($before) === '' || trim($after) === ''
                || mb_strlen($before) > $maxChars || mb_strlen($after) > $maxChars) {
                $violations[] = "invalid_order_anchor:{$id}";

                continue;
            }
            $before = $this->normalizeText($before);
            $after = $this->normalizeText($after);
            if ($before === $after) {
                $violations[] = "identical_order_anchors:{$id}";

                continue;
            }
            $key = hash('sha256', $before."\0".$after);
            if (isset($keys[$key])) {
                $violations[] = "duplicate_order_pair:{$id}";

                continue;
            }
            $keys[$key] = true;
            $pairs[] = ['before' => $before, 'after' => $after];
        }

        return $pairs;
    }

    /** @param list<string> $violations @param-out list<string> $violations @return array{duration_ms:int,peak_vram_mb:float} */
    private function runtime(string $variant, string $id, mixed $raw, array &$violations): array
    {
        if (! is_array($raw) || array_is_list($raw) || $this->unknownKeys($raw, ['duration_ms', 'peak_vram_mb']) !== []) {
            $violations[] = "invalid_{$variant}_runtime:{$id}";

            return ['duration_ms' => 0, 'peak_vram_mb' => 0.0];
        }
        $duration = $raw['duration_ms'] ?? null;
        $vram = $raw['peak_vram_mb'] ?? null;
        if (! is_int($duration) || $duration < 0 || $duration > 86_400_000) {
            $violations[] = "invalid_{$variant}_duration:{$id}";
            $duration = 0;
        }
        if ((! is_int($vram) && ! is_float($vram)) || ! is_finite((float) $vram) || $vram < 0 || $vram > 1_000_000) {
            $violations[] = "invalid_{$variant}_vram:{$id}";
            $vram = 0.0;
        }

        return ['duration_ms' => $duration, 'peak_vram_mb' => round((float) $vram, 2)];
    }

    /** @param array<string, mixed> $case @return array<string, mixed> */
    private function scoreVariant(array $case, string $variant): array
    {
        $text = (string) $case["{$variant}_text"];
        $edit = $this->editMetric((string) $case['expected_text'], $text);
        $required = (array) $case['required_spans'];
        $forbidden = (array) $case['forbidden_spans'];
        $orderPairs = (array) $case['order_pairs'];
        $requiredPassed = count(array_filter($required, static fn (string $span): bool => str_contains($text, $span)));
        $forbiddenViolations = count(array_filter($forbidden, static fn (string $span): bool => str_contains($text, $span)));
        $orderPassed = count(array_filter(
            $orderPairs,
            fn (array $pair): bool => $this->orderPairPasses($text, $pair['before'], $pair['after']),
        ));
        $pagePass = (int) $case["{$variant}_pages"] === (int) $case['expected_pages'];
        $blankCase = (string) $case['expected_text'] === '';
        $blankPass = ! $blankCase || $text === '';
        $unitTotal = count($required) + count($forbidden) + count($orderPairs) + 1 + ($blankCase ? 1 : 0);
        $unitPassed = $requiredPassed + (count($forbidden) - $forbiddenViolations) + $orderPassed
            + ($pagePass ? 1 : 0) + ($blankCase && $blankPass ? 1 : 0);

        return [
            'normalized_edit_similarity' => $edit['similarity'],
            'edit_distance' => $edit['distance'],
            'edit_unit' => $edit['unit'],
            'exact_match' => $text === (string) $case['expected_text'],
            'token_precision' => $this->tokenPrecision((string) $case['expected_text'], $text),
            'required_spans_passed' => $requiredPassed,
            'required_spans_total' => count($required),
            'required_span_recall' => count($required) > 0 ? round($requiredPassed / count($required), 4) : 1.0,
            'forbidden_span_violations' => $forbiddenViolations,
            'forbidden_spans_total' => count($forbidden),
            'forbidden_span_absence_rate' => count($forbidden) > 0
                ? round((count($forbidden) - $forbiddenViolations) / count($forbidden), 4)
                : 1.0,
            'order_pairs_passed' => $orderPassed,
            'order_pairs_total' => count($orderPairs),
            'reading_order_pass_rate' => count($orderPairs) > 0 ? round($orderPassed / count($orderPairs), 4) : 1.0,
            'page_provenance_pass' => $pagePass,
            'blank_case_pass' => $blankPass,
            'unit_checks_passed' => $unitPassed,
            'unit_checks_total' => $unitTotal,
            'unit_test_pass_rate' => round($unitPassed / $unitTotal, 4),
            'duration_ms' => (int) data_get($case, "{$variant}_runtime.duration_ms", 0),
            'peak_vram_mb' => (float) data_get($case, "{$variant}_runtime.peak_vram_mb", 0.0),
        ];
    }

    /** @param array<string, mixed> $baseline @param array<string, mixed> $candidate @return list<string> */
    private function caseRegressions(string $id, array $baseline, array $candidate): array
    {
        $allowed = (float) config('ocr_candidate_eval.thresholds.max_case_quality_regression', 0.0);
        $regressions = [];
        foreach (['normalized_edit_similarity', 'token_precision', 'required_span_recall', 'forbidden_span_absence_rate', 'reading_order_pass_rate', 'unit_test_pass_rate'] as $metric) {
            if ((float) $candidate[$metric] + $allowed < (float) $baseline[$metric]) {
                $regressions[] = "{$id}:{$metric}";
            }
        }
        foreach (['page_provenance_pass', 'blank_case_pass'] as $metric) {
            if (($candidate[$metric] ?? false) !== true) {
                $regressions[] = "{$id}:{$metric}";
            }
        }

        return $regressions;
    }

    /** @param list<array<string, mixed>> $cases @return array<string, mixed> */
    private function aggregate(array $cases, string $variant): array
    {
        $scores = array_column($cases, $variant);
        $count = count($scores);
        $sum = static fn (string $key): float => array_sum(array_map(
            static fn (array $score): float => (float) ($score[$key] ?? 0),
            $scores,
        ));
        $requiredTotal = (int) $sum('required_spans_total');
        $forbiddenTotal = (int) $sum('forbidden_spans_total');
        $orderTotal = (int) $sum('order_pairs_total');
        $unitTotal = (int) $sum('unit_checks_total');
        $blankScores = array_values(array_filter(
            $scores,
            static fn (array $score, int $index): bool => (bool) ($cases[$index]['blank_case'] ?? false),
            ARRAY_FILTER_USE_BOTH,
        ));
        $durations = array_map(static fn (array $score): int => (int) ($score['duration_ms'] ?? 0), $scores);
        sort($durations, SORT_NUMERIC);
        $totalDuration = array_sum($durations);
        $totalPages = array_sum(array_column($cases, 'expected_pages'));

        return [
            'case_count' => $count,
            'category_count' => count(array_unique(array_column($cases, 'category'))),
            'capability_count' => count(array_unique(array_merge(...array_map(
                static fn (array $case): array => (array) ($case['capabilities'] ?? []),
                $cases,
            )))),
            'normalized_edit_similarity' => $count > 0 ? round($sum('normalized_edit_similarity') / $count, 4) : 0.0,
            'token_precision' => $count > 0 ? round($sum('token_precision') / $count, 4) : 0.0,
            'exact_match_rate' => $count > 0 ? round($sum('exact_match') / $count, 4) : 0.0,
            'required_span_recall' => $requiredTotal > 0 ? round($sum('required_spans_passed') / $requiredTotal, 4) : 1.0,
            'forbidden_span_absence_rate' => $forbiddenTotal > 0
                ? round(($forbiddenTotal - $sum('forbidden_span_violations')) / $forbiddenTotal, 4)
                : 1.0,
            'reading_order_pass_rate' => $orderTotal > 0 ? round($sum('order_pairs_passed') / $orderTotal, 4) : 1.0,
            'page_provenance_rate' => $count > 0 ? round($sum('page_provenance_pass') / $count, 4) : 0.0,
            'blank_case_pass_rate' => $blankScores !== []
                ? round(array_sum(array_column($blankScores, 'blank_case_pass')) / count($blankScores), 4)
                : 0.0,
            'unit_test_pass_rate' => $unitTotal > 0 ? round($sum('unit_checks_passed') / $unitTotal, 4) : 0.0,
            'duration_total_ms' => $totalDuration,
            'duration_p50_ms' => $this->percentile($durations, 50),
            'duration_p95_ms' => $this->percentile($durations, 95),
            'pages_per_minute' => $totalDuration > 0 ? round($totalPages / ($totalDuration / 60_000), 2) : null,
            'peak_vram_mb' => $scores !== [] ? max(array_column($scores, 'peak_vram_mb')) : 0.0,
        ];
    }

    /** @param list<array<string, mixed>> $cases @return array<string, array<string, mixed>> */
    private function capabilityMetrics(array $cases, string $variant): array
    {
        $metrics = [];
        foreach ($this->requiredCapabilities() as $capability) {
            $matching = array_values(array_filter(
                $cases,
                static fn (array $case): bool => in_array($capability, (array) ($case['capabilities'] ?? []), true),
            ));
            $metrics[$capability] = $this->aggregate($matching, $variant);
        }

        return $metrics;
    }

    /** @param list<array<string, mixed>> $cases @param array<string, float|int> $thresholds @return list<string> */
    private function caseQualityFailures(array $cases, array $thresholds): array
    {
        $minimums = [
            'normalized_edit_similarity' => $thresholds['min_case_normalized_edit_similarity'],
            'token_precision' => $thresholds['min_case_token_precision'],
            'unit_test_pass_rate' => $thresholds['min_case_unit_test_pass_rate'],
        ];
        $failures = [];
        foreach ($cases as $case) {
            foreach ($minimums as $metric => $minimum) {
                if ((float) data_get($case, 'candidate.'.$metric, 0.0) < (float) $minimum) {
                    $failures[] = (string) $case['case'].':'.$metric;
                }
            }
        }

        return $failures;
    }

    /** @param array<string, mixed> $baseline @param array<string, mixed> $candidate @return array<string, float> */
    private function qualityDeltas(array $baseline, array $candidate): array
    {
        $deltas = [];
        foreach (['normalized_edit_similarity', 'token_precision', 'exact_match_rate', 'required_span_recall', 'forbidden_span_absence_rate', 'reading_order_pass_rate', 'page_provenance_rate', 'blank_case_pass_rate', 'unit_test_pass_rate'] as $metric) {
            $deltas[$metric] = round((float) ($candidate[$metric] ?? 0) - (float) ($baseline[$metric] ?? 0), 4);
        }

        return $deltas;
    }

    /** @param array<string, mixed> $candidate @param array<string, float|int> $thresholds */
    private function qualityPassed(array $candidate, array $thresholds): bool
    {
        return (float) $candidate['normalized_edit_similarity'] >= $thresholds['min_normalized_edit_similarity']
            && (float) $candidate['required_span_recall'] >= $thresholds['min_required_span_recall']
            && (float) $candidate['forbidden_span_absence_rate'] >= $thresholds['min_forbidden_span_absence_rate']
            && (float) $candidate['reading_order_pass_rate'] >= $thresholds['min_reading_order_pass_rate']
            && (float) $candidate['page_provenance_rate'] >= $thresholds['min_page_provenance_rate']
            && (float) $candidate['blank_case_pass_rate'] >= $thresholds['min_blank_case_pass_rate']
            && (float) $candidate['token_precision'] >= $thresholds['min_token_precision']
            && (float) $candidate['unit_test_pass_rate'] >= $thresholds['min_unit_test_pass_rate'];
    }

    /** @return array<string, float|int> */
    private function thresholds(): array
    {
        $raw = (array) config('ocr_candidate_eval.thresholds', []);

        return [
            'min_cases' => max(1, (int) ($raw['min_cases'] ?? 5)),
            'min_categories' => max(1, (int) ($raw['min_categories'] ?? 3)),
            'min_normalized_edit_similarity' => $this->ratio($raw['min_normalized_edit_similarity'] ?? 0.90),
            'min_required_span_recall' => $this->ratio($raw['min_required_span_recall'] ?? 1.0),
            'min_forbidden_span_absence_rate' => $this->ratio($raw['min_forbidden_span_absence_rate'] ?? 1.0),
            'min_reading_order_pass_rate' => $this->ratio($raw['min_reading_order_pass_rate'] ?? 1.0),
            'min_page_provenance_rate' => $this->ratio($raw['min_page_provenance_rate'] ?? 1.0),
            'min_blank_case_pass_rate' => $this->ratio($raw['min_blank_case_pass_rate'] ?? 1.0),
            'min_token_precision' => $this->ratio($raw['min_token_precision'] ?? 0.90),
            'min_unit_test_pass_rate' => $this->ratio($raw['min_unit_test_pass_rate'] ?? 0.98),
            'min_edit_similarity_improvement' => $this->ratio($raw['min_edit_similarity_improvement'] ?? 0.01),
            'min_unit_test_improvement' => $this->ratio($raw['min_unit_test_improvement'] ?? 0.02),
            'min_token_precision_improvement' => $this->ratio($raw['min_token_precision_improvement'] ?? 0.02),
            'min_reading_order_improvement' => $this->ratio($raw['min_reading_order_improvement'] ?? 0.01),
            'max_case_quality_regression' => $this->ratio($raw['max_case_quality_regression'] ?? 0.0),
            'min_case_normalized_edit_similarity' => $this->ratio($raw['min_case_normalized_edit_similarity'] ?? 0.75),
            'min_case_token_precision' => $this->ratio($raw['min_case_token_precision'] ?? 0.75),
            'min_case_unit_test_pass_rate' => $this->ratio($raw['min_case_unit_test_pass_rate'] ?? 0.90),
        ];
    }

    /** @return list<string> */
    private function requiredCapabilities(): array
    {
        return array_values(array_filter(
            (array) config('ocr_candidate_eval.required_capabilities', []),
            static fn (mixed $capability): bool => is_string($capability) && $capability !== '',
        ));
    }

    /** @return array{similarity:float,distance:int,unit:string} */
    private function editMetric(string $expected, string $actual): array
    {
        $expectedChars = mb_str_split($expected);
        $actualChars = mb_str_split($actual);
        $alphabet = array_values(array_unique(array_merge($expectedChars, $actualChars)));
        if (count($alphabet) <= 250) {
            $map = [];
            foreach ($alphabet as $index => $character) {
                $map[$character] = chr($index + 1);
            }
            $left = implode('', array_map(static fn (string $char): string => $map[$char], $expectedChars));
            $right = implode('', array_map(static fn (string $char): string => $map[$char], $actualChars));
            $distance = levenshtein($left, $right);
            $denominator = max(count($expectedChars), count($actualChars), 1);
            $unit = 'unicode_codepoint';
        } else {
            $distance = levenshtein($expected, $actual);
            $denominator = max(strlen($expected), strlen($actual), 1);
            $unit = 'utf8_byte_fallback';
        }

        return [
            'similarity' => round(max(0.0, 1 - ($distance / $denominator)), 4),
            'distance' => $distance,
            'unit' => $unit,
        ];
    }

    private function tokenPrecision(string $expected, string $actual): float
    {
        $expectedTokens = $this->tokens($expected);
        $actualTokens = $this->tokens($actual);
        if ($actualTokens === []) {
            return $expectedTokens === [] ? 1.0 : 0.0;
        }
        $available = array_count_values($expectedTokens);
        $matched = 0;
        foreach ($actualTokens as $token) {
            if (($available[$token] ?? 0) <= 0) {
                continue;
            }
            $available[$token]--;
            $matched++;
        }

        return round($matched / count($actualTokens), 4);
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+|[^\s\p{L}\p{N}]/u', mb_strtolower($text), $matches);

        return $matches[0];
    }

    private function orderPairPasses(string $text, string $before, string $after): bool
    {
        $beforeOffset = mb_strpos($text, $before);
        if ($beforeOffset === false) {
            return false;
        }

        return mb_strpos($text, $after, $beforeOffset + mb_strlen($before)) !== false;
    }

    private function normalizeText(string $text): string
    {
        $normalized = class_exists(Normalizer::class) ? Normalizer::normalize($text, Normalizer::FORM_KC) : $text;
        $normalized = is_string($normalized) ? $normalized : $text;
        $normalized = str_replace(["\r\n", "\r"], "\n", $normalized);

        return trim((string) preg_replace('/\s+/u', ' ', $normalized));
    }

    /** @param list<int> $values */
    private function percentile(array $values, int $percentile): int
    {
        if ($values === []) {
            return 0;
        }
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;

        return $values[max(0, min(count($values) - 1, $index))];
    }

    /** @param list<string> $allowed @return list<string> */
    private function unknownKeys(array $value, array $allowed): array
    {
        return array_values(array_diff(array_keys($value), $allowed));
    }

    private function validIdentifier(mixed $value, int $maxLength): bool
    {
        return is_string($value)
            && strlen($value) <= $maxLength
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/+-]*$/', $value) === 1;
    }

    private function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    private function safeString(mixed $value): ?string
    {
        return is_string($value) ? mb_substr($value, 0, 200) : null;
    }

    private function ratio(mixed $value): float
    {
        return max(0.0, min(1.0, (float) $value));
    }

    /** @return array<string, mixed> */
    private function exampleCase(
        string $id,
        string $category,
        array $capabilities,
        string $expected,
        string $baseline,
        string $candidate,
        array $required,
        array $forbidden = [],
        array $orderPairs = [],
        int $expectedPages = 1,
        int $baselinePages = 1,
        int $candidatePages = 1,
    ): array {
        return [
            'id' => $id,
            'category' => $category,
            'capabilities' => $capabilities,
            'evidence_sha256' => hash('sha256', "synthetic-ocr-evidence:{$id}"),
            'expected_text' => $expected,
            'baseline_text' => $baseline,
            'candidate_text' => $candidate,
            'expected_pages' => $expectedPages,
            'baseline_pages' => $baselinePages,
            'candidate_pages' => $candidatePages,
            'required_spans' => $required,
            'forbidden_spans' => $forbidden,
            'order_pairs' => $orderPairs,
            'baseline_runtime' => ['duration_ms' => 1000, 'peak_vram_mb' => 0],
            'candidate_runtime' => ['duration_ms' => 1400, 'peak_vram_mb' => 6800],
        ];
    }
}
