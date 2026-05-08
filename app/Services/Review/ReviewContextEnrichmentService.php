<?php

namespace App\Services\Review;

use App\Services\Genealogy\GenealogyReviewPacketApplyPreviewService;
use App\Services\Genealogy\GenealogyReviewPacketFocusService;
use App\Services\Genealogy\GenealogyReviewPacketOutcomeService;
use App\Services\Genealogy\GenealogyReviewPacketSourceLabelService;
use App\Services\Genealogy\GenealogyTypedRemediationMaterializationService;
use App\Services\Genealogy\PersonService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1 of the Genealogy Review UI redesign.
 *
 * Pre-assembles everything the master/detail review pane needs in one
 * call so the frontend doesn't have to round-trip three endpoints to
 * render a side-by-side compare:
 *
 *   - the raw review item (agent_review_queue row + parsed details JSON)
 *   - the on-file person dossier (PersonService::getPerson) when the
 *     review type carries a person_id
 *   - per-field comparison rows (proposed value vs on-file value with
 *     a coarse match_status: same | match | different | new | missing |
 *     conflict). Phase 1 emits "new" rows for source_add proposals and
 *     diff rows for field_name-bearing proposals; deeper classification
 *     (Mills trio, FAN overlap, agent reasoning) lands in Phase 2.
 *
 * Out of scope this phase: source classification, FAN-cluster overlap,
 * agent reasoning narrative, search-coverage breadcrumb. Those grow
 * the same return shape additively in Phase 2 — the controller
 * contract here is intentionally forward-compatible (keys are added,
 * never removed or repurposed).
 *
 * @see docs/research-reviews/2026-04-23-genealogy-review-ui-redesign.md
 */
class ReviewContextEnrichmentService
{
    /**
     * Date fields where a small numeric delta should be flagged as
     * "conflict" rather than just "different" — operator wants the
     * proximity called out.
     */
    private const DATE_FIELDS = ['birth_date', 'death_date', 'marriage_date', 'burial_date'];

    private const AGENT_QUEUE_TOKEN_LOOKUP_TYPES = [
        'genealogy_finding' => true,
        'genealogy_merge' => true,
        'genealogy_review_packet' => true,
    ];

    private const TYPED_REMEDIATION_FINDING_TYPES = [
        'data_quality_review',
        'genealogy_data_quality',
        'genealogy_source_cleanup',
        'source_duplicate_cleanup',
    ];

    private const TYPED_REMEDIATION_CHANGE_TYPES = [
        'data_quality_review',
        'genealogy_data_quality',
        'genealogy_source_cleanup',
        'source_duplicate_cleanup',
        'source_duplicate_mark',
        'family_duplicate_mark',
        'family_child_unlink',
        'genealogy_todo_create',
    ];

    private const STRUCTURED_MEDIA_ID_KEYS = [
        'genealogy_media_id' => true,
        'media_id' => true,
        'source_media_id' => true,
    ];

    private const STRUCTURED_MEDIA_IDS_KEYS = [
        'media_ids' => true,
        'source_media_ids' => true,
    ];

    /**
     * Phase 2: heuristic source-classification table (Mills trio).
     *
     * Pattern → [source_type, information_type, evidence_type, label].
     * Pattern is matched (case-insensitively) against a concatenation of
     * proposal.proposed_value (URL) plus proposal.evidence_sources joined
     * by space. First match wins; falls through to ['unknown','unknown',
     * 'unknown'] when nothing matches. Refining this is cheaper than
     * round-tripping the LLM for every proposal.
     */
    private const SOURCE_CLASSIFICATION_TABLE = [
        // Vital records — birth/death/marriage certificates
        '~vital(?:_|-|\s)?records?~i' => ['original',   'primary',   'direct',   'Vital records'],
        // US Federal Census — original record, primary info on residence/household, secondary on ages/birthdates
        '~(\d{4})\s*us\s*census|us\s*census|census~i' => ['original',   'primary',   'indirect', 'Census'],
        // National Archives — varies by record type, default to original
        '~national\s*archives?|catalog\.archives\.gov|nara~i' => ['original', 'primary', 'direct', 'NARA'],
        // Library of Congress newspapers — original at time of publication
        '~loc\.gov.*chronam|loc\.gov.*newspapers|library\s+of\s+congress~i' => ['original', 'secondary', 'indirect', 'LoC newspapers'],
        // FindAGrave — derivative transcription of headstones; sometimes carries photos of originals
        '~findagrave|find-a-grave~i' => ['derivative', 'secondary', 'direct',   'FindAGrave'],
        // FamilySearch — varies (some indexes derivative, some image originals)
        '~familysearch|family\s+search~i' => ['derivative', 'secondary', 'direct',   'FamilySearch'],
        // Ancestry.com indexes — derivative
        '~ancestry\.com|ancestry\s+library~i' => ['derivative', 'secondary', 'direct',   'Ancestry'],
        // Newspapers.com — digitized originals (period newspapers)
        '~newspapers\.com|newspaperarchive~i' => ['original',   'secondary', 'indirect', 'Newspaper'],
        // Compiled / authored histories
        '~biograph|compiled|published\s+(?:history|genealogy)~i' => ['authored', 'secondary', 'indirect', 'Compiled history'],
        // Wikipedia / Wikitree — authored
        '~wikipedia|wikitree~i' => ['authored',   'secondary', 'indirect', 'Wiki'],
    ];

    private readonly GenealogyReviewPacketApplyPreviewService $reviewPacketApplyPreview;

    private readonly GenealogyReviewPacketFocusService $reviewPacketFocus;

    private readonly GenealogyReviewPacketOutcomeService $reviewPacketOutcome;

    private readonly GenealogyTypedRemediationMaterializationService $typedRemediationMaterialization;

    public function __construct(
        private readonly PersonService $personService,
        ?GenealogyReviewPacketApplyPreviewService $reviewPacketApplyPreview = null,
        ?GenealogyReviewPacketFocusService $reviewPacketFocus = null,
        ?GenealogyReviewPacketOutcomeService $reviewPacketOutcome = null,
        ?GenealogyTypedRemediationMaterializationService $typedRemediationMaterialization = null,
    ) {
        $this->reviewPacketApplyPreview = $reviewPacketApplyPreview ?? new GenealogyReviewPacketApplyPreviewService;
        $this->reviewPacketFocus = $reviewPacketFocus ?? new GenealogyReviewPacketFocusService;
        $this->reviewPacketOutcome = $reviewPacketOutcome ?? new GenealogyReviewPacketOutcomeService;
        $this->typedRemediationMaterialization = $typedRemediationMaterialization ?? new GenealogyTypedRemediationMaterializationService;
    }

    /**
     * Build the enriched detail-pane payload for a single review item.
     *
     * @return array{
     *     item: array<string, mixed>,
     *     person: array<string, mixed>|null,
     *     comparison: array{field_diffs: array<int, array<string, mixed>>},
     *     proposals_summary: array{total: int, by_change_type: array<string, int>},
     * }|null  null when the review row doesn't exist
     */
    public function getContext(string $unifiedId): ?array
    {
        $parsed = $this->parseUnifiedId($unifiedId);
        if ($parsed === null) {
            return null;
        }
        [$type, $id] = $parsed;

        $row = $this->loadReviewRow($type, $id);
        if ($row === null) {
            return null;
        }

        $details = $this->decodeDetails($row->details ?? null);
        $personId = $this->extractPersonId($details);
        $person = $personId !== null ? $this->personService->getPerson($personId) : null;

        // Phase 4: when the review item is a proposed merge, surface BOTH
        // existing persons + impact counts so Layout B can render side-by-
        // side. Other types keep the single `person` slot.
        $mergeContext = $type === 'genealogy_merge'
            ? $this->buildMergeContext($details)
            : null;
        $mediaRefs = $this->resolveMediaReferences($details);
        $targetRef = $type === 'genealogy_review_packet'
            ? app(ReviewTargetReferenceService::class)->forReviewRow(
                $row,
                (string) $row->review_type,
                isset($row->finding_type) && is_scalar($row->finding_type) ? (string) $row->finding_type : null
            )
            : null;

        $context = [
            'item' => [
                'id' => (int) $row->id,
                'unified_id' => $unifiedId,
                'review_type' => (string) $row->review_type,
                'agent_id' => (string) ($row->agent_id ?? ''),
                'title' => (string) ($row->title ?? ''),
                'summary' => (string) ($row->summary ?? ''),
                'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
                'priority' => $row->priority !== null ? (int) $row->priority : null,
                'status' => (string) ($row->status ?? 'pending'),
                'finding_type' => isset($row->finding_type) && is_scalar($row->finding_type)
                    ? (string) $row->finding_type
                    : null,
                'created_at' => $row->created_at,
                'expires_at' => $row->expires_at,
                'details' => $details,
            ],
            'person' => $person,
            'comparison' => [
                'field_diffs' => $this->computeFieldDiffs($details, $person),
            ],
            'proposals_summary' => $this->summarizeProposals($details),
            // Phase 2 additions — purely additive on the response shape
            'source_classifications' => $this->classifyProposalSources($details),
            'fan_overlap' => $this->computeFanOverlap($details, $person),
            'agent_reasoning' => $this->buildAgentReasoning($row, $details),
            // Phase 4 additions — merge context (null for non-merge types)
            'merge_context' => $mergeContext,
            // Operator UX gap fix: resolve `media #N` references in
            // proposals/evidence to genealogy_media rows so the detail
            // pane can surface a clickable source link instead of a
            // bare "media #13986" string.
            'media_refs' => $mediaRefs,
        ];
        if ($targetRef !== null) {
            $context['item']['target_ref'] = $targetRef;
        }

        if ($type === 'genealogy_review_packet') {
            $packetContext = $this->buildGenealogyReviewPacketContext(
                $details,
                $person,
                $mediaRefs,
                isset($row->status) && is_scalar($row->status) ? (string) $row->status : null,
                $targetRef
            );
            $context = array_merge($context, $packetContext);
        }

        if ($type === 'genealogy_finding') {
            $context = array_merge($context, $this->buildTypedRemediationFindingContext($row, $details));
        }

        return $context;
    }

    /**
     * Pending typed-remediation genealogy_finding rows are advisory until
     * a later materialization/apply path exists. Generate a display-only
     * preview from the current details payload, but never write it back to
     * agent_review_queue.details and never advertise canonical writeback.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function buildTypedRemediationFindingContext(object $row, array $details): array
    {
        if ((string) ($row->status ?? 'pending') !== 'pending'
            || ! $this->isTypedRemediationFinding($row, $details)) {
            return [];
        }

        $preview = $this->reviewPacketApplyPreview->preview($details);
        $preview['generated_from_details'] = true;
        $preview['persisted'] = false;

        return [
            'typed_remediation_preview' => $preview,
            'typed_remediation_preview_meta' => [
                'persisted' => false,
                'generated' => true,
                'source' => 'generated_from_finding_details',
                'writeback' => false,
            ],
            'typed_remediation_materialization' => $this->typedRemediationMaterializationReadiness($row),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function typedRemediationMaterializationReadiness(object $row): array
    {
        try {
            $inspection = $this->typedRemediationMaterialization->inspectQueueRow($row);
        } catch (\Throwable) {
            $inspection = [
                'success' => false,
                'error' => 'inspection_failed',
            ];
        }

        $status = match (true) {
            (bool) ($inspection['materialized_existing'] ?? false) => 'existing_packet',
            (bool) ($inspection['success'] ?? false) => 'dry_run_ready',
            ($inspection['error'] ?? null) === 'packet_validation_failed' => 'validation_blocked',
            ($inspection['error'] ?? null) === 'unsupported_typed_remediation' => 'unsupported',
            default => 'failed',
        };

        return [
            'projection_only' => true,
            'status' => $status,
            'operation_types' => $this->safeTypedRemediationOperationTypes($inspection['operation_types'] ?? []),
            'validation' => $this->safeTypedRemediationValidation($inspection['validation'] ?? null),
            'packet_summary' => $this->safeTypedRemediationPacketSummary($inspection['packet_summary'] ?? null),
            'safety' => [
                'canonical_write_allowed' => false,
                'apply_enabled' => false,
                'apply_held' => true,
                'writeback_enabled' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function safeTypedRemediationOperationTypes(mixed $types): array
    {
        if (! is_array($types)) {
            return [];
        }

        $safe = [];
        foreach ($types as $type) {
            $code = $this->safeReviewPassCode($type);
            if ($code !== null && in_array($code, self::TYPED_REMEDIATION_CHANGE_TYPES, true)) {
                $safe[] = $code;
            }
        }

        sort($safe);

        return array_values(array_unique($safe));
    }

    /**
     * @return array{valid: bool|null, blocker_count: int, blocker_codes: list<string>}
     */
    private function safeTypedRemediationValidation(mixed $validation): array
    {
        if (! is_array($validation)) {
            return [
                'valid' => null,
                'blocker_count' => 0,
                'blocker_codes' => [],
            ];
        }

        $codes = [];
        foreach ((array) ($validation['errors'] ?? []) as $error) {
            if (! is_array($error)) {
                continue;
            }

            $code = $this->safeReviewPassCode($error['code'] ?? null);
            if ($code !== null && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        sort($codes);

        return [
            'valid' => isset($validation['valid']) ? (bool) $validation['valid'] : null,
            'blocker_count' => count($codes),
            'blocker_codes' => $codes,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeTypedRemediationPacketSummary(mixed $summary): ?array
    {
        if (! is_array($summary)) {
            return null;
        }

        return [
            'target_review_type' => (string) ($summary['target_review_type'] ?? 'genealogy_review_packet'),
            'source_reference_count' => (int) ($summary['source_locator_count'] ?? 0),
            'claim_count' => (int) ($summary['claim_count'] ?? 0),
            'identity_present' => (bool) ($summary['identity_present'] ?? false),
            'target_context_present' => (bool) ($summary['target_context_present'] ?? false),
            'target_context_types' => $this->safeTypedRemediationTargetContextTypes($summary['target_context_types'] ?? []),
            'privacy_present' => (bool) ($summary['privacy_present'] ?? false),
            'validation_valid' => (bool) ($summary['validation_valid'] ?? false),
            'validation_error_count' => (int) ($summary['validation_error_count'] ?? 0),
            'validation_warning_count' => (int) ($summary['validation_warning_count'] ?? 0),
            'preview_only' => (bool) ($summary['preview_only'] ?? false),
            'mutates_accepted_facts' => (bool) ($summary['mutates_accepted_facts'] ?? false),
        ];
    }

    /**
     * @return list<string>
     */
    private function safeTypedRemediationTargetContextTypes(mixed $types): array
    {
        if (! is_array($types)) {
            return [];
        }

        $safe = [];
        foreach ($types as $type) {
            $code = $this->safeReviewPassCode($type);
            if ($code !== null && in_array($code, ['tree', 'person', 'family', 'source'], true)) {
                $safe[] = $code;
            }
        }

        sort($safe);

        return array_values(array_unique($safe));
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function isTypedRemediationFinding(object $row, array $details): bool
    {
        $findingType = isset($row->finding_type) && is_scalar($row->finding_type)
            ? trim((string) $row->finding_type)
            : '';
        if (in_array($findingType, self::TYPED_REMEDIATION_FINDING_TYPES, true)) {
            return true;
        }

        foreach ($this->typedRemediationTypeCandidates($details) as $type) {
            if (in_array($type, self::TYPED_REMEDIATION_CHANGE_TYPES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<int, string>
     */
    private function typedRemediationTypeCandidates(array $details): array
    {
        $candidates = [];
        foreach (['change_type', 'operation_type', 'operation', 'type', 'finding_type'] as $key) {
            $this->appendScalarTypeCandidate($candidates, $details[$key] ?? null);
        }

        foreach (['remediation', 'remediation_packet'] as $key) {
            $value = $details[$key] ?? null;
            if (! is_array($value)) {
                continue;
            }
            foreach (['change_type', 'operation_type', 'operation', 'type'] as $nestedKey) {
                $this->appendScalarTypeCandidate($candidates, $value[$nestedKey] ?? null);
            }
        }

        foreach (['proposals', 'claims', 'remediations', 'remediation_packets'] as $key) {
            $items = $details[$key] ?? null;
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach (['change_type', 'operation_type', 'operation', 'type'] as $nestedKey) {
                    $this->appendScalarTypeCandidate($candidates, $item[$nestedKey] ?? null);
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param  array<int, string>  $candidates
     */
    private function appendScalarTypeCandidate(array &$candidates, mixed $value): void
    {
        if (is_scalar($value) && trim((string) $value) !== '') {
            $candidates[] = trim((string) $value);
        }
    }

    /**
     * Surface packet-specific details as first-class context keys for
     * the Research Hub detail pane. These are read-only projections of
     * agent_review_queue.details; packet review decisions live elsewhere.
     *
     * @param  array<string, mixed>  $details
     * @param  array<int, array<string, mixed>>  $mediaRefs
     * @return array<string, mixed>
     */
    private function buildGenealogyReviewPacketContext(
        array $details,
        ?array $person = null,
        array $mediaRefs = [],
        ?string $rowStatus = null,
        ?string $targetRef = null
    ): array {
        [$applyPreview, $applyPreviewMeta] = $this->packetApplyPreviewContext($details);
        $validation = $this->detailArray($details, 'validation');
        $packetOutcome = $this->reviewPacketOutcome->fromDetails(
            $details,
            $rowStatus
        );
        $claimContexts = $this->reviewPacketClaimContexts($details, $person, $mediaRefs);
        $reviewFocus = $this->reviewPacketFocus->fromContext($details, $applyPreview, $applyPreviewMeta, $validation, $person, $mediaRefs);
        $reviewFocus['target_ref'] = $targetRef;
        $reviewChecklist = $this->reviewPacketChecklist($details, $reviewFocus, $packetOutcome, $claimContexts);
        $evidenceLens = $this->reviewPacketEvidenceLens($details, $claimContexts);
        $reviewProof = $this->reviewPacketProof(
            $details,
            $reviewFocus,
            $reviewChecklist,
            $packetOutcome,
            $claimContexts,
            $validation
        );

        return [
            'target_ref' => $targetRef,
            'packet' => $this->detailArray($details, 'packet'),
            'packet_status' => isset($details['packet_status']) && is_scalar($details['packet_status'])
                ? (string) $details['packet_status']
                : null,
            'claims' => $this->detailArray($details, 'claims'),
            'source_locator' => isset($details['source_locator']) && is_scalar($details['source_locator'])
                ? (string) $details['source_locator']
                : null,
            'source_locators' => $this->detailArray($details, 'source_locators'),
            'sources' => $this->detailArray($details, 'sources'),
            'identity' => $this->detailArray($details, 'identity'),
            'privacy' => $this->detailArray($details, 'privacy'),
            'validation' => $validation,
            'apply_preview' => $applyPreview,
            'apply_preview_meta' => $applyPreviewMeta,
            'decision_log' => $this->detailArray($details, 'decision_log'),
            'packet_outcome' => $packetOutcome,
            'claim_contexts' => $claimContexts,
            'review_focus' => $reviewFocus,
            'review_checklist' => $reviewChecklist,
            'evidence_lens' => $evidenceLens,
            'review_proof' => $reviewProof,
            'review_pass' => $this->reviewPacketPass(
                $reviewFocus,
                $reviewChecklist,
                $evidenceLens,
                $reviewProof,
                $packetOutcome,
            ),
        ];
    }

    /**
     * Display-only evidence quality projection for review packets.
     *
     * This is intentionally a narrow, whitelisted summary. It reports
     * classification and presence signals that help the operator review the
     * packet, but it does not expose raw claim text, source locators, row IDs,
     * tokens, or any apply/write path.
     *
     * @param  array<string, mixed>  $details
     * @param  list<array<string, mixed>>  $claimContexts
     * @return array<string, mixed>
     */
    private function reviewPacketEvidenceLens(array $details, array $claimContexts): array
    {
        $sources = $this->detailArray($details, 'sources');
        $claims = $this->detailArray($details, 'claims');
        $source = $this->firstArrayRow($sources);
        $claim = $this->firstArrayRow($claims);
        $claimRaw = is_array($claim['raw'] ?? null) ? $claim['raw'] : [];
        $classification = $this->evidenceLensClassification($details, $sources, $claims);
        $payloads = [$details, $source, $claim, $claimRaw];

        $sourceOrigin = $this->firstScalarTextFromPayloads($payloads, [
            'source_origin',
            'source_type',
            'record_origin',
            'origin_type',
        ]) ?: $classification['source_type'];
        $informationType = $this->firstScalarTextFromPayloads($payloads, [
            'information_type',
            'information_quality',
            'info_type',
        ]) ?: $classification['information_type'];
        $evidenceType = $this->firstScalarTextFromPayloads($payloads, [
            'evidence_type',
            'evidence_quality',
        ]) ?: $classification['evidence_type'];
        $citationQuality = $this->firstScalarTextFromPayloads($payloads, [
            'citation_quality',
            'citation_status',
            'source_quality',
            'citation_quality_label',
        ]);
        $extractionCertainty = $this->firstScalarTextFromPayloads($payloads, [
            'extraction_certainty',
            'extraction_confidence',
            'certainty',
            'confidence_label',
            'confidence',
        ]);
        $conflictSignal = $this->firstBooleanFromPayloads($payloads, [
            'has_conflict',
            'conflict',
            'conflict_flag',
            'conflict_present',
            'conflicting_evidence',
        ]) ?? false;
        $negativeEvidence = $this->firstBooleanFromPayloads($payloads, [
            'negative_evidence',
            'has_negative_evidence',
            'negative_evidence_present',
        ]) ?? (strtolower((string) $evidenceType) === 'negative');
        $locatorPresent = $this->evidenceLensLocatorPresent($details, $sources, $claimContexts);
        $extractPresent = $this->evidenceLensExtractPresent($details, $sources, $claims);
        $note = $this->evidenceLensNote([$details, $source, $claim, $claimRaw]);

        $rows = [
            $this->checklistRow('source_origin', 'Source origin', $this->evidenceLensValue($sourceOrigin), $this->evidenceLensKnownState($sourceOrigin)),
            $this->checklistRow('information_type', 'Information type', $this->evidenceLensValue($informationType), $this->evidenceLensKnownState($informationType)),
            $this->checklistRow('evidence_type', 'Evidence type', $this->evidenceLensValue($evidenceType), $this->evidenceLensKnownState($evidenceType)),
            $this->checklistRow('citation_quality', 'Citation quality', $this->evidenceLensValue($citationQuality), $this->evidenceLensKnownState($citationQuality)),
            $this->checklistRow('extraction_certainty', 'Extraction certainty', $this->evidenceLensValue($extractionCertainty), $this->evidenceLensKnownState($extractionCertainty)),
            $this->checklistRow('conflict_signal', 'Conflict signal', $conflictSignal ? 'possible' : 'not signaled', $conflictSignal ? 'warning' : 'ok'),
            $this->checklistRow('negative_evidence', 'Negative evidence', $negativeEvidence ? 'yes' : 'no', $negativeEvidence ? 'warning' : 'ok'),
            $this->checklistRow('locator_present', 'Locator present', $locatorPresent ? 'yes' : 'no', $locatorPresent ? 'ok' : 'missing'),
            $this->checklistRow('extract_present', 'Extract present', $extractPresent ? 'yes' : 'no', $extractPresent ? 'ok' : 'missing'),
        ];

        if ($note !== null) {
            $rows[] = $this->checklistRow('evidence_note', 'Evidence note', $note, 'ok');
        }

        return [
            'schema' => 'genealogy_review_packet_evidence_lens.v1',
            'mode' => 'display_only',
            'derived' => true,
            'canonical_write_allowed' => false,
            'batch_review_allowed' => false,
            'details_included' => false,
            'raw_identifiers_included' => false,
            'tokens_included' => false,
            'locators_included' => false,
            'summary' => [
                'source_origin' => $this->evidenceLensValue($sourceOrigin),
                'information_type' => $this->evidenceLensValue($informationType),
                'evidence_type' => $this->evidenceLensValue($evidenceType),
                'citation_quality' => $this->evidenceLensValue($citationQuality),
                'extraction_certainty' => $this->evidenceLensValue($extractionCertainty),
                'has_conflict' => $conflictSignal,
                'has_negative_evidence' => $negativeEvidence,
                'locator_present' => $locatorPresent,
                'extract_present' => $extractPresent,
                'note_present' => $note !== null,
            ],
            'row_count' => count($rows),
            'rows' => $rows,
            'state_counts' => array_count_values(array_map(
                fn (array $row): string => (string) ($row['state'] ?? 'unknown'),
                $rows
            )),
        ];
    }

    /**
     * Display-only review checklist for a packet detail pane. The checklist is
     * derived from already-projected packet context and never persisted back
     * to agent_review_queue.details.
     *
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $reviewFocus
     * @param  array<string, mixed>  $packetOutcome
     * @param  list<array<string, mixed>>  $claimContexts
     * @return array<string, mixed>
     */
    private function reviewPacketChecklist(
        array $details,
        array $reviewFocus,
        array $packetOutcome,
        array $claimContexts
    ): array {
        $identity = $this->detailArray($details, 'identity');
        $privacy = $this->detailArray($details, 'privacy');
        $rows = [
            $this->checklistRow(
                'target_ref',
                'Target ref',
                $this->firstScalarText($reviewFocus, ['target_ref']),
                $this->firstScalarText($reviewFocus, ['target_ref']) !== null ? 'ok' : 'missing'
            ),
            $this->checklistRow(
                'review_mode',
                'Review mode',
                'single packet',
                ($reviewFocus['review_mode'] ?? null) === 'single_packet' ? 'ok' : 'warning'
            ),
            $this->checklistRow(
                'source_backed',
                'Source-backed',
                ($reviewFocus['source_backed'] ?? null) === true ? 'yes' : 'no',
                ($reviewFocus['source_backed'] ?? null) === true ? 'ok' : 'blocked'
            ),
            $this->checklistRow(
                'boundary',
                'Boundary',
                $this->firstScalarText($reviewFocus, ['boundary_label']),
                $this->firstScalarText($reviewFocus, ['boundary_label']) !== null ? 'ok' : 'warning'
            ),
            $this->checklistRow(
                'identity',
                'Identity',
                $this->identityChecklistValue($identity, $reviewFocus),
                $this->positiveInt($reviewFocus['person_id'] ?? null) !== null ? 'ok' : 'warning'
            ),
            $this->checklistRow(
                'privacy',
                'Privacy',
                $this->privacyChecklistValue($privacy),
                ($privacy['cleared'] ?? null) === true && ($privacy['living_person_risk'] ?? null) !== true ? 'ok' : 'warning'
            ),
            $this->checklistRow(
                'claims',
                'Claims',
                count($claimContexts).' claim context'.(count($claimContexts) === 1 ? '' : 's'),
                count($claimContexts) > 0 ? 'ok' : 'blocked'
            ),
            $this->checklistRow(
                'sources',
                'Sources',
                $this->checklistCountLabel($reviewFocus['source_count'] ?? null, 'source'),
                $this->positiveInt($reviewFocus['source_count'] ?? null) !== null ? 'ok' : 'warning'
            ),
            $this->checklistRow(
                'preview_only',
                'Preview-only',
                ($reviewFocus['preview_only'] ?? null) === true ? 'yes' : 'no',
                ($reviewFocus['preview_only'] ?? null) === true ? 'ok' : 'blocked'
            ),
            $this->checklistRow(
                'canonical_mutation',
                'Canonical mutation',
                ($reviewFocus['canonical_mutation'] ?? null) === true ? 'possible' : 'none',
                ($reviewFocus['canonical_mutation'] ?? null) === true ? 'blocked' : 'ok'
            ),
            $this->checklistRow(
                'approval_readiness',
                'Approval readiness',
                $this->reviewReadinessLabel($reviewFocus),
                ($reviewFocus['approval_ready'] ?? null) === true ? 'ok' : 'blocked'
            ),
            $this->checklistRow(
                'outcome_state',
                'Outcome state',
                $this->packetOutcomeLabel($packetOutcome),
                $this->packetOutcomeChecklistState($packetOutcome)
            ),
        ];

        return [
            'schema' => 'genealogy_review_packet_checklist.v1',
            'mode' => 'display_only',
            'derived' => true,
            'canonical_write_allowed' => false,
            'batch_review_allowed' => false,
            'row_count' => count($rows),
            'rows' => $rows,
            'state_counts' => array_count_values(array_map(
                fn (array $row): string => (string) ($row['state'] ?? 'unknown'),
                $rows
            )),
        ];
    }

    /**
     * Display-only proof receipt for the operator review pass. This is a
     * whitelisted projection of derived packet context, not a raw JSON dump.
     *
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $reviewFocus
     * @param  array<string, mixed>  $reviewChecklist
     * @param  array<string, mixed>  $packetOutcome
     * @param  list<array<string, mixed>>  $claimContexts
     * @param  array<string, mixed>  $validation
     * @return array<string, mixed>
     */
    private function reviewPacketProof(
        array $details,
        array $reviewFocus,
        array $reviewChecklist,
        array $packetOutcome,
        array $claimContexts,
        array $validation
    ): array {
        $privacy = $this->detailArray($details, 'privacy');
        $targetRef = $this->safeTargetRef($this->firstScalarText($reviewFocus, ['target_ref']));
        $sourceBacked = ($reviewFocus['source_backed'] ?? null) === true;
        $boundaryPresent = $this->firstScalarText($reviewFocus, ['boundary_label']) !== null;
        $identityPresent = $this->positiveInt($reviewFocus['person_id'] ?? null) !== null;
        $privacyCleared = ($privacy['cleared'] ?? null) === true && ($privacy['living_person_risk'] ?? null) !== true;
        $previewOnly = ($reviewFocus['preview_only'] ?? null) === true;
        $canonicalMutation = ($reviewFocus['canonical_mutation'] ?? null) === true;
        $approvalReady = ($reviewFocus['approval_ready'] ?? null) === true;
        $validationState = $this->validationProofState($validation);
        $claimCount = count($claimContexts);
        $sourceCount = $this->nonNegativeInt($reviewFocus['source_count'] ?? null) ?? 0;
        $resolvedMediaCount = $this->nonNegativeInt($reviewFocus['resolved_media_count'] ?? null) ?? 0;
        $missingMediaCount = $this->nonNegativeInt($reviewFocus['missing_media_count'] ?? null) ?? 0;
        $claimSourceCoverage = $this->reviewPacketClaimSourceCoverage($claimContexts);
        $allClaimsSourceLinked = (bool) $claimSourceCoverage['all_claims_source_linked'];
        $claimsWithSourceContext = (int) $claimSourceCoverage['claims_with_source_context'];

        $rows = [
            $this->checklistRow('target_ref', 'Target ref', $targetRef ?? 'missing', $targetRef !== null ? 'ok' : 'missing'),
            $this->checklistRow('review_mode', 'Review mode', 'single packet', ($reviewFocus['review_mode'] ?? null) === 'single_packet' ? 'ok' : 'warning'),
            $this->checklistRow('source_backed', 'Source-backed', $sourceBacked ? 'yes' : 'no', $sourceBacked ? 'ok' : 'blocked'),
            $this->checklistRow('boundary_present', 'Boundary present', $boundaryPresent ? 'yes' : 'no', $boundaryPresent ? 'ok' : 'warning'),
            $this->checklistRow('identity_present', 'Identity present', $identityPresent ? 'yes' : 'no', $identityPresent ? 'ok' : 'warning'),
            $this->checklistRow('privacy_cleared', 'Privacy cleared', $privacyCleared ? 'yes' : 'no', $privacyCleared ? 'ok' : 'warning'),
            $this->checklistRow('claim_count', 'Claim count', (string) $claimCount, $claimCount > 0 ? 'ok' : 'blocked'),
            $this->checklistRow('source_count', 'Source count', (string) $sourceCount, $sourceCount > 0 ? 'ok' : 'warning'),
            $this->checklistRow(
                'claim_source_coverage',
                'Claim/source coverage',
                (string) $claimSourceCoverage['claim_source_coverage'],
                $allClaimsSourceLinked ? 'ok' : ($claimsWithSourceContext > 0 ? 'warning' : 'blocked')
            ),
            $this->checklistRow(
                'all_claims_source_linked',
                'All claims source-linked',
                $allClaimsSourceLinked ? 'yes' : 'no',
                $allClaimsSourceLinked ? 'ok' : ($claimCount > 0 ? 'warning' : 'blocked')
            ),
            $this->checklistRow('media_resolved', 'Media resolved', (string) $resolvedMediaCount, 'ok'),
            $this->checklistRow('media_missing', 'Media missing', (string) $missingMediaCount, $missingMediaCount === 0 ? 'ok' : 'warning'),
            $this->checklistRow('validation_state', 'Validation', $validationState, $validationState === 'valid' ? 'ok' : 'blocked'),
            $this->checklistRow('approval_ready', 'Approval-ready', $approvalReady ? 'yes' : 'no', $approvalReady ? 'ok' : 'blocked'),
            $this->checklistRow('preview_only', 'Preview-only', $previewOnly ? 'yes' : 'no', $previewOnly ? 'ok' : 'blocked'),
            $this->checklistRow('canonical_mutation', 'Canonical mutation', $canonicalMutation ? 'possible' : 'none', $canonicalMutation ? 'blocked' : 'ok'),
            $this->checklistRow('outcome_state', 'Outcome state', $this->packetOutcomeLabel($packetOutcome), $this->packetOutcomeChecklistState($packetOutcome)),
            $this->checklistRow('canonical_write_allowed', 'Canonical write allowed', 'no', 'ok'),
            $this->checklistRow('batch_review_allowed', 'Batch review allowed', 'no', 'ok'),
            $this->checklistRow('details_included', 'Details included', 'no', 'ok'),
        ];

        return [
            'schema' => 'genealogy_review_packet_proof.v1',
            'mode' => 'display_only',
            'derived' => true,
            'target_ref' => $targetRef,
            'canonical_write_allowed' => false,
            'batch_review_allowed' => false,
            'details_included' => false,
            'summary' => [
                'review_ready' => $approvalReady,
                'source_backed' => $sourceBacked,
                'boundary_present' => $boundaryPresent,
                'identity_present' => $identityPresent,
                'privacy_cleared' => $privacyCleared,
                'claim_count' => $claimCount,
                'source_count' => $sourceCount,
                'claims_with_source_context' => $claimsWithSourceContext,
                'all_claims_source_linked' => $allClaimsSourceLinked,
                'claim_source_coverage' => (string) $claimSourceCoverage['claim_source_coverage'],
                'resolved_media_count' => $resolvedMediaCount,
                'missing_media_count' => $missingMediaCount,
                'validation_state' => $validationState,
                'preview_only' => $previewOnly,
                'canonical_mutation' => $canonicalMutation,
                'outcome_state' => $this->firstScalarText($packetOutcome, ['outcome_state']) ?? 'unknown',
                'checklist_row_count' => count((array) ($reviewChecklist['rows'] ?? [])),
            ],
            'row_count' => count($rows),
            'rows' => $rows,
            'state_counts' => array_count_values(array_map(
                fn (array $row): string => (string) ($row['state'] ?? 'unknown'),
                $rows
            )),
        ];
    }

    /**
     * Compact display-only Review Hub pass projection.
     *
     * This intentionally summarizes the existing sanitized projections instead
     * of copying packet details. It omits raw row IDs, person IDs, tokens, raw
     * source locators, claim text, source rows, media rows, and details JSON.
     *
     * @param  array<string, mixed>  $reviewFocus
     * @param  array<string, mixed>  $reviewChecklist
     * @param  array<string, mixed>  $evidenceLens
     * @param  array<string, mixed>  $reviewProof
     * @param  array<string, mixed>  $packetOutcome
     * @return array<string, mixed>
     */
    private function reviewPacketPass(
        array $reviewFocus,
        array $reviewChecklist,
        array $evidenceLens,
        array $reviewProof,
        array $packetOutcome
    ): array {
        $proofSummary = is_array($reviewProof['summary'] ?? null) ? $reviewProof['summary'] : [];
        $evidenceSummary = is_array($evidenceLens['summary'] ?? null) ? $evidenceLens['summary'] : [];
        $readiness = is_array($reviewFocus['review_readiness'] ?? null) ? $reviewFocus['review_readiness'] : [];
        $reasonCode = $this->safeReviewPassCode($readiness['reason_code'] ?? null);
        $state = $this->safeReviewPassCode($readiness['state'] ?? null) ?? 'unknown';
        $blockerCodes = $this->reviewPassBlockerCodes($reviewFocus);

        return [
            'schema' => 'genealogy_review_packet_review_pass.v1',
            'mode' => 'display_only',
            'derived' => true,
            'target_ref' => $this->safeTargetRef($this->firstScalarText($reviewFocus, ['target_ref'])),
            'state' => $state,
            'label' => $this->reviewPassLabel($state, $reasonCode),
            'reason_code' => $reasonCode,
            'blocker_count' => $this->nonNegativeInt($readiness['blocker_count'] ?? null) ?? count($blockerCodes),
            'blocker_codes' => $blockerCodes,
            'counts' => [
                'claim_count' => $this->nonNegativeInt($proofSummary['claim_count'] ?? null) ?? 0,
                'source_count' => $this->nonNegativeInt($proofSummary['source_count'] ?? null) ?? 0,
                'claims_with_source_context' => $this->nonNegativeInt($proofSummary['claims_with_source_context'] ?? null) ?? 0,
                'resolved_media_count' => $this->nonNegativeInt($proofSummary['resolved_media_count'] ?? null) ?? 0,
                'missing_media_count' => $this->nonNegativeInt($proofSummary['missing_media_count'] ?? null) ?? 0,
                'checklist_row_count' => $this->nonNegativeInt($proofSummary['checklist_row_count'] ?? null)
                    ?? $this->nonNegativeInt($reviewChecklist['row_count'] ?? null)
                    ?? 0,
            ],
            'signals' => [
                'review_ready' => ($proofSummary['review_ready'] ?? null) === true,
                'source_backed' => ($proofSummary['source_backed'] ?? null) === true,
                'all_claims_source_linked' => ($proofSummary['all_claims_source_linked'] ?? null) === true,
                'boundary_present' => ($proofSummary['boundary_present'] ?? null) === true,
                'identity_present' => ($proofSummary['identity_present'] ?? null) === true,
                'privacy_cleared' => ($proofSummary['privacy_cleared'] ?? null) === true,
                'preview_only' => ($proofSummary['preview_only'] ?? null) === true,
                'canonical_mutation' => ($proofSummary['canonical_mutation'] ?? null) === true,
                'validation_state' => $this->safeReviewPassCode($proofSummary['validation_state'] ?? null) ?? 'unknown',
                'outcome_state' => $this->safeReviewPassCode($packetOutcome['outcome_state'] ?? null) ?? 'unknown',
                'conflict_signal' => ($evidenceSummary['has_conflict'] ?? null) === true,
                'negative_evidence' => ($evidenceSummary['has_negative_evidence'] ?? null) === true,
                'locator_present' => ($evidenceSummary['locator_present'] ?? null) === true,
                'extract_present' => ($evidenceSummary['extract_present'] ?? null) === true,
            ],
            'posture' => $this->reviewPassPosture(),
        ];
    }

    /**
     * @param  array<string, mixed>  $reviewFocus
     * @return list<string>
     */
    private function reviewPassBlockerCodes(array $reviewFocus): array
    {
        $codes = [];
        foreach ((array) ($reviewFocus['approval_blockers'] ?? []) as $blocker) {
            if (! is_array($blocker)) {
                continue;
            }

            $code = $this->safeReviewPassCode($blocker['code'] ?? null);
            if ($code !== null) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param  list<array<string, mixed>>  $claimContexts
     * @return array{claim_source_coverage:string,claims_with_source_context:int,all_claims_source_linked:bool}
     */
    private function reviewPacketClaimSourceCoverage(array $claimContexts): array
    {
        $claimCount = count($claimContexts);
        $claimsWithSourceContext = 0;

        foreach ($claimContexts as $claimContext) {
            if ($this->claimContextHasSourceEvidence($claimContext)) {
                $claimsWithSourceContext++;
            }
        }

        return [
            'claim_source_coverage' => $claimsWithSourceContext.'/'.$claimCount,
            'claims_with_source_context' => $claimsWithSourceContext,
            'all_claims_source_linked' => $claimCount > 0 && $claimsWithSourceContext === $claimCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $claimContext
     */
    private function claimContextHasSourceEvidence(array $claimContext): bool
    {
        if ($this->firstScalarText($claimContext, [
            'source_ref',
            'source_label',
            'source_locator',
            'source_access_class',
        ]) !== null) {
            return true;
        }

        if (($this->nonNegativeInt($claimContext['media_ref_count'] ?? null) ?? 0) > 0) {
            return true;
        }

        return count(array_filter(
            (array) ($claimContext['media_refs'] ?? []),
            fn (mixed $media): bool => is_array($media)
        )) > 0;
    }

    private function reviewPassLabel(string $state, ?string $reasonCode): string
    {
        if ($state === 'ready') {
            return 'Ready for review';
        }

        return match ($reasonCode) {
            'apply_preview_missing' => 'Blocked: Apply preview missing',
            'preview_not_preview_only' => 'Blocked: Preview is not preview-only',
            'canonical_mutation_possible' => 'Blocked: Canonical mutation possible',
            'validation_missing' => 'Blocked: Validation missing',
            'validation_not_valid' => 'Blocked: Validation is not valid',
            'validation_errors' => 'Blocked: Validation errors present',
            'malformed_details' => 'Blocked: Malformed packet details',
            default => 'Blocked: Review readiness unknown',
        };
    }

    private function safeReviewPassCode(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '' || preg_match('/^[a-z0-9_:-]{1,80}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * @return array<string, bool>
     */
    private function reviewPassPosture(): array
    {
        return [
            'canonical_write_allowed' => false,
            'batch_review_allowed' => false,
            'automation_allowed' => false,
            'details_included' => false,
            'raw_identifiers_included' => false,
            'tokens_included' => false,
            'locators_included' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $validation
     */
    private function validationProofState(array $validation): string
    {
        $errors = is_array($validation['errors'] ?? null) ? $validation['errors'] : [];
        $warnings = is_array($validation['warnings'] ?? null) ? $validation['warnings'] : [];

        if (($validation['valid'] ?? null) === true) {
            return 'valid';
        }
        if ($errors !== []) {
            return 'errors';
        }
        if ($warnings !== []) {
            return 'warnings';
        }

        return $validation === [] ? 'missing' : 'unknown';
    }

    private function safeTargetRef(?string $targetRef): ?string
    {
        if ($targetRef === null || preg_match('/^genealogy_review_packet:target-[a-f0-9]{12}$/', $targetRef) !== 1) {
            return null;
        }

        return $targetRef;
    }

    /**
     * @return array{key: string, label: string, value: string, state: string}
     */
    private function checklistRow(string $key, string $label, mixed $value, string $state): array
    {
        $allowedStates = ['ok' => true, 'warning' => true, 'blocked' => true, 'missing' => true];

        return [
            'key' => $key,
            'label' => $label,
            'value' => is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : '-',
            'state' => isset($allowedStates[$state]) ? $state : 'warning',
        ];
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $reviewFocus
     */
    private function identityChecklistValue(array $identity, array $reviewFocus): string
    {
        $personLabel = $this->firstScalarText($reviewFocus, ['person_label']);
        if ($personLabel !== null && preg_match('/^Person #\d+$/', $personLabel) !== 1) {
            return $personLabel;
        }

        if ($this->positiveInt($reviewFocus['person_id'] ?? null) !== null) {
            return 'person reference present';
        }

        return $this->firstScalarText($identity, ['status']) ?: 'unknown';
    }

    /**
     * @param  array<string, mixed>  $privacy
     */
    private function privacyChecklistValue(array $privacy): string
    {
        if (($privacy['cleared'] ?? null) === true && ($privacy['living_person_risk'] ?? null) !== true) {
            return 'cleared';
        }

        if (($privacy['living_person_risk'] ?? null) === true) {
            return 'living-person risk';
        }

        return $this->firstScalarText($privacy, ['status', 'manual_source_gate']) ?: 'unknown';
    }

    private function checklistCountLabel(mixed $value, string $singular): string
    {
        $count = $this->nonNegativeInt($value);
        if ($count === null) {
            return 'unknown';
        }

        return $count.' '.$singular.($count === 1 ? '' : 's');
    }

    /**
     * @param  array<string, mixed>  $reviewFocus
     */
    private function reviewReadinessLabel(array $reviewFocus): string
    {
        $readiness = is_array($reviewFocus['review_readiness'] ?? null) ? $reviewFocus['review_readiness'] : [];

        return $this->firstScalarText($readiness, ['label'])
            ?: (($reviewFocus['approval_ready'] ?? null) === true ? 'Ready for review' : 'Not approval-ready');
    }

    /**
     * @param  array<string, mixed>  $packetOutcome
     */
    private function packetOutcomeLabel(array $packetOutcome): string
    {
        return $this->firstScalarText($packetOutcome, ['progress_label', 'outcome_label', 'outcome_state']) ?: 'awaiting first decision';
    }

    /**
     * @param  array<string, mixed>  $packetOutcome
     */
    private function packetOutcomeChecklistState(array $packetOutcome): string
    {
        $state = $this->firstScalarText($packetOutcome, ['outcome_state']);
        if ($state === 'terminal') {
            return 'ok';
        }
        if ($state === 'follow_up' || $state === 'touched') {
            return 'warning';
        }

        return 'missing';
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<string, mixed>
     */
    private function firstArrayRow(array $rows): array
    {
        foreach ($rows as $row) {
            if (is_array($row)) {
                return $row;
            }
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @param  list<string>  $keys
     */
    private function firstScalarTextFromPayloads(array $payloads, array $keys): ?string
    {
        foreach ($payloads as $payload) {
            $value = $this->firstScalarText($payload, $keys);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @param  list<string>  $keys
     */
    private function firstBooleanFromPayloads(array $payloads, array $keys): ?bool
    {
        foreach ($payloads as $payload) {
            foreach ($keys as $key) {
                if (! array_key_exists($key, $payload)) {
                    continue;
                }

                $value = $this->booleanSignal($payload[$key]);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function booleanSignal(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1 ? true : ($value === 0 ? false : null);
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'y', 'present', 'possible'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'n', 'absent', 'none', 'not signaled'], true)) {
                return false;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<int, mixed>  $sources
     * @param  array<int, mixed>  $claims
     * @return array{source_type: string, information_type: string, evidence_type: string}
     */
    private function evidenceLensClassification(array $details, array $sources, array $claims): array
    {
        foreach ([$details, $this->firstArrayRow($sources), $this->firstArrayRow($claims)] as $payload) {
            $classification = $this->extractAgentSourceClassification($payload);
            if ($classification !== null) {
                return [
                    'source_type' => $classification['source_type'],
                    'information_type' => $classification['information_type'],
                    'evidence_type' => $classification['evidence_type'],
                ];
            }
        }

        [$sourceType, $informationType, $evidenceType] = $this->classifyText(
            $this->evidenceLensClassificationText($details, $sources, $claims)
        );

        return [
            'source_type' => $sourceType,
            'information_type' => $informationType,
            'evidence_type' => $evidenceType,
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<int, mixed>  $sources
     * @param  array<int, mixed>  $claims
     */
    private function evidenceLensClassificationText(array $details, array $sources, array $claims): string
    {
        $parts = [];
        $this->appendEvidenceLensTextParts($parts, $details, [
            'source_locator',
            'source_label',
            'citation',
            'title',
            'summary',
        ]);

        foreach ([$sources, $claims] as $rows) {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $this->appendEvidenceLensTextParts($parts, $row, [
                    'locator',
                    'source_locator',
                    'url',
                    'uri',
                    'path',
                    'citation',
                    'title',
                    'name',
                    'label',
                    'source_ref',
                    'evidence_source',
                ]);
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * @param  list<string>  $parts
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function appendEvidenceLensTextParts(array &$parts, array $payload, array $keys): void
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $parts[] = trim((string) $value);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<int, mixed>  $sources
     * @param  list<array<string, mixed>>  $claimContexts
     */
    private function evidenceLensLocatorPresent(array $details, array $sources, array $claimContexts): bool
    {
        if ($this->firstScalarText($details, ['source_locator']) !== null) {
            return true;
        }

        foreach ($this->detailArray($details, 'source_locators') as $locator) {
            if (is_scalar($locator) && trim((string) $locator) !== '') {
                return true;
            }
        }

        foreach ($sources as $source) {
            if (is_array($source) && $this->firstScalarText($source, [
                'locator',
                'source_locator',
                'url',
                'uri',
                'path',
                'citation',
            ]) !== null) {
                return true;
            }
        }

        foreach ($claimContexts as $context) {
            if ($this->firstScalarText($context, ['source_locator', 'source_ref']) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<int, mixed>  $sources
     * @param  array<int, mixed>  $claims
     */
    private function evidenceLensExtractPresent(array $details, array $sources, array $claims): bool
    {
        foreach ([$details, $this->firstArrayRow($sources)] as $payload) {
            if ($this->firstScalarText($payload, [
                'extract',
                'excerpt',
                'transcript',
                'quote',
                'ocr_text',
                'extracted_text',
            ]) !== null) {
                return true;
            }
        }

        foreach ($claims as $claim) {
            if (! is_array($claim)) {
                continue;
            }
            $raw = is_array($claim['raw'] ?? null) ? $claim['raw'] : [];
            if ($this->firstScalarTextFromPayloads([$claim, $raw], [
                'extract',
                'excerpt',
                'transcript',
                'quote',
                'ocr_text',
                'text',
                'extracted_text',
                'extracted_claim',
            ]) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    private function evidenceLensNote(array $payloads): ?string
    {
        $note = $this->firstScalarTextFromPayloads($payloads, [
            'evidence_note',
            'evidence_notes',
            'citation_note',
            'citation_notes',
            'extraction_note',
            'extraction_notes',
            'notes',
            'note',
        ]);
        if ($note === null) {
            return null;
        }

        $note = preg_replace('/\s+/', ' ', $note);
        $note = is_string($note) ? trim($note) : '';
        if ($note === '' || $this->unsafeEvidenceLensText($note)) {
            return null;
        }

        return mb_strlen($note) > 120 ? mb_substr($note, 0, 117).'...' : $note;
    }

    private function evidenceLensValue(?string $value): string
    {
        if ($value === null) {
            return 'unknown';
        }

        $value = preg_replace('/\s+/', ' ', $value);
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || $this->unsafeEvidenceLensText($value)) {
            return 'unknown';
        }

        $value = strtolower($value);

        return $value !== '' ? str_replace('_', ' ', $value) : 'unknown';
    }

    private function unsafeEvidenceLensText(string $value): bool
    {
        return preg_match(
            '/(?:[a-z][a-z0-9+.-]*:\/\/|\/[^ ]+|[a-f0-9]{24,}|(?:token|secret|password|api[_-]?key|access[_-]?key|session)=[^ ]+)/i',
            $value
        ) === 1;
    }

    private function evidenceLensKnownState(?string $value): string
    {
        $normalized = $this->evidenceLensValue($value);

        return $normalized !== 'unknown' && $normalized !== 'unclassified' ? 'ok' : 'warning';
    }

    /**
     * Build display-only claim rows for the packet detail pane. The rows are
     * projections of the persisted packet payload plus already-resolved media
     * refs; they are never written back to agent_review_queue.details.
     *
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>|null  $person
     * @param  array<int, array<string, mixed>>  $mediaRefs
     * @return list<array<string, mixed>>
     */
    private function reviewPacketClaimContexts(array $details, ?array $person, array $mediaRefs): array
    {
        $claims = $this->detailArray($details, 'claims');
        if ($claims === []) {
            return [];
        }

        $sources = $this->detailArray($details, 'sources');
        $sourceLocators = $this->detailArray($details, 'source_locators');
        $mediaById = $this->mediaRefsById($mediaRefs);
        $contexts = [];

        foreach ($claims as $idx => $claim) {
            if (! is_array($claim)) {
                continue;
            }

            $contexts[] = $this->reviewPacketClaimContext(
                $claim,
                $idx,
                $details,
                $person,
                $sources,
                $sourceLocators,
                $mediaById
            );
        }

        return $contexts;
    }

    /**
     * @param  array<string, mixed>  $claim
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>|null  $person
     * @param  array<int, mixed>  $sources
     * @param  array<int, mixed>  $sourceLocators
     * @param  array<int, array<string, mixed>>  $mediaById
     * @return array<string, mixed>
     */
    private function reviewPacketClaimContext(
        array $claim,
        int $idx,
        array $details,
        ?array $person,
        array $sources,
        array $sourceLocators,
        array $mediaById
    ): array {
        $raw = is_array($claim['raw'] ?? null) ? $claim['raw'] : [];
        $claimIndex = $this->nonNegativeInt($claim['index'] ?? null);
        $sourceRef = $this->claimSourceRef($claim, $raw);
        $source = $this->matchClaimContextSource($sourceRef, $sources, $sourceLocators, $idx);
        $claimMediaRefs = $this->claimMediaReferences($claim, $mediaById);
        $personId = $this->claimPersonId($claim, $raw, $details, $person);

        return [
            'claim_index' => $claimIndex,
            'display_index' => $claimIndex !== null ? $claimIndex + 1 : $idx + 1,
            'claim_text' => $this->claimText($claim, $raw),
            'field_name' => $this->firstScalarText($claim, ['field_name'])
                ?: $this->firstScalarText($raw, ['field_name']),
            'change_type' => $this->firstScalarText($claim, ['change_type'])
                ?: $this->firstScalarText($raw, ['change_type']),
            'relationship_type' => $this->firstScalarText($claim, ['relationship_type'])
                ?: $this->firstScalarText($raw, ['relationship_type']),
            'person_id' => $personId,
            'person_label' => $this->personContextLabel($person, $personId),
            'source_ref' => $sourceRef,
            'source_label' => $this->reviewPacketClaimSourceLabel($source, $sourceRef, $idx),
            'source_locator' => $source['locator'] ?? $this->sourceLocatorFromRef($sourceRef),
            'source_access_class' => $source['access_class'] ?? null,
            'media_refs' => $claimMediaRefs,
            'media_ref_count' => count($claimMediaRefs),
            'resolved_media_count' => count(array_filter(
                $claimMediaRefs,
                fn (array $media): bool => $this->positiveInt($media['id'] ?? null) !== null
            )),
            'missing_media_count' => count(array_filter(
                $claimMediaRefs,
                fn (array $media): bool => ($media['file_exists'] ?? null) === false
            )),
        ];
    }

    /**
     * @param  array{label?: ?string}|null  $source
     */
    private function reviewPacketClaimSourceLabel(?array $source, ?string $sourceRef, int $idx): ?string
    {
        $label = $this->firstScalarText($source ?? [], ['label']);
        if ($label !== null) {
            return $label;
        }

        return $sourceRef !== null && trim($sourceRef) !== ''
            ? 'Source '.($idx + 1)
            : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $mediaRefs
     * @return array<int, array<string, mixed>>
     */
    private function mediaRefsById(array $mediaRefs): array
    {
        $byId = [];
        foreach ($mediaRefs as $media) {
            $id = $this->positiveInt($media['id'] ?? null);
            if ($id !== null) {
                $byId[$id] = $media;
            }
        }

        return $byId;
    }

    /**
     * @param  array<string, mixed>  $claim
     * @param  array<string, mixed>  $raw
     */
    private function claimText(array $claim, array $raw): ?string
    {
        return $this->firstScalarText($claim, [
            'claim',
            'claim_text',
            'statement',
            'extracted_claim',
            'extracted_text',
            'text',
            'proposed_value',
        ]) ?: $this->firstScalarText($raw, [
            'claim',
            'claim_text',
            'statement',
            'extracted_claim',
            'extracted_text',
            'text',
            'proposed_value',
            'value',
        ]);
    }

    /**
     * @param  array<string, mixed>  $claim
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>|null  $person
     */
    private function claimPersonId(array $claim, array $raw, array $details, ?array $person): ?int
    {
        foreach ([
            $claim['person_id'] ?? null,
            $claim['target_person_id'] ?? null,
            $raw['person_id'] ?? null,
            $raw['target_person_id'] ?? null,
            $details['identity']['person_id'] ?? null,
            $details['identity']['target_person_id'] ?? null,
            $person['id'] ?? null,
        ] as $value) {
            $personId = $this->positiveInt($value);
            if ($personId !== null) {
                return $personId;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $person
     */
    private function personContextLabel(?array $person, ?int $personId): ?string
    {
        if ($person !== null) {
            $loadedId = $this->positiveInt($person['id'] ?? null);
            if ($personId === null || $loadedId === null || $personId === $loadedId) {
                $name = trim(implode(' ', array_filter([
                    is_scalar($person['given_name'] ?? null) ? (string) $person['given_name'] : null,
                    is_scalar($person['surname'] ?? null) ? (string) $person['surname'] : null,
                ])));

                if ($name === '') {
                    foreach (['name', 'full_name', 'label'] as $key) {
                        if (is_scalar($person[$key] ?? null) && trim((string) $person[$key]) !== '') {
                            $name = trim((string) $person[$key]);
                            break;
                        }
                    }
                }

                if ($name !== '') {
                    return $name;
                }
            }
        }

        return $personId !== null ? 'person reference present' : null;
    }

    /**
     * @param  array<string, mixed>  $claim
     * @param  array<string, mixed>  $raw
     */
    private function claimSourceRef(array $claim, array $raw): ?string
    {
        return $this->firstScalarText($claim, [
            'source_ref',
            'source_locator',
            'evidence_source',
            'citation',
            'source_id',
            'media_id',
        ]) ?: $this->firstScalarText($raw, [
            'source_ref',
            'source_locator',
            'evidence_source',
            'citation',
            'source_id',
            'media_id',
        ]);
    }

    /**
     * @param  array<int, mixed>  $sources
     * @param  array<int, mixed>  $sourceLocators
     * @return array{label: ?string, locator: ?string, access_class: ?string}|null
     */
    private function matchClaimContextSource(?string $sourceRef, array $sources, array $sourceLocators, int $idx): ?array
    {
        $ref = $this->normalizeSourceContextValue($sourceRef);
        if ($ref !== null) {
            foreach ($sources as $sourceIdx => $source) {
                if (! is_array($source)) {
                    continue;
                }

                foreach ($this->sourceContextCandidates($source) as $candidate) {
                    if ($this->sourceContextMatches($ref, $candidate)) {
                        return $this->sourceContextRow($source, $sourceIdx);
                    }
                }
            }

            foreach ($sourceLocators as $locatorIdx => $locator) {
                if (is_scalar($locator) && $this->sourceContextMatches($ref, (string) $locator)) {
                    $trimmed = trim((string) $locator);

                    return [
                        'label' => 'Source '.((int) $locatorIdx + 1),
                        'locator' => $trimmed,
                        'access_class' => null,
                    ];
                }
            }
        }

        if (count(array_filter($sources, 'is_array')) === 1) {
            foreach ($sources as $sourceIdx => $source) {
                if (is_array($source)) {
                    return $this->sourceContextRow($source, $sourceIdx);
                }
            }
        }

        if (isset($sources[$idx]) && is_array($sources[$idx])) {
            return $this->sourceContextRow($sources[$idx], $idx);
        }

        if (isset($sourceLocators[$idx]) && is_scalar($sourceLocators[$idx]) && trim((string) $sourceLocators[$idx]) !== '') {
            $locator = trim((string) $sourceLocators[$idx]);

            return [
                'label' => 'Source '.($idx + 1),
                'locator' => $locator,
                'access_class' => null,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array{label: ?string, locator: ?string, access_class: ?string}
     */
    private function sourceContextRow(array $source, int $sourceIdx): array
    {
        return [
            'label' => $this->sourceContextLabel($source, $sourceIdx),
            'locator' => $this->sourceContextLocator($source),
            'access_class' => $this->firstScalarText($source, [
                'source_access_class',
                'access_class',
                'provider_boundary_status',
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<string>
     */
    private function sourceContextCandidates(array $source): array
    {
        $candidates = [];
        foreach ([
            'id',
            'source_id',
            'locator',
            'source_locator',
            'url',
            'uri',
            'path',
            'citation',
            'title',
            'name',
            'label',
        ] as $key) {
            if (is_scalar($source[$key] ?? null) && trim((string) $source[$key]) !== '') {
                $candidates[] = trim((string) $source[$key]);
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function sourceContextLocator(array $source): ?string
    {
        return $this->firstScalarText($source, [
            'locator',
            'source_locator',
            'url',
            'uri',
            'path',
            'citation',
        ]);
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function sourceContextLabel(array $source, int $idx): string
    {
        foreach (['title', 'name', 'label'] as $key) {
            $label = $source[$key] ?? null;
            if (is_scalar($label) && trim((string) $label) !== '') {
                return $this->sourceLabelService()->safeLabel($label, 'Source '.($idx + 1)) ?? 'Source '.($idx + 1);
            }
        }

        return 'Source '.($idx + 1);
    }

    private function sourceLabelService(): GenealogyReviewPacketSourceLabelService
    {
        return app(GenealogyReviewPacketSourceLabelService::class);
    }

    private function sourceLocatorFromRef(?string $sourceRef): ?string
    {
        return $sourceRef !== null && preg_match('/^https?:\/\//i', $sourceRef) === 1
            ? $sourceRef
            : null;
    }

    private function normalizeSourceContextValue(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return strtolower(trim($value));
    }

    private function sourceContextMatches(string $ref, string $candidate): bool
    {
        $value = $this->normalizeSourceContextValue($candidate);
        if ($value === null) {
            return false;
        }
        if ($value === $ref) {
            return true;
        }
        if (strlen($value) < 3 || strlen($ref) < 3) {
            return false;
        }

        return str_contains($value, $ref) || str_contains($ref, $value);
    }

    /**
     * @param  array<string, mixed>  $claim
     * @param  array<int, array<string, mixed>>  $mediaById
     * @return list<array<string, mixed>>
     */
    private function claimMediaReferences(array $claim, array $mediaById): array
    {
        $refs = [];
        foreach ($this->mediaReferenceIdsFromPayload($claim) as $id) {
            if (isset($mediaById[$id])) {
                $refs[] = $mediaById[$id];
            }
        }

        return $refs;
    }

    /**
     * @return list<int>
     */
    private function mediaReferenceIdsFromPayload(mixed $value): array
    {
        $ids = [];
        $this->collectStructuredMediaReferenceIds($value, $ids);

        $haystack = [];
        $this->appendMediaTextLeaves($haystack, $value);
        $haystackText = implode(' ', $haystack);
        if ($haystackText !== ''
            && preg_match_all('/\b(?:media\s*#?|media_id|genealogy_media_id)\s*[:=#]?\s*(\d{1,9})\b/i', $haystackText, $m)
        ) {
            $this->appendMediaReferenceIds($ids, $m[1]);
        }

        return array_values(array_unique($ids));
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }

    /**
     * Display-only packet previews may be generated on the fly for older
     * context-ready packet rows that predate persisted apply_preview details.
     * The generated preview is never written back; approval still requires
     * the persisted preview guard in GenealogyReviewPacketDecisionService.
     *
     * @param  array<string, mixed>  $details
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function packetApplyPreviewContext(array $details): array
    {
        if (array_key_exists('apply_preview', $details)) {
            if (is_array($details['apply_preview'])) {
                return [
                    $details['apply_preview'],
                    [
                        'persisted' => true,
                        'generated' => false,
                        'source' => 'agent_review_queue.details.apply_preview',
                    ],
                ];
            }

            return [
                [],
                [
                    'persisted' => false,
                    'generated' => false,
                    'source' => 'agent_review_queue.details.apply_preview',
                    'warning' => 'persisted_apply_preview_not_array',
                ],
            ];
        }

        $preview = $this->reviewPacketApplyPreview->preview($details);
        $preview['generated_from_details'] = true;
        $preview['persisted'] = false;

        return [
            $preview,
            [
                'persisted' => false,
                'generated' => true,
                'source' => 'generated_from_packet_details',
                'writeback' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<mixed>
     */
    private function detailArray(array $details, string $key): array
    {
        $value = $details[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * Find every `media #N` reference in the proposal payload (evidence
     * summary, proposed_value, summary text) and look up the
     * genealogy_media rows. Returns an array of {id, title, file_format,
     * mime_type, media_type, nextcloud_path, view_url} for each unique
     * id found. The view_url goes through the existing
     * /api/media/file?path=... proxy that already serves Nextcloud files
     * with auth.
     *
     * @param  array<string, mixed>  $details
     * @return array<int, array{id: int, title: string, file_format: ?string, mime_type: ?string, media_type: ?string, nextcloud_path: ?string, file_exists: bool, view_url: ?string}>
     */
    private function resolveMediaReferences(array $details): array
    {
        $haystack = $this->mediaReferenceTextCandidates($details);
        $haystackText = implode(' ', $haystack);
        $ids = $this->structuredMediaReferenceIds($details);

        if ($haystackText !== ''
            && preg_match_all('/\b(?:media\s*#?|media_id|genealogy_media_id)\s*[:=#]?\s*(\d{1,9})\b/i', $haystackText, $m)
        ) {
            $this->appendMediaReferenceIds($ids, $m[1]);
        }

        if ($ids === []) {
            return [];
        }
        // Cap to avoid runaway IN-list on a malformed payload.
        $ids = array_slice($ids, 0, 25);

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = DB::select(
                "SELECT id, title, file_format, mime_type, media_type, nextcloud_path, file_exists
                 FROM genealogy_media
                 WHERE id IN ({$placeholders})",
                $ids
            );
        } catch (\Throwable $e) {
            return [];
        }

        $outById = [];
        foreach ($rows as $row) {
            $path = (string) ($row->nextcloud_path ?? '');
            $outById[(int) $row->id] = [
                'id' => (int) $row->id,
                'title' => trim((string) ($row->title ?? '')) !== ''
                    ? (string) $row->title
                    : 'Media item',
                'file_format' => $row->file_format,
                'mime_type' => $row->mime_type,
                'media_type' => $row->media_type,
                'nextcloud_path' => $path !== '' ? $path : null,
                'file_exists' => (bool) $row->file_exists,
                'view_url' => $path !== ''
                    ? '/api/media/file?path='.rawurlencode($path)
                    : null,
            ];
        }

        $out = [];
        foreach ($ids as $id) {
            if (isset($outById[$id])) {
                $out[] = $outById[$id];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<int, int>
     */
    private function structuredMediaReferenceIds(array $details): array
    {
        $ids = [];
        $this->collectStructuredMediaReferenceIds($details, $ids);

        return $ids;
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function collectStructuredMediaReferenceIds(mixed $value, array &$ids, int $depth = 0): void
    {
        if ($depth > 6 || ! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $normalizedKey = strtolower((string) $key);
            if (isset(self::STRUCTURED_MEDIA_ID_KEYS[$normalizedKey])) {
                $this->appendMediaReferenceId($ids, $child);

                continue;
            }

            if (isset(self::STRUCTURED_MEDIA_IDS_KEYS[$normalizedKey])) {
                $this->appendMediaReferenceIds($ids, $child);

                continue;
            }

            if (is_array($child)) {
                $this->collectStructuredMediaReferenceIds($child, $ids, $depth + 1);
            }
        }
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function appendMediaReferenceIds(array &$ids, mixed $values): void
    {
        if (is_array($values)) {
            foreach ($values as $value) {
                $this->appendMediaReferenceIds($ids, $value);
            }

            return;
        }

        $this->appendMediaReferenceId($ids, $values);
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function appendMediaReferenceId(array &$ids, mixed $value): void
    {
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && ctype_digit(trim($value))) {
            $id = (int) trim($value);
        } else {
            return;
        }

        if ($id <= 0 || $id > 999_999_999 || in_array($id, $ids, true)) {
            return;
        }

        $ids[] = $id;
    }

    /**
     * Collect text fields where an agent may have written `media #N`.
     * Review packets can carry those references inside claims or claim.raw
     * instead of proposals, so this remains deliberately display-only and
     * independent from proposal materialization.
     *
     * @param  array<string, mixed>  $details
     * @return array<int, string>
     */
    private function mediaReferenceTextCandidates(array $details): array
    {
        $haystack = [];

        foreach (['summary', 'source_locator', 'packet_label', 'packet_key'] as $key) {
            $this->appendMediaTextCandidate($haystack, $details[$key] ?? null);
        }

        $packet = $details['packet'] ?? null;
        if (is_array($packet)) {
            foreach (['summary', 'description', 'packet_label', 'packet_key'] as $key) {
                $this->appendMediaTextCandidate($haystack, $packet[$key] ?? null);
            }
        }

        $proposals = $details['proposals'] ?? null;
        if (is_array($proposals)) {
            foreach ($proposals as $proposal) {
                if (! is_array($proposal)) {
                    continue;
                }
                foreach (['evidence_summary', 'proposed_value', 'summary', 'claim', 'claim_text', 'statement'] as $key) {
                    $this->appendMediaTextCandidate($haystack, $proposal[$key] ?? null);
                }
                $this->appendMediaTextLeaves($haystack, $proposal['evidence_sources'] ?? null);
            }
        }

        $claims = $details['claims'] ?? null;
        if (is_array($claims)) {
            foreach ($claims as $claim) {
                if (! is_array($claim)) {
                    continue;
                }
                foreach ([
                    'claim',
                    'claim_text',
                    'statement',
                    'extracted_claim',
                    'extracted_text',
                    'text',
                    'source_ref',
                    'source_locator',
                    'evidence_summary',
                    'proposed_value',
                ] as $key) {
                    $this->appendMediaTextCandidate($haystack, $claim[$key] ?? null);
                }
                $this->appendMediaTextLeaves($haystack, $claim['raw'] ?? null);
            }
        }

        return $haystack;
    }

    /**
     * @param  array<int, string>  $haystack
     */
    private function appendMediaTextCandidate(array &$haystack, mixed $value): void
    {
        if (is_scalar($value) && trim((string) $value) !== '') {
            $haystack[] = trim((string) $value);
        }
    }

    /**
     * @param  array<int, string>  $haystack
     */
    private function appendMediaTextLeaves(array &$haystack, mixed $value, int $depth = 0): void
    {
        if ($depth > 4) {
            return;
        }
        if (is_scalar($value)) {
            $this->appendMediaTextCandidate($haystack, $value);

            return;
        }
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $child) {
            $this->appendMediaTextLeaves($haystack, $child, $depth + 1);
        }
    }

    /**
     * Phase 4: assemble both persons + side-by-side field diffs + impact
     * counts for a genealogy_merge review item.
     *
     * Falls back to a partial payload (with a `warning` key) when one of
     * the persons can't be loaded — better to surface "person 2520 not
     * found" than to silently render an empty pane.
     *
     * @param  array<string, mixed>  $details
     * @return array{
     *     persons: array<int, array<string, mixed>|null>,
     *     person_ids: array<int, int>,
     *     field_diffs: array<int, array<string, mixed>>,
     *     impact: array{sources: array<int, int>, families_as_spouse: array<int, int>, children: array<int, int>, events: array<int, int>},
     *     warning: string|null,
     * }|null
     */
    private function buildMergeContext(array $details): ?array
    {
        $personIds = $details['person_ids'] ?? null;
        if (! is_array($personIds) || count($personIds) !== 2) {
            return null;
        }
        $idA = (int) $personIds[0];
        $idB = (int) $personIds[1];
        $a = $this->personService->getPerson($idA);
        $b = $this->personService->getPerson($idB);

        $warning = null;
        if ($a === null || $b === null) {
            $missing = [];
            if ($a === null) {
                $missing[] = $idA;
            }
            if ($b === null) {
                $missing[] = $idB;
            }
            $warning = 'Could not load person(s): '.implode(', ', $missing);
        }

        return [
            'persons' => [$a, $b],
            'person_ids' => [$idA, $idB],
            'field_diffs' => $this->computeMergeFieldDiffs($a, $b),
            'impact' => [
                'sources' => [count($a['media'] ?? []), count($b['media'] ?? [])],
                'families_as_spouse' => [count($a['families_as_spouse'] ?? []), count($b['families_as_spouse'] ?? [])],
                'children' => [
                    $this->countChildrenAcrossFamilies($a),
                    $this->countChildrenAcrossFamilies($b),
                ],
                'events' => [count($a['events'] ?? []), count($b['events'] ?? [])],
            ],
            'warning' => $warning,
        ];
    }

    /**
     * Side-by-side field diff for a merge — same field on both persons,
     * classified same/different/conflict/etc. so the operator can see at
     * a glance which fields agree and which need a winner pick.
     *
     * @param  array<string, mixed>|null  $a
     * @param  array<string, mixed>|null  $b
     * @return array<int, array<string, mixed>>
     */
    private function computeMergeFieldDiffs(?array $a, ?array $b): array
    {
        if ($a === null || $b === null) {
            return [];
        }
        $fields = ['given_name', 'surname', 'sex', 'birth_date', 'birth_place', 'death_date', 'death_place', 'occupation'];
        $diffs = [];
        foreach ($fields as $f) {
            $valA = $a[$f] ?? null;
            $valB = $b[$f] ?? null;
            $status = $this->classifyDiff($f, $valA, $valB);
            $delta = $this->computeDelta($f, $valA, $valB);
            $diffs[] = [
                'change_type' => 'merge_field',
                'field' => $f,
                'on_file' => $valA,        // person A side
                'proposed' => $valB,       // person B side
                'match_status' => $status,
                'delta' => $delta,
                'confidence' => null,
                'evidence_summary' => null,
                'evidence_sources' => [],
            ];
        }

        return $diffs;
    }

    /**
     * @param  array<string, mixed>|null  $person
     */
    private function countChildrenAcrossFamilies(?array $person): int
    {
        if ($person === null) {
            return 0;
        }
        $families = $person['families_as_spouse'] ?? [];
        if (! is_array($families)) {
            return 0;
        }
        $sum = 0;
        foreach ($families as $f) {
            if (is_array($f) && isset($f['children']) && is_array($f['children'])) {
                $sum += count($f['children']);
            }
        }

        return $sum;
    }

    /**
     * Parse a {type}:{id} unified id. Mirrors ReviewTypeRegistryService
     * but kept local so this service has no upward dependency on the
     * registry.
     *
     * @return array{0: string, 1: string}|null
     */
    private function parseUnifiedId(string $unifiedId): ?array
    {
        if (! preg_match('/^([a-z_]+):([A-Za-z0-9_-]+)$/', $unifiedId, $m)) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    /**
     * Type-aware review-row loader. Each genealogy review type has a
     * different unified_id key shape (per review_type_registry's
     * field_mapping.unified_id_template):
     *
     *   genealogy_finding       →  agent_review_queue.token (hex string)
     *   genealogy_merge         →  agent_review_queue.token
     *   genealogy_review_packet →  agent_review_queue.token
     *   change_proposal         →  genealogy_proposed_changes.id (numeric)
     *   proposal                →  agent_review_queue.id (numeric)
     *
     * Pre-fix Phase 1 assumed `id` for everything which made the
     * detail pane 404 for every real prod genealogy_finding (since
     * those use token in unified_id). Also missed change_proposal
     * entirely (lives in a different table). Both gaps closed here.
     *
     * For agent_review_queue token lookups, falls back to numeric id
     * lookup so existing test fixtures that pass the numeric id keep
     * working. New code should use token.
     */
    private function loadReviewRow(string $type, string $id): ?object
    {
        try {
            if ($type === 'change_proposal') {
                return $this->loadChangeProposalRow($id);
            }
            if (isset(self::AGENT_QUEUE_TOKEN_LOOKUP_TYPES[$type])) {
                $row = DB::selectOne(
                    'SELECT id, agent_id, review_type, finding_type, title, summary, details,
                            confidence, priority, status, token, created_at, expires_at
                     FROM agent_review_queue
                     WHERE token = ? AND review_type = ?',
                    [$id, $type]
                );
                if ($row !== null) {
                    return $row;
                }
                // Fallback: numeric id (test fixtures, legacy callers).
                if (ctype_digit($id)) {
                    return DB::selectOne(
                        'SELECT id, agent_id, review_type, finding_type, title, summary, details,
                                confidence, priority, status, token, created_at, expires_at
                         FROM agent_review_queue
                         WHERE id = ? AND review_type = ?',
                        [(int) $id, $type]
                    );
                }

                return null;
            }

            // Other agent_review_queue types still use id.
            return DB::selectOne(
                'SELECT id, agent_id, review_type, finding_type, title, summary, details,
                        confidence, priority, status, created_at, expires_at
                 FROM agent_review_queue
                 WHERE id = ? AND review_type = ?',
                [$id, $type]
            );
        } catch (\Throwable $e) {
            Log::warning('ReviewContextEnrichmentService: review row lookup failed', [
                'unified_id' => "{$type}:{$id}",
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * change_proposal items live in genealogy_proposed_changes, which
     * has a different schema than agent_review_queue. Synthesize a
     * shape compatible with the rest of the enrichment flow so the
     * downstream comparison/classification/FAN logic just works
     * without per-type branches everywhere. Each row maps to ONE
     * synthesized proposal (these tables hold one change per row,
     * unlike agent_review_queue.details.proposals which can carry many).
     */
    private function loadChangeProposalRow(string $id): ?object
    {
        if (! ctype_digit($id)) {
            return null;
        }
        $row = DB::selectOne(
            'SELECT id, tree_id, person_id, change_type, field_name, current_value, proposed_value,
                    evidence_sources, evidence_summary, confidence, agent_id, status, created_at
             FROM genealogy_proposed_changes
             WHERE id = ?',
            [(int) $id]
        );
        if (! $row) {
            return null;
        }

        $evidenceSources = json_decode((string) ($row->evidence_sources ?? '[]'), true);
        if (! is_array($evidenceSources)) {
            $evidenceSources = [];
        }

        $titleParts = [(string) $row->change_type];
        if (! empty($row->field_name)) {
            $titleParts[] = (string) $row->field_name;
        }
        $titleParts[] = 'person reference present';

        $proposal = [
            'person_id' => (int) $row->person_id,
            'change_type' => (string) $row->change_type,
            'field_name' => $row->field_name,
            'proposed_value' => $row->proposed_value,
            'evidence_summary' => $row->evidence_summary,
            'evidence_sources' => $evidenceSources,
            'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
        ];

        return (object) [
            'id' => $row->id,
            'agent_id' => $row->agent_id,
            'review_type' => 'change_proposal',
            'finding_type' => null,
            'title' => implode(' · ', $titleParts),
            'summary' => $row->evidence_summary,
            'details' => json_encode([
                'person_id' => (int) $row->person_id,
                'tree_id' => (int) $row->tree_id,
                'on_file_value' => $row->current_value,
                'proposals' => [$proposal],
            ]),
            'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
            'priority' => null,
            'status' => $row->status,
            'created_at' => $row->created_at,
            'expires_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeDetails(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function extractPersonId(array $details): ?int
    {
        $candidate = $this->positiveInt($details['person_id'] ?? null);
        if ($candidate !== null) {
            return $candidate;
        }

        $identity = $details['identity'] ?? null;
        if (is_array($identity)) {
            foreach (['person_id', 'target_person_id'] as $key) {
                $candidate = $this->positiveInt($identity[$key] ?? null);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        $claims = $details['claims'] ?? null;
        if (is_array($claims)) {
            foreach ($claims as $claim) {
                if (! is_array($claim)) {
                    continue;
                }
                foreach (['person_id', 'target_person_id'] as $key) {
                    $candidate = $this->positiveInt($claim[$key] ?? null);
                    if ($candidate !== null) {
                        return $candidate;
                    }
                }
            }
        }

        $proposals = $details['proposals'] ?? null;
        if (is_array($proposals)) {
            foreach ($proposals as $proposal) {
                if (! is_array($proposal)) {
                    continue;
                }
                $candidate = $this->positiveInt($proposal['person_id'] ?? null);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        $personIds = $details['person_ids'] ?? null;
        if (is_array($personIds)) {
            foreach ($personIds as $candidate) {
                $personId = $this->positiveInt($candidate);
                if ($personId !== null) {
                    return $personId;
                }
            }
        }

        return null;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Compute per-field diff rows from the proposals array.
     *
     * Each proposal becomes one diff row. Three shapes today:
     *   - source_add → match_status "new" (additive, no on-file value)
     *   - field_name set + on-file value present → "match" / "different" / "conflict"
     *   - field_name set + on-file value empty → "new"
     *
     * Operator-found temporal-mismatch defect: the genealogy-records
     * agent searches by surname alone — Mary Billington (1652-1718)
     * received Civil War (1861+) pension proposals because "Billington"
     * matched. Each row now carries a `temporal_mismatch` block when
     * the evidence's year references fall outside the person's
     * lifetime ± 50yr margin. Display-time signal; apply-time backstop
     * lives in PersonService::runSourceAddBackstop.
     *
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>|null  $person
     * @return array<int, array<string, mixed>>
     */
    private function computeFieldDiffs(array $details, ?array $person): array
    {
        $proposals = $this->reviewProposalItems($details);
        if ($proposals === []) {
            return [];
        }

        $diffs = [];
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            $changeType = (string) ($proposal['change_type'] ?? '');
            $fieldName = $proposal['field_name'] ?? null;
            $proposed = $proposal['proposed_value'] ?? null;
            $confidence = isset($proposal['confidence']) ? (float) $proposal['confidence'] : null;
            $evidenceSummary = isset($proposal['evidence_summary']) ? (string) $proposal['evidence_summary'] : null;
            $evidenceSources = $proposal['evidence_sources'] ?? [];
            if (! is_array($evidenceSources)) {
                $evidenceSources = [];
            }

            $temporalMismatch = $this->detectTemporalMismatch($proposal, $person);

            // source_add — additive, no on-file diff
            if ($changeType === 'source_add') {
                $diffs[] = [
                    'change_type' => 'source_add',
                    'field' => 'sources',
                    'on_file' => null,
                    'on_file_count' => $person !== null ? count($person['media'] ?? []) : null,
                    'proposed' => $proposed,
                    'match_status' => $temporalMismatch !== null ? 'conflict' : 'new',
                    'confidence' => $confidence,
                    'evidence_summary' => $evidenceSummary,
                    'evidence_sources' => array_values(array_filter(array_map('strval', $evidenceSources))),
                    'temporal_mismatch' => $temporalMismatch,
                ];

                continue;
            }

            // Field-bearing proposal (birth_date, death_place, etc.)
            if (is_string($fieldName) && $fieldName !== '') {
                $onFile = $person[$fieldName] ?? null;
                $matchStatus = $this->classifyDiff($fieldName, $onFile, $proposed);
                $delta = $this->computeDelta($fieldName, $onFile, $proposed);

                $diffs[] = [
                    'change_type' => $changeType ?: 'update',
                    'field' => $fieldName,
                    'on_file' => $onFile,
                    'proposed' => $proposed,
                    'match_status' => $temporalMismatch !== null ? 'conflict' : $matchStatus,
                    'delta' => $delta,
                    'confidence' => $confidence,
                    'evidence_summary' => $evidenceSummary,
                    'evidence_sources' => array_values(array_filter(array_map('strval', $evidenceSources))),
                    'temporal_mismatch' => $temporalMismatch,
                ];

                continue;
            }

            // Catch-all: unknown proposal shape — surface as "other" so
            // the operator at least sees the proposed value rather than
            // having it silently dropped.
            $diffs[] = [
                'change_type' => $changeType ?: 'other',
                'field' => null,
                'on_file' => null,
                'proposed' => $proposed,
                'match_status' => 'unknown',
                'confidence' => $confidence,
                'evidence_summary' => $evidenceSummary,
                'evidence_sources' => array_values(array_filter(array_map('strval', $evidenceSources))),
            ];
        }

        return $diffs;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<int, array<string, mixed>>
     */
    private function reviewProposalItems(array $details): array
    {
        $proposals = $details['proposals'] ?? null;
        if (is_array($proposals)) {
            return array_values(array_filter($proposals, 'is_array'));
        }

        $claims = $details['claims'] ?? null;
        if (! is_array($claims)) {
            return [];
        }

        return $this->packetClaimsAsProposals($claims, $details);
    }

    /**
     * Project review-packet claims into the existing comparison/proposal shape
     * so packet details can render in the current Research Hub diff contract.
     *
     * @param  array<int, mixed>  $claims
     * @param  array<string, mixed>  $details
     * @return array<int, array<string, mixed>>
     */
    private function packetClaimsAsProposals(array $claims, array $details): array
    {
        $items = [];

        foreach ($claims as $claim) {
            if (! is_array($claim)) {
                continue;
            }

            $raw = $claim['raw'] ?? [];
            if (! is_array($raw)) {
                $raw = [];
            }

            $evidenceSources = [];
            foreach ([
                $claim['source_ref'] ?? null,
                $raw['source_ref'] ?? null,
                $raw['source_locator'] ?? null,
                $details['source_locator'] ?? null,
            ] as $source) {
                if (is_scalar($source) && trim((string) $source) !== '') {
                    $evidenceSources[] = trim((string) $source);
                }
            }
            $sourceLocators = $details['source_locators'] ?? [];
            if (is_array($sourceLocators)) {
                foreach ($sourceLocators as $source) {
                    if (is_scalar($source) && trim((string) $source) !== '') {
                        $evidenceSources[] = trim((string) $source);
                    }
                }
            }

            $items[] = [
                'change_type' => $this->firstScalarText($claim, ['change_type'])
                    ?: $this->firstScalarText($raw, ['change_type'])
                    ?: 'claim',
                'field_name' => $this->firstScalarText($claim, ['field_name'])
                    ?: $this->firstScalarText($raw, ['field_name']),
                'proposed_value' => $this->firstScalarText($claim, ['proposed_value', 'value'])
                    ?: $this->firstScalarText($raw, ['proposed_value', 'value', 'claim', 'claim_text', 'statement', 'extracted_claim'])
                    ?: $this->firstScalarText($claim, ['claim']),
                'confidence' => $claim['confidence'] ?? $raw['confidence'] ?? null,
                'evidence_summary' => $this->firstScalarText($claim, ['claim'])
                    ?: $this->firstScalarText($raw, ['evidence_summary', 'summary', 'claim', 'claim_text', 'statement', 'extracted_claim']),
                'evidence_sources' => array_values(array_unique($evidenceSources)),
                'person_id' => $this->positiveInt($claim['person_id'] ?? null)
                    ?? $this->positiveInt($claim['target_person_id'] ?? null),
            ];
        }

        return $items;
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
     * Detect when a proposal's evidence references years that fall
     * outside the person's lifetime by more than ±50 years.
     *
     * Reasonable margin:
     *   birth_year - 50  (parents' marriage records, ancestral context)
     *   death_year + 100 (estate, descendants' references citing ancestor)
     *
     * Returns null when:
     *   - person has no birth_date AND no death_date (can't compare)
     *   - evidence has no extractable years (can't fail)
     *   - all extractable years fall within the lifetime + margin
     *
     * Returns {worst_year, person_birth, person_death, gap_years,
     * matched_years, severity} when at least one year is clearly
     * outside the range. severity = 'far' (>100yr off either side) or
     * 'near' (50-100yr).
     *
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>|null  $person
     * @return array{worst_year:int, person_birth:?int, person_death:?int, gap_years:int, matched_years:array<int,int>, severity:string}|null
     */
    private function detectTemporalMismatch(array $proposal, ?array $person): ?array
    {
        if ($person === null) {
            return null;
        }
        $birth = $this->extractYear($person['birth_date'] ?? null);
        $death = $this->extractYear($person['death_date'] ?? null);
        if ($birth === null && $death === null) {
            return null;
        }
        // If we only have one anchor, assume a 100-year lifespan to bound the other.
        $rangeStart = $birth ?? ($death - 100);
        $rangeEnd = $death ?? ($birth + 100);
        // Margin: birth - 50 ≤ allowed ≤ death + 100
        $allowedMin = $rangeStart - 50;
        $allowedMax = $rangeEnd + 100;

        $haystack = trim(
            (string) ($proposal['evidence_summary'] ?? '').' '.
            (string) ($proposal['proposed_value'] ?? '')
        );
        if ($haystack === '') {
            return null;
        }

        // Extract 4-digit years 1500-2099 (genealogy-relevant range).
        if (! preg_match_all('/\b(1[5-9]\d{2}|20\d{2})\b/', $haystack, $m)) {
            return null;
        }
        $years = array_values(array_unique(array_map('intval', $m[1])));
        sort($years);

        // Any year in range = the source has at least some lifetime overlap.
        // Bail (no mismatch) so we don't false-positive on a Civil War source
        // that happens to mention "1730" in a footnote.
        $inRange = array_filter($years, fn (int $y) => $y >= $allowedMin && $y <= $allowedMax);
        if ($inRange !== []) {
            return null;
        }

        // No year in range — pick the year closest to the lifetime as the
        // "worst" and report the gap.
        // Gap is reported relative to the ACTUAL lifetime (birth or
        // death), not the +50/+100 allowed-range edge — operator
        // mental model is "146 years past death," not "46 years past
        // my arbitrary margin." The allowance only gates whether
        // we flag at all.
        $worst = $years[0];
        $gap = 0;
        foreach ($years as $y) {
            $thisGap = ($y < $rangeStart) ? ($rangeStart - $y) : ($y - $rangeEnd);
            if ($thisGap > $gap) {
                $worst = $y;
                $gap = $thisGap;
            }
        }
        // Severity: > 100 actual years off lifetime is "far" (e.g.,
        // Civil War source for someone who died in the 1700s); 50-100
        // is "near" (Revolutionary War for someone who died in the
        // 1720s); under 50 wouldn't have flagged in the first place
        // because of the allowed margin.
        $severity = $gap > 100 ? 'far' : 'near';

        return [
            'worst_year' => $worst,
            'person_birth' => $birth,
            'person_death' => $death,
            'gap_years' => $gap,
            'matched_years' => $years,
            'severity' => $severity,
        ];
    }

    /**
     * Pull the first 4-digit year from a genealogy date string.
     * Handles "1652", "30 SEP 1630", "abt 1700", "1700-1750", etc.
     */
    private function extractYear(mixed $dateStr): ?int
    {
        if (! is_scalar($dateStr)) {
            return null;
        }
        if (! preg_match('/\b(1[5-9]\d{2}|20\d{2})\b/', (string) $dateStr, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * Coarse match classifier: same | match | different | new | missing | conflict.
     *
     * Phase 1 keeps this deliberately simple — string-equality with case
     * normalization. Date fields trigger "conflict" instead of generic
     * "different" so the conflict bar can highlight them. Phase 2 will
     * grow this with name-variant matching (Soundex etc.) and place
     * proximity.
     */
    private function classifyDiff(string $field, mixed $onFile, mixed $proposed): string
    {
        $onFileEmpty = $onFile === null || $onFile === '' || $onFile === [];
        $proposedEmpty = $proposed === null || $proposed === '' || $proposed === [];

        if ($onFileEmpty && $proposedEmpty) {
            return 'same';
        }
        if ($onFileEmpty) {
            return 'new';
        }
        if ($proposedEmpty) {
            return 'missing';
        }

        $a = is_scalar($onFile) ? strtolower(trim((string) $onFile)) : '';
        $b = is_scalar($proposed) ? strtolower(trim((string) $proposed)) : '';
        if ($a === $b) {
            return 'same';
        }

        if (in_array($field, self::DATE_FIELDS, true)) {
            return 'conflict';
        }

        return 'different';
    }

    /**
     * For date fields, surface the year delta so the conflict bar can
     * say "Δ2yr" inline. Returns null for non-date fields or when
     * either value is unparseable.
     */
    private function computeDelta(string $field, mixed $onFile, mixed $proposed): ?string
    {
        if (! in_array($field, self::DATE_FIELDS, true)) {
            return null;
        }
        $onFileTs = is_scalar($onFile) ? strtotime((string) $onFile) : false;
        $proposedTs = is_scalar($proposed) ? strtotime((string) $proposed) : false;
        if ($onFileTs === false || $proposedTs === false) {
            return null;
        }
        $diffYears = (int) round(abs($onFileTs - $proposedTs) / (60 * 60 * 24 * 365.25));

        return $diffYears === 0
            ? null
            : "Δ{$diffYears}yr";
    }

    // ============================================================
    // Phase 2 — source classification + FAN overlap + agent reasoning
    // ============================================================

    /**
     * Classify each proposal's source against the Mills trio
     * (source_type / information_type / evidence_type) using the
     * heuristic table. One entry per proposal — index aligns with
     * field_diffs index so the frontend can join them visually.
     *
     * @param  array<string, mixed>  $details
     * @return array<int, array{
     *     proposal_index: int,
     *     source_type: string,
     *     information_type: string,
     *     evidence_type: string,
     *     label: string,
     *     matched_pattern: string|null,
     *     source_text: string,
     * }>
     */
    private function classifyProposalSources(array $details): array
    {
        $proposals = $details['proposals'] ?? null;
        if (! is_array($proposals)) {
            return [];
        }

        $out = [];
        foreach ($proposals as $idx => $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            $sourceText = $this->buildSourceClassificationText($proposal);

            // Follow-up 2: prefer agent-supplied source_classification when
            // the agent emitted a valid trio. Falls back to heuristic when
            // the agent didn't classify (or emitted garbage).
            $agentCls = $this->extractAgentSourceClassification($proposal);
            if ($agentCls !== null) {
                $out[] = [
                    'proposal_index' => $idx,
                    'source_type' => $agentCls['source_type'],
                    'information_type' => $agentCls['information_type'],
                    'evidence_type' => $agentCls['evidence_type'],
                    'label' => $agentCls['label'] ?? 'Agent-classified',
                    'matched_pattern' => null,          // n/a for agent-supplied
                    'source_text' => $sourceText,
                    'source' => 'agent',                // so the UI/future can
                    // distinguish agent-
                    // supplied from heuristic
                ];

                continue;
            }

            [$sourceType, $infoType, $evidenceType, $label, $matched] = $this->classifyText($sourceText);

            $out[] = [
                'proposal_index' => $idx,
                'source_type' => $sourceType,
                'information_type' => $infoType,
                'evidence_type' => $evidenceType,
                'label' => $label,
                'matched_pattern' => $matched,
                'source_text' => $sourceText,
                'source' => 'heuristic',
            ];
        }

        return $out;
    }

    /**
     * Pull a valid Mills trio out of an agent-emitted proposal, if present.
     * Expected shape: proposal.source_classification = { source_type,
     * information_type, evidence_type, label? }.
     *
     * @param  array<string, mixed>  $proposal
     * @return array{source_type: string, information_type: string, evidence_type: string, label?: string}|null
     */
    private function extractAgentSourceClassification(array $proposal): ?array
    {
        $cls = $proposal['source_classification'] ?? null;
        if (! is_array($cls)) {
            return null;
        }
        $src = (string) ($cls['source_type'] ?? '');
        $inf = (string) ($cls['information_type'] ?? '');
        $ev = (string) ($cls['evidence_type'] ?? '');
        $allowedSrc = ['original', 'derivative', 'authored', 'unknown'];
        $allowedInf = ['primary', 'secondary', 'undetermined', 'unknown'];
        $allowedEv = ['direct', 'indirect', 'negative', 'unknown'];
        if (! in_array($src, $allowedSrc, true) || ! in_array($inf, $allowedInf, true) || ! in_array($ev, $allowedEv, true)) {
            return null;
        }
        $out = [
            'source_type' => $src,
            'information_type' => $inf,
            'evidence_type' => $ev,
        ];
        if (isset($cls['label']) && is_string($cls['label']) && $cls['label'] !== '') {
            $out['label'] = $cls['label'];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function buildSourceClassificationText(array $proposal): string
    {
        $parts = [];
        if (isset($proposal['proposed_value']) && is_string($proposal['proposed_value'])) {
            $parts[] = $proposal['proposed_value'];
        }
        $sources = $proposal['evidence_sources'] ?? null;
        if (is_array($sources)) {
            foreach ($sources as $s) {
                if (is_string($s)) {
                    $parts[] = $s;
                }
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string|null}
     */
    private function classifyText(string $text): array
    {
        if ($text === '') {
            return ['unknown', 'unknown', 'unknown', 'Unknown source', null];
        }
        foreach (self::SOURCE_CLASSIFICATION_TABLE as $pattern => $tuple) {
            if (preg_match($pattern, $text)) {
                return [$tuple[0], $tuple[1], $tuple[2], $tuple[3], $pattern];
            }
        }

        return ['unknown', 'unknown', 'unknown', 'Unclassified', null];
    }

    /**
     * Compute name-based FAN-cluster overlap between names mentioned in
     * the evidence summaries and persons already in the same tree.
     *
     * Heuristic: extract title-cased multi-word tokens from the
     * evidence_summary, match against genealogy_persons by full-name
     * substring within the same tree. False positives are tolerable —
     * the operator will see the matches as "potential overlap" not
     * confirmed identity. Skipped entirely when no person dossier
     * is available (no tree context).
     *
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>|null  $person
     * @return array<int, array{
     *     name: string,
     *     matched_person_id: int,
     *     matched_person_name: string,
     *     mentioned_in: string,
     * }>
     */
    private function computeFanOverlap(array $details, ?array $person): array
    {
        if ($person === null || ! isset($person['tree_id'])) {
            return [];
        }
        $treeId = (int) $person['tree_id'];
        if ($treeId <= 0) {
            return [];
        }

        $proposals = $details['proposals'] ?? null;
        if (! is_array($proposals)) {
            return [];
        }

        // F-02 fix: enforce the 25-candidate cap inside both inner
        // loops (not just at the proposal boundary). Pre-fix a single
        // proposal carrying 50 fan_members produced 50 sequential
        // LIKE queries because the cap was only checked at end-of-
        // proposal. Cap unifies agent + heuristic contributions.
        $candidateNames = [];
        $candidateCap = 25;
        foreach ($proposals as $idx => $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            // Follow-up 2: prefer agent-supplied FAN list when present.
            // Shape: proposal.fan_members = [{name, role?}, ...]. Names
            // added here skip the heuristic regex extraction step, so
            // the agent's judgment wins over the regex.
            $agentFan = $proposal['fan_members'] ?? null;
            if (is_array($agentFan)) {
                foreach ($agentFan as $m) {
                    if (count($candidateNames) >= $candidateCap) {
                        break;
                    }
                    if (! is_array($m) || ! isset($m['name']) || ! is_string($m['name'])) {
                        continue;
                    }
                    $name = trim($m['name']);
                    if ($name === '' || $this->namesMatch($name, $person)) {
                        continue;
                    }
                    $key = strtolower($name);
                    if (! isset($candidateNames[$key])) {
                        $role = isset($m['role']) && is_string($m['role']) ? $m['role'] : 'mentioned';
                        $candidateNames[$key] = [
                            'name' => $name,
                            'mentioned_in' => "Agent-extracted ({$role})",
                        ];
                    }
                }
            }

            // Heuristic fallback from evidence_summary text.
            if (isset($proposal['evidence_summary'])) {
                $summary = (string) $proposal['evidence_summary'];
                foreach ($this->extractCandidateNames($summary) as $name) {
                    if (count($candidateNames) >= $candidateCap) {
                        break;
                    }
                    if ($this->namesMatch($name, $person)) {
                        continue;
                    }
                    $key = strtolower($name);
                    if (! isset($candidateNames[$key])) {
                        $candidateNames[$key] = ['name' => $name, 'mentioned_in' => $summary];
                    }
                }
            }

            if (count($candidateNames) >= $candidateCap) {
                break;
            }
        }
        if ($candidateNames === []) {
            return [];
        }

        $matches = [];
        try {
            foreach ($candidateNames as $cand) {
                // F-01 fix: escape LIKE metacharacters in the
                // agent/operator-controlled name before binding. The
                // value is parameterized (no SQLi) but `%` and `_` would
                // otherwise act as wildcards — an agent emitting
                // {"name":"%"} would match every tree person up to the
                // LIMIT. Escape backslash first so subsequent escapes
                // aren't double-escaped.
                $likeSafe = str_replace(
                    ['\\', '%', '_'],
                    ['\\\\', '\\%', '\\_'],
                    $cand['name']
                );
                $rows = DB::select(
                    "SELECT id, given_name, surname
                     FROM genealogy_persons
                     WHERE tree_id = ?
                       AND CONCAT_WS(' ', COALESCE(given_name,''), COALESCE(surname,'')) LIKE ?
                     LIMIT 3",
                    [$treeId, '%'.$likeSafe.'%']
                );
                foreach ($rows as $row) {
                    $personName = trim(($row->given_name ?? '').' '.($row->surname ?? ''));
                    if ($personName === '') {
                        continue;
                    }
                    $matches[] = [
                        'name' => $cand['name'],
                        'matched_person_id' => (int) $row->id,
                        'matched_person_name' => $personName,
                        'mentioned_in' => $cand['mentioned_in'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::debug('ReviewContextEnrichmentService: FAN overlap query failed', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return $matches;
    }

    /**
     * Pull title-cased multi-word tokens that look like personal names
     * out of free text. Skips dictionary-common surnames the agent is
     * unlikely to mean ("Library of Congress" → 0 hits, not 1).
     *
     * @return array<int, string>
     */
    private function extractCandidateNames(string $text): array
    {
        // Match Tite Cased pairs/triples: "Jacob Cochran", "Mary O'Brien"
        // Allow apostrophes inside surname.
        if (! preg_match_all(
            "/\b([A-Z][a-z]+(?:\s+(?:[A-Z][a-z]+|[A-Z][a-z']+|[A-Z]\.))+)\b/",
            $text,
            $m
        )) {
            return [];
        }
        $blocked = [
            'Library Of Congress', 'National Archives', 'Family Search',
            'Find A Grave', 'United States', 'New York', 'New Jersey',
            'United Kingdom', 'Family Tree', 'Census Bureau', 'World War',
        ];
        $blockedLc = array_map('strtolower', $blocked);
        $out = [];
        foreach ($m[1] as $match) {
            $clean = trim($match);
            if (in_array(strtolower($clean), $blockedLc, true)) {
                continue;
            }
            // Require at least 2 words and reasonable length
            if (substr_count($clean, ' ') < 1) {
                continue;
            }
            if (mb_strlen($clean) < 6 || mb_strlen($clean) > 60) {
                continue;
            }
            $out[$clean] = true; // dedupe via key
        }

        return array_keys($out);
    }

    /**
     * @param  array<string, mixed>  $person
     */
    private function namesMatch(string $candidate, array $person): bool
    {
        $personName = trim(
            ($person['given_name'] ?? '').' '.($person['surname'] ?? '')
        );
        if ($personName === '') {
            return false;
        }

        return strcasecmp(trim($candidate), $personName) === 0;
    }

    /**
     * Build the agent_reasoning panel payload — narrative + confidence
     * drivers + search coverage. Phase 2 derives drivers heuristically
     * from the proposal payload itself; agents will surface richer
     * signals over time and this method becomes a passthrough.
     *
     * Search coverage looks at recent agent_episodes for the same
     * agent_id, capped to 24h, so the operator sees what the agent
     * actually exercised this session even when proposals don't carry
     * the breadcrumb.
     *
     * @param  array<string, mixed>  $details
     * @return array{
     *     narrative: string|null,
     *     confidence_drivers: array<int, array{feature: string, weight: float, note: string}>,
     *     search_coverage: array{repositories_consulted: array<int, string>, episode_count: int, window_hours: int},
     * }
     */
    private function buildAgentReasoning(object $row, array $details): array
    {
        $narrative = $this->extractAgentNarrative($details);
        if ($narrative === null) {
            $summary = trim((string) ($row->summary ?? ''));
            $title = trim((string) ($row->title ?? ''));
            $narrative = $summary !== ''
                ? $summary
                : ($title !== '' ? "Review item submitted by {$row->agent_id}: {$title}." : null);
        }
        $drivers = $this->deriveConfidenceDrivers($details);

        // Follow-up 2: prefer agent-supplied search_coverage when it's
        // present on the payload. The agent knows exactly what it ran
        // this session; the episode query is a retrospective guess.
        $coverage = $this->extractAgentSearchCoverage($details)
            ?? $this->loadSearchCoverage((string) ($row->agent_id ?? ''));

        return [
            'narrative' => $narrative,
            'confidence_drivers' => $drivers,
            'search_coverage' => $coverage,
        ];
    }

    /**
     * Extract agent-supplied search coverage from details.search_coverage.
     * Expected shape: {repositories_consulted: [...], queries_run: [...],
     * gaps: [...], episode_count?: int, window_hours?: int}. Returns null
     * when not supplied so the caller can fall back to the DB scan.
     *
     * @param  array<string, mixed>  $details
     * @return array{repositories_consulted: array<int, string>, episode_count: int, window_hours: int, queries_run?: array<int, string>, gaps?: array<int, string>, source: string}|null
     */
    private function extractAgentSearchCoverage(array $details): ?array
    {
        $cov = $details['search_coverage'] ?? null;
        if (! is_array($cov)) {
            return null;
        }
        $repos = is_array($cov['repositories_consulted'] ?? null)
            ? array_values(array_filter($cov['repositories_consulted'], 'is_string'))
            : null;
        if ($repos === null) {
            return null;
        }
        sort($repos);
        $out = [
            'repositories_consulted' => $repos,
            'episode_count' => (int) ($cov['episode_count'] ?? 0),
            'window_hours' => (int) ($cov['window_hours'] ?? 24),
            'source' => 'agent',
        ];
        if (is_array($cov['queries_run'] ?? null)) {
            $out['queries_run'] = array_values(array_filter($cov['queries_run'], 'is_string'));
        }
        if (is_array($cov['gaps'] ?? null)) {
            $out['gaps'] = array_values(array_filter($cov['gaps'], 'is_string'));
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function extractAgentNarrative(array $details): ?string
    {
        // Prefer an explicit reasoning field if the agent provided one.
        foreach (['reasoning', 'agent_reasoning', 'analysis_notes', 'outcome_reason', 'scope_reason'] as $key) {
            if (isset($details[$key]) && is_string($details[$key]) && $details[$key] !== '') {
                return $details[$key];
            }
        }

        $proposals = $details['proposals'] ?? null;
        if (is_array($proposals)) {
            foreach ($proposals as $proposal) {
                if (! is_array($proposal)) {
                    continue;
                }
                $summary = trim((string) ($proposal['evidence_summary'] ?? ''));
                if ($summary !== '') {
                    return $summary;
                }
            }
        }

        // Fallback: synthesize from filtered/raw proposal counts so the
        // operator at least sees what the agent did vs filtered out.
        $raw = isset($details['raw_proposal_count']) ? (int) $details['raw_proposal_count'] : null;
        $filtered = isset($details['filtered_out_count']) ? (int) $details['filtered_out_count'] : null;
        if ($raw === null && $filtered === null) {
            return null;
        }
        $kept = $raw !== null && $filtered !== null ? max(0, $raw - $filtered) : null;
        $parts = [];
        if ($raw !== null) {
            $parts[] = "{$raw} candidate(s) returned by upstream search";
        }
        if ($filtered !== null) {
            $parts[] = "{$filtered} filtered out by relevance/proximity gates";
        }
        if ($kept !== null) {
            $parts[] = "{$kept} surfaced for review";
        }

        return $parts === [] ? null : implode('; ', $parts).'.';
    }

    /**
     * Coarse confidence-driver derivation. Phase 2 emits these from the
     * proposal payload alone; Phase 3+ can replace with agent-supplied
     * features without breaking the response shape.
     *
     * @param  array<string, mixed>  $details
     * @return array<int, array{feature: string, weight: float, note: string}>
     */
    private function deriveConfidenceDrivers(array $details): array
    {
        $proposals = $details['proposals'] ?? null;
        if (! is_array($proposals) || $proposals === []) {
            return [];
        }
        $sourceCounts = [];
        $maxConfidence = 0.0;
        $changeTypes = [];
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            $maxConfidence = max($maxConfidence, (float) ($proposal['confidence'] ?? 0));
            foreach ((array) ($proposal['evidence_sources'] ?? []) as $s) {
                if (is_string($s)) {
                    $sourceCounts[$s] = ($sourceCounts[$s] ?? 0) + 1;
                }
            }
            $changeTypes[(string) ($proposal['change_type'] ?? 'unknown')] = true;
        }
        $drivers = [];
        if ($sourceCounts !== []) {
            $top = array_key_first($sourceCounts);
            $drivers[] = [
                'feature' => 'source_diversity',
                'weight' => min(1.0, count($sourceCounts) / 5.0),
                'note' => count($sourceCounts).' distinct source(s); top: '.$top,
            ];
        }
        if ($maxConfidence > 0) {
            $drivers[] = [
                'feature' => 'top_proposal_confidence',
                'weight' => $maxConfidence,
                'note' => 'highest individual proposal confidence',
            ];
        }
        if (count($changeTypes) > 1) {
            $drivers[] = [
                'feature' => 'change_type_breadth',
                'weight' => min(1.0, count($changeTypes) / 4.0),
                'note' => 'proposals span '.count($changeTypes).' change type(s)',
            ];
        }

        return $drivers;
    }

    /**
     * Pull recent agent episode coverage for context. Returns counts +
     * a deduped tool/repository list so the panel can show "this agent
     * touched [Library of Congress, NARA, FamilySearch] in the last
     * 24h." Falls back to empty on any query failure.
     *
     * @return array{repositories_consulted: array<int, string>, episode_count: int, window_hours: int}
     */
    private function loadSearchCoverage(string $agentId): array
    {
        $window = 24;
        $coverage = [
            'repositories_consulted' => [],
            'episode_count' => 0,
            'window_hours' => $window,
        ];
        if ($agentId === '') {
            return $coverage;
        }
        try {
            $rows = DB::select(
                'SELECT details FROM agent_episodes
                 WHERE agent_id = ?
                   AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                 ORDER BY created_at DESC
                 LIMIT 50',
                [$agentId, $window]
            );
        } catch (\Throwable $e) {
            return $coverage;
        }

        $repos = [];
        foreach ($rows as $row) {
            $details = json_decode((string) ($row->details ?? ''), true);
            if (! is_array($details)) {
                continue;
            }
            // Look for tool/repo signals in common shapes.
            foreach (['tools_used', 'repositories', 'sources', 'tool_names'] as $key) {
                $val = $details[$key] ?? null;
                if (is_array($val)) {
                    foreach ($val as $v) {
                        if (is_string($v)) {
                            $repos[$v] = true;
                        }
                    }
                }
            }
        }
        $coverage['episode_count'] = count($rows);
        $coverage['repositories_consulted'] = array_keys($repos);
        sort($coverage['repositories_consulted']);

        return $coverage;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{total: int, by_change_type: array<string, int>}
     */
    private function summarizeProposals(array $details): array
    {
        $proposals = $this->reviewProposalItems($details);
        if ($proposals === []) {
            return ['total' => 0, 'by_change_type' => []];
        }
        $byType = [];
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            $type = (string) ($proposal['change_type'] ?? 'unknown');
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        return ['total' => count($proposals), 'by_change_type' => $byType];
    }
}
