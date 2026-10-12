<?php

namespace App\Services\Genealogy;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

class PersonFaceAppearanceProfileService
{
    public const AGE_BAND_BABY = 'baby';

    public const AGE_BAND_CHILD = 'child';

    public const AGE_BAND_TEEN = 'teen';

    public const AGE_BAND_ADULT = 'adult';

    public const AGE_BAND_SENIOR = 'senior';

    public const AGE_BAND_UNKNOWN = 'unknown';

    /** @var array<string,array{0:int|null,1:int|null}> */
    private const AGE_BAND_RANGES = [
        self::AGE_BAND_BABY => [0, 2],
        self::AGE_BAND_CHILD => [3, 12],
        self::AGE_BAND_TEEN => [13, 19],
        self::AGE_BAND_ADULT => [20, 64],
        self::AGE_BAND_SENIOR => [65, null],
        self::AGE_BAND_UNKNOWN => [null, null],
    ];

    public function rebuildForPerson(int $personId): array
    {
        $plan = $this->planForPerson($personId);

        if ($plan['profiles'] === []) {
            $deleted = $this->deleteProfilesForIdentityKeys(['person:'.$personId]);

            return [
                'profiles_written' => 0,
                'links_written' => 0,
                'identities_rebuilt' => 1,
                'stale_profiles_deleted' => $deleted,
                'skipped' => $plan['skipped'],
            ];
        }

        return $this->storeProfileWritePlan($plan);
    }

    /** Read-only projection plan; verification is copied, never granted here. */
    public function planForPerson(int $personId): array
    {
        $rows = DB::select(
            "SELECT frf.id AS file_registry_face_id,
                    frf.file_registry_id,
                    frf.person_name,
                    frf.genealogy_person_id,
                    frf.verified,
                    frf.confidence,
                    frf.cluster_id,
                    fr.date_taken,
                    fr.date_taken_source,
                    fr.date_taken_precision,
                    fr.date_taken_confidence,
                    fr.date_taken_reasoning,
                    fr.is_scan,
                    fr.scan_date,
                    fr.scan_context,
                    fr.date_needs_review,
                    fr.date_extracted_at,
                    gp.tree_id,
                    gp.birth_date,
                    NULLIF(TRIM(CONCAT(COALESCE(gp.given_name, ''), ' ', COALESCE(gp.surname, ''))), '') AS display_name
             FROM file_registry_faces frf
             JOIN file_registry fr ON fr.id = frf.file_registry_id
             JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
             WHERE frf.genealogy_person_id = ?
               AND frf.hidden = 0
               AND frf.source_current = 1
               AND frf.superseded_at IS NULL
               AND fr.status = 'active'
             ORDER BY frf.verified DESC, frf.confidence DESC, frf.id ASC",
            [$personId]
        );

        $faceIds = array_map(fn (object $row): int => (int) $row->file_registry_face_id, $rows);

        return [
            ...$this->buildProfileWritePlan($rows, $this->loadFaceEmbeddingsByFaceIds($faceIds)),
            'identity_keys' => ['person:'.$personId],
        ];
    }

    public function rebuildForName(string $displayName): array
    {
        return $this->rebuildForNameVariants([$this->normalizeDisplayName($displayName)]);
    }

    /**
     * @param  list<string>  $displayNames
     */
    public function rebuildForNameVariants(array $displayNames): array
    {
        return $this->storeProfileWritePlan($this->planForNameVariants($displayNames));
    }

    public function planForName(string $displayName): array
    {
        return $this->planForNameVariants([$this->normalizeDisplayName($displayName)]);
    }

    /** Read-only projection plan preserving exact stored name variants. */
    public function planForNameVariants(array $displayNames): array
    {
        $displayNames = array_values(array_unique(array_filter(
            array_map(fn ($name): string => (string) $name, $displayNames),
            fn (string $name): bool => trim($name) !== ''
        )));

        if ($displayNames === []) {
            return ['profiles' => [], 'skipped' => 0, 'identity_keys' => []];
        }

        $canonicalDisplayName = $this->normalizeDisplayName($displayNames[0]);
        $identityKeys = array_values(array_unique(array_map(
            fn (string $name): string => 'name:'.$this->normalizeIdentityName($name),
            $displayNames
        )));
        $placeholders = implode(', ', array_fill(0, count($displayNames), '?'));
        $rows = DB::select(
            "SELECT frf.id AS file_registry_face_id,
                    frf.file_registry_id,
                    frf.person_name,
                    frf.genealogy_person_id,
                    frf.verified,
                    frf.confidence,
                    frf.cluster_id,
                    fr.date_taken,
                    fr.date_taken_source,
                    fr.date_taken_precision,
                    fr.date_taken_confidence,
                    fr.date_taken_reasoning,
                    fr.is_scan,
                    fr.scan_date,
                    fr.scan_context,
                    fr.date_needs_review,
                    fr.date_extracted_at,
                    NULL AS birth_date,
                    ? AS display_name
             FROM file_registry_faces frf
             JOIN file_registry fr ON fr.id = frf.file_registry_id
             WHERE frf.genealogy_person_id IS NULL
               AND frf.person_name IN ({$placeholders})
               AND frf.hidden = 0
               AND frf.source_current = 1
               AND frf.superseded_at IS NULL
               AND fr.status = 'active'
             ORDER BY frf.verified DESC, frf.confidence DESC, frf.id ASC",
            array_merge([$canonicalDisplayName], $displayNames)
        );

        $faceIds = array_map(fn (object $row): int => (int) $row->file_registry_face_id, $rows);

        return [
            ...$this->buildProfileWritePlan($rows, $this->loadFaceEmbeddingsByFaceIds($faceIds)),
            'identity_keys' => $identityKeys,
        ];
    }

    /**
     * @param  iterable<array<string,mixed>|object>  $rows
     */
    public function rebuildFromRows(iterable $rows): array
    {
        $rows = is_array($rows) ? array_values($rows) : iterator_to_array($rows, false);
        $faceIds = array_values(array_filter(array_map(
            fn (array|object $row): ?int => $this->nullableInt($this->value($row, 'file_registry_face_id')),
            $rows
        )));

        $plan = $this->buildProfileWritePlan($rows, $this->loadFaceEmbeddingsByFaceIds($faceIds));

        return $this->storeProfileWritePlan($plan);
    }

    /**
     * Builds a deterministic write plan without touching the database.
     *
     * @param  iterable<array<string,mixed>|object>  $rows
     * @param  array<int,array<string,mixed>|object>  $embeddingByFaceId
     * @return array{profiles:list<array<string,mixed>>,skipped:int}
     */
    public function buildProfileWritePlan(iterable $rows, array $embeddingByFaceId = []): array
    {
        $profiles = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $fileRegistryFaceId = $this->nullableInt($this->value($row, 'file_registry_face_id'));
            $fileRegistryId = $this->nullableInt($this->value($row, 'file_registry_id'));
            $identity = $this->identityPayload($row);

            if ($fileRegistryFaceId === null || $fileRegistryId === null || $identity === null) {
                $skipped++;

                continue;
            }

            $birthDate = $this->nullableString($this->value($row, 'birth_date'));
            $dateTaken = $this->value($row, 'date_taken');
            $dateHold = $this->photoDateHoldReason($row);
            $qualifiedDate = $dateHold === null ? $dateTaken : null;
            $ageAtPhoto = $this->deriveAgeAtPhoto($birthDate, $qualifiedDate);
            $ageBand = $this->deriveAgeBand($ageAtPhoto);
            [$ageMin, $ageMax] = $this->ageBandRange($ageBand);
            $embedding = $embeddingByFaceId[$fileRegistryFaceId] ?? null;
            $faceEmbeddingId = $this->nullableInt($this->value($embedding, 'face_embedding_id', $this->value($row, 'face_embedding_id')));
            $personClusterId = $this->nullableInt($this->value($embedding, 'person_cluster_id', $this->value($row, 'person_cluster_id', $this->value($row, 'cluster_id'))));
            $confidence = $this->nullableFloat($this->value($row, 'confidence', $this->value($embedding, 'quality_score')));
            $birthYear = $this->extractYear($birthDate);
            $photoDate = $this->formatPhotoDate($qualifiedDate);
            $evidenceSource = $this->ageEvidenceSource($birthDate, $qualifiedDate, $ageAtPhoto);
            if ($dateHold !== null && $birthDate !== null && trim($birthDate) !== '' && $dateTaken !== null) {
                $evidenceSource = 'unqualified_photo_date';
            }
            $verified = $this->truthy($this->value($row, 'verified')) ? 1 : 0;
            $dateEvidence = ['date_taken' => $dateTaken instanceof DateTimeInterface ? $dateTaken->format('Y-m-d H:i:s') : $dateTaken];
            foreach (['date_taken_source', 'date_taken_precision', 'date_taken_confidence', 'date_taken_reasoning', 'is_scan', 'scan_date', 'scan_context', 'date_needs_review', 'date_extracted_at'] as $field) {
                $dateEvidence[$field] = $this->value($row, $field);
            }
            $dateEvidence['qualified_for_age'] = $dateHold === null;
            $dateEvidence['hold_reason'] = $dateHold;

            $link = [
                'file_registry_face_id' => $fileRegistryFaceId,
                'file_registry_id' => $fileRegistryId,
                'face_embedding_id' => $faceEmbeddingId,
                'person_cluster_id' => $personClusterId,
                'age_at_photo' => $ageAtPhoto,
                'photo_date' => $photoDate,
                'birth_year' => $birthYear,
                'age_band' => $ageBand,
                'evidence_source' => $evidenceSource,
                'confidence' => $confidence,
                'verified' => $verified,
                'metadata' => array_filter([
                    'face_name' => $this->nullableString($this->value($row, 'person_name')),
                    'birth_date' => $birthDate,
                    'date_taken_source' => $this->nullableString($this->value($row, 'date_taken_source')),
                    'photo_date_evidence' => $dateEvidence,
                ], fn ($value): bool => $value !== null && $value !== ''),
            ];

            $profileKey = $identity['identity_key'].'|'.$ageBand;
            if (! isset($profiles[$profileKey])) {
                $profiles[$profileKey] = [
                    ...$identity,
                    'age_band' => $ageBand,
                    'age_min' => $ageMin,
                    'age_max' => $ageMax,
                    'representative_file_registry_face_id' => null,
                    'representative_face_embedding_id' => null,
                    'representative_person_cluster_id' => null,
                    'face_count' => 0,
                    'verified_face_count' => 0,
                    'confidence' => null,
                    'source' => 'derived_from_faces',
                    'metadata' => [
                        'age_at_photo_min' => null,
                        'age_at_photo_max' => null,
                        'evidence_sources' => [],
                    ],
                    'links' => [],
                    '_confidence_total' => 0.0,
                    '_confidence_count' => 0,
                    '_representative_score' => null,
                ];
            }

            $profiles[$profileKey]['links'][] = $link;
            $profiles[$profileKey]['face_count']++;
            $profiles[$profileKey]['verified_face_count'] += $verified;

            if ($confidence !== null) {
                $profiles[$profileKey]['_confidence_total'] += $confidence;
                $profiles[$profileKey]['_confidence_count']++;
            }

            if ($ageAtPhoto !== null) {
                $currentMin = $profiles[$profileKey]['metadata']['age_at_photo_min'];
                $currentMax = $profiles[$profileKey]['metadata']['age_at_photo_max'];
                $profiles[$profileKey]['metadata']['age_at_photo_min'] = $currentMin === null ? $ageAtPhoto : min($currentMin, $ageAtPhoto);
                $profiles[$profileKey]['metadata']['age_at_photo_max'] = $currentMax === null ? $ageAtPhoto : max($currentMax, $ageAtPhoto);
            }

            $profiles[$profileKey]['metadata']['evidence_sources'][$evidenceSource] = true;

            $representativeScore = $this->representativeScore($link);
            if ($profiles[$profileKey]['_representative_score'] === null || $representativeScore > $profiles[$profileKey]['_representative_score']) {
                $profiles[$profileKey]['_representative_score'] = $representativeScore;
                $profiles[$profileKey]['representative_file_registry_face_id'] = $fileRegistryFaceId;
                $profiles[$profileKey]['representative_face_embedding_id'] = $faceEmbeddingId;
                $profiles[$profileKey]['representative_person_cluster_id'] = $personClusterId;
            }
        }

        foreach ($profiles as &$profile) {
            $profile['confidence'] = $profile['_confidence_count'] > 0
                ? round($profile['_confidence_total'] / $profile['_confidence_count'], 2)
                : null;
            $profile['metadata']['evidence_sources'] = array_keys($profile['metadata']['evidence_sources']);
            unset($profile['_confidence_total'], $profile['_confidence_count'], $profile['_representative_score']);
        }
        unset($profile);

        ksort($profiles);

        return [
            'profiles' => array_values($profiles),
            'skipped' => $skipped,
        ];
    }

    /** Return existing owners outside a proposed projection scope without writing. */
    public function conflictingProfileOwners(array $plan): array
    {
        $profiles = $plan['profiles'] ?? [];
        $faceIds = array_values(array_unique(array_merge(...array_map(
            fn (array $profile): array => array_column($profile['links'], 'file_registry_face_id'),
            $profiles
        ))));
        if ($faceIds === []) {
            return [];
        }
        $identityKeys = array_values(array_unique(array_merge(
            $plan['identity_keys'] ?? [],
            array_map(fn (array $profile): string => (string) $profile['identity_key'], $profiles)
        )));
        $facePlaceholders = implode(', ', array_fill(0, count($faceIds), '?'));
        $identityPlaceholders = implode(', ', array_fill(0, count($identityKeys), '?'));
        $otherOwners = DB::select(
            "SELECT DISTINCT p.identity_key
             FROM person_face_profile_faces links
             JOIN person_face_appearance_profiles p ON p.id = links.profile_id
             WHERE links.file_registry_face_id IN ({$facePlaceholders})
               AND p.identity_key NOT IN ({$identityPlaceholders})
             ORDER BY p.identity_key",
            array_merge($faceIds, $identityKeys)
        );

        return array_map(fn (object $owner): string => $owner->identity_key, $otherOwners);
    }

    /**
     * Explicit identity_keys can include an empty projection in a reviewed refresh scope.
     *
     * @param  array{profiles:list<array<string,mixed>>,skipped:int,identity_keys?:list<string>}  $plan
     */
    public function storeProfileWritePlan(array $plan): array
    {
        $profiles = $plan['profiles'] ?? [];
        if ($profiles === [] && empty($plan['identity_keys'])) {
            return [
                'profiles_written' => 0,
                'links_written' => 0,
                'identities_rebuilt' => 0,
                'stale_profiles_deleted' => 0,
                'skipped' => (int) ($plan['skipped'] ?? 0),
            ];
        }

        $identityKeys = array_values(array_unique(array_merge(
            $plan['identity_keys'] ?? [],
            array_map(fn (array $profile): string => (string) $profile['identity_key'], $profiles)
        )));

        $profilesWritten = 0;
        $linksWritten = 0;
        $staleProfilesDeleted = 0;

        DB::transaction(function () use ($plan, $profiles, $identityKeys, &$profilesWritten, &$linksWritten, &$staleProfilesDeleted): void {
            if ($this->conflictingProfileOwners($plan) !== []) {
                throw new \RuntimeException('Appearance profile links belong to identities outside this refresh plan; review the affected projection scope first.');
            }
            $staleProfilesDeleted = $this->deleteProfilesForIdentityKeys($identityKeys);

            foreach ($profiles as $profile) {
                $profileId = $this->insertProfile($profile);
                $profilesWritten++;

                foreach ($profile['links'] as $link) {
                    $this->insertProfileFaceLink($profileId, $link);
                    $linksWritten++;
                }
            }
        });

        return [
            'profiles_written' => $profilesWritten,
            'links_written' => $linksWritten,
            'identities_rebuilt' => count($identityKeys),
            'stale_profiles_deleted' => $staleProfilesDeleted,
            'skipped' => (int) ($plan['skipped'] ?? 0),
        ];
    }

    public function deriveAgeBand(?int $ageAtPhoto): string
    {
        if ($ageAtPhoto === null || $ageAtPhoto < 0 || $ageAtPhoto > 125) {
            return self::AGE_BAND_UNKNOWN;
        }

        return match (true) {
            $ageAtPhoto <= 2 => self::AGE_BAND_BABY,
            $ageAtPhoto <= 12 => self::AGE_BAND_CHILD,
            $ageAtPhoto <= 19 => self::AGE_BAND_TEEN,
            $ageAtPhoto <= 64 => self::AGE_BAND_ADULT,
            default => self::AGE_BAND_SENIOR,
        };
    }

    /**
     * @return array{0:int|null,1:int|null}
     */
    public function ageBandRange(string $ageBand): array
    {
        return self::AGE_BAND_RANGES[$ageBand] ?? self::AGE_BAND_RANGES[self::AGE_BAND_UNKNOWN];
    }

    public function deriveAgeAtPhoto(?string $birthDate, DateTimeInterface|string|null $dateTaken): ?int
    {
        $birth = $this->parseBirthDate($birthDate);
        $photo = $this->parsePhotoDate($dateTaken);

        if ($birth === null || $photo === null) {
            return null;
        }

        $age = $photo['year'] - $birth['year'];
        if (isset($birth['month'], $birth['day'], $photo['month'], $photo['day'])
            && [$photo['month'], $photo['day']] < [$birth['month'], $birth['day']]) {
            $age--;
        }

        if ($age < 0 || $age > 125) {
            return null;
        }

        return $age;
    }

    private function deleteProfilesForIdentityKeys(array $identityKeys): int
    {
        if ($identityKeys === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($identityKeys), '?'));

        return DB::delete(
            "DELETE FROM person_face_appearance_profiles WHERE identity_key IN ({$placeholders})",
            $identityKeys
        );
    }

    private function insertProfile(array $profile): int
    {
        DB::statement(
            'INSERT INTO person_face_appearance_profiles
                (identity_key, identity_type, tree_id, genealogy_person_id, display_name, age_band,
                 age_min, age_max, representative_file_registry_face_id, representative_face_embedding_id,
                 representative_person_cluster_id, face_count, verified_face_count, confidence,
                 source, metadata, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                identity_type = VALUES(identity_type),
                tree_id = VALUES(tree_id),
                genealogy_person_id = VALUES(genealogy_person_id),
                display_name = VALUES(display_name),
                age_min = VALUES(age_min),
                age_max = VALUES(age_max),
                representative_file_registry_face_id = VALUES(representative_file_registry_face_id),
                representative_face_embedding_id = VALUES(representative_face_embedding_id),
                representative_person_cluster_id = VALUES(representative_person_cluster_id),
                face_count = VALUES(face_count),
                verified_face_count = VALUES(verified_face_count),
                confidence = VALUES(confidence),
                source = VALUES(source),
                metadata = VALUES(metadata),
                updated_at = NOW()',
            [
                $profile['identity_key'],
                $profile['identity_type'],
                $profile['tree_id'],
                $profile['genealogy_person_id'],
                $profile['display_name'],
                $profile['age_band'],
                $profile['age_min'],
                $profile['age_max'],
                $profile['representative_file_registry_face_id'],
                $profile['representative_face_embedding_id'],
                $profile['representative_person_cluster_id'],
                $profile['face_count'],
                $profile['verified_face_count'],
                $profile['confidence'],
                $profile['source'],
                $this->jsonOrNull($profile['metadata']),
            ]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    private function insertProfileFaceLink(int $profileId, array $link): void
    {
        DB::statement(
            'INSERT INTO person_face_profile_faces
                (profile_id, file_registry_face_id, file_registry_id, face_embedding_id,
                 person_cluster_id, age_at_photo, photo_date, birth_year, age_band,
                 evidence_source, confidence, verified, metadata, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                profile_id = VALUES(profile_id),
                file_registry_id = VALUES(file_registry_id),
                face_embedding_id = VALUES(face_embedding_id),
                person_cluster_id = VALUES(person_cluster_id),
                age_at_photo = VALUES(age_at_photo),
                photo_date = VALUES(photo_date),
                birth_year = VALUES(birth_year),
                age_band = VALUES(age_band),
                evidence_source = VALUES(evidence_source),
                confidence = VALUES(confidence),
                verified = VALUES(verified),
                metadata = VALUES(metadata),
                updated_at = NOW()',
            [
                $profileId,
                $link['file_registry_face_id'],
                $link['file_registry_id'],
                $link['face_embedding_id'],
                $link['person_cluster_id'],
                $link['age_at_photo'],
                $link['photo_date'],
                $link['birth_year'],
                $link['age_band'],
                $link['evidence_source'],
                $link['confidence'],
                $link['verified'],
                $this->jsonOrNull($link['metadata']),
            ]
        );
    }

    /**
     * @param  list<int>  $faceIds
     * @return array<int,object>
     */
    private function loadFaceEmbeddingsByFaceIds(array $faceIds): array
    {
        $faceIds = array_values(array_unique(array_filter($faceIds)));
        if ($faceIds === []) {
            return [];
        }

        $map = [];

        try {
            foreach (array_chunk($faceIds, 500) as $chunk) {
                $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
                $rows = DB::connection('pgsql_rag')->select(
                    "SELECT id AS face_embedding_id,
                            file_registry_face_id,
                            person_cluster_id,
                            quality_score
                     FROM face_embeddings
                     WHERE file_registry_face_id IN ({$placeholders})
                       AND source_current = TRUE
                       AND superseded_at IS NULL
                     ORDER BY id ASC",
                    $chunk
                );

                foreach ($rows as $row) {
                    $faceId = $this->nullableInt($row->file_registry_face_id ?? null);
                    if ($faceId !== null) {
                        $map[$faceId] = $row;
                    }
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $map;
    }

    /**
     * @return array{identity_key:string,identity_type:string,tree_id:int|null,genealogy_person_id:int|null,display_name:string}|null
     */
    private function identityPayload(array|object $row): ?array
    {
        $personId = $this->nullableInt($this->value($row, 'genealogy_person_id'));
        $treeId = $this->nullableInt($this->value($row, 'tree_id'));
        $displayName = $this->nullableString($this->value($row, 'display_name'));
        $personName = $this->nullableString($this->value($row, 'person_name'));

        if ($personId !== null) {
            return [
                'identity_key' => 'person:'.$personId,
                'identity_type' => 'genealogy_person',
                'tree_id' => $treeId,
                'genealogy_person_id' => $personId,
                'display_name' => $displayName ?: ($personName ?: 'Person #'.$personId),
            ];
        }

        if ($personName === null || $personName === '') {
            return null;
        }

        return [
            'identity_key' => 'name:'.$this->normalizeIdentityName($personName),
            'identity_type' => 'name',
            'tree_id' => null,
            'genealogy_person_id' => null,
            'display_name' => $displayName ?: $personName,
        ];
    }

    private function representativeScore(array $link): float
    {
        $score = 0.0;
        $score += ((int) $link['verified']) === 1 ? 100000.0 : 0.0;
        $score += $link['age_at_photo'] !== null ? 10000.0 : 0.0;
        $score += $link['face_embedding_id'] !== null ? 1000.0 : 0.0;
        $score += $link['confidence'] !== null ? (float) $link['confidence'] : 0.0;
        $score -= ((int) $link['file_registry_face_id']) / 1000000000;

        return $score;
    }

    private function photoDateHoldReason(mixed $row): ?string
    {
        $date = $this->value($row, 'date_taken');
        if ($date === null || $date === '') {
            return 'photo_date_missing';
        }
        if ($this->value($row, 'date_taken_precision') !== 'exact') {
            return 'photo_date_precision_unproven';
        }
        $source = $this->value($row, 'date_taken_source');
        if (! in_array($source, config('file_registry.profile_refresh.qualified_photo_date_sources', []), true)) {
            return 'photo_date_source_unqualified';
        }
        $confidence = $this->value($row, 'date_taken_confidence');
        if (! is_numeric($confidence) || ! is_finite((float) $confidence) || (float) $confidence <= 0 || (float) $confidence > 1) {
            return 'photo_date_confidence_unproven';
        }
        if (! in_array($this->value($row, 'date_needs_review'), [0, '0', false], true)) {
            return 'photo_date_review_pending';
        }
        if (! in_array($source, config('file_registry.profile_refresh.operator_photo_date_sources', []), true)
            && (! in_array($this->value($row, 'is_scan'), [0, '0', false], true)
                || $this->nullableString($this->value($row, 'scan_date')) !== null
                || $this->nullableString($this->value($row, 'scan_context')) !== null)) {
            return 'photo_date_scan_ingest_unproven';
        }
        $raw = $date instanceof DateTimeInterface ? $date->format('Y-m-d H:i:s') : (string) $date;
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?)?$/D', $raw, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || $this->parsePhotoDate($date) === null) {
            return 'photo_date_invalid';
        }

        return null;
    }

    private function ageEvidenceSource(?string $birthDate, mixed $dateTaken, ?int $ageAtPhoto): string
    {
        if ($ageAtPhoto !== null) {
            return 'birth_date_and_photo_date';
        }

        if ($birthDate === null || trim($birthDate) === '') {
            return 'missing_birth_date';
        }

        if ($dateTaken === null || (! $dateTaken instanceof DateTimeInterface && trim((string) $dateTaken) === '')) {
            return 'missing_photo_date';
        }

        return 'age_not_derivable';
    }

    private function normalizeIdentityName(string $name): string
    {
        return strtolower($this->normalizeDisplayName($name));
    }

    private function normalizeDisplayName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }

    private function formatPhotoDate(DateTimeInterface|string|null $dateTaken): ?string
    {
        $photo = $this->parsePhotoDate($dateTaken);

        return $photo['date'] ?? null;
    }

    /**
     * @return array{year:int,month?:int,day?:int}|null
     */
    private function parseBirthDate(?string $birthDate): ?array
    {
        $birthDate = trim((string) $birthDate);
        if ($birthDate === '') {
            return null;
        }

        if (preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $birthDate, $matches) === 1) {
            return [
                'year' => (int) $matches[1],
                'month' => (int) $matches[2],
                'day' => (int) $matches[3],
            ];
        }

        if (preg_match('/\b(\d{1,2})\s+([A-Za-z]{3,9})\s+(\d{4})\b/', $birthDate, $matches) === 1) {
            $month = $this->monthNumber($matches[2]);
            if ($month !== null) {
                return [
                    'year' => (int) $matches[3],
                    'month' => $month,
                    'day' => (int) $matches[1],
                ];
            }
        }

        if (preg_match('/\b(1[5-9]\d{2}|20\d{2}|21\d{2})\b/', $birthDate, $matches) === 1) {
            return ['year' => (int) $matches[1]];
        }

        return null;
    }

    /**
     * @return array{year:int,month:int,day:int,date:string}|null
     */
    private function parsePhotoDate(DateTimeInterface|string|null $dateTaken): ?array
    {
        if ($dateTaken === null || (! $dateTaken instanceof DateTimeInterface && trim((string) $dateTaken) === '')) {
            return null;
        }

        try {
            $date = $dateTaken instanceof DateTimeInterface
                ? CarbonImmutable::instance($dateTaken)
                : CarbonImmutable::parse((string) $dateTaken);
        } catch (Throwable) {
            return null;
        }

        return [
            'year' => (int) $date->format('Y'),
            'month' => (int) $date->format('n'),
            'day' => (int) $date->format('j'),
            'date' => $date->format('Y-m-d'),
        ];
    }

    private function extractYear(?string $value): ?int
    {
        $parts = $this->parseBirthDate($value);

        return $parts['year'] ?? null;
    }

    private function monthNumber(string $month): ?int
    {
        $months = [
            'jan' => 1,
            'january' => 1,
            'feb' => 2,
            'february' => 2,
            'mar' => 3,
            'march' => 3,
            'apr' => 4,
            'april' => 4,
            'may' => 5,
            'jun' => 6,
            'june' => 6,
            'jul' => 7,
            'july' => 7,
            'aug' => 8,
            'august' => 8,
            'sep' => 9,
            'sept' => 9,
            'september' => 9,
            'oct' => 10,
            'october' => 10,
            'nov' => 11,
            'november' => 11,
            'dec' => 12,
            'december' => 12,
        ];

        return $months[strtolower($month)] ?? null;
    }

    private function value(array|object|null $row, string $key, mixed $default = null): mixed
    {
        if ($row === null) {
            return $default;
        }

        if (is_array($row)) {
            return array_key_exists($key, $row) ? $row[$key] : $default;
        }

        return property_exists($row, $key) ? $row->{$key} : $default;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private function jsonOrNull(array $payload): ?string
    {
        return $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
