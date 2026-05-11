<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;

class GenealogyEvidenceAssetCaptureDecisionService
{
    private const LINE_ACTION_ATTACH = 'attach';
    private const LINE_ACTION_REJECT = 'reject';
    private const LINE_ACTION_NEEDS_RESEARCH = 'needs_research';
    private const LINE_ACTION_IGNORE = 'ignore_for_now';

    private const LINE_ACTIONS = [
        self::LINE_ACTION_ATTACH,
        self::LINE_ACTION_REJECT,
        self::LINE_ACTION_NEEDS_RESEARCH,
        self::LINE_ACTION_IGNORE,
    ];

    public function approve(string $token, ?string $notes = null, array $meta = []): array
    {
        return $this->transition(
            token: $token,
            status: 'approved',
            action: 'capture_approved',
            notes: $notes,
            meta: $meta,
            message: 'Evidence media capture approved for the gated executor; no download or genealogy mutation was performed.'
        );
    }

    public function reject(string $token, ?string $notes = null, array $meta = []): array
    {
        return $this->transition(
            token: $token,
            status: 'rejected',
            action: 'capture_rejected',
            notes: $notes,
            meta: $meta,
            message: 'Evidence media capture review rejected; no download or genealogy mutation was performed.'
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function transition(string $token, string $status, string $action, ?string $notes, array $meta, string $message): array
    {
        $row = DB::table('agent_review_queue')
            ->where('token', $token)
            ->where('review_type', GenealogyEvidenceAssetCaptureReviewService::REVIEW_TYPE)
            ->where('status', 'pending')
            ->first();

        if ($row === null) {
            return [
                'success' => false,
                'error' => 'Pending genealogy evidence asset capture review not found.',
            ];
        }

        $details = json_decode((string) ($row->details ?? '{}'), true);
        if (! is_array($details)) {
            $details = [];
        }

        if (($details['schema'] ?? null) !== 'genealogy_evidence_asset_capture_review.v1') {
            return [
                'success' => false,
                'error' => 'Capture review details schema is missing or unsupported.',
            ];
        }

        $lineDecisions = $this->lineDecisions($meta, $details);
        $lineSummary = $this->lineDecisionSummary($lineDecisions, $details);

        if ($status === 'approved') {
            if (($lineSummary['total_plan_count'] ?? 0) > 0 && ! ($lineSummary['complete'] ?? false)) {
                return [
                    'success' => false,
                    'error' => 'Line-item decisions are required before approving evidence media capture packet.',
                    'unresolved_line_items' => $lineSummary['unresolved_count'] ?? null,
                    'status' => $row->status,
                ];
            }

            if (($lineSummary['total_plan_count'] ?? 0) > 0 && ($lineSummary['attach_count'] ?? 0) < 1) {
                return [
                    'success' => false,
                    'error' => 'At least one media candidate must be selected for attachment before approving capture execution.',
                    'status' => $row->status,
                ];
            }

            $blockedAttach = $this->blockedAttachDecision($lineDecisions, $details);
            if ($blockedAttach !== null) {
                return [
                    'success' => false,
                    'error' => $blockedAttach,
                    'status' => $row->status,
                ];
            }
        }

        $decision = [
            'action' => $action,
            'actor' => 'operator',
            'notes_present' => trim((string) $notes) !== '',
            'reason_code' => $this->reasonCode($meta),
            'line_item_decisions_present' => $lineDecisions !== [],
            'line_item_decision_summary' => $lineSummary,
            'decided_at' => now()->toIso8601String(),
            'download_attempted' => false,
            'storage_write_attempted' => false,
            'genealogy_link_attempted' => false,
            'canonical_write_attempted' => false,
        ];

        $details['approval_status'] = $status;
        $details['approved_for_executor'] = $status === 'approved'
            && (($lineSummary['total_plan_count'] ?? 0) === 0 || (($lineSummary['complete'] ?? false) && ($lineSummary['attach_count'] ?? 0) > 0));
        $details['execution_posture']['download_attempted'] = false;
        $details['execution_posture']['storage_write_attempted'] = false;
        $details['execution_posture']['genealogy_link_attempted'] = false;
        $details['execution_posture']['canonical_write_allowed'] = false;
        $details['line_item_decisions'] = $lineDecisions;
        $details['line_item_decision_summary'] = $lineSummary;
        $details['decision_log'][] = $decision;

        $reviewerNotes = [
            'action' => $action,
            'notes' => $notes,
            'meta' => $meta,
        ];

        DB::table('agent_review_queue')
            ->where('id', $row->id)
            ->update([
                'status' => $status,
                'details' => json_encode($details, JSON_UNESCAPED_SLASHES),
                'reviewer_notes' => json_encode($reviewerNotes, JSON_UNESCAPED_SLASHES),
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

        return [
            'success' => true,
            'message' => $message,
            'status' => $status,
            'action' => $action,
            'approved_for_executor' => $details['approved_for_executor'],
            'download_attempted' => false,
            'storage_write_attempted' => false,
            'genealogy_link_attempted' => false,
            'canonical_write_attempted' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function reasonCode(array $meta): ?string
    {
        $value = strtolower(trim((string) ($meta['reason_code'] ?? '')));

        return $value !== '' && preg_match('/^[a-z0-9_-]{1,80}$/', $value) === 1 ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $details
     * @return array<int, array<string, mixed>>
     */
    private function lineDecisions(array $meta, array $details): array
    {
        $raw = $meta['line_decisions'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $plans = $details['plans'] ?? [];
        $maxIndex = is_array($plans) ? count($plans) - 1 : -1;
        $decisionsByIndex = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $planIndex = $entry['plan_index'] ?? null;
            if (! is_int($planIndex) && ! (is_string($planIndex) && preg_match('/^\d+$/', $planIndex) === 1)) {
                continue;
            }

            $planIndex = (int) $planIndex;
            if ($planIndex < 0 || $planIndex > $maxIndex) {
                continue;
            }

            $action = $entry['action'] ?? null;
            if (! is_string($action) || ! in_array($action, self::LINE_ACTIONS, true)) {
                continue;
            }

            $decision = [
                'plan_index' => $planIndex,
                'action' => $action,
            ];

            $reason = $this->lineReasonCode($entry['reason_code'] ?? null);
            if ($reason !== null) {
                $decision['reason_code'] = $reason;
            }

            $notes = $this->boundedText($entry['notes'] ?? null, 240);
            if ($notes !== null) {
                $decision['notes'] = $notes;
            }

            $decisionsByIndex[$planIndex] = $decision;
        }

        ksort($decisionsByIndex);

        return array_values($decisionsByIndex);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineDecisions
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function lineDecisionSummary(array $lineDecisions, array $details): array
    {
        $plans = $details['plans'] ?? [];
        $total = is_array($plans) ? count($plans) : 0;
        if ($total === 0 && is_numeric($details['capture_plan_count'] ?? null)) {
            $total = max(0, (int) $details['capture_plan_count']);
        }
        $counts = [
            self::LINE_ACTION_ATTACH => 0,
            self::LINE_ACTION_REJECT => 0,
            self::LINE_ACTION_NEEDS_RESEARCH => 0,
            self::LINE_ACTION_IGNORE => 0,
        ];

        foreach ($lineDecisions as $decision) {
            $action = $decision['action'] ?? null;
            if (is_string($action) && array_key_exists($action, $counts)) {
                $counts[$action]++;
            }
        }

        $resolved = count($lineDecisions);

        return [
            'total_plan_count' => $total,
            'resolved_count' => $resolved,
            'unresolved_count' => max(0, $total - $resolved),
            'attach_count' => $counts[self::LINE_ACTION_ATTACH],
            'reject_count' => $counts[self::LINE_ACTION_REJECT],
            'needs_research_count' => $counts[self::LINE_ACTION_NEEDS_RESEARCH],
            'ignore_for_now_count' => $counts[self::LINE_ACTION_IGNORE],
            'complete' => $total === 0 || $resolved >= $total,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineDecisions
     * @param  array<string, mixed>  $details
     */
    private function blockedAttachDecision(array $lineDecisions, array $details): ?string
    {
        $plans = $details['plans'] ?? [];
        if (! is_array($plans)) {
            return null;
        }

        foreach ($lineDecisions as $decision) {
            if (($decision['action'] ?? null) !== self::LINE_ACTION_ATTACH) {
                continue;
            }

            $planIndex = $decision['plan_index'] ?? null;
            if (! is_int($planIndex) || ! isset($plans[$planIndex]) || ! is_array($plans[$planIndex])) {
                continue;
            }

            $identityFit = $plans[$planIndex]['identity_fit'] ?? [];
            if (! is_array($identityFit)) {
                continue;
            }

            if (($identityFit['approval_ready'] ?? true) === false || ($identityFit['partial_name_only'] ?? false) === true) {
                return 'Partial-name-only media candidates cannot be attached. Reject, ignore, or mark that line as needing research.';
            }
        }

        return null;
    }

    private function lineReasonCode(mixed $reason): ?string
    {
        if (! is_string($reason)) {
            return null;
        }

        $reason = strtolower(trim($reason));
        if ($reason === '' || preg_match('/^[a-z0-9_-]{1,80}$/', $reason) !== 1) {
            return null;
        }

        return $reason;
    }

    private function boundedText(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return substr($value, 0, $limit);
    }
}
