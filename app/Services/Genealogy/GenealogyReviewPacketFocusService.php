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
    public function fromPersistedDetails(array $details, array $mediaRefs = []): array
    {
        $applyPreview = is_array($details['apply_preview'] ?? null) ? $details['apply_preview'] : [];
        $applyPreviewMeta = [
            'persisted' => is_array($details['apply_preview'] ?? null),
            'warning' => array_key_exists('apply_preview', $details) && ! is_array($details['apply_preview'] ?? null)
                ? 'persisted_apply_preview_not_array'
                : null,
        ];
        $validation = is_array($details['validation'] ?? null) ? $details['validation'] : [];

        return $this->build($details, $applyPreview, $applyPreviewMeta, $validation, null, $mediaRefs);
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
        $candidateReviewability = $this->facePhotoReviewability($details);
        $approvalReady = $persistedPreview
            && $previewOnly === true
            && $this->validationAllowsApproval($validation)
            && ($candidateReviewability['ready'] ?? true);
        $approvalBlockers = $this->approvalBlockers(
            $persistedPreview,
            $previewOnly,
            $applyPreview,
            $validation
        );
        if (($candidateReviewability['applicable'] ?? false) && ! ($candidateReviewability['ready'] ?? false)) {
            $approvalBlockers[] = [
                'code' => (string) ($candidateReviewability['blocker_code'] ?? 'candidate_comparison_missing'),
                'label' => (string) ($candidateReviewability['blocker_label'] ?? 'Candidate comparison evidence is incomplete'),
            ];
        }
        $claimCount = count(array_filter($claims, 'is_array'));
        $mediaRefCount = $this->mediaRefCount($details, $mediaRefs);
        $resolvedMediaCount = $this->resolvedMediaCount($details, $mediaRefs);
        $missingMediaCount = $this->missingMediaCount($details, $mediaRefs);
        $sourceVisibility = $this->sourceVisibility($details, $sourceLocators, $sources);
        $mediaVisibility = $this->mediaVisibility($mediaRefs);
        $operatorVetable = $approvalReady
            && $claimCount > 0
            && ($sourceVisibility['visible_source_count'] + $mediaVisibility['viewable_media_count']) > 0;
        $operatorBlocker = $this->operatorBlocker(
            $approvalReady,
            $claimCount,
            $sourceVisibility['visible_source_count'],
            $mediaVisibility['viewable_media_count'],
        );
        if (($candidateReviewability['applicable'] ?? false) && ! ($candidateReviewability['ready'] ?? false)) {
            $operatorBlocker = [
                'code' => (string) ($candidateReviewability['blocker_code'] ?? 'candidate_comparison_missing'),
                'label' => (string) ($candidateReviewability['blocker_label'] ?? 'Candidate comparison evidence is incomplete'),
            ];
        }

        return [
            'review_mode' => 'single_packet',
            'source_backed' => $claimCount > 0 && $sourceLocator !== null,
            'person_id' => $personId,
            'person_label' => $this->personLabel($person, $personId),
            'boundary_label' => $this->boundaryLabel($details),
            'source_locator' => $sourceLocator,
            'source_label' => $this->firstSourceLabel($sources),
            'source_access_class' => $this->firstSourceAccessClass($details, $sources),
            'source_count' => $this->sourceCount($sourceLocators, $sources),
            'claim_count' => $claimCount,
            'media_ref_count' => $mediaRefCount,
            'resolved_media_count' => $resolvedMediaCount,
            'missing_media_count' => $missingMediaCount,
            'visible_source_count' => $sourceVisibility['visible_source_count'],
            'external_source_count' => $sourceVisibility['external_source_count'],
            'private_source_count' => $sourceVisibility['private_source_count'],
            'viewable_media_count' => $mediaVisibility['viewable_media_count'],
            'unavailable_media_count' => $mediaVisibility['unavailable_media_count'],
            'visible_evidence_count' => $sourceVisibility['visible_source_count'] + $mediaVisibility['viewable_media_count'],
            'operator_vetable' => $operatorVetable,
            'operator_review_surface' => $operatorVetable ? 'preview' : 'diagnostic',
            'operator_blocker_code' => $operatorBlocker['code'],
            'operator_blocker_label' => $operatorBlocker['label'],
            'operator_action_label' => $operatorVetable
                ? 'Inspect the visible evidence; marking reviewed records this preview only and does not apply the claim.'
                : (($candidateReviewability['applicable'] ?? false)
                    ? 'Diagnostics only until each photo candidate includes both person names and candidate-specific media.'
                    : 'Diagnostics only until visible evidence is attached.'),
            'claim_summary' => $firstClaim !== null ? $this->claimSummary($firstClaim) : null,
            'claim_field' => $firstClaim !== null ? $this->firstScalarText($firstClaim, ['field_name']) : null,
            'claim_change_type' => $firstClaim !== null ? $this->firstScalarText($firstClaim, ['change_type', 'relationship_type']) : null,
            'claim_source_ref' => $firstClaim !== null ? $this->firstScalarText($firstClaim, ['source_ref', 'source_locator']) : null,
            'remediation_origin' => $this->remediationOrigin($details),
            'preview_status' => $this->previewStatus($applyPreview, $applyPreviewMeta),
            'preview_only' => $previewOnly,
            'canonical_mutation' => $persistedPreview ? $this->previewHasCanonicalMutation($applyPreview) : null,
            'approval_ready' => $approvalReady,
            'approval_blockers' => $approvalBlockers,
            'candidate_reviewability' => $candidateReviewability,
            'review_readiness' => $this->reviewReadiness($approvalReady, $approvalBlockers),
        ];
    }

    /**
     * Face/photo repair packets are only human-reviewable when every row names
     * both sides of the claimed conflict and points to its specific media.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public function facePhotoReviewability(array $details, bool $assumeApplicable = false): array
    {
        $packet = is_array($details['packet'] ?? null) ? $details['packet'] : [];
        $metadata = is_array($packet['metadata'] ?? null) ? $packet['metadata'] : [];
        $findingType = $this->firstScalarText($metadata, ['finding_type', 'origin'])
            ?: $this->firstScalarText($packet, ['finding_type', 'packet_identity_scope'])
            ?: $this->firstScalarText($details, ['finding_type']);
        $applicable = $assumeApplicable || in_array($findingType, [
            'face_person_source_repair_plan',
            'face_photo_provenance_repair',
            'ops:face-person-source-repair-plan',
        ], true);

        if (! $applicable) {
            return ['applicable' => false, 'ready' => true];
        }

        $plans = is_array($metadata['plans'] ?? null) ? $metadata['plans'] : [];
        $missingComparison = 0;
        $missingMediaReference = 0;
        $readyCandidates = 0;

        foreach ($plans as $plan) {
            if (! is_array($plan)) {
                $missingComparison++;

                continue;
            }

            $evidence = is_array($plan['evidence'] ?? null) ? $plan['evidence'] : [];
            $selector = is_array($plan['selector'] ?? null) ? $plan['selector'] : [];
            $linkedName = $this->firstScalarText($evidence, ['linked_canonical_name', 'person_name']);
            $comparisonName = $this->firstScalarText($evidence, ['matched_export_name', 'matched_variant_name']);
            $mediaIds = is_array($selector['media_ids'] ?? null) ? $selector['media_ids'] : [];
            $hasMediaReference = array_filter($mediaIds, fn (mixed $id): bool => $this->positiveInt($id) !== null) !== [];
            $hasDecidableComparison = $linkedName !== null
                && $comparisonName !== null
                && strcasecmp($linkedName, $comparisonName) !== 0;

            if (! $hasDecidableComparison) {
                $missingComparison++;
            }
            if (! $hasMediaReference) {
                $missingMediaReference++;
            }
            if ($hasDecidableComparison && $hasMediaReference) {
                $readyCandidates++;
            }
        }

        $candidateCount = count(array_filter($plans, 'is_array'));
        $ready = $candidateCount > 0
            && $missingComparison === 0
            && $missingMediaReference === 0;
        $blockerCode = null;
        $blockerLabel = null;
        if ($candidateCount === 0) {
            $blockerCode = 'candidate_rows_missing';
            $blockerLabel = 'No face/photo candidates are attached';
        } elseif ($missingComparison > 0) {
            $blockerCode = 'candidate_comparison_missing';
            $blockerLabel = "{$missingComparison} of {$candidateCount} candidates do not name both people being compared";
        } elseif ($missingMediaReference > 0) {
            $blockerCode = 'candidate_media_missing';
            $blockerLabel = "{$missingMediaReference} of {$candidateCount} candidates do not identify their photo";
        }

        return [
            'applicable' => true,
            'ready' => $ready,
            'candidate_count' => $candidateCount,
            'ready_candidate_count' => $readyCandidates,
            'missing_comparison_count' => $missingComparison,
            'missing_media_reference_count' => $missingMediaReference,
            'blocker_code' => $blockerCode,
            'blocker_label' => $blockerLabel,
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
                return $name;
            }
        }

        return $personId !== null ? 'person reference present' : null;
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
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>|null
     */
    private function remediationOrigin(array $details): ?array
    {
        $packet = is_array($details['packet'] ?? null) ? $details['packet'] : [];
        $materialization = is_array($packet['materialization'] ?? null)
            ? $packet['materialization']
            : (is_array($details['materialization'] ?? null) ? $details['materialization'] : []);
        $sourceReview = is_array($packet['source_review_queue'] ?? null)
            ? $packet['source_review_queue']
            : (is_array($details['source_review_queue'] ?? null) ? $details['source_review_queue'] : []);

        $sourceReviewType = $this->firstScalarText($materialization, ['source_review_type'])
            ?: $this->firstScalarText($sourceReview, ['review_type']);
        $findingType = $this->firstScalarText($sourceReview, ['finding_type'])
            ?: $this->firstScalarText($details, ['finding_type']);
        $sourceStatus = $this->firstScalarText($sourceReview, ['status']);
        $targetReviewType = $this->firstScalarText($materialization, ['target_review_type']);
        $source = $this->firstScalarText($materialization, ['source']);
        $operationTypes = $this->operationTypes($materialization['operation_types'] ?? null);
        $applyEnabled = $this->strictBool($materialization['apply_enabled'] ?? null);
        $writeback = $this->strictBool($materialization['writeback'] ?? null);

        if ($sourceReviewType === null
            && $findingType === null
            && $sourceStatus === null
            && $targetReviewType === null
            && $source === null
            && $operationTypes === []
            && $applyEnabled === null
            && $writeback === null) {
            return null;
        }

        return [
            'source' => $source,
            'source_review_type' => $sourceReviewType,
            'finding_type' => $findingType,
            'source_status' => $sourceStatus,
            'target_review_type' => $targetReviewType,
            'operation_types' => $operationTypes,
            'apply_enabled' => $applyEnabled,
            'writeback' => $writeback,
            'execute_effect' => 'create_or_reuse_review_packet_only',
        ];
    }

    /**
     * @return list<string>
     */
    private function operationTypes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $types = [];
        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $type = trim((string) $item);
            if ($type !== '' && preg_match('/^[a-z][a-z0-9_]{1,80}$/', $type) === 1) {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }

    private function strictBool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * @param  array<int, mixed>  $sources
     */
    private function firstSourceLabel(array $sources): ?string
    {
        foreach ($sources as $idx => $source) {
            if (! is_array($source)) {
                continue;
            }
            foreach (['label', 'title', 'name'] as $key) {
                $label = $source[$key] ?? null;
                if (is_scalar($label) && trim((string) $label) !== '') {
                    return $this->sourceLabelService()->safeLabel($label, 'Source '.((int) $idx + 1));
                }
            }
        }

        return null;
    }

    private function sourceLabelService(): GenealogyReviewPacketSourceLabelService
    {
        return app(GenealogyReviewPacketSourceLabelService::class);
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
            return count(array_filter(
                $mediaRefs,
                fn (array $media): bool => ($media['file_available'] ?? $media['file_exists'] ?? null) === false
            ));
        }

        return $this->nonNegativeIntOrNull($details['missing_media_count'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<int, mixed>  $sourceLocators
     * @param  array<int, mixed>  $sources
     * @return array{visible_source_count: int, external_source_count: int, private_source_count: int}
     */
    private function sourceVisibility(array $details, array $sourceLocators, array $sources): array
    {
        $external = 0;
        $private = 0;

        $locators = [];
        foreach ([$details['source_locator'] ?? null, ...$sourceLocators] as $locator) {
            if (is_scalar($locator) && trim((string) $locator) !== '') {
                $locators[] = trim((string) $locator);
            }
        }
        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }
            foreach (['locator', 'source_locator', 'url', 'uri', 'path', 'citation'] as $key) {
                $locator = $source[$key] ?? null;
                if (is_scalar($locator) && trim((string) $locator) !== '') {
                    $locators[] = trim((string) $locator);
                }
            }
        }

        foreach (array_unique($locators) as $locator) {
            if (preg_match('~^https?://~i', $locator) === 1) {
                $external++;
            } else {
                $private++;
            }
        }

        return [
            'visible_source_count' => $external,
            'external_source_count' => $external,
            'private_source_count' => $private,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $mediaRefs
     * @return array{viewable_media_count: int, unavailable_media_count: int}
     */
    private function mediaVisibility(array $mediaRefs): array
    {
        $viewable = 0;
        $unavailable = 0;
        foreach ($mediaRefs as $media) {
            $hasView = is_scalar($media['view_url'] ?? null) && trim((string) $media['view_url']) !== '';
            $available = ($media['file_available'] ?? null) === true || (($media['file_exists'] ?? null) === true && $hasView);
            if ($available && $hasView) {
                $viewable++;
            } elseif (($media['file_available'] ?? $media['file_exists'] ?? null) === false) {
                $unavailable++;
            }
        }

        return [
            'viewable_media_count' => $viewable,
            'unavailable_media_count' => $unavailable,
        ];
    }

    /**
     * @return array{code: string|null, label: string|null}
     */
    private function operatorBlocker(
        bool $approvalReady,
        int $claimCount,
        int $visibleSourceCount,
        int $viewableMediaCount
    ): array {
        if (! $approvalReady) {
            return [
                'code' => 'packet_not_approval_ready',
                'label' => 'Packet is not ready for operator review.',
            ];
        }

        if ($claimCount <= 0) {
            return [
                'code' => 'no_claim_to_review',
                'label' => 'No concrete claim is available for operator review.',
            ];
        }

        if (($visibleSourceCount + $viewableMediaCount) <= 0) {
            return [
                'code' => 'no_visible_evidence',
                'label' => 'No viewable source image, document, or external source link is attached.',
            ];
        }

        return [
            'code' => null,
            'label' => null,
        ];
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

    /**
     * @param  list<array{code: string, label: string}>  $approvalBlockers
     * @return array{state: string, label: string, reason_code: string|null, blocker_count: int}
     */
    private function reviewReadiness(bool $approvalReady, array $approvalBlockers): array
    {
        if ($approvalReady) {
            return [
                'state' => 'ready',
                'label' => 'Ready for review',
                'reason_code' => null,
                'blocker_count' => 0,
            ];
        }

        $firstBlocker = $approvalBlockers[0] ?? null;
        $reasonCode = $firstBlocker['code'] ?? 'approval_ready_unknown';
        $reasonLabel = $firstBlocker['label'] ?? 'Approval readiness unknown';

        return [
            'state' => 'blocked',
            'label' => "Blocked: {$reasonLabel}",
            'reason_code' => $reasonCode,
            'blocker_count' => count($approvalBlockers),
        ];
    }
}
