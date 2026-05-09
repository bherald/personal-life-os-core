<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;

class GenealogyEvidenceAssetCaptureDecisionService
{
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

        $decision = [
            'action' => $action,
            'actor' => 'operator',
            'notes_present' => trim((string) $notes) !== '',
            'reason_code' => $this->reasonCode($meta),
            'decided_at' => now()->toIso8601String(),
            'download_attempted' => false,
            'storage_write_attempted' => false,
            'genealogy_link_attempted' => false,
            'canonical_write_attempted' => false,
        ];

        $details['approval_status'] = $status;
        $details['approved_for_executor'] = $status === 'approved';
        $details['execution_posture']['download_attempted'] = false;
        $details['execution_posture']['storage_write_attempted'] = false;
        $details['execution_posture']['genealogy_link_attempted'] = false;
        $details['execution_posture']['canonical_write_allowed'] = false;
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
            'approved_for_executor' => $status === 'approved',
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
}
