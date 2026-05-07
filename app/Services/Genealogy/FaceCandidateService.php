<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;

class FaceCandidateService
{
    /**
     * Build read-only, deterministic person candidates for a named face.
     *
     * This first slice intentionally uses cheap name signals only. Cluster
     * co-occurrence and write paths belong in later slices.
     *
     * @return array<string, mixed>
     */
    public function candidatesForFace(int $fileRegistryFaceId, int $treeId, int $limit = 10, bool $includeRejected = false): array
    {
        $limit = max(1, min($limit, 50));
        $treeId = $this->resolveTreeId(max(0, $treeId));

        $face = DB::table('file_registry_faces')
            ->select('id', 'file_registry_id', 'person_name', 'genealogy_person_id', 'region_x', 'region_y', 'region_w', 'region_h', 'confidence', 'cluster_id', 'hidden')
            ->where('id', $fileRegistryFaceId)
            ->first();

        if ($face === null) {
            return [
                'success' => false,
                'status' => 404,
                'error' => 'face_not_found',
                'face_id' => $fileRegistryFaceId,
            ];
        }

        if ((bool) ($face->hidden ?? false)) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'face_hidden',
                'face_id' => $fileRegistryFaceId,
            ];
        }

        if ($face->genealogy_person_id !== null) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'face_already_linked',
                'face_id' => $fileRegistryFaceId,
                'current_genealogy_person_id' => (int) $face->genealogy_person_id,
            ];
        }

        if ($treeId['id'] <= 0) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'tree_id_required',
                'face_id' => $fileRegistryFaceId,
                'tree_count' => $treeId['count'],
            ];
        }

        $normalizedFaceName = $this->normalizeName((string) ($face->person_name ?? ''));
        $tokens = $this->nameTokens($normalizedFaceName);

        $candidates = $tokens === []
            ? []
            : $this->rankCandidates($treeId['id'], $tokens, $normalizedFaceName, $limit);

        $rejectedCandidateIds = $includeRejected ? [] : $this->rejectedCandidateIds($fileRegistryFaceId);
        if ($rejectedCandidateIds !== []) {
            $candidates = array_values(array_filter(
                $candidates,
                fn (array $candidate): bool => ! in_array((int) $candidate['genealogy_person_id'], $rejectedCandidateIds, true)
            ));
        }

        return [
            'success' => true,
            'status' => 200,
            'tree_id' => $treeId['id'],
            'face' => $this->facePayload($face),
            'candidates' => $candidates,
            'candidate_state' => $this->candidateState($tokens, $candidates),
            'suggested_action' => $this->suggestedAction($candidates),
            'candidate_review_posture' => $this->candidateReviewPosture(),
            'suppressed_rejected_candidates' => count($rejectedCandidateIds),
            'limit' => $limit,
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

    /**
     * @param  list<string>  $tokens
     * @return list<array<string, mixed>>
     */
    private function rankCandidates(int $treeId, array $tokens, string $normalizedFaceName, int $limit): array
    {
        $query = DB::table('genealogy_persons as gp')
            ->join('genealogy_trees as gt', 'gt.id', '=', 'gp.tree_id')
            ->leftJoinSub(
                DB::table('file_registry_faces')
                    ->select('genealogy_person_id', DB::raw('COUNT(*) AS face_count'))
                    ->whereNotNull('genealogy_person_id')
                    ->groupBy('genealogy_person_id'),
                'face_counts',
                'face_counts.genealogy_person_id',
                '=',
                'gp.id'
            )
            ->where('gp.tree_id', $treeId)
            ->where(function ($query) use ($tokens): void {
                foreach ($tokens as $token) {
                    $like = '%'.$token.'%';
                    $query->orWhereRaw('LOWER(gp.given_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(gp.surname) LIKE ?', [$like])
                        ->orWhereRaw("LOWER(CONCAT_WS(' ', gp.given_name, gp.surname)) LIKE ?", [$like]);
                }
            })
            ->select(
                'gp.id',
                'gp.tree_id',
                'gp.given_name',
                'gp.surname',
                'gp.birth_date',
                'gp.death_date',
                'gp.living',
                'gp.privacy_override',
                'gt.privacy as tree_privacy',
                'gt.living_privacy',
                DB::raw("CONCAT_WS(' ', gp.given_name, gp.surname) AS name"),
                DB::raw('COALESCE(face_counts.face_count, 0) AS face_count')
            )
            ->limit(max($limit * 5, 50))
            ->get();

        return $query
            ->map(function (object $person) use ($tokens, $normalizedFaceName): array {
                [$score, $reasons] = $this->scorePerson($person, $tokens, $normalizedFaceName);

                return [
                    'genealogy_person_id' => (int) $person->id,
                    'tree_id' => (int) $person->tree_id,
                    'name' => trim((string) $person->name),
                    'given_name' => $person->given_name,
                    'surname' => $person->surname,
                    'birth_date' => $person->birth_date,
                    'death_date' => $person->death_date,
                    'living' => $this->nullableBool($person->living ?? null),
                    'living_status' => $this->livingStatus($person->living ?? null),
                    'privacy_override' => $this->privacyOverride($person->privacy_override ?? null),
                    'tree_privacy' => $this->treePrivacy($person->tree_privacy ?? null),
                    'living_privacy' => $this->livingPrivacy($person->living_privacy ?? null),
                    'privacy_state' => $this->privacyState($person),
                    'requires_elevated_review' => $this->requiresElevatedReview($person),
                    'review_posture' => $this->reviewPosture($person),
                    'face_count' => (int) $person->face_count,
                    'score' => $score,
                    'reasons' => $reasons,
                ];
            })
            ->filter(fn (array $candidate): bool => $candidate['score'] > 0.0)
            ->sortBy([
                ['score', 'desc'],
                ['surname', 'asc'],
                ['given_name', 'asc'],
                ['genealogy_person_id', 'asc'],
            ])
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $tokens
     * @return array{0:float,1:list<string>}
     */
    private function scorePerson(object $person, array $tokens, string $normalizedFaceName): array
    {
        $given = $this->normalizeName((string) ($person->given_name ?? ''));
        $surname = $this->normalizeName((string) ($person->surname ?? ''));
        $full = trim($given.' '.$surname);
        $reasons = [];
        $score = 0.0;

        if ($normalizedFaceName !== '' && $full === $normalizedFaceName) {
            $score = max($score, 1.0);
            $reasons[] = 'exact_name';
        }

        if ($surname !== '' && in_array($surname, $tokens, true)) {
            $score = max($score, 0.60);
            $reasons[] = 'surname_exact';
        }

        if ($given !== '' && $this->hasPrefixMatch($given, $tokens)) {
            $score = max($score, $score >= 0.60 ? 0.85 : 0.55);
            $reasons[] = 'given_prefix';
        }

        if ($score < 0.60 && $this->tokenOverlapCount($full, $tokens) > 0) {
            $score = max($score, 0.50);
            $reasons[] = 'name_token';
        }

        return [round($score, 2), array_values(array_unique($reasons))];
    }

    /**
     * @return list<int>
     */
    private function rejectedCandidateIds(int $fileRegistryFaceId): array
    {
        $details = DB::table('genealogy_face_match_queue')
            ->where('file_registry_face_id', $fileRegistryFaceId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->value('match_details');

        if (! is_string($details) || trim($details) === '') {
            return [];
        }

        $decoded = json_decode($details, true);
        if (! is_array($decoded)) {
            return [];
        }

        $ids = $decoded['rejected_candidate_ids'] ?? [];
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn (int $id): bool => $id > 0
        )));
    }

    /**
     * @param  list<string>  $tokens
     */
    private function hasPrefixMatch(string $given, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token !== '' && (str_starts_with($given, $token) || str_starts_with($token, $given))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function tokenOverlapCount(string $name, array $tokens): int
    {
        $nameTokens = $this->nameTokens($name);

        return count(array_intersect($nameTokens, $tokens));
    }

    private function normalizeName(string $name): string
    {
        $name = preg_replace('/\([^)]*\)/', ' ', $name) ?? $name;
        $name = str_replace(',', ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return strtolower(trim($name));
    }

    /**
     * @return list<string>
     */
    private function nameTokens(string $name): array
    {
        if ($name === '') {
            return [];
        }

        $parts = preg_split('/\s+/', $name) ?: [];

        return array_values(array_filter(
            array_map(fn (string $part): string => trim($part), $parts),
            fn (string $part): bool => $part !== ''
        ));
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<array<string, mixed>>  $candidates
     */
    private function candidateState(array $tokens, array $candidates): string
    {
        if ($this->isVagueName($tokens)) {
            return 'vague_name';
        }

        if ($candidates === []) {
            return 'no_candidate';
        }

        $topScore = (float) ($candidates[0]['score'] ?? 0.0);
        $secondScore = (float) ($candidates[1]['score'] ?? 0.0);
        if ($topScore >= 0.85 && ($secondScore === 0.0 || $topScore - $secondScore >= 0.15)) {
            return 'strong_candidate';
        }

        return 'ambiguous';
    }

    /**
     * @param  list<string>  $tokens
     */
    private function isVagueName(array $tokens): bool
    {
        if ($tokens === []) {
            return true;
        }

        $generic = [
            'unknown',
            'unnamed',
            'unidentified',
            'person',
            'people',
            'face',
            'family',
            'friend',
            'baby',
            'child',
        ];

        return array_values(array_diff($tokens, $generic)) === [];
    }

    /**
     * @return array<string, mixed>
     */
    private function facePayload(object $face): array
    {
        return [
            'face_id' => (int) $face->id,
            'file_registry_id' => (int) $face->file_registry_id,
            'person_name' => $face->person_name,
            'genealogy_person_id' => $face->genealogy_person_id !== null ? (int) $face->genealogy_person_id : null,
            'cluster_id' => $face->cluster_id !== null ? (int) $face->cluster_id : null,
            'confidence' => $face->confidence !== null ? (float) $face->confidence : null,
            'region' => [
                'x' => $face->region_x !== null ? (float) $face->region_x : null,
                'y' => $face->region_y !== null ? (float) $face->region_y : null,
                'w' => $face->region_w !== null ? (float) $face->region_w : null,
                'h' => $face->region_h !== null ? (float) $face->region_h : null,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    private function suggestedAction(array $candidates): string
    {
        $topScore = (float) ($candidates[0]['score'] ?? 0.0);

        if ($topScore >= 0.85) {
            return 'link';
        }

        if ($topScore < 0.50) {
            return 'create_new';
        }

        return 'review';
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no'], true)) {
            return false;
        }

        return null;
    }

    private function livingStatus(mixed $value): string
    {
        $living = $this->nullableBool($value);

        if ($living === null) {
            return 'unknown';
        }

        return $living ? 'living' : 'not_living';
    }

    private function privacyOverride(mixed $value): string
    {
        $normalized = strtolower(trim((string) ($value ?? 'default')));

        return in_array($normalized, ['default', 'public', 'private', 'restricted'], true)
            ? $normalized
            : 'default';
    }

    private function treePrivacy(mixed $value): string
    {
        $normalized = strtolower(trim((string) ($value ?? 'private')));

        return in_array($normalized, ['private', 'shared', 'public'], true)
            ? $normalized
            : 'private';
    }

    private function livingPrivacy(mixed $value): string
    {
        $normalized = strtolower(trim((string) ($value ?? 'hide_details')));

        return in_array($normalized, ['hide_all', 'hide_details', 'show_all'], true)
            ? $normalized
            : 'hide_details';
    }

    private function privacyState(object $person): string
    {
        $living = $this->nullableBool($person->living ?? null);
        $privacyOverride = $this->privacyOverride($person->privacy_override ?? null);
        $treePrivacy = $this->treePrivacy($person->tree_privacy ?? null);
        $livingPrivacy = $this->livingPrivacy($person->living_privacy ?? null);

        if (in_array($privacyOverride, ['private', 'restricted'], true)) {
            return 'person_private';
        }

        if ($privacyOverride === 'public') {
            return $living === true ? 'living_public_override' : 'public_override';
        }

        if ($living === true) {
            return match ($livingPrivacy) {
                'hide_all' => 'living_hide_all',
                'hide_details' => 'living_hide_details',
                default => 'living_show_all',
            };
        }

        return match ($treePrivacy) {
            'public' => 'tree_public',
            'shared' => 'tree_shared',
            default => 'tree_private',
        };
    }

    private function requiresElevatedReview(object $person): bool
    {
        $living = $this->nullableBool($person->living ?? null);
        $privacyOverride = $this->privacyOverride($person->privacy_override ?? null);
        $livingPrivacy = $this->livingPrivacy($person->living_privacy ?? null);

        return $living === true
            || in_array($privacyOverride, ['private', 'restricted'], true)
            || ($living === null && $livingPrivacy === 'hide_all');
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewPosture(object $person): array
    {
        $living = $this->nullableBool($person->living ?? null);
        $livingStatus = $this->livingStatus($person->living ?? null);
        $privacyOverride = $this->privacyOverride($person->privacy_override ?? null);
        $treePrivacy = $this->treePrivacy($person->tree_privacy ?? null);
        $livingPrivacy = $this->livingPrivacy($person->living_privacy ?? null);
        $privacyState = $this->privacyState($person);
        $requiresElevatedReview = $this->requiresElevatedReview($person);
        $badges = [];

        if ($requiresElevatedReview) {
            $badges[] = [
                'key' => 'elevated-review',
                'label' => 'Extra review',
                'tone' => 'warning',
            ];
        }

        if ($living === true || $livingStatus === 'living') {
            $badges[] = [
                'key' => 'living',
                'label' => 'Living',
                'tone' => 'warning',
            ];
        } elseif ($livingStatus === 'unknown') {
            $badges[] = [
                'key' => 'living-unknown',
                'label' => 'Living unknown',
                'tone' => 'muted',
            ];
        }

        if ($privacyOverride !== 'default') {
            $badges[] = [
                'key' => 'privacy-override-'.$privacyOverride,
                'label' => 'Person '.$this->formatBadgeValue($privacyOverride),
                'tone' => $privacyOverride === 'public' ? 'muted' : 'warning',
            ];
        }

        $badges[] = [
            'key' => 'tree-privacy-'.$treePrivacy,
            'label' => 'Tree '.$this->formatBadgeValue($treePrivacy),
            'tone' => 'muted',
        ];

        if ($livingPrivacy !== '' && ($living === true || $livingStatus === 'unknown')) {
            $badges[] = [
                'key' => 'living-privacy-'.$livingPrivacy,
                'label' => 'Living '.$this->formatBadgeValue($livingPrivacy),
                'tone' => $livingPrivacy === 'show_all' ? 'muted' : 'warning',
            ];
        }

        return [
            'projection_only' => true,
            'living_status' => $livingStatus,
            'privacy_state' => $privacyState,
            'requires_elevated_review' => $requiresElevatedReview,
            'badges' => $badges,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateReviewPosture(): array
    {
        return [
            'schema' => 'face_genealogy_candidate_review_posture.v1',
            'projection_only' => true,
            'operator_review_required' => true,
            'operator_link_available' => true,
            'operator_decision_available' => true,
            'automation_allowed' => false,
            'automatic_link_allowed' => false,
            'create_person_allowed' => false,
            'metadata_writeback_allowed' => false,
            'posture_reason' => 'named_only_candidate_projection',
        ];
    }

    private function formatBadgeValue(string $value): string
    {
        return str_replace('_', ' ', $value);
    }
}
