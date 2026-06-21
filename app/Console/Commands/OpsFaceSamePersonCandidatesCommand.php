<?php

namespace App\Console\Commands;

use App\Support\PgVector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OpsFaceSamePersonCandidatesCommand extends Command
{
    private const TRUSTED_STATUSES = [
        'export_exact',
        'copy_export_exact',
        'export_variant_supported',
        'copy_export_variant_supported',
    ];

    protected $signature = 'ops:face-same-person-candidates
        {--tree=4 : Genealogy tree id}
        {--person-id= : Target genealogy person id}
        {--limit= : Candidate limit, falling back to faces.same_person_candidate_limit}
        {--seed-limit= : Trusted seed embedding limit, falling back to faces.same_person_seed_limit}
        {--scan-per-seed= : Similarity rows to inspect per seed, falling back to faces.same_person_scan_per_seed}
        {--min-similarity= : Review threshold, falling back to faces.same_person_min_similarity}
        {--strong-similarity= : Strong threshold, falling back to faces.same_person_strong_similarity}
        {--exemplar-similarity= : Multi-seed exemplar threshold, falling back to faces.same_person_exemplar_similarity}
        {--age-band= : Optional candidate age band filter: baby, child, teen, adult, senior, unknown}
        {--include-linked : Include candidates already linked to another genealogy person}
        {--include-inactive : Include non-active file_registry rows}
        {--json : Emit machine-readable JSON}
        {--compact : Omit candidate rows from JSON output}
        {--dry-run : Validate command shape without querying row data}';

    protected $description = 'Read-only visual same-person face candidate search from provenance-trusted seeds';

    public function handle(): int
    {
        $treeId = max(1, (int) $this->option('tree'));
        $personId = max(0, (int) $this->option('person-id'));
        $limit = $this->positiveIntOption('limit', $this->configInt('same_person_candidate_limit', 50), 1, 500);
        $seedLimit = $this->positiveIntOption('seed-limit', $this->configInt('same_person_seed_limit', 50), 1, 500);
        $scanPerSeed = $this->positiveIntOption('scan-per-seed', $this->configInt('same_person_scan_per_seed', max($limit * 4, 100)), 1, 1000);
        $minSimilarity = $this->floatOption('min-similarity', $this->configFloat('same_person_min_similarity', 0.82), 0.0, 1.0);
        $strongSimilarity = $this->floatOption('strong-similarity', $this->configFloat('same_person_strong_similarity', 0.92), 0.0, 1.0);
        $exemplarSimilarity = $this->floatOption('exemplar-similarity', $this->configFloat('same_person_exemplar_similarity', 0.90), 0.0, 1.0);
        $ageBand = $this->cleanOptionString($this->option('age-band'));
        $includeLinked = (bool) $this->option('include-linked');
        $includeInactive = (bool) $this->option('include-inactive');

        if ($personId < 1) {
            return $this->failPayload('person_id_required');
        }

        if ($strongSimilarity < $minSimilarity) {
            return $this->failPayload('strong_similarity_below_min_similarity');
        }

        if ($exemplarSimilarity < $minSimilarity) {
            return $this->failPayload('exemplar_similarity_below_min_similarity');
        }

        if ($ageBand !== null && ! in_array($ageBand, ['baby', 'child', 'teen', 'adult', 'senior', 'unknown'], true)) {
            return $this->failPayload('invalid_age_band');
        }

        $payload = $this->basePayload(
            $treeId,
            $personId,
            $limit,
            $seedLimit,
            $scanPerSeed,
            $minSimilarity,
            $strongSimilarity,
            $exemplarSimilarity,
            $ageBand,
            $includeLinked,
            $includeInactive
        );

        if ((bool) $this->option('dry-run')) {
            $payload['dry_run'] = true;
            $payload['summary']['query_would_run'] = false;
            $payload['summary']['candidate_count'] = 0;
            $payload['candidates'] = [];
            $this->renderPayload($payload);

            return self::SUCCESS;
        }

        $person = $this->loadPerson($treeId, $personId);
        if ($person === null) {
            $payload['mode'] = 'error';
            $payload['error'] = 'person_not_found';
            $this->renderPayload($payload);

            return self::FAILURE;
        }

        $payload['target_person'] = $this->formatPerson($person);
        $seedRows = $this->loadSeedRows($treeId, $personId, $seedLimit, $includeInactive);
        $payload['summary']['trusted_seed_faces'] = count($seedRows);

        if ($seedRows === []) {
            $payload['summary']['query_would_run'] = true;
            $payload['summary']['blocked_reason'] = 'insufficient_seed';
            $payload['summary']['candidate_count'] = 0;
            $payload['candidates'] = [];
            $this->renderPayload($payload);

            return self::SUCCESS;
        }

        $seedFaceIds = array_map(fn (object $row): int => (int) $row->face_id, $seedRows);
        $seedEmbeddings = $this->loadSeedEmbeddings($seedFaceIds);
        $payload['summary']['seed_embeddings'] = count($seedEmbeddings);

        if ($seedEmbeddings === []) {
            $payload['summary']['query_would_run'] = true;
            $payload['summary']['blocked_reason'] = 'no_seed_embeddings';
            $payload['summary']['candidate_count'] = 0;
            $payload['candidates'] = [];
            $this->renderPayload($payload);

            return self::SUCCESS;
        }

        $rawMatches = $this->searchMatches($seedEmbeddings, $minSimilarity, $strongSimilarity, $scanPerSeed);
        $payload['summary']['raw_visual_matches'] = count($rawMatches);

        $candidateFaceIds = array_values(array_diff(array_keys($rawMatches), $seedFaceIds));
        if ($candidateFaceIds === []) {
            $payload['summary']['candidate_count'] = 0;
            $payload['candidates'] = [];
            $this->renderPayload($payload);

            return self::SUCCESS;
        }

        $candidateRows = $this->loadCandidateRows($treeId, $candidateFaceIds);
        $mixedClusterIds = $this->mixedClusterIds(
            array_values(array_unique(array_filter(array_map(
                fn (object $row): ?int => $row->cluster_id !== null ? (int) $row->cluster_id : null,
                $candidateRows
            )))),
            $includeInactive
        );

        $scored = [];
        $suppressed = [
            'seed_faces' => 0,
            'hidden' => 0,
            'inactive' => 0,
            'target_linked' => 0,
            'other_linked' => 0,
            'age_band' => 0,
        ];

        foreach ($candidateRows as $row) {
            $faceId = (int) $row->face_id;
            if (in_array($faceId, $seedFaceIds, true)) {
                $suppressed['seed_faces']++;

                continue;
            }

            if ((int) ($row->hidden ?? 0) === 1) {
                $suppressed['hidden']++;

                continue;
            }

            if (! $includeInactive && (string) ($row->file_status ?? '') !== 'active') {
                $suppressed['inactive']++;

                continue;
            }

            $linkedPersonId = $row->genealogy_person_id !== null ? (int) $row->genealogy_person_id : null;
            if ($linkedPersonId === $personId) {
                $suppressed['target_linked']++;

                continue;
            }

            if (! $includeLinked && $linkedPersonId !== null) {
                $suppressed['other_linked']++;

                continue;
            }

            $ageContext = $this->ageContext($person->birth_date ?? null, $row->date_taken ?? null);
            if ($ageBand !== null && ($ageContext['age_band'] ?? 'unknown') !== $ageBand) {
                $suppressed['age_band']++;

                continue;
            }

            $candidate = $this->formatCandidate(
                $row,
                $rawMatches[$faceId],
                $ageContext,
                $personId,
                $mixedClusterIds,
                $strongSimilarity,
                $exemplarSimilarity
            );
            $scored[] = $candidate;
        }

        usort($scored, fn (array $a, array $b): int => $this->compareCandidates($a, $b));

        $scored = array_slice($scored, 0, $limit);
        $payload['summary']['candidate_count'] = count($scored);
        $payload['summary']['suppressed'] = $suppressed;
        $payload['summary']['bucket_counts'] = $this->bucketCounts($scored);
        $payload['summary']['query_would_run'] = true;
        $payload['candidates'] = $scored;

        if ((bool) $this->option('compact')) {
            unset($payload['candidates']);
            $payload['compact'] = true;
            $payload['posture']['candidate_rows_included'] = false;
        }

        $this->renderPayload($payload);

        return self::SUCCESS;
    }

    private function basePayload(
        int $treeId,
        int $personId,
        int $limit,
        int $seedLimit,
        int $scanPerSeed,
        float $minSimilarity,
        float $strongSimilarity,
        float $exemplarSimilarity,
        ?string $ageBand,
        bool $includeLinked,
        bool $includeInactive
    ): array {
        return [
            'command' => 'ops:face-same-person-candidates',
            'mode' => 'observe',
            'dry_run' => false,
            'read_only' => true,
            'mutation_allowed' => false,
            'captured_at' => now()->toIso8601String(),
            'filters' => [
                'tree_id' => $treeId,
                'person_id' => $personId,
                'limit' => $limit,
                'seed_limit' => $seedLimit,
                'scan_per_seed' => $scanPerSeed,
                'min_similarity' => $minSimilarity,
                'strong_similarity' => $strongSimilarity,
                'exemplar_similarity' => $exemplarSimilarity,
                'age_band' => $ageBand,
                'include_linked' => $includeLinked,
                'active_only' => ! $includeInactive,
            ],
            'posture' => [
                'clean_room_reference_only' => true,
                'read_only' => true,
                'operator_review_required' => true,
                'automatic_link_allowed' => false,
                'cluster_merge_allowed' => false,
                'create_person_allowed' => false,
                'metadata_writeback_allowed' => false,
                'candidate_rows_included' => ! (bool) $this->option('compact'),
                'trusted_seed_statuses' => self::TRUSTED_STATUSES,
            ],
            'target_person' => null,
            'summary' => [
                'query_would_run' => true,
                'trusted_seed_faces' => 0,
                'seed_embeddings' => 0,
                'raw_visual_matches' => 0,
                'candidate_count' => 0,
                'bucket_counts' => [],
                'suppressed' => [],
            ],
            'candidates' => [],
        ];
    }

    private function loadPerson(int $treeId, int $personId): ?object
    {
        return DB::selectOne(
            'SELECT id, tree_id, given_name, surname, suffix, nickname, birth_date
             FROM genealogy_persons
             WHERE id = ? AND tree_id = ?
             LIMIT 1',
            [$personId, $treeId]
        );
    }

    /**
     * @return list<object>
     */
    private function loadSeedRows(int $treeId, int $personId, int $limit, bool $includeInactive): array
    {
        $trustedPlaceholders = implode(',', array_fill(0, count(self::TRUSTED_STATUSES), '?'));
        $activeFilter = $includeInactive ? '' : "AND fr.status = 'active'";

        return DB::select(
            "SELECT
                frf.id AS face_id,
                frf.file_registry_id,
                frf.person_name,
                frf.genealogy_person_id,
                frf.cluster_id,
                frf.confidence,
                frf.verified,
                fr.date_taken,
                fr.filename,
                fip.status AS provenance_status,
                fip.evidence_source,
                fip.matched_export_name,
                fip.matched_variant_name
             FROM face_identity_provenance fip
             JOIN file_registry_faces frf ON frf.id = fip.file_registry_face_id
             JOIN file_registry fr ON fr.id = frf.file_registry_id
             WHERE fip.tree_id = ?
               AND fip.genealogy_person_id = ?
               AND fip.status IN ({$trustedPlaceholders})
               AND frf.hidden = 0
               {$activeFilter}
             ORDER BY frf.verified DESC,
                      CASE WHEN fr.date_taken IS NULL THEN 1 ELSE 0 END ASC,
                      frf.confidence DESC,
                      frf.id ASC
             LIMIT ?",
            array_merge([$treeId, $personId], self::TRUSTED_STATUSES, [$limit])
        );
    }

    /**
     * @param  list<int>  $faceIds
     * @return list<array<string,mixed>>
     */
    private function loadSeedEmbeddings(array $faceIds): array
    {
        if ($faceIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($faceIds), '?'));
        $rows = DB::connection('pgsql_rag')->select(
            "SELECT id AS embedding_id,
                    file_registry_face_id,
                    file_registry_id,
                    person_cluster_id,
                    embedding::text AS embedding_text,
                    quality_score
             FROM face_embeddings
             WHERE file_registry_face_id IN ({$placeholders})
             ORDER BY quality_score DESC NULLS LAST, id ASC",
            $faceIds
        );

        $embeddings = [];
        foreach ($rows as $row) {
            $vector = $this->parseVector((string) ($row->embedding_text ?? ''));
            if (count($vector) !== 128) {
                continue;
            }

            $embeddings[] = [
                'embedding_id' => (int) $row->embedding_id,
                'face_id' => (int) $row->file_registry_face_id,
                'file_registry_id' => (int) $row->file_registry_id,
                'person_cluster_id' => $row->person_cluster_id !== null ? (int) $row->person_cluster_id : null,
                'embedding' => $vector,
                'embedding_literal' => PgVector::literal($vector, 6),
                'quality_score' => $row->quality_score !== null ? (float) $row->quality_score : null,
            ];
        }

        return $embeddings;
    }

    /**
     * @param  list<array<string,mixed>>  $seedEmbeddings
     * @return array<int,array<string,mixed>>
     */
    private function searchMatches(array $seedEmbeddings, float $minSimilarity, float $strongSimilarity, int $scanPerSeed): array
    {
        $matches = [];
        $centroid = $this->centroid(array_map(fn (array $seed): array => $seed['embedding'], $seedEmbeddings));
        if (count($centroid) === 128) {
            $this->mergeVisualMatches(
                $matches,
                $this->searchByVector(PgVector::literal($centroid, 6), $minSimilarity, $scanPerSeed),
                null,
                true,
                $strongSimilarity
            );
        }

        foreach ($seedEmbeddings as $seed) {
            $rows = $this->searchByVector((string) $seed['embedding_literal'], $minSimilarity, $scanPerSeed);
            $this->mergeVisualMatches($matches, $rows, (int) $seed['face_id'], false, $strongSimilarity);
        }

        return $matches;
    }

    /**
     * @return list<object>
     */
    private function searchByVector(string $embeddingLiteral, float $minSimilarity, int $limit): array
    {
        return DB::connection('pgsql_rag')->select(
            'SELECT
                fe.id AS candidate_embedding_id,
                fe.file_registry_face_id AS face_id,
                fe.file_registry_id,
                fe.person_cluster_id,
                fe.crop_path,
                fe.quality_score,
                1 - (fe.embedding <=> ?::vector) AS similarity
             FROM face_embeddings fe
             WHERE fe.file_registry_face_id IS NOT NULL
               AND 1 - (fe.embedding <=> ?::vector) >= ?
             ORDER BY fe.embedding <=> ?::vector
             LIMIT ?',
            [$embeddingLiteral, $embeddingLiteral, $minSimilarity, $embeddingLiteral, $limit]
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $matches
     * @param  list<object>  $rows
     */
    private function mergeVisualMatches(array &$matches, array $rows, ?int $seedFaceId, bool $centroid, float $strongSimilarity): void
    {
        foreach ($rows as $row) {
            $faceId = (int) ($row->face_id ?? 0);
            if ($faceId < 1) {
                continue;
            }

            $similarity = round((float) ($row->similarity ?? 0), 6);
            if (! isset($matches[$faceId])) {
                $matches[$faceId] = [
                    'face_id' => $faceId,
                    'candidate_embedding_id' => (int) $row->candidate_embedding_id,
                    'file_registry_id' => (int) $row->file_registry_id,
                    'person_cluster_id' => $row->person_cluster_id !== null ? (int) $row->person_cluster_id : null,
                    'crop_path' => $row->crop_path ?? null,
                    'quality_score' => $row->quality_score !== null ? (float) $row->quality_score : null,
                    'best_similarity' => $similarity,
                    'best_seed_similarity' => null,
                    'centroid_similarity' => null,
                    'seed_match_count' => 0,
                    'strong_seed_match_count' => 0,
                    'best_seed_face_id' => null,
                    'matched_seed_face_ids' => [],
                ];
            }

            if ($centroid) {
                $matches[$faceId]['centroid_similarity'] = max(
                    (float) ($matches[$faceId]['centroid_similarity'] ?? 0),
                    $similarity
                );
            } elseif ($seedFaceId !== null && ! in_array($seedFaceId, $matches[$faceId]['matched_seed_face_ids'], true)) {
                $matches[$faceId]['seed_match_count']++;
                $matches[$faceId]['matched_seed_face_ids'][] = $seedFaceId;
                $matches[$faceId]['best_seed_similarity'] = max(
                    (float) ($matches[$faceId]['best_seed_similarity'] ?? 0),
                    $similarity
                );
                if ($similarity >= $strongSimilarity) {
                    $matches[$faceId]['strong_seed_match_count']++;
                }
            }

            if ($similarity > (float) $matches[$faceId]['best_similarity']) {
                $matches[$faceId]['best_similarity'] = $similarity;
                $matches[$faceId]['candidate_embedding_id'] = (int) $row->candidate_embedding_id;
                $matches[$faceId]['person_cluster_id'] = $row->person_cluster_id !== null ? (int) $row->person_cluster_id : null;
                $matches[$faceId]['crop_path'] = $row->crop_path ?? null;
                $matches[$faceId]['quality_score'] = $row->quality_score !== null ? (float) $row->quality_score : null;
                if (! $centroid) {
                    $matches[$faceId]['best_seed_face_id'] = $seedFaceId;
                }
            }
        }
    }

    /**
     * @param  list<int>  $faceIds
     * @return array<int,object>
     */
    private function loadCandidateRows(int $treeId, array $faceIds): array
    {
        if ($faceIds === []) {
            return [];
        }

        $rowsById = [];
        foreach (array_chunk($faceIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = DB::select(
                "SELECT
                    frf.id AS face_id,
                    frf.file_registry_id,
                    frf.person_name,
                    frf.genealogy_person_id,
                    frf.cluster_id,
                    frf.confidence,
                    frf.source,
                    frf.verified,
                    frf.hidden,
                    frf.region_x,
                    frf.region_y,
                    frf.region_w,
                    frf.region_h,
                    fr.asset_uuid,
                    fr.filename,
                    fr.status AS file_status,
                    fr.date_taken,
                    fip.status AS provenance_status,
                    fip.evidence_source,
                    fip.canonical_name,
                    fip.matched_export_name,
                    fip.matched_variant_name
                 FROM file_registry_faces frf
                 JOIN file_registry fr ON fr.id = frf.file_registry_id
                 LEFT JOIN face_identity_provenance fip
                   ON fip.file_registry_face_id = frf.id
                  AND fip.tree_id = ?
                 WHERE frf.id IN ({$placeholders})",
                array_merge([$treeId], $chunk)
            );

            foreach ($rows as $row) {
                $rowsById[(int) $row->face_id] = $row;
            }
        }

        return $rowsById;
    }

    /**
     * @param  list<int>  $clusterIds
     * @return array<int,bool>
     */
    private function mixedClusterIds(array $clusterIds, bool $includeInactive): array
    {
        if ($clusterIds === []) {
            return [];
        }

        $activeFilter = $includeInactive ? '' : "AND fr.status = 'active'";
        $mixed = [];

        foreach (array_chunk($clusterIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = DB::select(
                "SELECT frf.cluster_id
                 FROM file_registry_faces frf
                 JOIN file_registry fr ON fr.id = frf.file_registry_id
                 WHERE frf.hidden = 0
                   AND frf.cluster_id IN ({$placeholders})
                   AND NULLIF(TRIM(frf.person_name), '') IS NOT NULL
                   AND LOWER(TRIM(frf.person_name)) != 'unknown'
                   {$activeFilter}
                 GROUP BY frf.cluster_id
                 HAVING COUNT(DISTINCT LOWER(TRIM(frf.person_name))) > 1",
                $chunk
            );

            foreach ($rows as $row) {
                $mixed[(int) $row->cluster_id] = true;
            }
        }

        return $mixed;
    }

    private function formatCandidate(
        object $row,
        array $match,
        array $ageContext,
        int $targetPersonId,
        array $mixedClusterIds,
        float $strongSimilarity,
        float $exemplarSimilarity
    ): array {
        $clusterId = $row->cluster_id !== null ? (int) $row->cluster_id : null;
        $provenanceStatus = $row->provenance_status !== null ? (string) $row->provenance_status : null;
        $linkedPersonId = $row->genealogy_person_id !== null ? (int) $row->genealogy_person_id : null;
        $bucket = $this->candidateBucket(
            $match,
            $ageContext,
            $provenanceStatus,
            $linkedPersonId,
            $targetPersonId,
            $clusterId !== null && isset($mixedClusterIds[$clusterId]),
            $strongSimilarity,
            $exemplarSimilarity
        );

        $reasons = $this->candidateReasons(
            $bucket,
            $match,
            $ageContext,
            $provenanceStatus,
            $linkedPersonId,
            $targetPersonId,
            $clusterId !== null && isset($mixedClusterIds[$clusterId]),
            $strongSimilarity,
            $exemplarSimilarity
        );

        $sortScore = match ($bucket) {
            'strong_visual_candidate' => 500,
            'visual_plus_context_candidate' => 400,
            'age_band_mismatch_review' => 250,
            'mixed_cluster_blocked' => 200,
            'trusted_person_conflict' => 100,
            'export_conflict' => 50,
            default => 0,
        };

        return [
            'face_id' => (int) $row->face_id,
            'file_registry_id' => (int) $row->file_registry_id,
            'candidate_embedding_id' => (int) $match['candidate_embedding_id'],
            'person_cluster_id' => $match['person_cluster_id'],
            'bucket' => $bucket,
            'reasons' => $reasons,
            'best_similarity' => round((float) $match['best_similarity'], 4),
            'best_seed_similarity' => $match['best_seed_similarity'] !== null ? round((float) $match['best_seed_similarity'], 4) : null,
            'centroid_similarity' => $match['centroid_similarity'] !== null ? round((float) $match['centroid_similarity'], 4) : null,
            'seed_match_count' => (int) $match['seed_match_count'],
            'strong_seed_match_count' => (int) $match['strong_seed_match_count'],
            'best_seed_face_id' => $match['best_seed_face_id'],
            'quality_score' => $match['quality_score'],
            'crop_path' => $match['crop_path'],
            'person_name' => $this->nullableString($row->person_name ?? null),
            'linked_person_id' => $linkedPersonId,
            'provenance_status' => $provenanceStatus,
            'provenance_evidence_source' => $this->nullableString($row->evidence_source ?? null),
            'matched_export_name' => $this->nullableString($row->matched_export_name ?? null),
            'matched_variant_name' => $this->nullableString($row->matched_variant_name ?? null),
            'source' => $this->nullableString($row->source ?? null),
            'verified' => (int) ($row->verified ?? 0) === 1,
            'file_status' => (string) ($row->file_status ?? ''),
            'filename' => $this->nullableString($row->filename ?? null),
            'date_taken' => $this->nullableString($row->date_taken ?? null),
            'age_context' => $ageContext,
            'review_posture' => [
                'operator_review_required' => true,
                'automatic_link_allowed' => false,
                'metadata_writeback_allowed' => false,
            ],
            'sort_score' => $sortScore,
        ];
    }

    private function candidateBucket(
        array $match,
        array $ageContext,
        ?string $provenanceStatus,
        ?int $linkedPersonId,
        int $targetPersonId,
        bool $mixedCluster,
        float $strongSimilarity,
        float $exemplarSimilarity
    ): string {
        if ($linkedPersonId !== null && $linkedPersonId !== $targetPersonId && in_array($provenanceStatus, self::TRUSTED_STATUSES, true)) {
            return 'trusted_person_conflict';
        }

        if ($provenanceStatus !== null && in_array($provenanceStatus, ['export_exact_person_conflict', 'linked_person_differs_from_export'], true)) {
            return 'export_conflict';
        }

        if ($mixedCluster) {
            return 'mixed_cluster_blocked';
        }

        if (($ageContext['age_plausible'] ?? null) === false) {
            return 'age_band_mismatch_review';
        }

        $centroid = $match['centroid_similarity'] !== null ? (float) $match['centroid_similarity'] : 0.0;
        $bestSeed = $match['best_seed_similarity'] !== null ? (float) $match['best_seed_similarity'] : 0.0;
        $strongSeedMatches = (int) $match['strong_seed_match_count'];
        $seedMatches = (int) $match['seed_match_count'];

        if ($centroid >= $strongSimilarity || ($bestSeed >= $strongSimilarity && $strongSeedMatches >= 1 && $seedMatches >= 2)) {
            return 'strong_visual_candidate';
        }

        if ($bestSeed >= $exemplarSimilarity || $seedMatches >= 2) {
            return 'visual_plus_context_candidate';
        }

        return 'visual_plus_context_candidate';
    }

    private function candidateReasons(
        string $bucket,
        array $match,
        array $ageContext,
        ?string $provenanceStatus,
        ?int $linkedPersonId,
        int $targetPersonId,
        bool $mixedCluster,
        float $strongSimilarity,
        float $exemplarSimilarity
    ): array {
        $reasons = [$bucket];
        $bestSeed = $match['best_seed_similarity'] !== null ? (float) $match['best_seed_similarity'] : 0.0;
        $centroid = $match['centroid_similarity'] !== null ? (float) $match['centroid_similarity'] : null;

        if ($centroid !== null && $centroid >= $strongSimilarity) {
            $reasons[] = 'centroid_similarity_above_strong_threshold';
        }
        if ($bestSeed >= $strongSimilarity) {
            $reasons[] = 'best_seed_similarity_above_strong_threshold';
        } elseif ($bestSeed >= $exemplarSimilarity) {
            $reasons[] = 'best_seed_similarity_above_exemplar_threshold';
        }
        if ((int) $match['seed_match_count'] >= 2) {
            $reasons[] = 'multiple_seed_exemplars_matched';
        }
        if ($provenanceStatus !== null) {
            $reasons[] = 'provenance_status_'.$provenanceStatus;
        }
        if ($linkedPersonId !== null && $linkedPersonId !== $targetPersonId) {
            $reasons[] = 'linked_to_different_genealogy_person';
        }
        if ($mixedCluster) {
            $reasons[] = 'candidate_cluster_has_multiple_names';
        }
        if (($ageContext['age_plausible'] ?? null) === false) {
            $reasons[] = 'age_at_photo_not_plausible_for_target_person';
        }

        return array_values(array_unique($reasons));
    }

    private function formatPerson(object $person): array
    {
        return [
            'person_id' => (int) $person->id,
            'tree_id' => (int) $person->tree_id,
            'display_name' => trim(implode(' ', array_filter([
                $person->given_name ?? null,
                $person->surname ?? null,
                $person->suffix ?? null,
            ], fn ($part): bool => trim((string) $part) !== ''))) ?: 'Person #'.$person->id,
            'nickname' => $this->nullableString($person->nickname ?? null),
            'birth_date' => $this->nullableString($person->birth_date ?? null),
        ];
    }

    private function ageContext(mixed $birthDate, mixed $dateTaken): array
    {
        $birthYear = $this->yearFromValue($birthDate);
        $photoYear = $this->yearFromValue($dateTaken);
        $age = ($birthYear !== null && $photoYear !== null) ? $photoYear - $birthYear : null;

        return [
            'birth_year' => $birthYear,
            'photo_year' => $photoYear,
            'age_at_photo' => $age,
            'age_band' => $this->ageBand($age),
            'age_plausible' => $age === null ? null : ($age >= 0 && $age <= 125),
        ];
    }

    private function ageBand(?int $age): string
    {
        if ($age === null || $age < 0 || $age > 125) {
            return 'unknown';
        }

        return match (true) {
            $age <= 2 => 'baby',
            $age <= 12 => 'child',
            $age <= 19 => 'teen',
            $age <= 64 => 'adult',
            default => 'senior',
        };
    }

    private function yearFromValue(mixed $value): ?int
    {
        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        if (preg_match('/\b(1[5-9][0-9]{2}|20[0-9]{2}|21[0-9]{2})\b/', $string, $matches) !== 1) {
            return null;
        }

        $year = (int) $matches[1];

        return $year >= 1500 && $year <= 2199 ? $year : null;
    }

    /**
     * @param  list<array<int,float>>  $embeddings
     * @return list<float>
     */
    private function centroid(array $embeddings): array
    {
        if ($embeddings === []) {
            return [];
        }

        $dimensions = count($embeddings[0]);
        $sums = array_fill(0, $dimensions, 0.0);
        $count = 0;

        foreach ($embeddings as $embedding) {
            if (count($embedding) !== $dimensions) {
                continue;
            }
            foreach ($embedding as $index => $value) {
                $sums[$index] += (float) $value;
            }
            $count++;
        }

        if ($count === 0) {
            return [];
        }

        return array_map(fn (float $sum): float => $sum / $count, $sums);
    }

    /**
     * @return list<float>
     */
    private function parseVector(string $value): array
    {
        $trimmed = trim($value, "[] \t\n\r\0\x0B");
        if ($trimmed === '') {
            return [];
        }

        return array_map('floatval', explode(',', $trimmed));
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,int>
     */
    private function bucketCounts(array $candidates): array
    {
        $counts = [];
        foreach ($candidates as $candidate) {
            $bucket = (string) ($candidate['bucket'] ?? 'unknown');
            $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    private function compareCandidates(array $a, array $b): int
    {
        return ((int) $b['sort_score'] <=> (int) $a['sort_score'])
            ?: ((float) $b['best_similarity'] <=> (float) $a['best_similarity'])
            ?: ((int) $b['seed_match_count'] <=> (int) $a['seed_match_count'])
            ?: ((int) $a['face_id'] <=> (int) $b['face_id']);
    }

    private function positiveIntOption(string $option, int $default, int $min, int $max): int
    {
        $raw = $this->option($option);
        if ($raw === null || $raw === '') {
            return max($min, min($max, $default));
        }

        return max($min, min($max, (int) $raw));
    }

    private function floatOption(string $option, float $default, float $min, float $max): float
    {
        $raw = $this->option($option);
        if ($raw === null || $raw === '') {
            return max($min, min($max, $default));
        }

        return max($min, min($max, (float) $raw));
    }

    private function configInt(string $key, int $default): int
    {
        $value = $this->configValue($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function configFloat(string $key, float $default): float
    {
        $value = $this->configValue($key);

        return is_numeric($value) ? (float) $value : $default;
    }

    private function configValue(string $key): ?string
    {
        try {
            $row = DB::selectOne(
                'SELECT config_value FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
                ['faces', $key]
            );

            return $row !== null ? (string) $row->config_value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function cleanOptionString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : strtolower($trimmed);
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function failPayload(string $error): int
    {
        $this->renderPayload([
            'command' => 'ops:face-same-person-candidates',
            'mode' => 'error',
            'error' => $error,
            'read_only' => true,
            'mutation_allowed' => false,
        ]);

        return self::FAILURE;
    }

    private function renderPayload(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line(sprintf(
            'face-same-person-candidates: mode=%s seeds=%d embeddings=%d candidates=%d',
            $payload['mode'] ?? 'unknown',
            (int) ($payload['summary']['trusted_seed_faces'] ?? 0),
            (int) ($payload['summary']['seed_embeddings'] ?? 0),
            (int) ($payload['summary']['candidate_count'] ?? 0)
        ));
    }
}
