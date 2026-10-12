<?php

namespace App\Services\Genealogy;

use App\Services\Genealogy\Support\GivenNameVariants;
use App\Services\Genealogy\Support\ProximityNameMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenealogyReviewProposalQualityService
{
    public const POLICY = 'proof_grade_ft4_v1';

    private const FACT_FIELDS = [
        'birth_date',
        'birth_place',
        'death_date',
        'death_place',
        'burial_date',
        'burial_place',
        'occupation',
        'education',
        'religion',
        'title',
        'nationality',
        'cause_of_death',
    ];

    private const FACT_CHANGE_TYPES = ['fact_update', 'update'];

    private const CORROBORATING_ANCHOR_TYPES = [
        'date',
        'year',
        'place',
        'location',
        'relative',
        'spouse',
        'parent',
        'child',
        'sibling',
        'household',
        'occupation',
        'source_chain',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @return array<int, array<string, mixed>>
     */
    public function filter(int $personId, array $proposals, string $agentId, ?int $treeId = null): array
    {
        $person = $this->person($personId, $treeId);
        if ($person === null) {
            Log::warning('Genealogy proof-grade proposal gate rejected bundle', [
                'person_id' => $personId,
                'agent_id' => $agentId,
                'gate' => 'person_not_found',
                'input_count' => count($proposals),
            ]);

            return [];
        }

        $survivors = [];
        $rejectedByGate = [];
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                $rejectedByGate['invalid_proposal_shape'] = ($rejectedByGate['invalid_proposal_shape'] ?? 0) + 1;

                continue;
            }

            $result = $this->evaluateForPerson($person, $proposal);
            if ($result['ok']) {
                $survivors[] = $result['proposal'];

                continue;
            }

            $gate = (string) ($result['gate'] ?? 'unknown');
            $rejectedByGate[$gate] = ($rejectedByGate[$gate] ?? 0) + 1;
        }

        Log::info('Genealogy proof-grade proposal gate summary', [
            'person_id' => $personId,
            'agent_id' => $agentId,
            'policy' => self::POLICY,
            'input_count' => count($proposals),
            'survivor_count' => count($survivors),
            'rejected_count' => count($proposals) - count($survivors),
            'rejected_by_gate' => $rejectedByGate,
        ]);

        return $survivors;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array{ok: bool, proposal?: array<string, mixed>, gate?: string, reason?: string}
     */
    public function evaluate(int $personId, array $proposal, ?int $treeId = null): array
    {
        $person = $this->person($personId, $treeId);
        if ($person === null) {
            return $this->reject('person_not_found', 'The FT4 person no longer exists.');
        }

        return $this->evaluateForPerson($person, $proposal);
    }

    /**
     * @param  array<string, mixed>  $person
     * @param  array<string, mixed>  $proposal
     * @return array{ok: bool, proposal?: array<string, mixed>, gate?: string, reason?: string}
     */
    private function evaluateForPerson(array $person, array $proposal): array
    {
        $changeType = strtolower(trim((string) ($proposal['change_type'] ?? '')));
        if (! in_array($changeType, array_merge(self::FACT_CHANGE_TYPES, ['source_add']), true)) {
            return $this->reject(
                'unsupported_canary_change_type',
                'The proof-grade canary accepts fact updates and record-level source additions only.'
            );
        }

        $confidence = is_numeric($proposal['confidence'] ?? null)
            ? (float) $proposal['confidence']
            : 0.0;
        $minimumConfidence = $this->minimumConfidence();
        if ($confidence < $minimumConfidence) {
            return $this->reject(
                'below_actionability_confidence',
                sprintf('Proposal confidence %.3f is below the %.3f Genea review threshold.', $confidence, $minimumConfidence)
            );
        }

        $locator = $this->sourceLocator($proposal, $changeType);
        if ($locator === null) {
            return $this->reject('source_locator_required', 'A stable record-level HTTP(S) source locator is required.');
        }

        $citation = $this->sourceCitation($proposal);
        if ($citation === null) {
            return $this->reject('source_citation_required', 'A record-level source citation is required.');
        }

        $recordTitle = trim((string) ($proposal['record_title'] ?? ''));
        if (mb_strlen($recordTitle) < 5) {
            return $this->reject('record_title_required', 'A specific record title is required.');
        }

        $recordExcerpt = trim((string) ($proposal['record_text_excerpt'] ?? $proposal['extracted_text'] ?? ''));
        if (mb_strlen($recordExcerpt) < 20) {
            return $this->reject('record_text_required', 'A short record-text excerpt is required.');
        }

        $givenName = trim((string) ($person['given_name'] ?? ''));
        $surname = trim((string) ($person['surname'] ?? ''));
        if (! ProximityNameMatcher::matchesFullName(
            $recordExcerpt,
            $givenName,
            $surname,
            null,
            GivenNameVariants::variantsFor($givenName)
        )) {
            return $this->reject(
                'record_text_identity_mismatch',
                'The record excerpt does not identify the FT4 person by given name and surname in proximity.'
            );
        }

        $anchors = $this->identityAnchors($proposal['identity_anchors'] ?? null);
        if (! $this->hasNameAnchor($anchors, $givenName, $surname)) {
            return $this->reject('name_identity_anchor_required', 'Identity anchors must include the matched person name.');
        }
        if (! $this->hasCorroboratingAnchor($anchors)) {
            return $this->reject(
                'corroborating_identity_anchor_required',
                'Identity anchors need a date, place, relative, household, occupation, or source-chain bridge.'
            );
        }

        $evidenceSummary = trim((string) ($proposal['evidence_summary'] ?? ''));
        if (mb_strlen($evidenceSummary) < 20) {
            return $this->reject('evidence_summary_required', 'A concise evidence explanation is required.');
        }

        if (in_array($changeType, self::FACT_CHANGE_TYPES, true)) {
            $field = trim((string) ($proposal['field_name'] ?? ''));
            if (! in_array($field, self::FACT_FIELDS, true)) {
                return $this->reject('unsupported_fact_field', 'The proposed FT4 field is not supported by the canary.');
            }

            $proposedValue = $this->scalarValue($proposal['proposed_value'] ?? null);
            if ($proposedValue === null) {
                return $this->reject('proposed_value_required', 'A non-empty proposed FT4 value is required.');
            }

            $currentValue = $this->scalarValue($person[$field] ?? null);
            if ($this->sameValue($currentValue, $proposedValue)) {
                return $this->reject('no_ft4_difference', 'The proposed value is already on file in FT4.');
            }

            $proposal['current_value'] = $currentValue;
            $proposal['field_name'] = $field;
            $proposal['proposed_value'] = $proposedValue;
        } else {
            $proposal['current_value'] = null;
            $proposal['field_name'] = null;
            $proposal['proposed_value'] = $locator;
        }

        $proposal['person_id'] = (int) $person['id'];
        $proposal['tree_id'] = (int) $person['tree_id'];
        $proposal['change_type'] = $changeType === 'update' ? 'fact_update' : $changeType;
        $proposal['confidence'] = $confidence;
        $proposal['source_locator'] = $locator;
        $proposal['source_citation'] = mb_substr($citation, 0, 500);
        $proposal['record_title'] = mb_substr($recordTitle, 0, 255);
        $proposal['record_text_excerpt'] = mb_substr($recordExcerpt, 0, 500);
        $proposal['identity_anchors'] = $anchors;
        $proposal['evidence_sources'] = array_values(array_unique(array_filter(array_merge(
            is_array($proposal['evidence_sources'] ?? null) ? $proposal['evidence_sources'] : [],
            [$citation, $locator]
        ), static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')));
        $proposal['proof_grade'] = [
            'policy' => self::POLICY,
            'minimum_confidence' => $minimumConfidence,
            'record_text_identity_verified' => true,
            'source_locator_verified' => true,
            'current_value_verified' => in_array($changeType, self::FACT_CHANGE_TYPES, true),
            'identity_anchor_types' => array_values(array_unique(array_column($anchors, 'type'))),
        ];

        return ['ok' => true, 'proposal' => $proposal];
    }

    /** @return array<string, mixed>|null */
    private function person(int $personId, ?int $treeId = null): ?array
    {
        $columns = array_merge(['id', 'tree_id', 'given_name', 'surname'], self::FACT_FIELDS);
        $query = DB::table('genealogy_persons')->where('id', $personId);
        if ($treeId !== null) {
            $query->where('tree_id', $treeId);
        }
        $row = $query->first($columns);

        return $row === null ? null : (array) $row;
    }

    private function minimumConfidence(): float
    {
        try {
            $value = DB::table('review_type_registry')
                ->where('name', 'genealogy_finding')
                ->value('actionability_min_confidence');
        } catch (\Throwable) {
            $value = null;
        }

        return is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : 0.8;
    }

    /** @param array<string, mixed> $proposal */
    private function sourceLocator(array $proposal, string $changeType): ?string
    {
        $candidates = [
            $proposal['source_locator'] ?? null,
            $proposal['record_url'] ?? null,
            $proposal['citation_url'] ?? null,
            $changeType === 'source_add' ? ($proposal['proposed_value'] ?? null) : null,
        ];
        foreach ((array) ($proposal['evidence_sources'] ?? []) as $source) {
            $candidates[] = $source;
        }

        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }
            $value = trim((string) $candidate);
            if (filter_var($value, FILTER_VALIDATE_URL) !== false
                && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                return mb_substr($value, 0, 2048);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $proposal */
    private function sourceCitation(array $proposal): ?string
    {
        $explicit = trim((string) ($proposal['source_citation'] ?? ''));
        if (mb_strlen($explicit) >= 8) {
            return $explicit;
        }

        foreach ((array) ($proposal['evidence_sources'] ?? []) as $source) {
            if (! is_scalar($source)) {
                continue;
            }
            $value = trim((string) $source);
            if (mb_strlen($value) >= 8 && filter_var($value, FILTER_VALIDATE_URL) === false
                && preg_match('/^(source|unknown|n\/a|source_search_all|nara_search)$/i', $value) !== 1) {
                return $value;
            }
        }

        return null;
    }

    /** @return array<int, array{type: string, value: string}> */
    private function identityAnchors(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $anchors = [];
        foreach ($raw as $key => $anchor) {
            if (is_array($anchor)) {
                $type = strtolower(trim((string) ($anchor['type'] ?? '')));
                $value = trim((string) ($anchor['value'] ?? $anchor['evidence'] ?? ''));
            } elseif (is_string($key) && is_scalar($anchor)) {
                $type = strtolower(trim($key));
                $value = trim((string) $anchor);
            } else {
                continue;
            }

            $type = (string) preg_replace('/[^a-z0-9_]+/', '_', $type);
            if ($type !== '' && $value !== '') {
                $anchors[] = [
                    'type' => mb_substr($type, 0, 40),
                    'value' => mb_substr($value, 0, 300),
                ];
            }
        }

        return $anchors;
    }

    /** @param array<int, array{type: string, value: string}> $anchors */
    private function hasNameAnchor(array $anchors, string $givenName, string $surname): bool
    {
        foreach ($anchors as $anchor) {
            if (! in_array($anchor['type'], ['name', 'full_name', 'target_name'], true)) {
                continue;
            }
            if (ProximityNameMatcher::matchesFullName(
                $anchor['value'],
                $givenName,
                $surname,
                null,
                GivenNameVariants::variantsFor($givenName)
            )) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array{type: string, value: string}> $anchors */
    private function hasCorroboratingAnchor(array $anchors): bool
    {
        foreach ($anchors as $anchor) {
            if (in_array($anchor['type'], self::CORROBORATING_ANCHOR_TYPES, true)) {
                return true;
            }
        }

        return false;
    }

    private function scalarValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 1000);
    }

    private function sameValue(?string $left, ?string $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        $normalize = static fn (string $value): string => strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));

        return $normalize($left) === $normalize($right);
    }

    /** @return array{ok: false, gate: string, reason: string} */
    private function reject(string $gate, string $reason): array
    {
        return ['ok' => false, 'gate' => $gate, 'reason' => $reason];
    }
}
