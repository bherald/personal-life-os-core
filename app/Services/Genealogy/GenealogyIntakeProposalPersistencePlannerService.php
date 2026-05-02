<?php

namespace App\Services\Genealogy;

class GenealogyIntakeProposalPersistencePlannerService
{
    private const STRUCTURED_FACT_FIELDS = [
        'given_name',
        'surname',
        'suffix',
        'nickname',
        'sex',
        'birth_date',
        'birth_year',
        'birth_place',
        'death_date',
        'death_year',
        'death_place',
        'burial_date',
        'burial_place',
        'residence',
        'occupation',
        'education',
        'religion',
        'notes',
        'title',
        'physical_description',
        'nationality',
        'ssn',
        'id_number',
        'property',
        'cause_of_death',
    ];

    private const STRUCTURED_SECTION_FIELDS = [
        'identity' => [
            'given_name',
            'surname',
            'suffix',
            'nickname',
            'sex',
            'occupation',
            'education',
            'religion',
            'notes',
            'title',
            'physical_description',
            'nationality',
            'ssn',
            'id_number',
            'property',
            'cause_of_death',
        ],
        'events' => [
            'birth_date',
            'birth_year',
            'birth_place',
            'death_date',
            'death_year',
            'death_place',
            'burial_date',
            'burial_place',
            'residence',
        ],
    ];

    /**
     * Build conservative write intents from one approved-ready proposal preview.
     * Pure planner only: no DB, no side effects, no mutation.
     */
    public function plan(array $proposalPreview, array $draftInput, array $context = []): array
    {
        $canGenerate = (bool) ($proposalPreview['proposal_outline']['can_generate'] ?? false);
        $packetKey = (string) ($draftInput['packet_key'] ?? $proposalPreview['packet']['packet_key'] ?? '');
        $packetLabel = (string) ($draftInput['packet_label'] ?? $proposalPreview['packet']['packet_label'] ?? 'unknown');
        $summaryText = trim((string) ($draftInput['packet_summary'] ?? $proposalPreview['evidence']['summary_text'] ?? ''));
        $reviewNotes = trim((string) ($draftInput['review_decision']['notes'] ?? ''));
        $anchors = array_values((array) ($draftInput['page_anchors'] ?? $proposalPreview['evidence']['anchors'] ?? []));
        $structuredFacts = $this->normalizeStructuredFacts((array) ($draftInput['structured_facts'] ?? []), $anchors);
        $structuredSources = $this->normalizeStructuredSources((array) ($draftInput['sources'] ?? []), $anchors);
        $approvedSections = $this->normalizeSections($context['approved_sections'] ?? []);

        $plan = [
            'ready' => $canGenerate,
            'blocked_reasons' => [],
            'existing_person_changes' => [],
            'relationship_proposals' => [],
            'skipped' => [],
            'blocked' => [],
        ];

        if (! $canGenerate) {
            $plan['blocked_reasons'] = $this->normalizeReasons(
                (array) ($proposalPreview['proposal_outline']['blocking_reasons'] ?? ['preview_not_generatable'])
            );
            $plan['blocked'][] = [
                'type' => 'preview_not_generatable',
                'packet_key' => $packetKey,
                'packet_label' => $packetLabel,
                'reasons' => $plan['blocked_reasons'],
            ];

            return $plan;
        }

        if ($approvedSections === []) {
            $plan['skipped'][] = [
                'type' => 'missing_approved_sections',
                'packet_key' => $packetKey,
                'packet_label' => $packetLabel,
                'reason' => 'No approved sections selected for persistence planning.',
            ];

            return $plan;
        }

        $personId = $this->normalizePositiveInt($context['person_id'] ?? null);
        $treeId = $this->normalizePositiveInt($context['tree_id'] ?? null);
        $relationshipType = trim((string) ($context['relationship_type'] ?? ''));
        $relatedPersonId = $this->normalizePositiveInt($context['related_person_id'] ?? null);

        foreach ($approvedSections as $section) {
            if (in_array($section, ['identity', 'events', 'sources', 'notes'], true) && $personId === null) {
                $plan['blocked'][] = [
                    'type' => 'missing_person_target',
                    'section' => $section,
                    'packet_key' => $packetKey,
                    'packet_label' => $packetLabel,
                    'reason' => 'Existing person target is required for this section.',
                ];
            }
        }

        if ($personId !== null) {
            $this->planExistingPersonChanges(
                $plan,
                $approvedSections,
                $personId,
                $packetKey,
                $packetLabel,
                $summaryText,
                $reviewNotes,
                $anchors,
                $structuredFacts,
                $structuredSources
            );
        }

        if (in_array('relationships', $approvedSections, true)) {
            if ($relationshipType === '' || $relatedPersonId === null || $treeId === null) {
                $plan['blocked'][] = [
                    'type' => 'missing_relationship_anchor',
                    'section' => 'relationships',
                    'packet_key' => $packetKey,
                    'packet_label' => $packetLabel,
                    'reason' => 'relationship_type, related_person_id, and tree_id are required for relationship planning.',
                ];
            } elseif ($summaryText === '') {
                $plan['skipped'][] = [
                    'type' => 'empty_relationship_summary',
                    'section' => 'relationships',
                    'packet_key' => $packetKey,
                    'packet_label' => $packetLabel,
                    'reason' => 'No concrete relationship evidence summary available.',
                ];
            } else {
                $plan['relationship_proposals'][] = [
                    'tree_id' => $treeId,
                    'person_id' => $personId,
                    'relationship_type' => $relationshipType,
                    'related_person_id' => $relatedPersonId,
                    'reason' => 'approved_relationship_section',
                    'source_packet_key' => $packetKey,
                    'source_packet_label' => $packetLabel,
                    'evidence_summary' => $summaryText,
                    'page_anchors' => $anchors,
                ];
            }
        }

        return $plan;
    }

    private function planExistingPersonChanges(
        array &$plan,
        array $approvedSections,
        int $personId,
        string $packetKey,
        string $packetLabel,
        string $summaryText,
        string $reviewNotes,
        array $anchors,
        array $structuredFacts,
        array $structuredSources
    ): void {
        foreach ($approvedSections as $section) {
            switch ($section) {
                case 'notes':
                    $noteContent = trim(implode("\n\n", array_filter([$summaryText, $reviewNotes])));
                    if ($noteContent === '') {
                        $plan['skipped'][] = [
                            'type' => 'no_note_content',
                            'section' => 'notes',
                            'packet_key' => $packetKey,
                            'packet_label' => $packetLabel,
                            'reason' => 'No note content available to persist.',
                        ];
                        break;
                    }

                    $plan['existing_person_changes'][] = [
                        'person_id' => $personId,
                        'change_type' => 'notes_append',
                        'field_name' => null,
                        'reason' => 'approved_notes_section',
                        'source_packet_key' => $packetKey,
                        'source_packet_label' => $packetLabel,
                        'proposed_value' => $noteContent,
                        'page_anchors' => $anchors,
                    ];
                    break;

                case 'identity':
                    $this->planStructuredFactUpdates(
                        $plan,
                        'identity',
                        $personId,
                        $packetKey,
                        $packetLabel,
                        $structuredFacts
                    );
                    break;

                case 'events':
                    $this->planStructuredFactUpdates(
                        $plan,
                        'events',
                        $personId,
                        $packetKey,
                        $packetLabel,
                        $structuredFacts
                    );
                    break;

                case 'sources':
                    $this->planSourceAddChanges(
                        $plan,
                        $personId,
                        $packetKey,
                        $packetLabel,
                        $structuredSources
                    );
                    break;
            }
        }
    }

    /**
     * Emit source_add write intents from normalized draft sources.
     *
     * Contract: each normalized source carries a url or numeric source_id
     * (the normalizer already filtered junk). proposed_value is the url or the
     * numeric source_id string. title is captured in evidence_summary; an
     * explicit source_id is passed via evidence_sources so downstream persistence
     * can cite it. Dedup runs in-plan on (person_id, proposed_value) to keep the
     * planner idempotent even if the DB-level dedup in PersonService::proposeChange
     * is unavailable (e.g. in dry-run preview contexts).
     */
    private function planSourceAddChanges(
        array &$plan,
        int $personId,
        string $packetKey,
        string $packetLabel,
        array $structuredSources
    ): void {
        if ($structuredSources === []) {
            $plan['skipped'][] = [
                'type' => 'no_structured_source_data',
                'section' => 'sources',
                'packet_key' => $packetKey,
                'packet_label' => $packetLabel,
                'reason' => 'No explicit source_id or source URL is present in the draft input.',
            ];

            return;
        }

        $seen = [];
        foreach ($plan['existing_person_changes'] as $existing) {
            if (($existing['change_type'] ?? '') === 'source_add'
                && (int) ($existing['person_id'] ?? 0) === $personId) {
                $seen[(string) ($existing['proposed_value'] ?? '')] = true;
            }
        }

        $emitted = false;
        foreach ($structuredSources as $source) {
            $proposedValue = (string) $source['proposed_value'];
            if (isset($seen[$proposedValue])) {
                continue;
            }
            $seen[$proposedValue] = true;

            $evidenceSummary = $source['title'] !== ''
                ? $source['title']
                : ('Source cited in intake packet '.$packetLabel);

            $change = [
                'person_id' => $personId,
                'change_type' => 'source_add',
                'field_name' => null,
                'reason' => 'approved_sources_section',
                'source_packet_key' => $packetKey,
                'source_packet_label' => $packetLabel,
                'proposed_value' => $proposedValue,
                'evidence_summary' => $evidenceSummary,
                'page_anchors' => $source['page_anchors'],
            ];

            if ($source['source_id'] !== null) {
                $change['evidence_sources'] = [(string) $source['source_id']];
            }

            $plan['existing_person_changes'][] = $change;
            $emitted = true;
        }

        if (! $emitted) {
            $plan['skipped'][] = [
                'type' => 'no_structured_source_data',
                'section' => 'sources',
                'packet_key' => $packetKey,
                'packet_label' => $packetLabel,
                'reason' => 'All draft sources were duplicates of already-planned source_add proposals.',
            ];
        }
    }

    private function planStructuredFactUpdates(
        array &$plan,
        string $section,
        int $personId,
        string $packetKey,
        string $packetLabel,
        array $structuredFacts
    ): void {
        $allowedFields = self::STRUCTURED_SECTION_FIELDS[$section] ?? [];
        $matched = array_values(array_filter(
            $structuredFacts,
            static fn (array $fact): bool => in_array((string) ($fact['field'] ?? ''), $allowedFields, true)
        ));

        if ($matched === []) {
            $this->appendNoStructuredDataSkip($plan, $section, $packetKey, $packetLabel);

            return;
        }

        $emitted = false;

        foreach ($matched as $fact) {
            if ($this->shouldSkipDerivedYearFact($fact, $matched)) {
                continue;
            }

            $change = $this->buildStructuredChange($personId, $section, $packetKey, $packetLabel, $fact);
            if ($change === null) {
                continue;
            }

            $plan['existing_person_changes'][] = $change;
            $emitted = true;
        }

        if (! $emitted) {
            $this->appendNoStructuredDataSkip($plan, $section, $packetKey, $packetLabel);
        }
    }

    private function appendNoStructuredDataSkip(array &$plan, string $section, string $packetKey, string $packetLabel): void
    {
        $plan['skipped'][] = [
            'type' => $section === 'identity' ? 'no_structured_identity_data' : 'no_structured_event_data',
            'section' => $section,
            'packet_key' => $packetKey,
            'packet_label' => $packetLabel,
            'reason' => $section === 'identity'
                ? 'No explicit structured identity fields are present in the draft input.'
                : 'No explicit structured event payload is present in the draft input.',
        ];
    }

    private function shouldSkipDerivedYearFact(array $fact, array $matched): bool
    {
        $field = (string) ($fact['field'] ?? '');

        if ($field === 'birth_year') {
            return $this->hasStructuredField($matched, 'birth_date');
        }

        if ($field === 'death_year') {
            return $this->hasStructuredField($matched, 'death_date');
        }

        return false;
    }

    private function hasStructuredField(array $facts, string $field): bool
    {
        foreach ($facts as $fact) {
            if ((string) ($fact['field'] ?? '') === $field) {
                return true;
            }
        }

        return false;
    }

    private function buildStructuredChange(
        int $personId,
        string $section,
        string $packetKey,
        string $packetLabel,
        array $fact
    ): ?array {
        $field = (string) ($fact['field'] ?? '');
        $value = (string) ($fact['value'] ?? '');
        $anchors = array_values((array) ($fact['page_anchors'] ?? []));

        return match ($field) {
            'birth_year' => $this->buildStructuredFactUpdate($personId, $section, $packetKey, $packetLabel, 'birth_date', $value, $anchors),
            'death_year' => $this->buildStructuredFactUpdate($personId, $section, $packetKey, $packetLabel, 'death_date', $value, $anchors),
            'residence' => $this->buildResidenceAddChange($personId, $section, $packetKey, $packetLabel, $value, $anchors),
            default => $this->buildStructuredFactUpdate($personId, $section, $packetKey, $packetLabel, $field, $value, $anchors),
        };
    }

    private function buildStructuredFactUpdate(
        int $personId,
        string $section,
        string $packetKey,
        string $packetLabel,
        string $fieldName,
        string $value,
        array $anchors
    ): array {
        return [
            'person_id' => $personId,
            'change_type' => 'fact_update',
            'field_name' => $fieldName,
            'reason' => 'approved_'.$section.'_section',
            'source_packet_key' => $packetKey,
            'source_packet_label' => $packetLabel,
            'proposed_value' => $value,
            'page_anchors' => $anchors,
        ];
    }

    private function buildResidenceAddChange(
        int $personId,
        string $section,
        string $packetKey,
        string $packetLabel,
        string $value,
        array $anchors
    ): ?array {
        $payload = json_encode([
            'residence_date' => null,
            'place' => $value,
            'source_id' => null,
        ]);

        if ($payload === false) {
            return null;
        }

        return [
            'person_id' => $personId,
            'change_type' => 'residence_add',
            'field_name' => null,
            'reason' => 'approved_'.$section.'_section',
            'source_packet_key' => $packetKey,
            'source_packet_label' => $packetLabel,
            'proposed_value' => $payload,
            'page_anchors' => $anchors,
        ];
    }

    private function normalizeSections(array $sections): array
    {
        $normalized = [];

        foreach ($sections as $section) {
            $value = trim((string) $section);
            if ($value === '') {
                continue;
            }

            if (! in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    private function normalizeReasons(array $reasons): array
    {
        $normalized = [];

        foreach ($reasons as $reason) {
            $value = trim((string) $reason);
            if ($value === '') {
                continue;
            }

            if (! in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized === [] ? ['preview_not_generatable'] : $normalized;
    }

    private function normalizeStructuredFacts(array $facts, array $defaultAnchors): array
    {
        $normalized = [];

        foreach ($facts as $fact) {
            if (! is_array($fact)) {
                continue;
            }

            $field = trim((string) ($fact['field'] ?? ''));
            $value = trim((string) ($fact['value'] ?? ''));
            if ($field === '' || $value === '' || ! in_array($field, self::STRUCTURED_FACT_FIELDS, true)) {
                continue;
            }

            $anchors = array_values(array_filter(array_map(
                static fn ($anchor) => trim((string) $anchor),
                (array) ($fact['page_anchors'] ?? $defaultAnchors)
            )));

            $normalized[] = [
                'field' => $field,
                'value' => $value,
                'page_anchors' => $anchors,
            ];
        }

        return $normalized;
    }

    /**
     * Normalize raw draft_input['sources'] into actionable source_add inputs.
     *
     * Accepted shapes per entry: ['url' => ..., 'title' => ..., 'source_id' => ..., 'page_anchors' => [...]].
     * Entries without a usable URL or numeric source_id are dropped — PersonService::proposeChange
     * rejects free-text source_add, so there's no point emitting them.
     */
    private function normalizeStructuredSources(array $sources, array $defaultAnchors): array
    {
        $normalized = [];
        $seenValues = [];

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $url = trim((string) ($source['url'] ?? ''));
            $sourceId = $this->normalizePositiveInt($source['source_id'] ?? null);
            $title = trim((string) ($source['title'] ?? ''));

            // Pick proposed_value: prefer explicit source_id over url so dedup collapses
            // "url that already has a source row" into one proposal.
            if ($sourceId !== null) {
                $proposedValue = (string) $sourceId;
            } elseif ($url !== '' && preg_match('/^https?:\/\//i', $url)) {
                $proposedValue = $url;
            } else {
                // No actionable anchor — skip silently. Upstream already has a reason.
                continue;
            }

            if (isset($seenValues[$proposedValue])) {
                continue;
            }
            $seenValues[$proposedValue] = true;

            $anchors = array_values(array_filter(array_map(
                static fn ($anchor) => trim((string) $anchor),
                (array) ($source['page_anchors'] ?? $defaultAnchors)
            )));

            $normalized[] = [
                'proposed_value' => $proposedValue,
                'title' => $title,
                'source_id' => $sourceId,
                'page_anchors' => $anchors,
            ];
        }

        return $normalized;
    }

    private function normalizePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
