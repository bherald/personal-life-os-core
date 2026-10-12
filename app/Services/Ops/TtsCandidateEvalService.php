<?php

namespace App\Services\Ops;

use Normalizer;

final class TtsCandidateEvalService
{
    public const ARTIFACT_SCHEMA = 'plos.tts_candidate.v1';

    public const RESULT_SCHEMA = 'plos.tts_candidate_eval.v1';

    /** @param array<string, mixed> $artifact @return array<string, mixed> */
    public function evaluate(array $artifact): array
    {
        $violations = [];
        $this->validateEnvelope($artifact, $violations);
        $baselineIdentity = $this->identity('baseline', $artifact['baseline'] ?? null, $violations);
        $candidateIdentity = $this->identity('candidate', $artifact['candidate'] ?? null, $violations);
        $assessor = $this->assessor($artifact['quality_assessor'] ?? null, $violations);
        $cases = $this->evaluateCases($artifact['cases'] ?? null, $violations);
        $thresholds = $this->thresholds();

        $ids = array_column($cases, 'case');
        if (count($ids) !== count(array_unique($ids))) {
            $violations[] = 'duplicate_case_id';
        }
        if (count($cases) < $thresholds['min_cases']) {
            $violations[] = 'insufficient_cases';
        }
        if (count(array_unique(array_column($cases, 'category'))) < $thresholds['min_categories']) {
            $violations[] = 'insufficient_categories';
        }

        $coveredRaw = [];
        foreach ($cases as $case) {
            $coveredRaw = array_merge($coveredRaw, (array) ($case['capabilities'] ?? []));
        }
        $covered = array_values(array_filter($this->requiredCapabilities(), static fn (string $capability): bool => in_array($capability, $coveredRaw, true)));
        $missing = array_values(array_diff($this->requiredCapabilities(), $covered));
        foreach ($missing as $capability) {
            $violations[] = 'missing_capability:'.$capability;
        }

        $baseline = $this->aggregate($cases, 'baseline');
        $candidate = $this->aggregate($cases, 'candidate');
        $deltas = [
            'word_error_rate' => $this->round($candidate['word_error_rate'] - $baseline['word_error_rate']),
            'character_error_rate' => $this->round($candidate['character_error_rate'] - $baseline['character_error_rate']),
            'critical_phrase_recall' => $this->round($candidate['critical_phrase_recall'] - $baseline['critical_phrase_recall']),
            'naturalness_score' => $this->round($candidate['naturalness_score'] - $baseline['naturalness_score']),
            'real_time_factor' => $this->round($candidate['real_time_factor'] - $baseline['real_time_factor']),
        ];
        $regressions = array_values(array_unique(array_merge(...array_map(static fn (array $case): array => $case['regressions'], $cases))));
        if ($candidate['real_time_factor'] > $baseline['real_time_factor'] + $thresholds['max_aggregate_real_time_factor_regression']) {
            $regressions[] = 'aggregate:real_time_factor';
        }
        $failures = $this->caseQualityFailures($cases, $thresholds);
        $qualityPassed = $candidate['word_error_rate'] <= $thresholds['max_word_error_rate']
            && $candidate['character_error_rate'] <= $thresholds['max_character_error_rate']
            && $candidate['critical_phrase_recall'] >= $thresholds['min_critical_phrase_recall']
            && $candidate['language_accuracy'] >= $thresholds['min_language_accuracy']
            && $candidate['naturalness_score'] >= $thresholds['min_naturalness_score']
            && $candidate['real_time_factor'] <= $thresholds['max_real_time_factor'];
        $improved = ($baseline['word_error_rate'] - $candidate['word_error_rate']) >= $thresholds['min_word_error_rate_improvement']
            || ($candidate['naturalness_score'] - $baseline['naturalness_score']) >= $thresholds['min_naturalness_improvement']
            || ($baseline['real_time_factor'] - $candidate['real_time_factor']) >= $thresholds['min_real_time_factor_improvement'];

        $violations = array_values(array_unique($violations));
        $regressions = array_values(array_unique($regressions));
        $eligible = $violations === [] && $regressions === [] && $failures === [] && $qualityPassed && $improved;

        return [
            'schema' => self::RESULT_SCHEMA,
            'status' => $eligible ? 'eligible_for_review' : ($violations === [] ? 'not_eligible' : 'invalid'),
            'read_only' => true,
            'generated_at' => now('UTC')->toIso8601String(),
            'candidate_id' => $this->safeString($artifact['candidate_id'] ?? null),
            'baseline_identity' => $baselineIdentity,
            'candidate_identity' => $candidateIdentity,
            'quality_assessor' => $assessor,
            'baseline' => $baseline,
            'candidate' => $candidate,
            'deltas' => $deltas,
            'thresholds' => $thresholds,
            'capability_coverage' => ['required' => $this->requiredCapabilities(), 'covered' => $covered, 'missing' => $missing],
            'regressions' => $regressions,
            'case_quality_failures' => $failures,
            'violations' => $violations,
            'cases' => $cases,
            'promotion' => [
                'eligible_for_operator_review' => $eligible,
                'quality_thresholds_passed' => $qualityPassed,
                'case_quality_floors_passed' => $failures === [],
                'required_capabilities_covered' => $missing === [],
                'measurable_intelligibility_naturalness_or_speed_improvement' => $improved,
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
                'audio_tts_asr_or_quality_runtime_calls' => false,
                'production_tts_route_changed' => false,
                'model_voice_or_weight_installed' => false,
                'prompt_compression_changed' => false,
                'precompaction_changed' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function exampleArtifact(): array
    {
        $cases = [
            ['short_greeting', 'short', ['short_form'], 'Hello, Ada.', ['Hello', 'Ada']],
            ['plain_notice', 'plain', ['plain_language'], 'The library closes at six this evening.', ['library', 'six']],
            ['family_record', 'names_dates', ['names_dates'], "Margaret O'Brien married Samuel Müller on 14 October 1912.", ["Margaret O'Brien", 'Samuel Müller', '14 October 1912']],
            ['measurement', 'numbers_units', ['numbers_units'], 'Add 2.5 milliliters and heat the sample to 37 degrees Celsius.', ['2.5 milliliters', '37 degrees Celsius']],
            ['initialisms', 'abbreviations', ['abbreviations'], 'The API sends JSON over HTTPS to the local GPU.', ['API', 'JSON', 'HTTPS', 'GPU']],
            ['quoted_question', 'prosody', ['punctuation_prosody'], 'She asked, "Are we ready?" Then she paused and said, "Begin."', ['Are we ready', 'Begin']],
            ['long_paragraph', 'long_form', ['long_form'], str_repeat('The witness reviewed the record and confirmed the date. ', 8), ['reviewed the record', 'confirmed the date']],
            ['difficult_words', 'pronunciation', ['pronunciation_challenge'], 'Siobhan met Joaquin in Schuylkill County near Mauch Chunk.', ['Siobhan', 'Joaquin', 'Schuylkill', 'Mauch Chunk']],
        ];

        return [
            'schema' => self::ARTIFACT_SCHEMA,
            'candidate_id' => 'synthetic-kokoro-contract-v1',
            'baseline' => $this->exampleIdentity('piper', 'en_US-lessac-medium', 'lessac'),
            'candidate' => $this->exampleIdentity('kokoro-onnx', 'Kokoro-82M', 'af_heart'),
            'quality_assessor' => [
                'method' => 'human_blind_rating',
                'name' => 'synthetic-contract-panel',
                'version' => '1.0',
                'revision' => hash('sha256', 'synthetic-contract-panel-revision'),
                'protocol_sha256' => hash('sha256', 'synthetic-contract-panel-protocol'),
                'license' => 'CC-BY-4.0',
                'license_reviewed' => true,
                'blind' => true,
                'listener_count' => 5,
            ],
            'operator_review_required' => true,
            'cases' => array_map(fn (array $case): array => $this->exampleCase(...$case), $cases),
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function compactPayload(array $payload): array
    {
        $payload['compact'] = true;
        unset($payload['cases']);

        return $payload;
    }

    /** @param list<string> $violations @param-out list<string> $violations */
    private function validateEnvelope(array $artifact, array &$violations): void
    {
        if ($this->unknownKeys($artifact, ['schema', 'candidate_id', 'baseline', 'candidate', 'quality_assessor', 'operator_review_required', 'cases']) !== []) {
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

    /** @param list<string> $violations @param-out list<string> $violations @return array<string, mixed> */
    private function identity(string $label, mixed $raw, array &$violations): array
    {
        $keys = ['engine', 'version', 'revision', 'model', 'model_sha256', 'model_license', 'voice', 'voice_sha256', 'voice_license', 'device', 'license_reviewed'];
        if (! is_array($raw) || array_is_list($raw)) {
            $violations[] = "invalid_{$label}_identity";
            $raw = [];
        }
        if ($this->unknownKeys($raw, $keys) !== []) {
            $violations[] = "unknown_{$label}_identity_field";
        }
        foreach (['engine', 'version', 'revision', 'model', 'model_license', 'voice', 'voice_license', 'device'] as $key) {
            if (! $this->validIdentifier($raw[$key] ?? null, 200)) {
                $violations[] = "invalid_{$label}_{$key}";
            }
        }
        foreach (['model_sha256', 'voice_sha256'] as $key) {
            if (! $this->validSha256($raw[$key] ?? null)) {
                $violations[] = "invalid_{$label}_{$key}";
            }
        }
        if (($raw['license_reviewed'] ?? null) !== true) {
            $violations[] = "{$label}_license_not_reviewed";
        }

        return array_intersect_key($raw, array_flip($keys));
    }

    /** @param list<string> $violations @param-out list<string> $violations @return array<string, mixed> */
    private function assessor(mixed $raw, array &$violations): array
    {
        $keys = ['method', 'name', 'version', 'revision', 'protocol_sha256', 'license', 'license_reviewed', 'blind', 'listener_count'];
        if (! is_array($raw) || array_is_list($raw)) {
            $violations[] = 'invalid_quality_assessor';
            $raw = [];
        }
        if ($this->unknownKeys($raw, $keys) !== []) {
            $violations[] = 'unknown_quality_assessor_field';
        }
        $method = $raw['method'] ?? null;
        if (! in_array($method, ['human_blind_rating', 'model_naturalness_estimator'], true)) {
            $violations[] = 'invalid_quality_assessor_method';
        }
        foreach (['name', 'version', 'revision', 'license'] as $key) {
            if (! $this->validIdentifier($raw[$key] ?? null, 200)) {
                $violations[] = 'invalid_quality_assessor_'.$key;
            }
        }
        if (! $this->validSha256($raw['protocol_sha256'] ?? null)) {
            $violations[] = 'invalid_quality_assessor_protocol_sha256';
        }
        if (($raw['license_reviewed'] ?? null) !== true || ($raw['blind'] ?? null) !== true) {
            $violations[] = 'quality_assessor_review_or_blinding_missing';
        }
        $listeners = filter_var($raw['listener_count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($listeners === false || ($method === 'human_blind_rating' && $listeners < 1) || ($method === 'model_naturalness_estimator' && $listeners !== 0)) {
            $violations[] = 'invalid_quality_assessor_listener_count';
        }

        return array_intersect_key($raw, array_flip($keys));
    }

    /** @param list<string> $violations @param-out list<string> $violations @return list<array<string, mixed>> */
    private function evaluateCases(mixed $raw, array &$violations): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $violations[] = 'cases_must_be_a_list';

            return [];
        }
        $max = max(1, (int) config('tts_candidate_eval.limits.max_cases', 24));
        if (count($raw) > $max) {
            $violations[] = 'too_many_cases';
            $raw = array_slice($raw, 0, $max);
        }

        return array_map(fn (mixed $case, int $index): array => $this->evaluateCase($case, $index, $violations), $raw, array_keys($raw));
    }

    /** @param list<string> $violations @param-out list<string> $violations @return array<string, mixed> */
    private function evaluateCase(mixed $raw, int $index, array &$violations): array
    {
        $fallback = 'case_'.$index;
        if (! is_array($raw) || array_is_list($raw)) {
            $violations[] = 'invalid_case:'.$fallback;
            $raw = [];
        }
        $keys = ['id', 'category', 'capabilities', 'evidence_sha256', 'language', 'input_text', 'expected_transcript', 'critical_phrases', 'baseline', 'candidate'];
        if ($this->unknownKeys($raw, $keys) !== []) {
            $violations[] = 'unknown_case_field:'.$fallback;
        }
        $id = $this->validIdentifier($raw['id'] ?? null, 160) ? (string) $raw['id'] : $fallback;
        if ($id === $fallback && ($raw['id'] ?? null) !== $fallback) {
            $violations[] = 'invalid_case_id:'.$fallback;
        }
        $category = $this->validIdentifier($raw['category'] ?? null, 120) ? (string) $raw['category'] : 'invalid';
        if ($category === 'invalid') {
            $violations[] = 'invalid_case_category:'.$id;
        }
        $capabilities = $this->capabilities($raw['capabilities'] ?? null, $id, $violations);
        if (! $this->validSha256($raw['evidence_sha256'] ?? null)) {
            $violations[] = 'invalid_evidence_sha256:'.$id;
        }
        $language = strtolower(trim((string) ($raw['language'] ?? '')));
        if (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $language) !== 1) {
            $violations[] = 'invalid_language:'.$id;
        }
        $input = $this->boundedText($raw['input_text'] ?? null, $id, 'input', $violations);
        $expected = $this->boundedText($raw['expected_transcript'] ?? null, $id, 'expected', $violations);
        if ($this->normalizeText($input) === '' || $this->normalizeText($expected) === '') {
            $violations[] = 'empty_case_text:'.$id;
        }
        $phrases = $this->criticalPhrases($raw['critical_phrases'] ?? null, $expected, $id, $violations);
        $baseline = $this->caseOutput('baseline', $raw['baseline'] ?? null, $expected, $phrases, $language, $id, $violations);
        $candidate = $this->caseOutput('candidate', $raw['candidate'] ?? null, $expected, $phrases, $language, $id, $violations);
        $tolerance = (float) config('tts_candidate_eval.thresholds.max_case_quality_regression', 0.0);
        $regressions = [];
        foreach (['word_error_rate', 'character_error_rate'] as $metric) {
            if ($candidate[$metric] > $baseline[$metric] + $tolerance) {
                $regressions[] = $id.':'.$metric;
            }
        }
        foreach (['critical_phrase_recall', 'language_correct', 'naturalness_score'] as $metric) {
            if ((float) $candidate[$metric] + $tolerance < (float) $baseline[$metric]) {
                $regressions[] = $id.':'.$metric;
            }
        }
        foreach (['clipped_sample_ratio', 'peak_dbfs', 'leading_silence_seconds', 'trailing_silence_seconds'] as $metric) {
            if ($candidate[$metric] > $baseline[$metric] + $tolerance) {
                $regressions[] = $id.':'.$metric;
            }
        }
        if ($candidate['sample_rate_hz'] < $baseline['sample_rate_hz']) {
            $regressions[] = $id.':sample_rate_hz';
        }

        return [
            'case' => $id,
            'category' => $category,
            'capabilities' => $capabilities,
            'evidence_sha256' => $this->validSha256($raw['evidence_sha256'] ?? null) ? strtolower((string) $raw['evidence_sha256']) : null,
            'input_sha256' => hash('sha256', $input),
            'expected_transcript_sha256' => hash('sha256', $expected),
            'critical_phrase_count' => count($phrases),
            'baseline' => $baseline,
            'candidate' => $candidate,
            'regressions' => $regressions,
        ];
    }

    /** @param list<string> $phrases @param list<string> $violations @param-out list<string> $violations @return array<string, mixed> */
    private function caseOutput(string $label, mixed $raw, string $expected, array $phrases, string $language, string $id, array &$violations): array
    {
        $keys = ['audio_sha256', 'transcript', 'language', 'runtime_seconds', 'audio_duration_seconds', 'sample_rate_hz', 'channels', 'peak_dbfs', 'integrated_lufs', 'clipped_sample_ratio', 'leading_silence_seconds', 'trailing_silence_seconds', 'naturalness_score'];
        if (! is_array($raw) || array_is_list($raw)) {
            $violations[] = "invalid_{$label}_output:{$id}";
            $raw = [];
        }
        if ($this->unknownKeys($raw, $keys) !== []) {
            $violations[] = "unknown_{$label}_output_field:{$id}";
        }
        if (! $this->validSha256($raw['audio_sha256'] ?? null)) {
            $violations[] = "invalid_{$label}_audio_sha256:{$id}";
        }
        $transcript = $this->boundedText($raw['transcript'] ?? null, $id, $label, $violations);
        $actualLanguage = strtolower(trim((string) ($raw['language'] ?? '')));
        if (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $actualLanguage) !== 1) {
            $violations[] = "invalid_{$label}_language:{$id}";
        }
        $runtime = $this->positiveNumber($raw['runtime_seconds'] ?? null);
        $duration = $this->positiveNumber($raw['audio_duration_seconds'] ?? null);
        if ($runtime === null || $duration === null || $duration > (float) config('tts_candidate_eval.limits.max_audio_duration_seconds', 3600)) {
            $violations[] = "invalid_{$label}_duration:{$id}";
            $runtime = $runtime ?? 1.0;
            $duration = 1.0;
        }
        $sampleRate = filter_var($raw['sample_rate_hz'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 384000]]);
        $channels = filter_var($raw['channels'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 8]]);
        if ($sampleRate === false) {
            $violations[] = "invalid_{$label}_sample_rate:{$id}";
            $sampleRate = 0;
        }
        if ($channels === false) {
            $violations[] = "invalid_{$label}_channels:{$id}";
            $channels = 0;
        }
        $numbers = [];
        foreach (['peak_dbfs', 'integrated_lufs', 'clipped_sample_ratio', 'leading_silence_seconds', 'trailing_silence_seconds', 'naturalness_score'] as $key) {
            $numbers[$key] = $this->finiteNumber($raw[$key] ?? null);
            if ($numbers[$key] === null) {
                $violations[] = "invalid_{$label}_{$key}:{$id}";
                $numbers[$key] = 0.0;
            }
        }
        if ($numbers['clipped_sample_ratio'] < 0 || $numbers['clipped_sample_ratio'] > 1 || $numbers['leading_silence_seconds'] < 0 || $numbers['trailing_silence_seconds'] < 0 || $numbers['naturalness_score'] < 1 || $numbers['naturalness_score'] > 5) {
            $violations[] = "invalid_{$label}_metric_range:{$id}";
        }

        return [
            'audio_sha256' => $this->validSha256($raw['audio_sha256'] ?? null) ? strtolower((string) $raw['audio_sha256']) : null,
            'transcript_sha256' => hash('sha256', $transcript),
            'word_error_rate' => $this->round($this->errorRate($this->wordTokens($expected), $this->wordTokens($transcript))),
            'character_error_rate' => $this->round($this->errorRate($this->characters($expected), $this->characters($transcript))),
            'critical_phrase_recall' => $this->round($this->criticalPhraseRecall($phrases, $transcript)),
            'language_correct' => hash_equals($language, $actualLanguage),
            'real_time_factor' => $this->round($runtime / $duration),
            'audio_duration_seconds' => $this->round($duration),
            'sample_rate_hz' => $sampleRate,
            'channels' => $channels,
            'peak_dbfs' => $this->round($numbers['peak_dbfs']),
            'integrated_lufs' => $this->round($numbers['integrated_lufs']),
            'clipped_sample_ratio' => $this->round($numbers['clipped_sample_ratio']),
            'leading_silence_seconds' => $this->round($numbers['leading_silence_seconds']),
            'trailing_silence_seconds' => $this->round($numbers['trailing_silence_seconds']),
            'naturalness_score' => $this->round($numbers['naturalness_score']),
        ];
    }

    /** @param list<array<string, mixed>> $cases @return array<string, int|float> */
    private function aggregate(array $cases, string $label): array
    {
        $outputs = array_column($cases, $label);

        return [
            'case_count' => count($cases),
            'word_error_rate' => $this->average($outputs, 'word_error_rate'),
            'character_error_rate' => $this->average($outputs, 'character_error_rate'),
            'critical_phrase_recall' => $this->average($outputs, 'critical_phrase_recall'),
            'language_accuracy' => $this->average($outputs, 'language_correct'),
            'naturalness_score' => $this->average($outputs, 'naturalness_score'),
            'real_time_factor' => $this->average($outputs, 'real_time_factor'),
        ];
    }

    /** @param list<array<string, mixed>> $cases @param array<string, int|float> $thresholds @return list<string> */
    private function caseQualityFailures(array $cases, array $thresholds): array
    {
        $failures = [];
        foreach ($cases as $case) {
            $out = $case['candidate'];
            $id = $case['case'];
            $checks = [
                'word_error_rate' => $out['word_error_rate'] <= $thresholds['max_case_word_error_rate'],
                'character_error_rate' => $out['character_error_rate'] <= $thresholds['max_case_character_error_rate'],
                'critical_phrase_recall' => $case['critical_phrase_count'] === 0 || $out['critical_phrase_recall'] >= 1.0,
                'language_correct' => $out['language_correct'],
                'naturalness_score' => $out['naturalness_score'] >= $thresholds['min_case_naturalness_score'],
                'clipped_sample_ratio' => $out['clipped_sample_ratio'] <= $thresholds['max_clipped_sample_ratio'],
                'peak_dbfs' => $out['peak_dbfs'] <= $thresholds['max_peak_dbfs'],
                'integrated_lufs' => $out['integrated_lufs'] >= $thresholds['min_integrated_lufs'] && $out['integrated_lufs'] <= $thresholds['max_integrated_lufs'],
                'leading_silence_seconds' => $out['leading_silence_seconds'] <= $thresholds['max_leading_silence_seconds'],
                'trailing_silence_seconds' => $out['trailing_silence_seconds'] <= $thresholds['max_trailing_silence_seconds'],
                'sample_rate_hz' => $out['sample_rate_hz'] >= $thresholds['min_sample_rate_hz'],
                'channels' => in_array($out['channels'], [1, 2], true),
            ];
            foreach ($checks as $metric => $passed) {
                if (! $passed) {
                    $failures[] = $id.':'.$metric;
                }
            }
        }

        return $failures;
    }

    /** @param list<string> $violations @param-out list<string> $violations @return list<string> */
    private function capabilities(mixed $raw, string $id, array &$violations): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $violations[] = 'invalid_capabilities:'.$id;

            return [];
        }
        if (count($raw) > (int) config('tts_candidate_eval.limits.max_capabilities_per_case', 4)) {
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
    private function criticalPhrases(mixed $raw, string $expected, string $id, array &$violations): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $violations[] = 'invalid_critical_phrases:'.$id;

            return [];
        }
        $max = (int) config('tts_candidate_eval.limits.max_critical_phrases_per_case', 25);
        if (count($raw) > $max) {
            $violations[] = 'too_many_critical_phrases:'.$id;
            $raw = array_slice($raw, 0, $max);
        }
        $phrases = [];
        $normalizedExpected = $this->normalizeText($expected);
        foreach ($raw as $phrase) {
            if (! is_string($phrase) || trim($phrase) === '' || mb_strlen($phrase) > (int) config('tts_candidate_eval.limits.max_critical_phrase_chars', 240)) {
                $violations[] = 'invalid_critical_phrase:'.$id;

                continue;
            }
            $normalized = $this->normalizeText($phrase);
            if ($normalized === '' || ! str_contains($normalizedExpected, $normalized)) {
                $violations[] = 'critical_phrase_not_in_expected:'.$id;

                continue;
            }
            $phrases[$normalized] = $phrase;
        }

        return array_values($phrases);
    }

    /** @param list<string> $violations @param-out list<string> $violations */
    private function boundedText(mixed $raw, string $id, string $label, array &$violations): string
    {
        if (! is_string($raw) || ! mb_check_encoding($raw, 'UTF-8') || mb_strlen($raw) > (int) config('tts_candidate_eval.limits.max_text_chars', 2000)) {
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

        return count(array_filter($phrases, fn (string $phrase): bool => str_contains($normalized, $this->normalizeText($phrase)))) / count($phrases);
    }

    /** @param list<string> $reference @param list<string> $candidate */
    private function errorRate(array $reference, array $candidate): float
    {
        if ($reference === []) {
            return $candidate === [] ? 0.0 : 1.0;
        }
        $previous = range(0, count($candidate));
        foreach ($reference as $leftIndex => $leftValue) {
            $current = [$leftIndex + 1];
            foreach ($candidate as $rightIndex => $rightValue) {
                $current[] = min($current[$rightIndex] + 1, $previous[$rightIndex + 1] + 1, $previous[$rightIndex] + ($leftValue === $rightValue ? 0 : 1));
            }
            $previous = $current;
        }

        return $previous[count($candidate)] / count($reference);
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
        return $rows === [] ? 0.0 : $this->round(array_sum(array_map(static fn (array $row): float => (float) ($row[$key] ?? 0), $rows)) / count($rows));
    }

    /** @return array<string, int|float> */
    private function thresholds(): array
    {
        return array_map(static fn (mixed $value): int|float => is_int($value) ? $value : (float) $value, (array) config('tts_candidate_eval.thresholds', []));
    }

    /** @return list<string> */
    private function requiredCapabilities(): array
    {
        return array_values(array_map('strval', (array) config('tts_candidate_eval.required_capabilities', [])));
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
        $number = $this->finiteNumber($value);

        return $number !== null && $number > 0 ? $number : null;
    }

    private function finiteNumber(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private function safeString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function round(float $value): float
    {
        return round($value, 6);
    }

    /** @return array<string, mixed> */
    private function exampleIdentity(string $engine, string $model, string $voice): array
    {
        return [
            'engine' => $engine,
            'version' => 'synthetic-1.0',
            'revision' => hash('sha256', $engine.'-revision'),
            'model' => $model,
            'model_sha256' => hash('sha256', $engine.'-'.$model.'-model'),
            'model_license' => $engine === 'kokoro-onnx' ? 'Apache-2.0' : 'MIT',
            'voice' => $voice,
            'voice_sha256' => hash('sha256', $engine.'-'.$voice.'-voice'),
            'voice_license' => 'Apache-2.0',
            'device' => 'cpu',
            'license_reviewed' => true,
        ];
    }

    /** @param list<string> $capabilities @param list<string> $phrases @return array<string, mixed> */
    private function exampleCase(string $id, string $category, array $capabilities, string $text, array $phrases): array
    {
        $baselineTranscript = str_replace(['the ', 'The '], '', $text);

        return [
            'id' => $id,
            'category' => $category,
            'capabilities' => $capabilities,
            'evidence_sha256' => hash('sha256', 'synthetic-tts-evidence-'.$id),
            'language' => 'en',
            'input_text' => $text,
            'expected_transcript' => $text,
            'critical_phrases' => $phrases,
            'baseline' => $this->exampleOutput($id, 'baseline', $baselineTranscript, 3.6, 1.6),
            'candidate' => $this->exampleOutput($id, 'candidate', $text, 4.1, 0.7),
        ];
    }

    /** @return array<string, mixed> */
    private function exampleOutput(string $id, string $label, string $transcript, float $naturalness, float $runtime): array
    {
        return [
            'audio_sha256' => hash('sha256', "synthetic-{$label}-audio-{$id}"),
            'transcript' => $transcript,
            'language' => 'en',
            'runtime_seconds' => $runtime,
            'audio_duration_seconds' => 2.0,
            'sample_rate_hz' => 24000,
            'channels' => 1,
            'peak_dbfs' => -1.0,
            'integrated_lufs' => -20.0,
            'clipped_sample_ratio' => 0.0,
            'leading_silence_seconds' => 0.1,
            'trailing_silence_seconds' => 0.2,
            'naturalness_score' => $naturalness,
        ];
    }
}
