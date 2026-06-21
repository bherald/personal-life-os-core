<?php

namespace App\Services\Genealogy;

use App\Services\Genealogy\Support\GivenNameVariants;
use App\Services\Genealogy\Support\TemporalProximityChecker;
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

        $face = DB::table('file_registry_faces as frf')
            ->leftJoin('file_registry as fr', 'fr.id', '=', 'frf.file_registry_id')
            ->select(
                'frf.id',
                'frf.file_registry_id',
                'frf.person_name',
                'frf.genealogy_person_id',
                'frf.region_x',
                'frf.region_y',
                'frf.region_w',
                'frf.region_h',
                'frf.confidence',
                'frf.cluster_id',
                'frf.hidden',
                'fr.date_taken'
            )
            ->where('frf.id', $fileRegistryFaceId)
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
        $photoYear = $this->photoYear($face->date_taken ?? null);

        $candidates = $tokens === []
            ? []
            : $this->mergeIdentityCandidates(
                $this->rankCandidates($treeId['id'], $tokens, $normalizedFaceName, $limit, $photoYear),
                $this->rankKnownFaceIdentities($fileRegistryFaceId, $treeId['id'], $tokens, $normalizedFaceName, $limit, $photoYear)
            );

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
            'candidates' => array_slice($candidates, 0, $limit),
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
    private function rankCandidates(
        int $treeId,
        array $tokens,
        string $normalizedFaceName,
        int $limit,
        ?int $photoYear
    ): array {
        $searchTokens = $this->expandedNameTokens($tokens);

        $candidatePoolLimit = max($limit * 20, 250);

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
            ->leftJoinSub(
                DB::table('genealogy_name_variants')
                    ->select(
                        'person_id',
                        DB::raw("GROUP_CONCAT(DISTINCT TRIM(COALESCE(NULLIF(full_name, ''), CONCAT_WS(' ', given_names, surname))) SEPARATOR '||') AS variant_names")
                    )
                    ->groupBy('person_id'),
                'name_variants',
                'name_variants.person_id',
                '=',
                'gp.id'
            )
            ->where('gp.tree_id', $treeId)
            ->where(function ($query) use ($searchTokens): void {
                foreach ($searchTokens as $token) {
                    $like = '%'.$token.'%';
                    $query->orWhereRaw('LOWER(gp.given_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(gp.surname) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(gp.nickname) LIKE ?', [$like])
                        ->orWhereRaw("LOWER(CONCAT_WS(' ', gp.given_name, gp.surname)) LIKE ?", [$like])
                        ->orWhereRaw('LOWER(name_variants.variant_names) LIKE ?', [$like]);
                }
            })
            ->select(
                'gp.id',
                'gp.tree_id',
                'gp.given_name',
                'gp.surname',
                'gp.nickname',
                'gp.birth_date',
                'gp.death_date',
                'gp.living',
                'gp.privacy_override',
                'gt.privacy as tree_privacy',
                'gt.living_privacy',
                DB::raw("CONCAT_WS(' ', gp.given_name, gp.surname) AS name"),
                DB::raw('COALESCE(face_counts.face_count, 0) AS face_count'),
                DB::raw('COALESCE(name_variants.variant_names, "") AS variant_names')
            )
            ->orderByRaw(
                "CASE
                    WHEN LOWER(CONCAT_WS(' ', gp.given_name, gp.surname)) = ? THEN 0
                    WHEN LOWER(COALESCE(name_variants.variant_names, '')) LIKE ? THEN 1
                    WHEN LOWER(CONCAT_WS(' ', gp.given_name, gp.surname)) LIKE ? THEN 2
                    ELSE 3
                END",
                [$normalizedFaceName, '%'.$normalizedFaceName.'%', $normalizedFaceName.'%']
            )
            ->limit($candidatePoolLimit)
            ->get();

        return $query
            ->map(function (object $person) use ($tokens, $normalizedFaceName, $photoYear): array {
                [$score, $reasons] = $this->scorePerson($person, $tokens, $normalizedFaceName);
                $lifespanContext = $this->lifespanContext($person, $photoYear);

                return [
                    'genealogy_person_id' => (int) $person->id,
                    'identity_key' => 'genealogy:'.(int) $person->id,
                    'identity_type' => 'genealogy_person',
                    'bridge_priority' => 1,
                    'tree_id' => (int) $person->tree_id,
                    'name' => trim((string) $person->name),
                    'given_name' => $person->given_name,
                    'surname' => $person->surname,
                    'nickname' => $person->nickname,
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
                    'photo_date_context' => $lifespanContext['photo_date_context'],
                    'lifespan_fit' => $lifespanContext['lifespan_fit'],
                    'age_at_photo' => $lifespanContext['age_at_photo'],
                    'lifespan_warnings' => $lifespanContext['lifespan_warnings'],
                    'face_count' => (int) $person->face_count,
                    'known_face_count' => (int) $person->face_count,
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
     * Known face labels are real identities for review even when they have not
     * been bridged to an FT person yet.
     *
     * @param  list<string>  $tokens
     * @return list<array<string, mixed>>
     */
    private function rankKnownFaceIdentities(
        int $fileRegistryFaceId,
        int $treeId,
        array $tokens,
        string $normalizedFaceName,
        int $limit,
        ?int $photoYear
    ): array {
        $searchTokens = $this->expandedNameTokens($tokens);
        $candidatePoolLimit = max($limit * 20, 250);

        $query = DB::table('file_registry_faces as frf')
            ->leftJoin('genealogy_persons as gp', 'gp.id', '=', 'frf.genealogy_person_id')
            ->leftJoin('genealogy_trees as gt', 'gt.id', '=', 'gp.tree_id')
            ->where('frf.hidden', 0)
            ->where('frf.id', '<>', $fileRegistryFaceId)
            ->whereRaw("NULLIF(TRIM(frf.person_name), '') IS NOT NULL")
            ->whereRaw("LOWER(TRIM(frf.person_name)) <> 'unknown'")
            ->where(function ($query) use ($treeId): void {
                $query->whereNull('frf.genealogy_person_id')
                    ->orWhere('gp.tree_id', $treeId);
            })
            ->where(function ($query) use ($searchTokens): void {
                foreach ($searchTokens as $token) {
                    $like = '%'.$token.'%';
                    $query->orWhereRaw('LOWER(frf.person_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(gp.given_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(gp.surname) LIKE ?', [$like])
                        ->orWhereRaw("LOWER(CONCAT_WS(' ', gp.given_name, gp.surname)) LIKE ?", [$like]);
                }
            })
            ->select(
                'frf.person_name',
                'frf.genealogy_person_id',
                'gp.id',
                'gp.tree_id',
                'gp.given_name',
                'gp.surname',
                'gp.nickname',
                'gp.birth_date',
                'gp.death_date',
                'gp.living',
                'gp.privacy_override',
                'gt.privacy as tree_privacy',
                'gt.living_privacy',
                DB::raw("CONCAT_WS(' ', gp.given_name, gp.surname) AS name"),
                DB::raw('COUNT(*) AS face_count')
            )
            ->groupBy(
                'frf.person_name',
                'frf.genealogy_person_id',
                'gp.id',
                'gp.tree_id',
                'gp.given_name',
                'gp.surname',
                'gp.nickname',
                'gp.birth_date',
                'gp.death_date',
                'gp.living',
                'gp.privacy_override',
                'gt.privacy',
                'gt.living_privacy'
            )
            ->orderByRaw(
                "CASE
                    WHEN LOWER(frf.person_name) = ? THEN 0
                    WHEN LOWER(frf.person_name) LIKE ? THEN 1
                    ELSE 2
                END",
                [$normalizedFaceName, $normalizedFaceName.'%']
            )
            ->orderByRaw('COUNT(*) DESC')
            ->limit($candidatePoolLimit)
            ->get();

        return $query
            ->map(function (object $identity) use ($tokens, $normalizedFaceName, $photoYear): array {
                [$score, $reasons] = $this->scoreKnownFaceIdentity($identity, $tokens, $normalizedFaceName);
                $linkedPersonId = $identity->genealogy_person_id !== null ? (int) $identity->genealogy_person_id : null;
                $faceLabel = trim((string) $identity->person_name);
                $canonicalName = trim((string) ($identity->name ?? ''));
                $labelMatchesLinkedPerson = $linkedPersonId !== null
                    && $canonicalName !== ''
                    && $this->normalizeName($faceLabel) === $this->normalizeName($canonicalName);
                $personId = $labelMatchesLinkedPerson ? $linkedPersonId : null;
                $lifespanContext = $personId !== null
                    ? $this->lifespanContext($identity, $photoYear)
                    : [
                        'photo_date_context' => [
                            'projection_only' => true,
                            'photo_year' => $photoYear,
                            'birth_year' => null,
                            'death_year' => null,
                        ],
                        'lifespan_fit' => $photoYear === null ? 'unknown_photo_date' : 'standalone_face_label',
                        'age_at_photo' => null,
                        'lifespan_warnings' => [],
                    ];

                return [
                    'genealogy_person_id' => $personId,
                    'identity_key' => $personId !== null
                        ? 'genealogy:'.$personId
                        : 'face_label:'.$this->normalizeName($faceLabel),
                    'identity_type' => $personId !== null ? 'genealogy_person' : 'face_label',
                    'bridge_priority' => $personId !== null ? 1 : 0,
                    'tree_id' => $personId !== null && $identity->tree_id !== null ? (int) $identity->tree_id : null,
                    'name' => $personId !== null && $canonicalName !== '' ? $canonicalName : $faceLabel,
                    'person_name' => $faceLabel,
                    'face_label' => $faceLabel,
                    'given_name' => $personId !== null ? ($identity->given_name ?? null) : null,
                    'surname' => $personId !== null ? ($identity->surname ?? null) : null,
                    'nickname' => $personId !== null ? ($identity->nickname ?? null) : null,
                    'birth_date' => $personId !== null ? ($identity->birth_date ?? null) : null,
                    'death_date' => $personId !== null ? ($identity->death_date ?? null) : null,
                    'living' => $personId !== null ? $this->nullableBool($identity->living ?? null) : null,
                    'living_status' => $personId !== null ? $this->livingStatus($identity->living ?? null) : 'unknown',
                    'privacy_override' => $personId !== null ? $this->privacyOverride($identity->privacy_override ?? null) : 'default',
                    'tree_privacy' => $personId !== null ? $this->treePrivacy($identity->tree_privacy ?? null) : 'unknown',
                    'living_privacy' => $personId !== null ? $this->livingPrivacy($identity->living_privacy ?? null) : 'unknown',
                    'privacy_state' => $personId !== null ? $this->privacyState($identity) : 'standalone_face_label',
                    'requires_elevated_review' => $personId !== null ? $this->requiresElevatedReview($identity) : false,
                    'review_posture' => $personId !== null ? $this->reviewPosture($identity) : $this->standaloneFaceLabelPosture(),
                    'photo_date_context' => $lifespanContext['photo_date_context'],
                    'lifespan_fit' => $lifespanContext['lifespan_fit'],
                    'age_at_photo' => $lifespanContext['age_at_photo'],
                    'lifespan_warnings' => $lifespanContext['lifespan_warnings'],
                    'face_count' => (int) $identity->face_count,
                    'known_face_count' => (int) $identity->face_count,
                    'score' => $score,
                    'reasons' => $reasons,
                ];
            })
            ->filter(fn (array $candidate): bool => $candidate['score'] > 0.0)
            ->sortBy([
                ['score', 'desc'],
                ['bridge_priority', 'desc'],
                ['known_face_count', 'desc'],
                ['name', 'asc'],
                ['identity_key', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $genealogyCandidates
     * @param  list<array<string, mixed>>  $faceIdentityCandidates
     * @return list<array<string, mixed>>
     */
    private function mergeIdentityCandidates(array $genealogyCandidates, array $faceIdentityCandidates): array
    {
        $merged = [];

        foreach ([...$genealogyCandidates, ...$faceIdentityCandidates] as $candidate) {
            $key = (string) ($candidate['identity_key'] ?? '');
            if ($key === '') {
                continue;
            }

            if (! isset($merged[$key])) {
                $merged[$key] = $candidate;
                continue;
            }

            $existing = $merged[$key];
            $existingScore = (float) ($existing['score'] ?? 0.0);
            $candidateScore = (float) ($candidate['score'] ?? 0.0);

            if ($candidateScore > $existingScore) {
                $candidate['reasons'] = array_values(array_unique(array_merge(
                    (array) ($existing['reasons'] ?? []),
                    (array) ($candidate['reasons'] ?? [])
                )));
                $candidate['known_face_count'] = max(
                    (int) ($existing['known_face_count'] ?? 0),
                    (int) ($candidate['known_face_count'] ?? 0)
                );
                $candidate['face_count'] = max((int) ($existing['face_count'] ?? 0), (int) ($candidate['face_count'] ?? 0));
                $merged[$key] = $candidate;
                continue;
            }

            $existing['reasons'] = array_values(array_unique(array_merge(
                (array) ($existing['reasons'] ?? []),
                (array) ($candidate['reasons'] ?? [])
            )));
            $existing['known_face_count'] = max(
                (int) ($existing['known_face_count'] ?? 0),
                (int) ($candidate['known_face_count'] ?? 0)
            );
            $existing['face_count'] = max((int) ($existing['face_count'] ?? 0), (int) ($candidate['face_count'] ?? 0));
            if (empty($existing['face_label']) && ! empty($candidate['face_label'])) {
                $existing['face_label'] = $candidate['face_label'];
            }
            if (empty($existing['person_name']) && ! empty($candidate['person_name'])) {
                $existing['person_name'] = $candidate['person_name'];
            }
            $merged[$key] = $existing;
        }

        return collect(array_values($merged))
            ->sortBy([
                ['score', 'desc'],
                ['bridge_priority', 'desc'],
                ['known_face_count', 'desc'],
                ['surname', 'asc'],
                ['given_name', 'asc'],
                ['name', 'asc'],
                ['identity_key', 'asc'],
            ])
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
        $nickname = $this->normalizeName((string) ($person->nickname ?? ''));
        $full = trim($given.' '.$surname);
        $variantNames = $this->variantNames($person);
        $reasons = [];
        $score = 0.0;

        if ($normalizedFaceName !== '' && $full === $normalizedFaceName) {
            $score = max($score, 1.0);
            $reasons[] = 'exact_name';
        }

        if (
            $normalizedFaceName !== ''
            && $variantNames !== []
            && in_array($normalizedFaceName, $variantNames, true)
        ) {
            $score = max($score, 0.97);
            $reasons[] = 'variant_exact';
        }

        $nicknameFull = trim($nickname.' '.$surname);
        if ($normalizedFaceName !== '' && $nicknameFull !== '' && $nicknameFull === $normalizedFaceName) {
            $score = max($score, 0.96);
            $reasons[] = 'recorded_nickname_exact';
        }

        if ($surname !== '' && in_array($surname, $tokens, true)) {
            $score = max($score, 0.60);
            $reasons[] = 'surname_exact';
        }

        if (
            $surname !== ''
            && in_array($surname, $tokens, true)
            && $this->givenNameVariantMatch($given, $tokens)
        ) {
            $score = max($score, 0.92);
            $reasons[] = 'given_variant';
        }

        if (
            $nickname !== ''
            && $surname !== ''
            && in_array($surname, $tokens, true)
            && $this->hasPrefixMatch($nickname, $tokens)
        ) {
            $score = max($score, 0.93);
            $reasons[] = 'recorded_nickname';
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
     * @param  list<string>  $tokens
     * @return array{0:float,1:list<string>}
     */
    private function scoreKnownFaceIdentity(object $identity, array $tokens, string $normalizedFaceName): array
    {
        $label = $this->normalizeName((string) ($identity->person_name ?? ''));
        if ($label === '') {
            return [0.0, []];
        }

        $labelTokens = $this->nameTokens($label);
        $overlap = count(array_intersect($labelTokens, $tokens));
        $score = 0.0;
        $reasons = [];

        if ($normalizedFaceName !== '' && $label === $normalizedFaceName) {
            $score = 1.0;
            $reasons[] = 'known_face_exact';
        } elseif ($tokens !== [] && count(array_diff($tokens, $labelTokens)) === 0) {
            $score = 0.94;
            $reasons[] = 'known_face_all_tokens';
        } elseif ($overlap >= 2) {
            $score = 0.86;
            $reasons[] = 'known_face_token_overlap';
        } elseif ($overlap === 1) {
            $score = 0.50;
            $reasons[] = 'known_face_token';
        }

        $canonicalName = $this->normalizeName((string) ($identity->name ?? ''));
        $hasLinkedPerson = ($identity->genealogy_person_id ?? null) !== null;
        $labelMatchesLinkedPerson = $hasLinkedPerson && $canonicalName !== '' && $label === $canonicalName;

        if (! $hasLinkedPerson) {
            $reasons[] = 'standalone_face_label';
        } elseif (! $labelMatchesLinkedPerson) {
            $reasons[] = 'face_label_bridge_mismatch';
            $reasons[] = 'standalone_face_label';
        } else {
            $reasons[] = 'face_label_with_ft_bridge';
        }

        return [round($score, 2), array_values(array_unique($reasons))];
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function expandedNameTokens(array $tokens): array
    {
        $expanded = $tokens;
        foreach ($tokens as $token) {
            foreach (GivenNameVariants::variantsFor($token) as $variant) {
                $expanded[] = $variant;
            }
        }

        return array_values(array_unique(array_filter($expanded, fn (string $token): bool => $token !== '')));
    }

    /**
     * @param  list<string>  $tokens
     */
    private function givenNameVariantMatch(string $given, array $tokens): bool
    {
        $givenTokens = $this->nameTokens($given);
        $primaryGiven = $givenTokens[0] ?? '';
        if ($primaryGiven === '') {
            return false;
        }

        $givenVariants = GivenNameVariants::variantsFor($primaryGiven);
        foreach ($tokens as $token) {
            if ($token !== '' && in_array($token, $givenVariants, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function variantNames(object $person): array
    {
        $raw = (string) ($person->variant_names ?? '');
        if (trim($raw) === '') {
            return [];
        }

        $parts = explode('||', $raw);

        return array_values(array_unique(array_filter(
            array_map(fn (string $name): string => $this->normalizeName($name), $parts),
            fn (string $name): bool => $name !== ''
        )));
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
        $topName = $this->candidateNormalizedName($candidates[0]);
        $secondScore = 0.0;
        foreach (array_slice($candidates, 1) as $candidate) {
            if (
                empty($candidate['genealogy_person_id'])
                && $topName !== ''
                && $this->candidateNormalizedName($candidate) === $topName
            ) {
                continue;
            }

            $secondScore = (float) ($candidate['score'] ?? 0.0);
            break;
        }
        $topReasons = $candidates[0]['reasons'] ?? [];
        if (
            $topScore >= 0.95
            && $secondScore < 0.95
            && is_array($topReasons)
            && array_intersect($topReasons, ['variant_exact', 'recorded_nickname_exact']) !== []
        ) {
            return 'strong_candidate';
        }

        if ($topScore >= 0.85 && ($secondScore === 0.0 || $topScore - $secondScore >= 0.15)) {
            return 'strong_candidate';
        }

        return 'ambiguous';
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidateNormalizedName(array $candidate): string
    {
        return $this->normalizeName((string) (
            $candidate['face_label']
            ?? $candidate['person_name']
            ?? $candidate['name']
            ?? ''
        ));
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
            'photo_date_context' => $this->facePhotoDateContext($face->date_taken ?? null),
            'region' => [
                'x' => $face->region_x !== null ? (float) $face->region_x : null,
                'y' => $face->region_y !== null ? (float) $face->region_y : null,
                'w' => $face->region_w !== null ? (float) $face->region_w : null,
                'h' => $face->region_h !== null ? (float) $face->region_h : null,
            ],
        ];
    }

    private function photoYear(mixed $dateTaken): ?int
    {
        return TemporalProximityChecker::extractYear($dateTaken);
    }

    /**
     * @return array<string, mixed>
     */
    private function facePhotoDateContext(mixed $dateTaken): array
    {
        $photoYear = $this->photoYear($dateTaken);

        return [
            'projection_only' => true,
            'available' => $photoYear !== null,
            'source' => $photoYear !== null ? 'file_registry.date_taken' : null,
            'photo_year' => $photoYear,
            'date_taken' => $this->safeDateTaken($dateTaken),
        ];
    }

    /**
     * @return array{
     *     photo_date_context: array<string, mixed>,
     *     lifespan_fit: string,
     *     age_at_photo: int|null,
     *     lifespan_warnings: list<string>
     * }
     */
    private function lifespanContext(object $person, ?int $photoYear): array
    {
        $birthYear = TemporalProximityChecker::extractYear($person->birth_date ?? null);
        $deathYear = TemporalProximityChecker::extractYear($person->death_date ?? null);
        $warnings = [];
        $lifespanFit = 'compatible';

        if ($photoYear === null) {
            $lifespanFit = 'unknown_photo_date';
        } elseif ($birthYear === null && $deathYear === null) {
            $lifespanFit = 'unknown_lifespan';
        } elseif ($birthYear !== null && $photoYear < $birthYear) {
            $lifespanFit = 'before_birth';
            $warnings[] = 'photo_before_birth';
        } elseif ($deathYear !== null && $photoYear > $deathYear) {
            $lifespanFit = 'after_death';
            $warnings[] = 'photo_after_death';
        }

        return [
            'photo_date_context' => [
                'projection_only' => true,
                'photo_year' => $photoYear,
                'birth_year' => $birthYear,
                'death_year' => $deathYear,
            ],
            'lifespan_fit' => $lifespanFit,
            'age_at_photo' => $photoYear !== null && $birthYear !== null && $photoYear >= $birthYear
                ? $photoYear - $birthYear
                : null,
            'lifespan_warnings' => $warnings,
        ];
    }

    private function safeDateTaken(mixed $dateTaken): ?string
    {
        if ($dateTaken instanceof \DateTimeInterface) {
            return $dateTaken->format('Y-m-d');
        }

        if (! is_scalar($dateTaken)) {
            return null;
        }

        $value = trim((string) $dateTaken);
        if ($this->photoYear($value) === null) {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1
            ? substr($value, 0, 10)
            : null;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    private function suggestedAction(array $candidates): string
    {
        $topScore = (float) ($candidates[0]['score'] ?? 0.0);

        if ($topScore >= 0.85 && empty($candidates[0]['genealogy_person_id'])) {
            return 'use_name';
        }

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

    /**
     * @return array<string, mixed>
     */
    private function standaloneFaceLabelPosture(): array
    {
        return [
            'projection_only' => true,
            'operator_review_required' => true,
            'operator_link_available' => false,
            'operator_decision_available' => true,
            'automation_allowed' => false,
            'automatic_link_allowed' => false,
            'create_person_allowed' => false,
            'metadata_writeback_allowed' => true,
            'posture_reason' => 'standalone_face_label_candidate',
            'badges' => [
                ['key' => 'standalone-face-label', 'label' => 'Known face label', 'tone' => 'safe'],
            ],
        ];
    }

    private function formatBadgeValue(string $value): string
    {
        return str_replace('_', ' ', $value);
    }
}
