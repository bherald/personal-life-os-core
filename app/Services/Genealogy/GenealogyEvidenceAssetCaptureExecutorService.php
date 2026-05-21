<?php

namespace App\Services\Genealogy;

use App\Services\Review\ReviewEvidenceAssetCandidateService;
use App\Services\Review\ReviewTargetReferenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GenealogyEvidenceAssetCaptureExecutorService
{
    public function __construct(
        private readonly ReviewTargetReferenceService $targetReferences,
        private readonly ReviewEvidenceAssetCandidateService $candidateService,
        private readonly GenealogyEvidenceAssetCaptureStorageService $storageService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(
        int $limit = 25,
        bool $savePreflight = false,
        bool $confirmed = false,
        bool $compact = false,
        bool $executeCapture = false,
        bool $downloadConfirmed = false,
        bool $storageConfirmed = false,
        bool $linkConfirmed = false,
        ?int $maxBytes = null,
        ?int $treeId = null,
    ): array {
        $limit = max(1, min($limit, 100));
        $payload = $this->basePayload(
            $limit,
            $savePreflight,
            $confirmed,
            $compact,
            $executeCapture,
            $downloadConfirmed,
            $storageConfirmed,
            $linkConfirmed,
            $maxBytes,
            $treeId,
        );

        if ($savePreflight && $executeCapture) {
            $payload['status'] = 'blocked';
            $payload['blockers'][] = 'choose_preflight_or_capture_mode';
            $payload['next_action'] = 'Run --save-preflight and --execute-capture as separate operator steps.';

            return $payload;
        }

        if ($savePreflight && ! $confirmed) {
            $payload['status'] = 'blocked';
            $payload['blockers'][] = 'noncanonical_write_confirmation_required';
            $payload['next_action'] = 'Re-run with --save-preflight --confirm-noncanonical-write to stamp approved review rows only.';

            return $payload;
        }

        if ($executeCapture) {
            $captureBlockers = $this->captureExecutionBlockers($downloadConfirmed, $storageConfirmed);
            if ($captureBlockers !== []) {
                $payload['status'] = 'blocked';
                $payload['blockers'] = array_values(array_unique(array_merge($payload['blockers'], $captureBlockers)));
                $payload['next_action'] = 'Re-run with --execute-capture --confirm-download --confirm-storage-write after confirming provider/storage policy.';

                return $payload;
            }
        }

        if (! Schema::hasTable('agent_review_queue')) {
            $payload['status'] = 'observe_unavailable';
            $payload['summary']['unavailable_reason'] = 'agent_review_queue_missing';

            return $payload;
        }

        $payload['summary']['pending_capture_reviews'] = $this->captureReviewCount('pending');
        $payload['summary']['approved_capture_reviews'] = $this->captureReviewCount('approved');

        $rows = DB::table('agent_review_queue')
            ->select(['id', 'token', 'review_type', 'finding_type', 'title', 'summary', 'details', 'priority', 'status', 'created_at', 'reviewed_at'])
            ->where('review_type', GenealogyEvidenceAssetCaptureReviewService::REVIEW_TYPE)
            ->where('status', 'approved')
            ->orderBy('reviewed_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            if ($treeId !== null && ! $this->captureReviewRowBelongsToTree($row, $treeId)) {
                $payload['summary']['tree_filtered_rows']++;

                continue;
            }

            $payload['summary']['inspected_approved_rows']++;
            $preflight = $this->preflightRow($row);
            $this->mergePreflightSummary($payload, $preflight);

            if (($preflight['ready_for_future_executor'] ?? false) === true) {
                $payload['summary']['ready_approved_rows']++;
            }

            if ($savePreflight && ($preflight['ready_for_future_executor'] ?? false) === true) {
                $this->stampPreflight($row, $preflight);
                $payload['summary']['preflight_rows_stamped']++;
            }

            if ($executeCapture && ($preflight['ready_for_future_executor'] ?? false) === true) {
                $execution = $this->executeCaptureRow($row, $linkConfirmed, $maxBytes);
                $this->mergeExecutionSummary($payload, $execution);
                $this->stampExecution($row, $execution, $linkConfirmed);
                $payload['summary']['capture_rows_executed']++;
                if (! $compact) {
                    $payload['rows'][] = $this->redactExecution($execution);
                }
            } elseif (! $compact) {
                $payload['rows'][] = $this->redactPreflight($preflight);
            }
        }

        if ($payload['summary']['approved_capture_reviews'] === 0 && $payload['summary']['pending_capture_reviews'] > 0) {
            $payload['status'] = 'awaiting_operator_approval';
            $payload['blockers'][] = 'operator_approval_required';
            $payload['next_action'] = 'Approve or reject pending genealogy_evidence_asset_capture Review Hub rows before capture execution.';
        } elseif ($payload['summary']['approved_capture_reviews'] === 0) {
            $payload['status'] = 'observe_empty';
            $payload['next_action'] = 'Materialize and approve genealogy evidence asset capture reviews before execution.';
        } elseif ($payload['summary']['ready_approved_rows'] === 0) {
            $payload['status'] = 'blocked';
            if (($payload['summary']['line_item_missing_decisions'] ?? 0) > 0) {
                $payload['blockers'][] = 'line_item_decisions_missing';
                $payload['next_action'] = 'Re-review approved capture rows so every media candidate has an explicit attach/reject/needs-research/ignore decision.';
            } else {
                $payload['blockers'][] = 'approved_capture_rows_not_rehydratable';
                $payload['next_action'] = 'Resolve source packet or capture-plan mismatches before enabling the future capture adapter.';
            }
        } elseif ($executeCapture) {
            if ($payload['summary']['capture_rows_executed'] === 0) {
                $payload['status'] = 'blocked';
                $payload['blockers'][] = 'no_capture_rows_executed';
                $payload['next_action'] = 'Resolve approved row preflight blockers before running capture execution again.';
            } elseif ($payload['summary']['capture_failures'] > 0) {
                $payload['status'] = 'capture_partial';
                $payload['next_action'] = 'Review executor_capture_result on approved rows, fix blockers, then rerun capture for remaining media.';
            } else {
                $payload['status'] = 'capture_executed';
                $payload['next_action'] = 'Run media enrichment/transcription intake reports and review any generated person/family/source links.';
            }
        } elseif ($savePreflight) {
            $payload['status'] = 'preflight_saved';
        } else {
            $payload['status'] = 'preflight_ready';
            $payload['next_action'] = 'Run --execute-capture with explicit download/storage confirmations to capture approved evidence media.';
        }

        $payload['blockers'] = array_values(array_unique($payload['blockers']));

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function compactPayload(array $payload): array
    {
        unset($payload['rows']);
        $payload['compact'] = true;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toText(array $payload): string
    {
        $summary = $payload['summary'] ?? [];

        return sprintf(
            'Evidence asset capture executor: %s pending=%s approved=%s inspected=%s ready=%s plans=%s attach_plans=%s rehydrated=%s captured=%s media_created=%s downloads_enabled=%s storage_writes_enabled=%s canonical_writes_enabled=false',
            $payload['status'] ?? 'unknown',
            $summary['pending_capture_reviews'] ?? 0,
            $summary['approved_capture_reviews'] ?? 0,
            $summary['inspected_approved_rows'] ?? 0,
            $summary['ready_approved_rows'] ?? 0,
            $summary['capture_plan_count'] ?? 0,
            $summary['line_item_attach_plans'] ?? 0,
            $summary['rehydrated_plan_count'] ?? 0,
            $summary['files_saved'] ?? 0,
            $summary['media_rows_created'] ?? 0,
            ($payload['posture']['downloads_enabled'] ?? false) ? 'true' : 'false',
            ($payload['posture']['storage_writes_enabled'] ?? false) ? 'true' : 'false',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toMarkdown(array $payload): string
    {
        $summary = $payload['summary'] ?? [];

        return implode("\n", [
            '# Genealogy Evidence Asset Capture Executor',
            '',
            '- Status: `'.($payload['status'] ?? 'unknown').'`',
            '- Mode: `'.($payload['mode'] ?? 'preflight').'`',
            '- Pending capture reviews: `'.($summary['pending_capture_reviews'] ?? 0).'`',
            '- Approved capture reviews: `'.($summary['approved_capture_reviews'] ?? 0).'`',
            '- Inspected approved rows: `'.($summary['inspected_approved_rows'] ?? 0).'`',
            '- Ready approved rows: `'.($summary['ready_approved_rows'] ?? 0).'`',
            '- Capture plans: `'.($summary['capture_plan_count'] ?? 0).'`',
            '- Operator-selected attach plans: `'.($summary['line_item_attach_plans'] ?? 0).'`',
            '- Operator-held plans: `'.($summary['line_item_non_attach_plans'] ?? 0).'`',
            '- Rehydrated plans: `'.($summary['rehydrated_plan_count'] ?? 0).'`',
            '- Files saved: `'.($summary['files_saved'] ?? 0).'`',
            '- Media rows created: `'.($summary['media_rows_created'] ?? 0).'`',
            '- Person links created: `'.($summary['person_links_created'] ?? 0).'`',
            '- Family links created: `'.($summary['family_links_created'] ?? 0).'`',
            '- Source citation links created: `'.($summary['citation_links_created'] ?? 0).'`',
            '- Downloads enabled: `'.(($payload['posture']['downloads_enabled'] ?? false) ? 'true' : 'false').'`',
            '- Storage writes enabled: `'.(($payload['posture']['storage_writes_enabled'] ?? false) ? 'true' : 'false').'`',
            '- Genealogy links enabled: `'.(($payload['posture']['genealogy_links_enabled'] ?? false) ? 'true' : 'false').'`',
            '- Canonical writes enabled: `false`',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(
        int $limit,
        bool $savePreflight,
        bool $confirmed,
        bool $compact,
        bool $executeCapture,
        bool $downloadConfirmed,
        bool $storageConfirmed,
        bool $linkConfirmed,
        ?int $maxBytes,
        ?int $treeId,
    ): array {
        $downloadsEnabled = $executeCapture
            && $downloadConfirmed
            && (bool) config('genealogy.evidence_asset_capture.downloads_enabled', true);
        $storageWritesEnabled = $executeCapture
            && $storageConfirmed
            && (bool) config('genealogy.evidence_asset_capture.storage_writes_enabled', true);
        $genealogyLinksEnabled = $executeCapture
            && $linkConfirmed
            && (bool) config('genealogy.evidence_asset_capture.genealogy_links_enabled', true);

        return [
            'version' => 1,
            'command' => 'genealogy:evidence-asset-capture-executor',
            'mode' => $executeCapture ? 'execute_capture' : ($savePreflight ? 'save_preflight' : 'preflight'),
            'save_preflight' => $savePreflight,
            'execute_capture' => $executeCapture,
            'confirm_noncanonical_write' => $confirmed,
            'confirm_download' => $downloadConfirmed,
            'confirm_storage_write' => $storageConfirmed,
            'confirm_genealogy_link' => $linkConfirmed,
            'compact_requested' => $compact,
            'max_bytes' => $maxBytes ?? (int) config('genealogy.evidence_asset_capture.max_bytes', 26214400),
            'read_only' => ! (($savePreflight && $confirmed) || ($executeCapture && $downloadsEnabled && $storageWritesEnabled)),
            'mutation_allowed' => ($savePreflight && $confirmed) || ($executeCapture && $downloadsEnabled && $storageWritesEnabled),
            'canonical_write_allowed' => false,
            'noncanonical_write_allowed' => ($savePreflight && $confirmed) || ($executeCapture && $downloadsEnabled && $storageWritesEnabled),
            'download_attempted' => false,
            'storage_write_attempted' => false,
            'review_decision_attempted' => false,
            'genealogy_link_attempted' => false,
            'capture_execution_enabled' => $executeCapture && $downloadsEnabled && $storageWritesEnabled,
            'captured_at' => now()->toIso8601String(),
            'tree_id' => $treeId,
            'limit' => $limit,
            'status' => $executeCapture ? 'execute_capture' : 'preflight',
            'blockers' => [],
            'summary' => [
                'pending_capture_reviews' => 0,
                'approved_capture_reviews' => 0,
                'tree_filtered_rows' => 0,
                'inspected_approved_rows' => 0,
                'ready_approved_rows' => 0,
                'source_rows_resolved' => 0,
                'source_rows_unresolved' => 0,
                'capture_plan_count' => 0,
                'review_plan_count' => 0,
                'line_item_attach_plans' => 0,
                'line_item_non_attach_plans' => 0,
                'line_item_missing_decisions' => 0,
                'rehydrated_plan_count' => 0,
                'unresolved_plan_count' => 0,
                'direct_download_plans' => 0,
                'html_snapshot_plans' => 0,
                'local_reference_plans' => 0,
                'preflight_rows_stamped' => 0,
                'capture_rows_executed' => 0,
                'download_attempts' => 0,
                'storage_write_attempts' => 0,
                'files_saved' => 0,
                'media_rows_created' => 0,
                'media_rows_reused' => 0,
                'person_links_created' => 0,
                'family_links_created' => 0,
                'citation_links_created' => 0,
                'link_skipped_confirmation_required' => 0,
                'capture_failures' => 0,
            ],
            'posture' => [
                'row_identifiers_included' => false,
                'tokens_included' => false,
                'raw_details_included' => false,
                'raw_locators_included' => false,
                'downloads_enabled' => $downloadsEnabled,
                'storage_writes_enabled' => $storageWritesEnabled,
                'review_decisions_enabled' => false,
                'genealogy_links_enabled' => $genealogyLinksEnabled,
                'canonical_writes_enabled' => false,
                'noncanonical_preflight_stamp_enabled' => $savePreflight && $confirmed,
                'capture_review_stamp_enabled' => $executeCapture && $downloadsEnabled && $storageWritesEnabled,
            ],
            'rows' => [],
        ];
    }

    private function captureReviewCount(string $status): int
    {
        return (int) DB::table('agent_review_queue')
            ->where('review_type', GenealogyEvidenceAssetCaptureReviewService::REVIEW_TYPE)
            ->where('status', $status)
            ->count();
    }

    /**
     * @return list<string>
     */
    private function captureExecutionBlockers(bool $downloadConfirmed, bool $storageConfirmed): array
    {
        $blockers = [];

        if (! $downloadConfirmed) {
            $blockers[] = 'download_confirmation_required';
        }
        if (! $storageConfirmed) {
            $blockers[] = 'storage_write_confirmation_required';
        }
        if (! (bool) config('genealogy.evidence_asset_capture.downloads_enabled', true)) {
            $blockers[] = 'downloads_disabled_by_config';
        }
        if (! (bool) config('genealogy.evidence_asset_capture.storage_writes_enabled', true)) {
            $blockers[] = 'storage_writes_disabled_by_config';
        }

        return $blockers;
    }

    /**
     * @return array<string, mixed>
     */
    private function executeCaptureRow(object $row, bool $linkConfirmed, ?int $maxBytes): array
    {
        $details = $this->decodeDetails($row->details ?? null);
        $details = $this->detailsWithAttachApprovedPlans($details);
        $targetRef = is_scalar($details['source_target_ref'] ?? null) ? (string) $details['source_target_ref'] : null;
        $sourceRow = $targetRef !== null
            ? $this->targetReferences->pendingReviewRowForTargetRef($targetRef, ['genealogy_review_packet'])
            : null;
        $sourceDetails = $sourceRow !== null ? $this->decodeDetails($sourceRow->details ?? null) : [];

        return $this->storageService->captureApprovedReview($row, $details, $sourceDetails, [
            'link_confirmed' => $linkConfirmed && (bool) config('genealogy.evidence_asset_capture.genealogy_links_enabled', true),
            'max_bytes' => $maxBytes ?? (int) config('genealogy.evidence_asset_capture.max_bytes', 26214400),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $execution
     */
    private function mergeExecutionSummary(array &$payload, array $execution): void
    {
        $summary = is_array($execution['summary'] ?? null) ? $execution['summary'] : [];
        foreach ([
            'download_attempts',
            'storage_write_attempts',
            'files_saved',
            'media_rows_created',
            'media_rows_reused',
            'person_links_created',
            'family_links_created',
            'citation_links_created',
            'link_skipped_confirmation_required',
        ] as $key) {
            $payload['summary'][$key] += (int) ($summary[$key] ?? 0);
        }

        $payload['summary']['capture_failures'] += (int) ($summary['failures'] ?? 0);
        $payload['download_attempted'] = $payload['download_attempted'] || (bool) ($execution['download_attempted'] ?? false);
        $payload['storage_write_attempted'] = $payload['storage_write_attempted'] || (bool) ($execution['storage_write_attempted'] ?? false);
        $payload['genealogy_link_attempted'] = $payload['genealogy_link_attempted'] || (bool) ($execution['genealogy_link_attempted'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $execution
     */
    private function stampExecution(object $row, array $execution, bool $linkConfirmed): void
    {
        $details = $this->decodeDetails($row->details ?? null);
        $details['executor_capture_result'] = array_merge($this->redactExecution($execution), [
            'stamped_at' => now()->toIso8601String(),
        ]);
        $details['execution_posture'] = array_merge(
            is_array($details['execution_posture'] ?? null) ? $details['execution_posture'] : [],
            [
                'download_attempted' => (bool) ($execution['download_attempted'] ?? false),
                'storage_write_attempted' => (bool) ($execution['storage_write_attempted'] ?? false),
                'genealogy_link_attempted' => (bool) ($execution['genealogy_link_attempted'] ?? false),
                'genealogy_link_confirmed' => $linkConfirmed,
                'canonical_write_allowed' => false,
            ],
        );

        DB::table('agent_review_queue')
            ->where('id', $row->id)
            ->where('review_type', GenealogyEvidenceAssetCaptureReviewService::REVIEW_TYPE)
            ->where('status', 'approved')
            ->update([
                'details' => json_encode($details, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function preflightRow(object $row): array
    {
        $details = $this->decodeDetails($row->details ?? null);
        $planFilter = $this->attachApprovedPlanFilter($details);
        $plans = $planFilter['plans'];
        $targetRef = is_scalar($details['source_target_ref'] ?? null) ? (string) $details['source_target_ref'] : null;
        $sourceRow = $targetRef !== null
            ? $this->targetReferences->pendingReviewRowForTargetRef($targetRef, ['genealogy_review_packet'])
            : null;
        $sourceDetails = $sourceRow !== null ? $this->decodeDetails($sourceRow->details ?? null) : [];
        $candidateHashes = $sourceDetails !== []
            ? $this->candidateHashes($this->candidateService->fromDetails($sourceDetails))
            : [];

        $rehydrated = 0;
        $unresolved = 0;
        $policyCounts = [
            'direct_download_allowed' => 0,
            'html_snapshot_allowed' => 0,
            'already_local_reference' => 0,
        ];

        foreach ($plans as $plan) {
            if (! is_array($plan)) {
                $unresolved++;

                continue;
            }

            $policy = is_scalar($plan['capture_policy'] ?? null) ? (string) $plan['capture_policy'] : 'unknown';
            if (array_key_exists($policy, $policyCounts)) {
                $policyCounts[$policy]++;
            }

            $hash = is_scalar($plan['locator_hash'] ?? null) ? strtolower((string) $plan['locator_hash']) : '';
            if ($this->hasCandidateHash($candidateHashes, $hash)) {
                $rehydrated++;
            } else {
                $unresolved++;
            }
        }

        return [
            'schema' => 'genealogy_evidence_asset_capture_executor_preflight.v1',
            'review_type' => GenealogyEvidenceAssetCaptureReviewService::REVIEW_TYPE,
            'approved_for_executor' => ($details['approved_for_executor'] ?? false) === true,
            'source_target_ref_present' => $targetRef !== null,
            'source_row_resolved' => $sourceRow !== null,
            'review_plan_count' => $planFilter['review_plan_count'],
            'capture_plan_count' => count($plans),
            'line_item_decisions_applied' => $planFilter['line_item_decisions_applied'],
            'line_item_missing_decisions' => $planFilter['line_item_missing_decisions'],
            'line_item_attach_plan_count' => $planFilter['line_item_attach_plan_count'],
            'line_item_non_attach_plan_count' => $planFilter['line_item_non_attach_plan_count'],
            'rehydrated_plan_count' => $rehydrated,
            'unresolved_plan_count' => $unresolved,
            'direct_download_plans' => $policyCounts['direct_download_allowed'],
            'html_snapshot_plans' => $policyCounts['html_snapshot_allowed'],
            'local_reference_plans' => $policyCounts['already_local_reference'],
            'ready_for_future_executor' => ($details['approved_for_executor'] ?? false) === true
                && $sourceRow !== null
                && $planFilter['line_item_missing_decisions'] === false
                && count($plans) > 0
                && $unresolved === 0,
            'download_attempted' => false,
            'storage_write_attempted' => false,
            'genealogy_link_attempted' => false,
            'canonical_write_attempted' => false,
        ];
    }

    private function captureReviewRowBelongsToTree(object $row, int $treeId): bool
    {
        $details = $this->decodeDetails($row->details ?? null);

        return is_numeric($details['tree_id'] ?? null) && (int) $details['tree_id'] === $treeId;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, true>
     */
    private function candidateHashes(array $candidates): array
    {
        $hashes = [];
        foreach ($candidates as $candidate) {
            $hash = is_scalar($candidate['locator_hash'] ?? null) ? strtolower((string) $candidate['locator_hash']) : '';
            if ($hash !== '') {
                $hashes[$hash] = true;
            }
        }

        return $hashes;
    }

    /**
     * @param  array<string, true>  $candidateHashes
     */
    private function hasCandidateHash(array $candidateHashes, string $planHash): bool
    {
        $planHash = strtolower(trim($planHash));
        if (preg_match('/^[a-f0-9]{8,40}$/', $planHash) !== 1) {
            return false;
        }

        foreach (array_keys($candidateHashes) as $candidateHash) {
            if ($candidateHash === $planHash) {
                return true;
            }

            if (str_starts_with($candidateHash, $planHash) || str_starts_with($planHash, $candidateHash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $preflight
     */
    private function mergePreflightSummary(array &$payload, array $preflight): void
    {
        if (($preflight['source_row_resolved'] ?? false) === true) {
            $payload['summary']['source_rows_resolved']++;
        } else {
            $payload['summary']['source_rows_unresolved']++;
        }

        foreach ([
            'capture_plan_count',
            'review_plan_count',
            'rehydrated_plan_count',
            'unresolved_plan_count',
            'direct_download_plans',
            'html_snapshot_plans',
            'local_reference_plans',
        ] as $key) {
            $payload['summary'][$key] += (int) ($preflight[$key] ?? 0);
        }

        $payload['summary']['line_item_attach_plans'] += (int) ($preflight['line_item_attach_plan_count'] ?? 0);
        $payload['summary']['line_item_non_attach_plans'] += (int) ($preflight['line_item_non_attach_plan_count'] ?? 0);
        if (($preflight['line_item_missing_decisions'] ?? false) === true) {
            $payload['summary']['line_item_missing_decisions']++;
        }
    }

    /**
     * @param  array<string, mixed>  $preflight
     */
    private function stampPreflight(object $row, array $preflight): void
    {
        $details = $this->decodeDetails($row->details ?? null);
        $details['executor_preflight'] = array_merge($preflight, [
            'stamped_at' => now()->toIso8601String(),
            'capture_execution_enabled' => false,
        ]);

        DB::table('agent_review_queue')
            ->where('id', $row->id)
            ->where('review_type', GenealogyEvidenceAssetCaptureReviewService::REVIEW_TYPE)
            ->where('status', 'approved')
            ->update([
                'details' => json_encode($details, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeDetails(mixed $details): array
    {
        if (is_array($details)) {
            return $details;
        }

        if (! is_string($details) || trim($details) === '') {
            return [];
        }

        $decoded = json_decode($details, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function detailsWithAttachApprovedPlans(array $details): array
    {
        $filter = $this->attachApprovedPlanFilter($details);
        $details['plans'] = $filter['plans'];
        $details['capture_plan_count'] = count($filter['plans']);
        $details['executor_line_item_filter'] = [
            'schema' => 'genealogy_evidence_asset_capture_line_item_filter.v1',
            'review_plan_count' => $filter['review_plan_count'],
            'attach_plan_count' => $filter['line_item_attach_plan_count'],
            'non_attach_plan_count' => $filter['line_item_non_attach_plan_count'],
            'missing_decisions' => $filter['line_item_missing_decisions'],
        ];

        return $details;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{
     *     plans: list<array<string, mixed>>,
     *     review_plan_count: int,
     *     line_item_decisions_applied: bool,
     *     line_item_missing_decisions: bool,
     *     line_item_attach_plan_count: int,
     *     line_item_non_attach_plan_count: int
     * }
     */
    private function attachApprovedPlanFilter(array $details): array
    {
        $plans = is_array($details['plans'] ?? null) ? array_values($details['plans']) : [];
        $reviewPlanCount = count($plans);
        $lineDecisions = is_array($details['line_item_decisions'] ?? null)
            ? array_values($details['line_item_decisions'])
            : [];

        if ($reviewPlanCount === 0) {
            return [
                'plans' => [],
                'review_plan_count' => 0,
                'line_item_decisions_applied' => false,
                'line_item_missing_decisions' => false,
                'line_item_attach_plan_count' => 0,
                'line_item_non_attach_plan_count' => 0,
            ];
        }

        if ($lineDecisions === []) {
            return [
                'plans' => [],
                'review_plan_count' => $reviewPlanCount,
                'line_item_decisions_applied' => false,
                'line_item_missing_decisions' => true,
                'line_item_attach_plan_count' => 0,
                'line_item_non_attach_plan_count' => $reviewPlanCount,
            ];
        }

        $attachIndexes = [];
        foreach ($lineDecisions as $decision) {
            if (! is_array($decision) || ($decision['action'] ?? null) !== 'attach') {
                continue;
            }

            $index = $decision['plan_index'] ?? null;
            if (is_int($index) || (is_string($index) && preg_match('/^\d+$/', $index) === 1)) {
                $attachIndexes[(int) $index] = true;
            }
        }

        $attachPlans = [];
        foreach ($plans as $index => $plan) {
            if (! is_array($plan) || ! isset($attachIndexes[$index])) {
                continue;
            }

            $attachPlans[] = $plan;
        }

        return [
            'plans' => $attachPlans,
            'review_plan_count' => $reviewPlanCount,
            'line_item_decisions_applied' => true,
            'line_item_missing_decisions' => false,
            'line_item_attach_plan_count' => count($attachPlans),
            'line_item_non_attach_plan_count' => max(0, $reviewPlanCount - count($attachPlans)),
        ];
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @return array<string, mixed>
     */
    private function redactPreflight(array $preflight): array
    {
        return [
            'schema' => $preflight['schema'] ?? 'genealogy_evidence_asset_capture_executor_preflight.v1',
            'review_type' => GenealogyEvidenceAssetCaptureReviewService::REVIEW_TYPE,
            'approved_for_executor' => (bool) ($preflight['approved_for_executor'] ?? false),
            'source_target_ref_present' => (bool) ($preflight['source_target_ref_present'] ?? false),
            'source_row_resolved' => (bool) ($preflight['source_row_resolved'] ?? false),
            'review_plan_count' => (int) ($preflight['review_plan_count'] ?? 0),
            'capture_plan_count' => (int) ($preflight['capture_plan_count'] ?? 0),
            'line_item_decisions_applied' => (bool) ($preflight['line_item_decisions_applied'] ?? false),
            'line_item_missing_decisions' => (bool) ($preflight['line_item_missing_decisions'] ?? false),
            'line_item_attach_plan_count' => (int) ($preflight['line_item_attach_plan_count'] ?? 0),
            'line_item_non_attach_plan_count' => (int) ($preflight['line_item_non_attach_plan_count'] ?? 0),
            'rehydrated_plan_count' => (int) ($preflight['rehydrated_plan_count'] ?? 0),
            'unresolved_plan_count' => (int) ($preflight['unresolved_plan_count'] ?? 0),
            'direct_download_plans' => (int) ($preflight['direct_download_plans'] ?? 0),
            'html_snapshot_plans' => (int) ($preflight['html_snapshot_plans'] ?? 0),
            'local_reference_plans' => (int) ($preflight['local_reference_plans'] ?? 0),
            'ready_for_future_executor' => (bool) ($preflight['ready_for_future_executor'] ?? false),
            'download_attempted' => false,
            'storage_write_attempted' => false,
            'genealogy_link_attempted' => false,
            'canonical_write_attempted' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $execution
     * @return array<string, mixed>
     */
    private function redactExecution(array $execution): array
    {
        return [
            'schema' => $execution['schema'] ?? 'genealogy_evidence_asset_capture_execution.v1',
            'review_type' => GenealogyEvidenceAssetCaptureReviewService::REVIEW_TYPE,
            'source_target_ref_present' => (bool) ($execution['source_target_ref_present'] ?? false),
            'capture_plan_count' => (int) ($execution['capture_plan_count'] ?? 0),
            'download_attempted' => (bool) ($execution['download_attempted'] ?? false),
            'storage_write_attempted' => (bool) ($execution['storage_write_attempted'] ?? false),
            'genealogy_link_attempted' => (bool) ($execution['genealogy_link_attempted'] ?? false),
            'canonical_write_attempted' => false,
            'summary' => is_array($execution['summary'] ?? null) ? $execution['summary'] : [],
            'items' => is_array($execution['items'] ?? null) ? $execution['items'] : [],
        ];
    }
}
