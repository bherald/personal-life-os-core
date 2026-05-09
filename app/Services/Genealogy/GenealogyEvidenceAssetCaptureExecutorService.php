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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(int $limit = 25, bool $savePreflight = false, bool $confirmed = false, bool $compact = false): array
    {
        $limit = max(1, min($limit, 100));
        $payload = $this->basePayload($limit, $savePreflight, $confirmed, $compact);

        if ($savePreflight && ! $confirmed) {
            $payload['status'] = 'blocked';
            $payload['blockers'][] = 'noncanonical_write_confirmation_required';
            $payload['next_action'] = 'Re-run with --save-preflight --confirm-noncanonical-write to stamp approved review rows only.';

            return $payload;
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

            if (! $compact) {
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
            $payload['blockers'][] = 'approved_capture_rows_not_rehydratable';
            $payload['next_action'] = 'Resolve source packet or capture-plan mismatches before enabling the future capture adapter.';
        } elseif ($savePreflight) {
            $payload['status'] = 'preflight_saved';
        } else {
            $payload['status'] = 'preflight_ready';
            $payload['next_action'] = 'Future gated capture adapter can consume only approved, rehydratable rows after explicit download/storage confirmations are implemented.';
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
            'Evidence asset capture executor: %s pending=%s approved=%s inspected=%s ready=%s plans=%s rehydrated=%s downloads_enabled=false storage_writes_enabled=false canonical_writes_enabled=false',
            $payload['status'] ?? 'unknown',
            $summary['pending_capture_reviews'] ?? 0,
            $summary['approved_capture_reviews'] ?? 0,
            $summary['inspected_approved_rows'] ?? 0,
            $summary['ready_approved_rows'] ?? 0,
            $summary['capture_plan_count'] ?? 0,
            $summary['rehydrated_plan_count'] ?? 0,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toMarkdown(array $payload): string
    {
        $summary = $payload['summary'] ?? [];

        return implode("\n", [
            '# Genealogy Evidence Asset Capture Executor Preflight',
            '',
            '- Status: `'.($payload['status'] ?? 'unknown').'`',
            '- Pending capture reviews: `'.($summary['pending_capture_reviews'] ?? 0).'`',
            '- Approved capture reviews: `'.($summary['approved_capture_reviews'] ?? 0).'`',
            '- Inspected approved rows: `'.($summary['inspected_approved_rows'] ?? 0).'`',
            '- Ready approved rows: `'.($summary['ready_approved_rows'] ?? 0).'`',
            '- Capture plans: `'.($summary['capture_plan_count'] ?? 0).'`',
            '- Rehydrated plans: `'.($summary['rehydrated_plan_count'] ?? 0).'`',
            '- Downloads enabled: `false`',
            '- Storage writes enabled: `false`',
            '- Canonical writes enabled: `false`',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(int $limit, bool $savePreflight, bool $confirmed, bool $compact): array
    {
        return [
            'version' => 1,
            'command' => 'genealogy:evidence-asset-capture-executor',
            'mode' => $savePreflight ? 'save_preflight' : 'preflight',
            'save_preflight' => $savePreflight,
            'confirm_noncanonical_write' => $confirmed,
            'compact_requested' => $compact,
            'read_only' => ! $savePreflight,
            'mutation_allowed' => $savePreflight && $confirmed,
            'canonical_write_allowed' => false,
            'noncanonical_write_allowed' => $savePreflight && $confirmed,
            'download_attempted' => false,
            'storage_write_attempted' => false,
            'review_decision_attempted' => false,
            'genealogy_link_attempted' => false,
            'capture_execution_enabled' => false,
            'captured_at' => now()->toIso8601String(),
            'limit' => $limit,
            'status' => 'preflight',
            'blockers' => [],
            'summary' => [
                'pending_capture_reviews' => 0,
                'approved_capture_reviews' => 0,
                'inspected_approved_rows' => 0,
                'ready_approved_rows' => 0,
                'source_rows_resolved' => 0,
                'source_rows_unresolved' => 0,
                'capture_plan_count' => 0,
                'rehydrated_plan_count' => 0,
                'unresolved_plan_count' => 0,
                'direct_download_plans' => 0,
                'html_snapshot_plans' => 0,
                'local_reference_plans' => 0,
                'preflight_rows_stamped' => 0,
            ],
            'posture' => [
                'row_identifiers_included' => false,
                'tokens_included' => false,
                'raw_details_included' => false,
                'raw_locators_included' => false,
                'downloads_enabled' => false,
                'storage_writes_enabled' => false,
                'review_decisions_enabled' => false,
                'genealogy_links_enabled' => false,
                'canonical_writes_enabled' => false,
                'noncanonical_preflight_stamp_enabled' => $savePreflight && $confirmed,
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
     * @return array<string, mixed>
     */
    private function preflightRow(object $row): array
    {
        $details = $this->decodeDetails($row->details ?? null);
        $plans = is_array($details['plans'] ?? null) ? array_values($details['plans']) : [];
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
            'capture_plan_count' => count($plans),
            'rehydrated_plan_count' => $rehydrated,
            'unresolved_plan_count' => $unresolved,
            'direct_download_plans' => $policyCounts['direct_download_allowed'],
            'html_snapshot_plans' => $policyCounts['html_snapshot_allowed'],
            'local_reference_plans' => $policyCounts['already_local_reference'],
            'ready_for_future_executor' => ($details['approved_for_executor'] ?? false) === true
                && $sourceRow !== null
                && count($plans) > 0
                && $unresolved === 0,
            'download_attempted' => false,
            'storage_write_attempted' => false,
            'genealogy_link_attempted' => false,
            'canonical_write_attempted' => false,
        ];
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
            'rehydrated_plan_count',
            'unresolved_plan_count',
            'direct_download_plans',
            'html_snapshot_plans',
            'local_reference_plans',
        ] as $key) {
            $payload['summary'][$key] += (int) ($preflight[$key] ?? 0);
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
            'capture_plan_count' => (int) ($preflight['capture_plan_count'] ?? 0),
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
}
