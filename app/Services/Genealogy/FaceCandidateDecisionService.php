<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;

class FaceCandidateDecisionService
{
    private const ACTIONS = [
        'keep_name_only',
        'outside_tree',
        'too_vague',
        'not_this_person',
        'defer',
    ];

    private const TERMINAL_ACTIONS = [
        'keep_name_only',
        'outside_tree',
        'too_vague',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function decide(int $fileRegistryFaceId, array $input, ?int $userId = null): array
    {
        $action = (string) ($input['action'] ?? '');
        if (! in_array($action, self::ACTIONS, true)) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'invalid_action',
                'allowed_actions' => self::ACTIONS,
            ];
        }

        $tree = $this->resolveTreeId((int) ($input['tree_id'] ?? 0));
        if ($tree['id'] <= 0) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'tree_id_required',
                'tree_count' => $tree['count'],
            ];
        }

        $face = $this->loadFace($fileRegistryFaceId);
        if ($face === null) {
            return [
                'success' => false,
                'status' => 404,
                'error' => 'face_not_found',
            ];
        }

        if ((bool) ($face->hidden ?? false)) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'face_hidden',
            ];
        }

        if ($face->genealogy_person_id !== null) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'face_already_linked',
                'current_genealogy_person_id' => (int) $face->genealogy_person_id,
            ];
        }

        if (trim((string) ($face->person_name ?? '')) === '') {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'face_name_required',
            ];
        }

        $candidatePersonId = $this->positiveInt($input['genealogy_person_id'] ?? null);
        if ($action === 'not_this_person' && $candidatePersonId === null) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'genealogy_person_id_required',
            ];
        }

        if ($candidatePersonId !== null && ! $this->personExistsInTree($candidatePersonId, $tree['id'])) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'genealogy_person_not_found',
            ];
        }

        $media = $this->resolveExistingMedia($tree['id'], $face);
        if ($media === null) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'genealogy_media_not_found',
                'message' => 'Candidate decisions require an existing genealogy_media row; no media or genealogy links were created.',
            ];
        }

        $queue = $this->findQueueRow($fileRegistryFaceId, (int) $media->id, (string) $face->person_name);
        $details = $this->decodeDetails($queue?->match_details ?? null);
        $decision = $this->decisionPayload($action, $fileRegistryFaceId, $tree['id'], $candidatePersonId, $input, $userId);
        $details = $this->appendDecision($details, $decision);

        $status = $decision['terminal'] ? 'ignored' : 'pending';
        $reviewNotes = $this->reviewNotes($decision);
        $faceRegion = json_encode([
            'x' => $face->region_x !== null ? (float) $face->region_x : null,
            'y' => $face->region_y !== null ? (float) $face->region_y : null,
            'w' => $face->region_w !== null ? (float) $face->region_w : null,
            'h' => $face->region_h !== null ? (float) $face->region_h : null,
        ], JSON_UNESCAPED_SLASHES);
        $detailsJson = json_encode($details, JSON_UNESCAPED_SLASHES);

        if ($detailsJson === false || $faceRegion === false) {
            return [
                'success' => false,
                'status' => 500,
                'error' => 'candidate_decision_encode_failed',
            ];
        }

        if ($queue !== null) {
            DB::table('genealogy_face_match_queue')
                ->where('id', $queue->id)
                ->update([
                    'tree_id' => $tree['id'],
                    'media_id' => (int) $media->id,
                    'file_registry_face_id' => $fileRegistryFaceId,
                    'suggested_person_id' => $candidatePersonId ?? $queue->suggested_person_id,
                    'status' => $status,
                    'reviewed_by' => $userId,
                    'reviewed_at' => now(),
                    'review_notes' => $reviewNotes,
                    'match_details' => $detailsJson,
                    'updated_at' => now(),
                ]);
            $queueId = (int) $queue->id;
            $queueAction = 'updated';
        } else {
            DB::table('genealogy_face_match_queue')->insert([
                'tree_id' => $tree['id'],
                'media_id' => (int) $media->id,
                'face_name' => (string) $face->person_name,
                'suggested_person_id' => $candidatePersonId,
                'match_type' => 'candidate_decision',
                'confidence_score' => 0,
                'face_region' => $faceRegion,
                'match_details' => $detailsJson,
                'status' => $status,
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'review_notes' => $reviewNotes,
                'file_registry_face_id' => $fileRegistryFaceId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $queueId = (int) DB::getPdo()->lastInsertId();
            $queueAction = 'created';
        }

        return [
            'success' => true,
            'status' => 200,
            'queue_action' => $queueAction,
            'queue' => [
                'id' => $queueId,
                'status' => $status,
                'match_type' => $queue?->match_type ?? 'candidate_decision',
            ],
            'decision' => $decision,
        ];
    }

    /**
     * @return array{id:int,count:int|null}
     */
    private function resolveTreeId(int $treeId): array
    {
        if ($treeId > 0) {
            return ['id' => $treeId, 'count' => null];
        }

        $tree = DB::table('genealogy_trees')
            ->selectRaw('COUNT(*) AS tree_count, MIN(id) AS only_tree_id')
            ->first();

        $count = (int) ($tree->tree_count ?? 0);
        if ($count === 1) {
            return ['id' => (int) $tree->only_tree_id, 'count' => $count];
        }

        return ['id' => 0, 'count' => $count];
    }

    private function loadFace(int $fileRegistryFaceId): ?object
    {
        return DB::table('file_registry_faces as frf')
            ->join('file_registry as fr', 'fr.id', '=', 'frf.file_registry_id')
            ->select(
                'frf.id',
                'frf.file_registry_id',
                'frf.person_name',
                'frf.genealogy_person_id',
                'frf.region_x',
                'frf.region_y',
                'frf.region_w',
                'frf.region_h',
                'frf.hidden',
                'fr.current_path',
                'fr.original_path'
            )
            ->where('frf.id', $fileRegistryFaceId)
            ->first();
    }

    private function resolveExistingMedia(int $treeId, object $face): ?object
    {
        $path = $face->current_path ?: $face->original_path;
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return DB::table('genealogy_media')
            ->select('id')
            ->where('tree_id', $treeId)
            ->where(function ($query) use ($path, $face): void {
                $query->where('nextcloud_path', $path);
                if (is_string($face->original_path ?? null) && trim((string) $face->original_path) !== '') {
                    $query->orWhere('original_path', (string) $face->original_path);
                }
            })
            ->orderByDesc('id')
            ->first();
    }

    private function findQueueRow(int $fileRegistryFaceId, int $mediaId, string $faceName): ?object
    {
        $byFace = DB::table('genealogy_face_match_queue')
            ->where('file_registry_face_id', $fileRegistryFaceId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if ($byFace !== null) {
            return $byFace;
        }

        return DB::table('genealogy_face_match_queue')
            ->where('media_id', $mediaId)
            ->where('face_name', $faceName)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }

    private function personExistsInTree(int $personId, int $treeId): bool
    {
        return DB::table('genealogy_persons')
            ->where('id', $personId)
            ->where('tree_id', $treeId)
            ->exists();
    }

    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decisionPayload(
        string $action,
        int $fileRegistryFaceId,
        int $treeId,
        ?int $candidatePersonId,
        array $input,
        ?int $userId
    ): array {
        $reason = trim((string) ($input['reason'] ?? ''));

        return [
            'action' => $action,
            'terminal' => in_array($action, self::TERMINAL_ACTIONS, true),
            'file_registry_face_id' => $fileRegistryFaceId,
            'tree_id' => $treeId,
            'genealogy_person_id' => $candidatePersonId,
            'reason' => mb_substr($reason, 0, 500),
            'decided_by' => $userId,
            'decided_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeDetails(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private function appendDecision(array $details, array $decision): array
    {
        $history = $details['candidate_decisions'] ?? [];
        if (! is_array($history)) {
            $history = [];
        }

        $history[] = $decision;
        $history = array_slice($history, -25);

        $rejectedIds = $details['rejected_candidate_ids'] ?? [];
        if (! is_array($rejectedIds)) {
            $rejectedIds = [];
        }

        if (($decision['action'] ?? null) === 'not_this_person' && ! empty($decision['genealogy_person_id'])) {
            $rejectedIds[] = (int) $decision['genealogy_person_id'];
        }

        $details['candidate_decision_version'] = 1;
        $details['latest_candidate_decision'] = $decision;
        $details['candidate_decisions'] = array_values($history);
        $details['rejected_candidate_ids'] = array_values(array_unique(array_map('intval', $rejectedIds)));

        return $details;
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function reviewNotes(array $decision): string
    {
        $notes = 'candidate_decision: '.$decision['action'];
        if (! empty($decision['genealogy_person_id'])) {
            $notes .= ' person='.$decision['genealogy_person_id'];
        }
        if (! empty($decision['reason'])) {
            $notes .= ' reason='.$decision['reason'];
        }

        return mb_substr($notes, 0, 1000);
    }
}
