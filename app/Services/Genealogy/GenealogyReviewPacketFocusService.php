<?php

namespace App\Services\Genealogy;

class GenealogyReviewPacketFocusService
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $applyPreview
     * @param  array<string, mixed>  $applyPreviewMeta
     * @param  array<string, mixed>  $validation
     * @param  array<string, mixed>|null  $person
     * @param  array<int, array<string, mixed>>  $mediaRefs
     * @return array<string, mixed>
     */
    public function fromContext(
        array $details,
        array $applyPreview,
        array $applyPreviewMeta,
        array $validation,
        ?array $person = null,
        array $mediaRefs = []
    ): array {
        return $this->build($details, $applyPreview, $applyPreviewMeta, $validation, $person, $mediaRefs);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public function fromPersistedDetails(array $details): array
    {
        $applyPreview = is_array($details['apply_preview'] ?? null) ? $details['apply_preview'] : [];
        $applyPreviewMeta = [
            'persisted' => is_array($details['apply_preview'] ?? null),
            'warning' => array_key_exists('apply_preview', $details) && ! is_array($details['apply_preview'] ?? null)
                ? 'persisted_apply_preview_not_array'
                : null,
        ];
        $validation = is_array($details['validation'] ?? null) ? $details['validation'] : [];

        return $this->build($details, $applyPreview, $applyPreviewMeta, $validation, null, []);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $applyPreview
     * @param  array<string, mixed>  $applyPreviewMeta
     * @param  array<string, mixed>  $validation
     * @param  array<string, mixed>|null  $person
     * @param  array<int, array<string, mixed>>  $mediaRefs
     * @return array<string, mixed>
     */
    private function build(
        array $details,
        array $applyPreview,
        array $applyPreviewMeta,
        array $validation,
        ?array $person,
        array $mediaRefs
    ): array {
        $claims = is_array($details['claims'] ?? null) ? $details['claims'] : [];
        $sourceLocators = is_array($details['source_locators'] ?? null) ? $details['source_locators'] : [];
        $sources = is_array($details['sources'] ?? null) ? $details['sources'] : [];
        $personId = $this->extractPersonId($details);
        $firstClaim = $this->firstArrayItem($claims);
        $sourceLocator = $this->firstSourceLocator($details, $sourceLocators, $sources);
        $previewOnly = $this->previewIsPreviewOnly($applyPreview);
        $persistedPreview = ($applyPreviewMeta['persisted'] ?? false) === true;
        $approvalReady = $persistedPreview
            && $previewOnly === true
            && $this->validationAllowsApproval($validation);

        return [
            'review_mode' => 'single_packet',
            'source_backed' => count(array_filter($claims, 'is_array')) > 0 && $sourceLocator !== null,
            'person_id' => $personId,
            'person_label' => $this->personLabel($person, $personId),
            'boundary_label' => $this->boundaryLabel($details),
            'source_locator' => $sourceLocator,
            'source_label' => $this->firstSourceLabel($sources),
            'source_access_class' => $this->firstSourceAccessClass($details, $sources),
            'source_count' => $this->sourceCount($sourceLocators, $sources),
            'claim_count' => count(array_filter($claims, 'is_array')),
            'media_ref_count' => $this->mediaRefCount($details, $mediaRefs),
            'resolved_media_count' => $this->resolvedMediaCount($details, $mediaRefs),
            'missing_media_count' => $this->missingMediaCount($details, $mediaRefs),
            'claim_summary' => $firstClaim !== null ? $this->claimSummary($firstClaim) : null,
            'claim_field' => $firstClaim !== null ? $this->firstScalarText($firstClaim, ['field_name']) : null,
            'claim_change_type' => $firstClaim !== null ? $this->firstScalarText($firstClaim, ['change_type', 'relationship_type']) : null,
            'claim_source_ref' => $firstClaim !== null ? $this->firstScalarText($firstClaim, ['source_ref', 'source_locator']) : null,
            'preview_status' => $this->previewStatus($applyPreview, $applyPreviewMeta),
            'preview_only' => $previewOnly,
            'canonical_mutation' => $persistedPreview ? $this->previewHasCanonicalMutation($applyPreview) : null,
            'approval_ready' => $approvalReady,
            'approval_blockers' => $approvalReady ? [] : $this->approvalBlockers(
                $persistedPreview,
                $previewOnly,
                $applyPreview,
                $validation
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function extractPersonId(array $details): ?int
    {
        foreach ([
            $details['person_id'] ?? null,
            $details['target_person_id'] ?? null,
            $details['identity']['person_id'] ?? null,
            $details['identity']['target_person_id'] ?? null,
        ] as $value) {
            $personId = $this->positiveInt($value);
            if ($personId !== null) {
                return $personId;
            }
        }

        $claims = is_array($details['claims'] ?? null) ? $details['claims'] : [];
        foreach ($claims as $claim) {
            if (! is_array($claim)) {
                continue;
            }
            foreach (['person_id', 'target_person_id'] as $key) {
                $personId = $this->positiveInt($claim[$key] ?? null);
                if ($personId !== null) {
                    return $personId;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function boundaryLabel(array $details): ?string
    {
        $packet = is_array($details['packet'] ?? null) ? $details['packet'] : [];
        $sprint = is_array($details['sprint'] ?? null) ? $details['sprint'] : [];

        return $this->firstScalarText($sprint, ['boundary_label', 'operator_boundary'])
            ?: $this->firstScalarText($details, ['boundary_label', 'operator_boundary', 'sprint_boundary', 'boundary'])
            ?: $this->firstScalarText($packet, ['boundary_label', 'operator_boundary', 'sprint_boundary', 'boundary']);
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<string, mixed>|null
     */
    private function firstArrayItem(array $items): ?array
    {
        foreach ($items as $item) {
            if (is_array($item)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $person
     */
    private function personLabel(?array $person, ?int $personId): ?string
    {
        if ($person !== null) {
            $name = trim(implode(' ', array_filter([
                is_scalar($person['given_name'] ?? null) ? (string) $person['given_name'] : null,
                is_scalar($person['surname'] ?? null) ? (string) $person['surname'] : null,
            ])));
            if ($name !== '') {
                return $personId !== null ? "{$name} (#{$personId})" : $name;
            }
        }

        return $personId !== null ? "Person #{$personId}" : null;
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<int, mixed>  $sourceLocators
     * @param  array<int, mixed>  $sources
     */
    private function firstSourceLocator(array $details, array $sourceLocators, array $sources): ?string
    {
        foreach ([
            $details['source_locator'] ?? null,
            ...$sourceLocators,
        ] as $locator) {
            if (is_scalar($locator) && trim((string) $locator) !== '') {
                return trim((string) $locator);
            }
        }

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }
            foreach (['locator', 'source_locator', 'url', 'uri', 'path', 'citation'] as $key) {
                $locator = $source[$key] ?? null;
                if (is_scalar($locator) && trim((string) $locator) !== '') {
                    return trim((string) $locator);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $sources
     */
    private function firstSourceLabel(array $sources): ?string
    {
        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }
            foreach (['label', 'title', 'name'] as $key) {
                $label = $source[$key] ?? null;
                if (is_scalar($label) && trim((string) $label) !== '') {
                    return trim((string) $label);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<int, mixed>  $sources
     */
    private function firstSourceAccessClass(array $details, array $sources): ?string
    {
        $direct = $this->firstScalarText($details, [
            'source_access_class',
            'access_class',
            'provider_boundary_status',
        ]);
        if ($direct !== null) {
            return $direct;
        }

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $value = $this->firstScalarText($source, [
                'source_access_class',
                'access_class',
                'provider_boundary_status',
            ]);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $sourceLocators
     * @param  array<int, mixed>  $sources
     */
    private function sourceCount(array $sourceLocators, array $sources): int
    {
        $locators = array_values(array_filter($sourceLocators, fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== ''));

        return count($locators !== [] ? $locators : array_filter($sources, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<int, array<string, mixed>>  $mediaRefs
     */
    private function mediaRefCount(array $details, array $mediaRefs): ?int
    {
        if ($mediaRefs !== []) {
            return count($mediaRefs);
        }

        return $this->nonNegativeIntOrNull($details['media_ref_count'] ?? null)
            ?? $this->detailMediaRefCount($details);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<int, array<string, mixed>>  $mediaRefs
     */
    private function resolvedMediaCount(array $details, array $mediaRefs): ?int
    {
        if ($mediaRefs !== []) {
            return count(array_filter($mediaRefs, fn (array $media): bool => $this->positiveInt($media['id'] ?? null) !== null));
        }

        return $this->nonNegativeIntOrNull($details['resolved_media_count'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<int, array<string, mixed>>  $mediaRefs
     */
    private function missingMediaCount(array $details, array $mediaRefs): ?int
    {
        if ($mediaRefs !== []) {
            return count(array_filter($mediaRefs, fn (array $media): bool => ($media['file_exists'] ?? null) === false));
        }

        return $this->nonNegativeIntOrNull($details['missing_media_count'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function detailMediaRefCount(array $details): ?int
    {
        $mediaRefs = $details['media_refs'] ?? null;
        if (is_array($mediaRefs)) {
            return count(array_filter($mediaRefs, fn (mixed $value): bool => is_array($value) || is_scalar($value)));
        }

        return null;
    }

    private function nonNegativeIntOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function claimSummary(array $claim): ?string
    {
        $raw = is_array($claim['raw'] ?? null) ? $claim['raw'] : [];

        return $this->firstScalarText($claim, ['claim', 'claim_text', 'statement', 'extracted_claim', 'extracted_text', 'text'])
            ?: $this->firstScalarText($raw, ['claim', 'claim_text', 'statement', 'extracted_claim', 'extracted_text', 'text'])
            ?: $this->firstScalarText($claim, ['proposed_value'])
            ?: $this->firstScalarText($raw, ['proposed_value']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function firstScalarText(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>  $meta
     */
    private function previewStatus(array $preview, array $meta): string
    {
        if (isset($meta['warning']) && is_scalar($meta['warning'])) {
            return (string) $meta['warning'];
        }

        $status = $preview['status'] ?? null;
        if (is_scalar($status) && trim((string) $status) !== '') {
            return trim((string) $status);
        }

        if ($this->previewIsPreviewOnly($preview)) {
            return 'preview_only';
        }

        return $preview === [] ? 'missing' : 'unknown';
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    private function previewIsPreviewOnly(array $preview): bool
    {
        return $preview !== []
            && ($preview['mutates_accepted_facts'] ?? null) === false
            && ! $this->previewHasCanonicalMutation($preview);
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    private function previewHasCanonicalMutation(array $preview): bool
    {
        if ($this->previewFlagEnabled($preview['mutates_accepted_facts'] ?? null)) {
            return true;
        }

        if ($this->hasAcceptedFactMutations($preview['accepted_fact_mutations'] ?? [])) {
            return true;
        }

        $operations = $preview['operations'] ?? [];
        if (! is_array($operations)) {
            return false;
        }

        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }
            if ($this->previewFlagEnabled($operation['mutates_accepted_facts'] ?? null)
                || $this->previewFlagEnabled($operation['apply_enabled'] ?? null)
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasAcceptedFactMutations(mixed $acceptedFactMutations): bool
    {
        if (is_array($acceptedFactMutations)) {
            return $acceptedFactMutations !== [];
        }

        return $acceptedFactMutations !== null
            && $acceptedFactMutations !== false
            && $acceptedFactMutations !== '';
    }

    private function previewFlagEnabled(mixed $value): bool
    {
        if ($value === null || $value === false || $value === 0 || $value === '') {
            return false;
        }

        if (is_string($value)) {
            return ! in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off'], true);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $validation
     */
    private function validationAllowsApproval(array $validation): bool
    {
        if (($validation['valid'] ?? null) !== true) {
            return false;
        }

        $errors = $validation['errors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>  $validation
     * @return list<array{code: string, label: string}>
     */
    private function approvalBlockers(
        bool $persistedPreview,
        bool $previewOnly,
        array $preview,
        array $validation
    ): array {
        $blockers = [];

        if (! $persistedPreview) {
            $blockers[] = [
                'code' => 'apply_preview_missing',
                'label' => 'Apply preview missing',
            ];
        } elseif (! $previewOnly) {
            $blockers[] = [
                'code' => 'preview_not_preview_only',
                'label' => 'Preview is not preview-only',
            ];

            if ($this->previewHasCanonicalMutation($preview)) {
                $blockers[] = [
                    'code' => 'canonical_mutation_possible',
                    'label' => 'Canonical mutation possible',
                ];
            }
        }

        if ($validation === []) {
            $blockers[] = [
                'code' => 'validation_missing',
                'label' => 'Validation missing',
            ];

            return $blockers;
        }

        if (($validation['valid'] ?? null) !== true) {
            $blockers[] = [
                'code' => 'validation_not_valid',
                'label' => 'Validation is not valid',
            ];
        }

        $errors = $validation['errors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            $blockers[] = [
                'code' => 'validation_errors',
                'label' => 'Validation errors present',
            ];
        }

        return $blockers;
    }
}
