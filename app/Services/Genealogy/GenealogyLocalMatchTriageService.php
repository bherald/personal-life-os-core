<?php

namespace App\Services\Genealogy;

use App\Services\AIService;

class GenealogyLocalMatchTriageService
{
    public function __construct(
        private readonly AIService $aiService
    ) {}

    public function triageCandidate(array $extractedPerson, array $candidatePerson, array $context = []): array
    {
        $preflight = $this->preflightTriage($extractedPerson, $candidatePerson, $context);
        if ($preflight !== null) {
            return $preflight;
        }

        $response = $this->aiService->process(
            $this->buildPrompt($extractedPerson, $candidatePerson, $context),
            [
                'model_role' => 'standard',
                'temperature' => 0.0,
                'max_tokens' => 500,
                'use_cache' => false,
                'dedup' => false,
                'factual_mode' => true,
                'task_type' => 'genealogy_local_source_triage',
            ]
        );

        if (! ($response['success'] ?? true)) {
            return [
                'label' => 'uncertain',
                'confidence' => 'low',
                'rationale' => $response['error'] ?? 'triage_failed',
            ];
        }

        return $this->parseResponse((string) ($response['response'] ?? ''));
    }

    private function buildPrompt(array $extractedPerson, array $candidatePerson, array $context): string
    {
        $documentType = (string) ($context['media_type'] ?? 'document');
        $title = (string) ($context['title'] ?? 'Genealogy document');
        $extracted = $this->normalizeExtracted($extractedPerson);
        $candidate = $this->normalizeCandidate($candidatePerson);

        return <<<PROMPT
Classify whether this extracted person and candidate tree person are the same person.

Return ONLY valid JSON with:
{
  "label": "same_person",
  "confidence": "high",
  "rationale": "short reason"
}

Allowed labels: same_person, collateral, wrong_person, uncertain

Document:
- type: {$documentType}
- title: {$title}

Extracted person:
- name: {$extracted['name']}
- given_name: {$extracted['given_name']}
- surname: {$extracted['surname']}
- birth_year: {$extracted['birth_year']}
- role: {$extracted['role']}

Candidate person:
- given_name: {$candidate['given_name']}
- surname: {$candidate['surname']}
- birth_date: {$candidate['birth_date']}

Prefer collateral or uncertain over false same_person.
PROMPT;
    }

    private function preflightTriage(array $extractedPerson, array $candidatePerson, array $context): ?array
    {
        $extracted = $this->normalizeExtracted($extractedPerson);
        $candidate = $this->normalizeCandidate($candidatePerson);
        $allowPhoneticSurname = (bool) ($context['allow_phonetic_surname'] ?? false);

        if ($extracted['surname_key'] !== '' && $candidate['surname_key'] !== '' && $extracted['surname_key'] !== $candidate['surname_key'] && ! $allowPhoneticSurname) {
            return [
                'label' => 'wrong_person',
                'confidence' => 'high',
                'rationale' => 'surname_mismatch',
            ];
        }

        if ($extracted['birth_year'] !== null && $candidate['birth_year'] !== null) {
            $gap = abs($extracted['birth_year'] - $candidate['birth_year']);
            if ($gap > 10) {
                return [
                    'label' => 'wrong_person',
                    'confidence' => 'high',
                    'rationale' => 'birth_year_gap_'.$gap,
                ];
            }
        }

        if (
            $extracted['given_key'] !== ''
            && $candidate['given_key'] !== ''
            && strlen($extracted['given_key']) >= 3
            && $extracted['surname_key'] !== ''
            && $candidate['surname_key'] !== ''
            && $extracted['surname_key'] === $candidate['surname_key']
            && str_starts_with($candidate['given_key'], $extracted['given_key'])
            && $extracted['birth_year'] !== null
            && $candidate['birth_year'] !== null
            && abs($extracted['birth_year'] - $candidate['birth_year']) <= 3
        ) {
            return [
                'label' => 'same_person',
                'confidence' => 'high',
                'rationale' => 'deterministic_name_year_match',
            ];
        }

        return null;
    }

    private function normalizeExtracted(array $person): array
    {
        $name = trim((string) ($person['name'] ?? ''));
        $givenName = trim((string) ($person['given_name'] ?? ''));
        $surname = trim((string) ($person['surname'] ?? ''));

        if (($givenName === '' || $surname === '') && $name !== '') {
            $parts = preg_split('/\s+/', $name) ?: [];
            if ($surname === '' && count($parts) > 1) {
                $surname = (string) array_pop($parts);
            }
            if ($givenName === '') {
                $givenName = implode(' ', $parts);
            }
        }

        $birthYear = $this->normalizeYear($person['birth_year'] ?? $person['birth_date'] ?? null);

        return [
            'name' => $name !== '' ? $name : trim($givenName.' '.$surname),
            'given_name' => $givenName,
            'surname' => $surname,
            'given_key' => $this->normalizeNameKey($givenName),
            'surname_key' => $this->normalizeNameKey($surname),
            'birth_year' => $birthYear,
            'role' => trim((string) ($person['role'] ?? '')),
        ];
    }

    private function normalizeCandidate(array $person): array
    {
        $givenName = trim((string) ($person['given_name'] ?? ''));
        $surname = trim((string) ($person['surname'] ?? ''));
        $birthDate = $person['birth_date'] ?? null;

        return [
            'given_name' => $givenName,
            'surname' => $surname,
            'given_key' => $this->normalizeNameKey($givenName),
            'surname_key' => $this->normalizeNameKey($surname),
            'birth_date' => trim((string) $birthDate),
            'birth_year' => $this->normalizeYear($birthDate),
        ];
    }

    private function normalizeYear(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match('/\b(1[5-9]\d{2}|20\d{2})\b/', (string) $value, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function normalizeNameKey(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z]/i', '', $value));
    }

    private function parseResponse(string $response): array
    {
        $decoded = json_decode(trim($response), true);
        if (! is_array($decoded) && preg_match('/\{[\s\S]*\}/', $response, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
        }

        if (! is_array($decoded)) {
            return [
                'label' => 'uncertain',
                'confidence' => 'low',
                'rationale' => 'invalid_json',
            ];
        }

        return [
            'label' => strtolower((string) ($decoded['label'] ?? 'uncertain')),
            'confidence' => strtolower((string) ($decoded['confidence'] ?? 'low')),
            'rationale' => (string) ($decoded['rationale'] ?? ''),
        ];
    }
}
