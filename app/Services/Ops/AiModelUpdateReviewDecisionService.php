<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AiModelUpdateReviewDecisionService
{
    public function __construct(
        private readonly AiModelUpdateReviewPresentationService $presentation,
    ) {}

    /** @return array<string, mixed> */
    public function approve(int $id, ?string $notes = null): array
    {
        return DB::transaction(function () use ($id, $notes): array {
            $row = DB::table('agent_review_queue')
                ->where('id', $id)
                ->where('review_type', 'ai_model_update')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return ['success' => false, 'error' => 'AI model review item not found.'];
            }
            if ((string) $row->status !== 'pending') {
                return ['success' => false, 'error' => 'AI model review item is no longer pending.'];
            }

            $details = $this->decodeDetails($row->details ?? null);
            $projection = $this->presentation->project($details);
            if (($projection['review_ready'] ?? false) !== true) {
                return [
                    'success' => false,
                    'error' => 'AI model review packet failed the operator presentation contract.',
                    'blockers' => $projection['blockers'] ?? [],
                ];
            }

            $details['operator_disposition'] = [
                'schema' => 'plos.ai_model_update_operator_disposition.v1',
                'status' => 'approved_bounded',
                'actor' => 'operator_review_hub',
                'approved_at' => now()->toIso8601String(),
                'approved_action_ids' => array_values(array_map(
                    static fn (array $action): string => (string) $action['resource_id'],
                    (array) ($projection['actions'] ?? [])
                )),
                'decision_counts' => $projection['summary']['decision_counts'] ?? [],
                'approved_scope' => [
                    'retain_current_resources',
                    'bounded_candidate_or_change_validation',
                ],
                'not_authorized' => [
                    'automatic_install',
                    'model_or_route_promotion',
                    'automatic_retirement',
                    'privacy_boundary_widening',
                    'public_media_release',
                    'face_or_photo_identity_writeback',
                    'prompt_compression',
                    'precompaction',
                ],
                'operator_notes' => $this->safeNotes($notes),
            ];

            $values = [
                'status' => 'approved',
                'details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'reviewed_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('agent_review_queue', 'reviewer_notes')) {
                $values['reviewer_notes'] = $this->safeNotes($notes);
            }
            $updated = DB::table('agent_review_queue')
                ->where('id', $id)
                ->where('status', 'pending')
                ->update($values);

            return $updated === 1
                ? [
                    'success' => true,
                    'message' => 'Portfolio review approved for bounded validation only; no model or route changed.',
                    'approved_action_count' => (int) ($projection['summary']['action_count'] ?? 0),
                    'automatic_changes_applied' => false,
                ]
                : ['success' => false, 'error' => 'AI model review changed before approval completed.'];
        });
    }

    /** @return array<string, mixed> */
    public function reject(int $id, ?string $reason = null): array
    {
        return DB::transaction(function () use ($id, $reason): array {
            $row = DB::table('agent_review_queue')
                ->where('id', $id)
                ->where('review_type', 'ai_model_update')
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                return ['success' => false, 'error' => 'Pending AI model review item not found.'];
            }

            $details = $this->decodeDetails($row->details ?? null);
            $details['operator_disposition'] = [
                'schema' => 'plos.ai_model_update_operator_disposition.v1',
                'status' => 'rejected',
                'actor' => 'operator_review_hub',
                'rejected_at' => now()->toIso8601String(),
                'reason' => $this->safeNotes($reason),
                'automatic_changes_applied' => false,
            ];
            $values = [
                'status' => 'rejected',
                'details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'reviewed_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('agent_review_queue', 'reviewer_notes')) {
                $values['reviewer_notes'] = $this->safeNotes($reason);
            }
            DB::table('agent_review_queue')->where('id', $id)->where('status', 'pending')->update($values);

            return [
                'success' => true,
                'message' => 'Portfolio review rejected; no model or route changed.',
                'automatic_changes_applied' => false,
            ];
        });
    }

    /** @return array<string, mixed> */
    private function decodeDetails(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function safeNotes(?string $notes): ?string
    {
        $value = trim((string) $notes);

        return $value === '' ? null : mb_substr($value, 0, 2000);
    }
}
