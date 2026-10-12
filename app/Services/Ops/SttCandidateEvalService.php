<?php

namespace App\Services\Ops;

use Normalizer;

final class SttCandidateEvalService
{
    public const ARTIFACT_SCHEMA = 'plos.stt_candidate.v1';

    public const RESULT_SCHEMA = 'plos.stt_candidate_eval.v1';

    /** @param array<string, mixed> $artifact @return array<string, mixed> */
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

        $covered = [];
        foreach ($cases as $case) {
            $covered = array_merge($covered, (array) ($case['capabilities'] ?? []));
        }
        $covered = array_values(array_filter(
            $this->requiredCapabilities(),
            static fn (string $capability): bool => in_array($capability, $covered, true)
        ));
        $missing = array_values(array_diff($this->requiredCapabilities(), $covered));
        foreach ($missing as $capability) {
            $violations[] = 'missing_capability:'.$capability;
        }
        if (! in_array(true, array_column($cases, 'silence_case'), true)) {
            $violations[] = 'silence_case_required';
        }

        $baseline = $this->aggregate($cases, 'baseline');
        $candidate = $this->aggregate($cases, 'candidate');
        $deltas = [
            'word_error_rate' => $this->round($candidate['word_error_rate'] - $baseline['word_error_rate']),
            'character_error_rate' => $this->round($candidate['character_error_rate'] - $baseline['character_error_rate']),
            'critical_phrase_recall' => $this->round($candidate['critical_phrase_recall'] - $baseline['critical_phrase_recall']),
            'language_accuracy' => $this->round($candidate['language_accuracy'] - $baseline['language_accuracy']),
            'silence_pass_rate' => $this->round($candidate['silence_pass_rate'] - $baseline['silence_pass_rate']),
            'real_time_factor' => $this->round($candidate['real_time_factor'] - $baseline['real_time_factor']),
        ];

        $regressions = array_values(array_unique(array_merge(...array_map(
            static fn (array $case): array => $case['regressions'],
            $cases
        ))));
        if ($candidate['real_time_factor'] > $baseline['real_time_factor'] + $thresholds['max_aggregate_real_time_factor_regression']) {
            $regressions[] = 'aggregate:real_time_factor';
        }
        $caseQualityFailures = $this->caseQualityFailures($cases, $thresholds);
        $qualityPassed = $candidate['word_error_rate'] <= $thresholds['max_word_error_rate']
            && $candidate['character_error_rate'] <= $thresholds['max_character_error_rate']
            && $candidate['critical_phrase_recall'] >= $thresholds['min_critical_phrase_recall']
            && $candidate['language_accuracy'] >= $thresholds['min_language_accuracy']
            && $candidate['silence_pass_rate'] >= $thresholds['min_silence_pass_rate']
            && $candidate['real_time_factor'] <= $thresholds['max_real_time_factor'];
        $improved = ($baseline['word_error_rate'] - $candidate['word_error_rate']) >= $thresholds['min_word_error_rate_improvement']
            || ($baseline['real_time_factor'] - $candidate['real_time_factor']) >= $thresholds['min_real_time_factor_improvement'];

        $violations = array_values(array_unique($violations));
        $regressions = array_values(array_unique($regressions));
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
                'covered' => $covered,
                'missing' => $missing,
            ],
            'regressions' => $regressions,
            'case_quality_failures' => $caseQualityFailures,
            'violations' => $violations,
            'cases' => $cases,
            'promotion' => [
                'eligible_for_operator_review' => $eligible,
                'quality_thresholds_passed' => $qualityPassed,
                'case_quality_floors_passed' => $caseQualityFailures === [],
                'required_capabilities_covered' => $missing === [],
                'measurable_accuracy_or_speed_improvement' => $improved,
                'autonomous_runtime_install_allowed' => false,
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
                'audio_or_stt_runtime_calls' => false,
                'production_transcription_route_changed' => false,
                'model_or_weight_installed' => false,
                'prompt_compression_changed' => false,
                'precompaction_changed' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function exampleArtifact(): array
    {
        return [
            'schema' => self::ARTIFACT_SCHEMA,
            'candidate_id' => 'synthetic-whisper-cpp-contract-v1',
            'baseline' => $this->exampleIdentity('openai-whisper', 'base', 'cpu'),
            'candidate' => $this->exampleIdentity('whisper-cpp', 'ggml-base', 'cpu'),
            'operator_review_required' => true,
            'cases' => [
                $this->exampleCase('clean_meeting', 'clean', ['clean_speech'], 'en', 12.0, 'The meeting begins at nine thirty in the library.', 'The meeting begin at nine thirty in library.', 7.2, 3.6, ['nine thirty', 'library']),
                $this->exampleCase('noisy_invoice', 'background_noise', ['noisy_speech'], 'en', 18.0, 'Please send the revised invoice before Friday afternoon.', 'Please send revised invoice before Friday.', 13.5, 5.4, ['revised invoice', 'Friday afternoon']),
                $this->exampleCase('accented_station', 'accent', ['accented_speech'], 'en', 16.0, 'The railway station closes shortly after midnight.', 'The railway station close shortly after midnight.', 11.2, 4.8, ['railway station', 'after midnight']),
                $this->exampleCase('genealogy_names', 'names_dates', ['names_dates'], 'en', 20.0, "Margaret O'Brien married Samuel Müller on 14 October 1912.", 'Margaret O Brian married Samuel Miller on 14 October 1912.', 15.0, 6.0, ["Margaret O'Brien", 'Samuel Müller', '14 October 1912']),
                $this->exampleCase('long_interview', 'long_form', ['long_form'], 'en', 120.0, 'The first witness described the eastern road. After a pause, the second witness confirmed the bridge was closed.', 'The first witness described the eastern road. The second witness confirmed the bridge closed.', 90.0, 36.0, ['first witness', 'after a pause', 'bridge was closed']),
                $this->exampleCase('german_family', 'multilingual', ['multilingual'], 'de', 14.0, 'Familie Müller wohnte seit 1894 in Köln.', 'Familie Muller wohnte seit 1894 in Koln.', 10.5, 4.2, ['Familie Müller', '1894', 'Köln']),
                $this->exampleCase('room_silence', 'silence', ['silence'], 'und', 10.0, '', 'Thank you for watching.', 6.0, 2.5, []),
            ],
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function compactPayload(array $payload): array
    {
        $compact = $payload;
        $compact['compact'] = true;
        unset($compact['cases']);

        return $compact;
    }

    /** @param list<string> $violations @param-out list<string> $violations */
    private function validateEnvelope(array $artifact, array &$violations): void
    {
        if ($this->unknownKeys($artifact, ['schema', 'candidate_id', 'baseline', 'candidate', 'operator_review_required', 'cases']) !== []) {
            $violations[] = 'unknown_artifact_field';
        }
        if (($artifact['schema'] ?? null) !== self::ARTIFACT_SCHEMA) {
            $violations[] = 'invalid_artifact_schema';
        }
        if (! $this->validIdentifier($artifact['candidate_id'] ?? null, 160)) {
            $violations[] = 'invalid_candidate_id';
        }
        if (($artifact['operator_review_required'] ?? null) !== true) {
            $violations[] = 'operator_review_not_required_by_artifact';
        }
    }

    /** @param list<string> $violations @param-out list<string> $violations @return array<string, string|null> */
    private function identity(string $label, mixed $raw, array &$violations): array
    {
        $keys = ['engine', 'version', 'revision', 'model', 'model_sha256', 'device'];
        if (! is_array($raw) || array_is_list($raw)) {
            $violations[] = "invalid_{$label}_identity";

            return array_fill_keys($keys, null);
        }
        if ($this->unknownKeys($raw, $keys) !== []) {
            $violations[] = "unknown_{$label}_identity_field";
        }
        foreach (['engine', 'version', 'revision', 'model', 'device'] as $key) {
            if (! $this->validIdentifier($raw[$key] ?? null, 200)) {
                $violations[] = "invalid_{$label}_{$key}";
            }
        }
        if (! $this->validSha256($raw['model_sha256'] ?? null)) {
            $violations[] = "invalid_{$label}_model_sha256";
        }

        return array_map(fn (string $key): ?string => $this->safeString($raw[$key] ?? null), array_combine($keys, $keys));
    }

    /** @param list<string> $violations @param-out list<string> $violations @return list<array<string, mixed>> */
    private function evaluateCases(mixed $raw, array &$violations): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $violations[] = 'cases_must_be_a_list';

            return [];
        }
        $max = max(1, (int) config('stt_candidate_eval.limits.max_cases', 50));
        if (count($raw) > $max) {
            $violations[] = 'too_many_cases';
            $raw = array_slice($raw, 0, $max);
        }

        $cases = [];
        foreach ($raw as $index => $case) {
            $cases[] = $this->evaluateCase($case, $index, $violations);
        }

        return $cases;
    }

    /** @param list<string> $violations @param-out list<string> $violations @return array<string, mixed> */
    private function evaluateCase(mixed $raw, int $index, array &$violations): array
    {
        $fallbackId = 'case_'.$index;
        if (! is_array($raw) || array_is_list($raw)) {
            $violations[] = 'invalid_case:'.$fallbackId;
            $raw = [];
        }
        $keys = ['id', 'category', 'capabilities', 'evidence_sha256', 'expected_language', 'audio_duration_seconds', 'reference_text', 'critical_phrases', 'baseline', 'candidate'];
        if ($this->unknownKeys($raw, $keys) !== []) {
            $violations[] = 'unknown_case_field:'.$fallbackId;
        }

        $id = $this->validIdentifier($raw['id'] ?? null, 160) ? (string) $raw['id'] : $fallbackId;
        if ($id === $fallbackId && ($raw['id'] ?? null) !== $fallbackId) {
            $violations[] = 'invalid_case_id:'.$fallbackId;
        }
        $category = $this->validIdentifier($raw['category'] ?? null, 120) ? (string) $raw['category'] : 'invalid';
        if ($category === 'invalid') {
            $violations[] = 'invalid_case_category:'.$id;
        }
        $capabilities = $this->capabilities($raw['capabilities'] ?? null, $id, $violations);
        if (! $this->validSha256($raw['evidence_sha256'] ?? null)) {
            $violations[] = 'invalid_evidence_sha256:'.$id;
        }
        $expectedLanguage = strtolower(trim((string) ($raw['expected_language'] ?? '')));
        if (preg_match('/^(?:[a-z]{2,3}|und)(?:-[a-z0-9]{2,8})?$/', $expectedLanguage) !== 1) {
            $violations[] = 'invalid_expected_language:'.$id;
        }
        $duration = $this->positiveNumber($raw['audio_duration_seconds'] ?? null);
        if ($duration === null || $duration > (float) config('stt_candidate_eval.limits.max_audio_duration_seconds', 21_600)) {
            $violations[] = 'invalid_audio_duration:'.$id;
            $duration = 1.0;
        }
        $reference = $this->boundedText($raw['reference_text'] ?? null, $id, 'reference', $violations);
        $criticalPhrases = $this->criticalPhrases($raw['critical_phrases'] ?? null, $reference, $id, $violations);
        $silence = in_array('silence', $capabilities, true);
        if ($silence !== ($this->normalizeText($reference) === '')) {
            $violations[] = 'silence_capability_mismatch:'.$id;
        }
        if (! $silence && $this->normalizeText($reference) === '') {
            $violations[] = 'empty_reference:'.$id;
        }

        $baseline = $this->caseOutput('baseline', $raw['baseline'] ?? null, $reference, $criticalPhrases, $expectedLanguage, $duration, $silence, $id, $violations);
        $candidate = $this->caseOutput('candidate', $raw['candidate'] ?? null, $reference, $criticalPhrases, $expectedLanguage, $duration, $silence, $id, $violations);
        $tolerance = (float) config('stt_candidate_eval.thresholds.max_case_quality_regression', 0.0);
        $regressions = [];
        foreach (['word_error_rate', 'character_error_rate'] as $metric) {
            if ($candidate[$metric] > $baseline[$metric] + $tolerance) {
                $regressions[] = $id.':'.$metric;
            }
        }
        foreach (['critical_phrase_recall', 'language_correct', 'silence_pass'] as $metric) {
            if ((float) $candidate[$metric] + $tolerance < (float) $baseline[$metric]) {
                $regressions[] = $id.':'.$metric;
            }
        }

        return [
            'case' => $id,
            'category' => $category,
            'capabilities' => $capabilities,
            'evidence_sha256' => $this->validSha256($raw['evidence_sha256'] ?? null) ? strtolower((string) $raw['evidence_sha256']) : null,
            'reference_sha256' => hash('sha256', $reference),
            'expected_language' => $expectedLanguage,
            'audio_duration_seconds' => $this->round($duration),
            'silence_case' => $silence,
            'critical_phrase_count' => count($criticalPhrases),
            'baseline' => $baseline,
            'candidate' => $candidate,
            'regressions' => $regressions,
        ];
    }

    /** @param list<string> $violations @param-out list<string> $violations @return array<string, mixed> */
    private function caseOutput(string $label, mixed $raw, string $reference, array $criticalPhrases, string $expectedLanguage, float $duration, bool $silence, string $id, array &$violations): array
    {
        if (! is_array($raw) || array_is_list($raw)) {
            $violations[] = "invalid_{$label}_output:{$id}";
            $raw = [];
        }
        if ($this->unknownKeys($raw, ['transcript', 'language', 'runtime_seconds']) !== []) {
            $violations[] = "unknown_{$label}_output_field:{$id}";
        }
        $transcript = $this->boundedText($raw['transcript'] ?? null, $id, $label, $violations);
        $language = strtolower(trim((string) ($raw['language'] ?? '')));
        if (preg_match('/^(?:[a-z]{2,3}|und)(?:-[a-z0-9]{2,8})?$/', $language) !== 1) {
            $violations[] = "invalid_{$label}_language:{$id}";
        }
        $runtime = $this->positiveNumber($raw['runtime_seconds'] ?? null);
        if ($runtime === null) {
            $violations[] = "invalid_{$label}_runtime:{$id}";
            $runtime = $duration;
        }

        $wordErrorRate = $this->errorRate($this->wordTokens($reference), $this->wordTokens($transcript));
        $characterErrorRate = $this->errorRate($this->characters($reference), $this->characters($transcript));
        $criticalRecall = $this->criticalPhraseRecall($criticalPhrases, $transcript);
        $languageCorrect = hash_equals($expectedLanguage, $language);
        $silencePass = ! $silence || $this->normalizeText($transcript) === '';

        return [
            'transcript_sha256' => hash('sha256', $transcript),
            'normalized_transcript_sha256' => hash('sha256', $this->normalizeText($transcript)),
            'word_count' => count($this->wordTokens($transcript)),
            'word_error_rate' => $this->round($wordErrorRate),
            'character_error_rate' => $this->round($characterErrorRate),
            'critical_phrase_recall' => $this->round($criticalRecall),
            'language_correct' => $languageCorrect,
            'silence_pass' => $silencePass,
            'real_time_factor' => $this->round($runtime / $duration),
        ];
    }

    /** @param list<array<string, mixed>> $cases @return array<string, int|float> */
    private function aggregate(array $cases, string $label): array
    {
        $speech = array_values(array_filter($cases, static fn (array $case): bool => ! $case['silence_case']));
        $silence = array_values(array_filter($cases, static fn (array $case): bool => $case['silence_case']));
        $outputs = array_column($speech, $label);
        $allOutputs = array_column($cases, $label);

        return [
            'case_count' => count($cases),
            'speech_case_count' => count($speech),
            'silence_case_count' => count($silence),
            'word_error_rate' => $this->average($outputs, 'word_error_rate'),
            'character_error_rate' => $this->average($outputs, 'character_error_rate'),
            'critical_phrase_recall' => $this->average($outputs, 'critical_phrase_recall'),
            'language_accuracy' => $this->average($outputs, 'language_correct'),
            'silence_pass_rate' => $this->average(array_column($silence, $label), 'silence_pass'),
            'real_time_factor' => $this->average($allOutputs, 'real_time_factor'),
        ];
    }

    /** @param list<array<string, mixed>> $cases @param array<string, float|int> $thresholds @return list<string> */
    private function caseQualityFailures(array $cases, array $thresholds): array
    {
        $failures = [];
        foreach ($cases as $case) {
            $candidate = $case['candidate'];
            $id = $case['case'];
            if (! $case['silence_case'] && $candidate['word_error_rate'] > $thresholds['max_case_word_error_rate']) {
                $failures[] = $id.':word_error_rate';
            }
            if (! $case['silence_case'] && $candidate['character_error_rate'] > $thresholds['max_case_character_error_rate']) {
                $failures[] = $id.':character_error_rate';
            }
            if ($case['critical_phrase_count'] > 0 && $candidate['critical_phrase_recall'] < 1.0) {
                $failures[] = $id.':critical_phrase_recall';
            }
            if (! $candidate['language_correct']) {
                $failures[] = $id.':language_correct';
            }
            if (! $candidate['silence_pass']) {
                $failures[] = $id.':silence_pass';
            }
        }

        return $failures;
    }

    /** @param list<mixed> $raw @param list<string> $violations @param-out list<string> $violations @return list<string> */
    private function capabilities(mixed $raw, string $id, array &$violations): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $violations[] = 'invalid_capabilities:'.$id;

            return [];
        }
        if (count($raw) > (int) config('stt_candidate_eval.limits.max_capabilities_per_case', 4)) {
            $violations[] = 'too_many_capabilities:'.$id;
        }
        $values = array_values(array_unique(array_map('strval', $raw)));
        foreach ($values as $value) {
            if (! in_array($value, $this->requiredCapabilities(), true)) {
                $violations[] = 'unknown_capability:'.$id;
            }
        }

        return array_values(array_filter($values, fn (string $value): bool => in_array($value, $this->requiredCapabilities(), true)));
    }

    /** @param list<string> $violations @param-out list<string> $violations @return list<string> */
    private function criticalPhrases(mixed $raw, string $reference, string $id, array &$violations): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $violations[] = 'invalid_critical_phrases:'.$id;

            return [];
        }
        if (count($raw) > (int) config('stt_candidate_eval.limits.max_critical_phrases_per_case', 25)) {
            $violations[] = 'too_many_critical_phrases:'.$id;
            $raw = array_slice($raw, 0, (int) config('stt_candidate_eval.limits.max_critical_phrases_per_case', 25));
        }
        $phrases = [];
        $normalizedReference = $this->normalizeText($reference);
        foreach ($raw as $phrase) {
            if (! is_string($phrase) || trim($phrase) === '' || mb_strlen($phrase) > (int) config('stt_candidate_eval.limits.max_critical_phrase_chars', 240)) {
                $violations[] = 'invalid_critical_phrase:'.$id;

                continue;
            }
            $normalized = $this->normalizeText($phrase);
            if ($normalized === '' || ! str_contains($normalizedReference, $normalized)) {
                $violations[] = 'critical_phrase_not_in_reference:'.$id;

                continue;
            }
            $phrases[$normalized] = $phrase;
        }

        return array_values($phrases);
    }

    /** @param list<string> $violations @param-out list<string> $violations */
    private function boundedText(mixed $raw, string $id, string $label, array &$violations): string
    {
        if (! is_string($raw)
            || ! mb_check_encoding($raw, 'UTF-8')
            || mb_strlen($raw) > (int) config('stt_candidate_eval.limits.max_text_chars', 2000)) {
            $violations[] = "invalid_{$label}_text:{$id}";

            return '';
        }

        return $raw;
    }

    /** @param list<string> $phrases */
    private function criticalPhraseRecall(array $phrases, string $transcript): float
    {
        if ($phrases === []) {
            return 1.0;
        }
        $normalized = $this->normalizeText($transcript);
        $matched = count(array_filter($phrases, fn (string $phrase): bool => str_contains($normalized, $this->normalizeText($phrase))));

        return $matched / count($phrases);
    }

    /** @param list<string> $reference @param list<string> $candidate */
    private function errorRate(array $reference, array $candidate): float
    {
        if ($reference === []) {
            return $candidate === [] ? 0.0 : 1.0;
        }

        return $this->editDistance($reference, $candidate) / count($reference);
    }

    /** @param list<string> $left @param list<string> $right */
    private function editDistance(array $left, array $right): int
    {
        $previous = range(0, count($right));
        foreach ($left as $leftIndex => $leftValue) {
            $current = [$leftIndex + 1];
            foreach ($right as $rightIndex => $rightValue) {
                $current[] = min(
                    $current[$rightIndex] + 1,
                    $previous[$rightIndex + 1] + 1,
                    $previous[$rightIndex] + ($leftValue === $rightValue ? 0 : 1)
                );
            }
            $previous = $current;
        }

        return $previous[count($right)];
    }

    /** @return list<string> */
    private function wordTokens(string $text): array
    {
        preg_match_all("/[\\p{L}\\p{N}]+(?:['’][\\p{L}\\p{N}]+)*/u", $this->normalizeText($text), $matches);

        return $matches[0] ?? [];
    }

    /** @return list<string> */
    private function characters(string $text): array
    {
        $normalized = str_replace(' ', '', $this->normalizeText($text));

        return $normalized === '' ? [] : (preg_split('//u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    private function normalizeText(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        }
        $text = mb_strtolower($text);
        $text = preg_replace("/[^\\p{L}\\p{N}'’]+/u", ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** @param list<array<string, mixed>> $rows */
    private function average(array $rows, string $key): float
    {
        if ($rows === []) {
            return 1.0;
        }

        return $this->round(array_sum(array_map(static fn (array $row): float => (float) ($row[$key] ?? 0), $rows)) / count($rows));
    }

    /** @return array<string, float|int> */
    private function thresholds(): array
    {
        return array_map(static fn (mixed $value): float|int => is_int($value) ? $value : (float) $value, (array) config('stt_candidate_eval.thresholds', []));
    }

    /** @return list<string> */
    private function requiredCapabilities(): array
    {
        return array_values(array_map('strval', (array) config('stt_candidate_eval.required_capabilities', [])));
    }

    /** @param array<string, mixed> $value @param list<string> $allowed @return list<string> */
    private function unknownKeys(array $value, array $allowed): array
    {
        return array_values(array_diff(array_keys($value), $allowed));
    }

    private function validIdentifier(mixed $value, int $max): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= $max && preg_match('/^[\p{L}\p{N}._:+\-\/ ]+$/u', $value) === 1;
    }

    private function validSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/i', $value) === 1;
    }

    private function positiveNumber(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value > 0 ? (float) $value : null;
    }

    private function safeString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function round(float $value): float
    {
        return round($value, 6);
    }

    /** @return array<string, string> */
    private function exampleIdentity(string $engine, string $model, string $device): array
    {
        return [
            'engine' => $engine,
            'version' => 'synthetic-1.0',
            'revision' => hash('sha256', $engine.'-revision'),
            'model' => $model,
            'model_sha256' => hash('sha256', $engine.'-'.$model.'-weights'),
            'device' => $device,
        ];
    }

    /** @param list<string> $capabilities @param list<string> $criticalPhrases @return array<string, mixed> */
    private function exampleCase(string $id, string $category, array $capabilities, string $language, float $duration, string $reference, string $baseline, float $baselineRuntime, float $candidateRuntime, array $criticalPhrases): array
    {
        return [
            'id' => $id,
            'category' => $category,
            'capabilities' => $capabilities,
            'evidence_sha256' => hash('sha256', 'synthetic-audio-'.$id),
            'expected_language' => $language,
            'audio_duration_seconds' => $duration,
            'reference_text' => $reference,
            'critical_phrases' => $criticalPhrases,
            'baseline' => ['transcript' => $baseline, 'language' => $language, 'runtime_seconds' => $baselineRuntime],
            'candidate' => ['transcript' => $reference, 'language' => $language, 'runtime_seconds' => $candidateRuntime],
        ];
    }
}
