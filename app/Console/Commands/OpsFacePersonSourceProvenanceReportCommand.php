<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OpsFacePersonSourceProvenanceReportCommand extends Command
{
    private const TRUSTED_STATUSES = [
        'export_exact',
        'copy_export_exact',
        'export_variant_supported',
        'copy_export_variant_supported',
    ];

    private const CONFLICT_STATUSES = [
        'export_exact_person_conflict',
        'linked_person_differs_from_export',
    ];

    private const COVERAGE_VISIBLE_UNNAMED_UNLINKED_FACE = 'coverage_visible_unnamed_unlinked_face';
    private const COVERAGE_UNLINKED_PHOTO_MEDIA = 'coverage_unlinked_photo_media';
    private const COVERAGE_STATUSES = [
        self::COVERAGE_VISIBLE_UNNAMED_UNLINKED_FACE,
        self::COVERAGE_UNLINKED_PHOTO_MEDIA,
    ];

    protected $signature = 'ops:face-person-source-provenance-report
        {--tree=4 : Genealogy tree id}
        {--person-id= : Optional person id filter}
        {--source-id= : Optional source id filter}
        {--status= : Optional exact review status bucket filter}
        {--limit=25 : Maximum sample rows and rollups to include}
        {--offset=0 : Sample row offset after filtering}
        {--json : Emit machine-readable JSON}
        {--compact : Omit sample rows from JSON output}
        {--dry-run : Validate command shape without querying row data}';

    protected $description = 'Read-only all-person face/photo provenance report by source, export trust, and review state';

    public function handle(): int
    {
        $treeId = max(1, (int) $this->option('tree'));
        $personId = $this->positiveNullableInt($this->option('person-id'));
        $sourceId = $this->positiveNullableInt($this->option('source-id'));
        $status = $this->cleanOptionString($this->option('status'));
        $limit = max(1, min(500, (int) $this->option('limit')));
        $offset = max(0, min(100000, (int) $this->option('offset')));

        $payload = $this->basePayload($treeId, $personId, $sourceId, $status, $limit, $offset);

        if ((bool) $this->option('dry-run')) {
            $payload['dry_run'] = true;
            $payload['summary']['query_would_run'] = false;
            $this->renderPayload($payload);

            return self::SUCCESS;
        }

        $payload['coverage'] = $this->coverageCounters($treeId);
        $payload['coverage_gap_rollups'] = $this->coverageGapRollups($treeId, $limit);
        $coverageStatusSelected = $this->isCoverageStatus($status);
        $mediaRows = $coverageStatusSelected ? [] : $this->personMediaRows($treeId);
        $mediaByPath = $this->mediaByNormalizedPath($mediaRows);
        $rollups = [];
        $sourceRollups = [];
        $samples = [];
        $sampleIndex = 0;

        if ($coverageStatusSelected) {
            $coverage = $this->coverageGapSamples($treeId, $status, $limit, $offset);
            $samples = $coverage['samples'];
            $payload['summary']['coverage_gap_rows_scanned'] = $coverage['scanned'];
            $payload['summary']['coverage_gap_rows_requiring_review'] = $coverage['review_required'];
            $payload['summary']['review_required_total'] = $coverage['review_required'];
            $payload['status_counts'][$status] = $coverage['review_required'];
        } else {
            foreach ($this->faceRows($treeId) as $row) {
                $contexts = $this->matchedMediaContexts($mediaByPath, [$row->current_path ?? null, $row->original_path ?? null]);
                $targetPersonId = $this->faceTargetPersonId($row);
                if ($personId !== null && ! $this->faceMatchesPersonFilter($row, $contexts, $personId)) {
                    continue;
                }

                $sourceContext = $this->sourceContextForFace($contexts, $targetPersonId, $personId);
                if ($sourceId !== null && ! in_array($sourceId, $sourceContext['source_ids'], true)) {
                    continue;
                }

                $bucket = $this->faceStatus($row, $sourceContext['source_count']);
                if ($status !== null && $bucket !== $status) {
                    continue;
                }

                $reviewRequired = $bucket !== 'face_trusted_export_source_backed';
                $payload['summary']['face_rows_scanned']++;
                if ($reviewRequired) {
                    $payload['summary']['face_rows_requiring_review']++;
                }
                $payload['status_counts'][$bucket] = ($payload['status_counts'][$bucket] ?? 0) + 1;

                $personKey = $this->personRollupKey($targetPersonId, $this->faceDisplayName($row));
                $this->bumpPersonRollup($rollups, $personKey, $targetPersonId, $this->faceDisplayName($row), $reviewRequired, 'face_rows');
                $this->bumpSourceRollups($sourceRollups, $sourceContext['source_ids'], $sourceContext['source_titles'], $reviewRequired, 'face_rows');

                if ($sampleIndex++ >= $offset && count($samples) < $limit) {
                    $samples[] = [
                        'kind' => 'face_row',
                        'status' => $bucket,
                        'review_required' => $reviewRequired,
                        'face_id' => (int) $row->face_id,
                        'file_registry_id' => (int) $row->file_registry_id,
                        'file_status' => (string) ($row->file_status ?? ''),
                        'filename' => $this->nullableString($row->filename ?? null),
                        'person_name' => $this->nullableString($row->person_name ?? null),
                        'linked_person_id' => $row->genealogy_person_id !== null ? (int) $row->genealogy_person_id : null,
                        'linked_canonical_name' => $this->nullableString($row->linked_canonical_name ?? null),
                        'provenance_status' => $this->nullableString($row->provenance_status ?? null),
                        'provenance_person_id' => $row->provenance_person_id !== null ? (int) $row->provenance_person_id : null,
                        'matched_export_name' => $this->nullableString($row->matched_export_name ?? null),
                        'matched_variant_name' => $this->nullableString($row->matched_variant_name ?? null),
                        'media_ids' => array_slice($sourceContext['media_ids'], 0, 8),
                        'source_ids' => array_slice($sourceContext['source_ids'], 0, 8),
                        'citation_ids' => array_slice($sourceContext['citation_ids'], 0, 8),
                    ];
                }
            }

            foreach ($mediaRows as $row) {
                if ($personId !== null && (int) $row->person_id !== $personId) {
                    continue;
                }

                $sourceIds = $this->csvInts($row->source_ids ?? null);
                if ($sourceId !== null && ! in_array($sourceId, $sourceIds, true)) {
                    continue;
                }

                $bucket = $this->personMediaStatus($row, count($sourceIds));
                if ($status !== null && $bucket !== $status) {
                    continue;
                }

                $reviewRequired = ! in_array($bucket, ['person_media_source_backed_confirmed'], true);
                $payload['summary']['person_media_links_scanned']++;
                if ($reviewRequired) {
                    $payload['summary']['person_media_links_requiring_review']++;
                }
                if (count($sourceIds) > 0) {
                    $payload['summary']['source_backed_person_media_links']++;
                } else {
                    $payload['summary']['unbacked_person_media_links']++;
                }
                $payload['status_counts'][$bucket] = ($payload['status_counts'][$bucket] ?? 0) + 1;

                $personName = (string) ($row->canonical_name ?: $row->person_name ?: 'Unknown');
                $personKey = $this->personRollupKey((int) $row->person_id, $personName);
                $this->bumpPersonRollup($rollups, $personKey, (int) $row->person_id, $personName, $reviewRequired, 'person_media_links');
                $this->bumpSourceRollups($sourceRollups, $sourceIds, $this->sourceTitles($row), $reviewRequired, 'person_media_links');

                if ($sampleIndex++ >= $offset && count($samples) < $limit) {
                    $samples[] = [
                        'kind' => 'person_media_link',
                        'status' => $bucket,
                        'review_required' => $reviewRequired,
                        'person_media_id' => (int) $row->person_media_id,
                        'person_id' => (int) $row->person_id,
                        'person_name' => $personName,
                        'media_id' => (int) $row->media_id,
                        'media_title' => $this->nullableString($row->title ?? null),
                        'media_type' => $this->nullableString($row->media_type ?? null),
                        'face_confirmed' => (bool) $row->face_confirmed,
                        'has_face_region' => $this->hasFaceRegion($row),
                        'source_ids' => array_slice($sourceIds, 0, 8),
                        'citation_ids' => array_slice($this->csvInts($row->citation_ids ?? null), 0, 8),
                    ];
                }
            }

            $payload['summary']['review_required_total'] =
                $payload['summary']['face_rows_requiring_review']
                + $payload['summary']['person_media_links_requiring_review'];
        }
        $payload['summary']['persons_with_review_required'] = count(array_filter(
            $rollups,
            fn (array $rollup): bool => (int) $rollup['review_required'] > 0
        ));
        $payload['summary']['sources_with_review_required'] = count(array_filter(
            $sourceRollups,
            fn (array $rollup): bool => (int) $rollup['review_required'] > 0
        ));
        $payload['person_rollups'] = $this->topRollups($rollups, $limit);
        $payload['source_rollups'] = $this->topRollups($sourceRollups, $limit);
        $payload['samples'] = $samples;
        $payload['next_action'] = $this->nextAction($payload['summary']);

        if ((bool) $this->option('compact')) {
            unset($payload['samples']);
            $payload['compact'] = true;
            $payload['posture']['samples_included'] = false;
            $payload['posture']['row_identifiers_included'] = false;
            $payload = $this->compactScheduledSafePayload($payload);
        }

        $this->renderPayload($payload);

        return self::SUCCESS;
    }

    /**
     * @return array<int, object>
     */
    private function faceRows(int $treeId): array
    {
        return DB::select(
            "SELECT
                frf.id AS face_id,
                frf.file_registry_id,
                frf.person_name,
                frf.genealogy_person_id,
                frf.verified,
                frf.source,
                frf.hidden,
                frf.cluster_id,
                fr.current_path,
                fr.original_path,
                fr.filename,
                fr.status AS file_status,
                fr.date_taken,
                TRIM(CONCAT_WS(' ', NULLIF(gp.given_name, ''), NULLIF(gp.surname, ''), NULLIF(gp.suffix, ''))) AS linked_canonical_name,
                fip.status AS provenance_status,
                fip.evidence_source AS provenance_evidence_source,
                fip.genealogy_person_id AS provenance_person_id,
                fip.canonical_name AS provenance_canonical_name,
                fip.matched_export_name,
                fip.matched_variant_name
             FROM file_registry_faces frf
             JOIN file_registry fr ON fr.id = frf.file_registry_id
             LEFT JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
             LEFT JOIN face_identity_provenance fip ON fip.file_registry_face_id = frf.id
             WHERE frf.hidden = 0
               AND (TRIM(COALESCE(frf.person_name, '')) <> ''
                    OR frf.genealogy_person_id IS NOT NULL
                    OR fip.id IS NOT NULL)
               AND (gp.tree_id = ?
                    OR fip.tree_id = ?
                    OR (gp.id IS NULL AND fip.id IS NULL))",
            [$treeId, $treeId]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function coverageCounters(int $treeId): array
    {
        $face = DB::selectOne(
            "SELECT
                COUNT(*) AS total_face_rows,
                SUM(CASE WHEN frf.hidden = 0 THEN 1 ELSE 0 END) AS visible_face_rows,
                SUM(CASE WHEN frf.hidden = 1 THEN 1 ELSE 0 END) AS hidden_face_rows,
                SUM(
                    CASE WHEN frf.hidden = 0
                           AND (TRIM(COALESCE(frf.person_name, '')) <> ''
                                OR frf.genealogy_person_id IS NOT NULL
                                OR fip.id IS NOT NULL)
                         THEN 1 ELSE 0 END
                ) AS eligible_face_rows,
                SUM(
                    CASE WHEN frf.hidden = 0
                           AND TRIM(COALESCE(frf.person_name, '')) = ''
                           AND frf.genealogy_person_id IS NULL
                           AND fip.id IS NULL
                         THEN 1 ELSE 0 END
                ) AS excluded_visible_unnamed_unlinked_face_rows
             FROM file_registry_faces frf
             JOIN file_registry fr ON fr.id = frf.file_registry_id
             LEFT JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
             LEFT JOIN face_identity_provenance fip ON fip.file_registry_face_id = frf.id
             WHERE gp.tree_id = ?
                OR fip.tree_id = ?
                OR (gp.id IS NULL AND fip.id IS NULL)",
            [$treeId, $treeId]
        );

        $photos = DB::selectOne(
            "SELECT
                COUNT(*) AS total_photo_media,
                SUM(CASE WHEN linked.media_id IS NOT NULL THEN 1 ELSE 0 END) AS person_media_linked_photo_media,
                SUM(CASE WHEN linked.media_id IS NULL THEN 1 ELSE 0 END) AS unlinked_photo_media
             FROM genealogy_media gm
             LEFT JOIN (
                SELECT DISTINCT media_id
                FROM genealogy_person_media
             ) linked ON linked.media_id = gm.id
             WHERE gm.tree_id = ?
               AND gm.media_type = 'photo'",
            [$treeId]
        );

        $excludedVisible = (int) ($face->excluded_visible_unnamed_unlinked_face_rows ?? 0);
        $unlinkedPhotos = (int) ($photos->unlinked_photo_media ?? 0);

        return [
            'scope' => 'tree_linked_provenance_and_unlinked_face_rows',
            'total_face_rows' => (int) ($face->total_face_rows ?? 0),
            'visible_face_rows' => (int) ($face->visible_face_rows ?? 0),
            'eligible_face_rows' => (int) ($face->eligible_face_rows ?? 0),
            'excluded_visible_unnamed_unlinked_face_rows' => $excludedVisible,
            'hidden_face_rows' => (int) ($face->hidden_face_rows ?? 0),
            'total_photo_media' => (int) ($photos->total_photo_media ?? 0),
            'person_media_linked_photo_media' => (int) ($photos->person_media_linked_photo_media ?? 0),
            'unlinked_photo_media' => $unlinkedPhotos,
            'coverage_gap_units' => $excludedVisible + $unlinkedPhotos,
            'creates_repair_packets' => false,
            'identity_decision_required' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function coverageGapRollups(int $treeId, int $limit): array
    {
        $faceBuckets = DB::select(
            "SELECT
                COALESCE(NULLIF(frf.source, ''), 'unknown') AS detector_source_label,
                COALESCE(NULLIF(fr.status, ''), 'unknown') AS file_status_label,
                COUNT(*) AS face_count,
                COUNT(DISTINCT frf.file_registry_id) AS file_count
             FROM file_registry_faces frf
             JOIN file_registry fr ON fr.id = frf.file_registry_id
             LEFT JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
             LEFT JOIN face_identity_provenance fip ON fip.file_registry_face_id = frf.id
             WHERE frf.hidden = 0
               AND TRIM(COALESCE(frf.person_name, '')) = ''
               AND frf.genealogy_person_id IS NULL
               AND fip.id IS NULL
               AND (gp.tree_id = ?
                    OR fip.tree_id = ?
                    OR (gp.id IS NULL AND fip.id IS NULL))
             GROUP BY detector_source_label, file_status_label
             ORDER BY face_count DESC, file_count DESC, detector_source_label
             LIMIT ?",
            [$treeId, $treeId, $limit]
        );

        $photoBuckets = DB::select(
            "SELECT
                LEFT(COALESCE(NULLIF(gm.source_folder, ''), 'unspecified'), 140) AS folder_label,
                COALESCE(NULLIF(gm.analysis_status, ''), 'unknown') AS analysis_status_label,
                COUNT(*) AS photo_count,
                SUM(CASE WHEN gm.has_faces = 1 THEN 1 ELSE 0 END) AS with_face_count,
                SUM(CASE WHEN gm.has_faces = 1 THEN 0 ELSE 1 END) AS without_face_count
             FROM genealogy_media gm
             LEFT JOIN (
                SELECT DISTINCT media_id
                FROM genealogy_person_media
             ) linked ON linked.media_id = gm.id
             WHERE gm.tree_id = ?
               AND gm.media_type = 'photo'
               AND linked.media_id IS NULL
             GROUP BY folder_label, analysis_status_label
             ORDER BY photo_count DESC, folder_label
             LIMIT ?",
            [$treeId, $limit]
        );

        return [
            'read_only' => true,
            'creates_repair_packets' => false,
            'identity_decision_required' => false,
            'visible_unnamed_unlinked_faces' => array_map(
                fn (object $row): array => [
                    'detector_source_label' => (string) ($row->detector_source_label ?? 'unknown'),
                    'file_status_label' => (string) ($row->file_status_label ?? 'unknown'),
                    'face_count' => (int) ($row->face_count ?? 0),
                    'file_count' => (int) ($row->file_count ?? 0),
                ],
                $faceBuckets
            ),
            'unlinked_photo_media' => array_map(
                fn (object $row): array => [
                    'folder_label' => (string) ($row->folder_label ?? 'unspecified'),
                    'analysis_status_label' => (string) ($row->analysis_status_label ?? 'unknown'),
                    'photo_count' => (int) ($row->photo_count ?? 0),
                    'with_face_count' => (int) ($row->with_face_count ?? 0),
                    'without_face_count' => (int) ($row->without_face_count ?? 0),
                ],
                $photoBuckets
            ),
        ];
    }

    /**
     * @return array{scanned:int,review_required:int,samples:array<int,array<string,mixed>>}
     */
    private function coverageGapSamples(int $treeId, string $status, int $limit, int $offset): array
    {
        if ($status === self::COVERAGE_VISIBLE_UNNAMED_UNLINKED_FACE) {
            $rows = DB::select(
                "SELECT
                    frf.id AS face_id,
                    frf.file_registry_id,
                    COALESCE(NULLIF(frf.source, ''), 'unknown') AS detector_source_label,
                    COALESCE(NULLIF(fr.status, ''), 'unknown') AS file_status_label,
                    fr.filename
                 FROM file_registry_faces frf
                 JOIN file_registry fr ON fr.id = frf.file_registry_id
                 LEFT JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
                 LEFT JOIN face_identity_provenance fip ON fip.file_registry_face_id = frf.id
                 WHERE frf.hidden = 0
                   AND TRIM(COALESCE(frf.person_name, '')) = ''
                   AND frf.genealogy_person_id IS NULL
                   AND fip.id IS NULL
                   AND (gp.tree_id = ?
                        OR fip.tree_id = ?
                        OR (gp.id IS NULL AND fip.id IS NULL))
                 ORDER BY fr.id ASC, frf.id ASC
                 LIMIT ? OFFSET ?",
                [$treeId, $treeId, $limit, $offset]
            );

            $samples = array_map(
                fn (object $row): array => [
                    'kind' => 'visible_unnamed_unlinked_face',
                    'status' => self::COVERAGE_VISIBLE_UNNAMED_UNLINKED_FACE,
                    'review_required' => true,
                    'face_id' => (int) $row->face_id,
                    'file_registry_id' => (int) $row->file_registry_id,
                    'detector_source_label' => (string) ($row->detector_source_label ?? 'unknown'),
                    'file_status' => (string) ($row->file_status_label ?? 'unknown'),
                    'filename' => $this->nullableString($row->filename ?? null),
                    'person_name' => null,
                    'linked_person_id' => null,
                    'provenance_person_id' => null,
                    'source_ids' => [],
                    'citation_ids' => [],
                ],
                $rows
            );

            return [
                'scanned' => count($samples),
                'review_required' => count($samples),
                'samples' => $samples,
            ];
        }

        if ($status === self::COVERAGE_UNLINKED_PHOTO_MEDIA) {
            $rows = DB::select(
                "SELECT
                    gm.id AS media_id,
                    gm.title,
                    gm.media_type,
                    gm.source_folder,
                    gm.analysis_status,
                    gm.has_faces
                 FROM genealogy_media gm
                 LEFT JOIN (
                    SELECT DISTINCT media_id
                    FROM genealogy_person_media
                 ) linked ON linked.media_id = gm.id
                 WHERE gm.tree_id = ?
                   AND gm.media_type = 'photo'
                   AND linked.media_id IS NULL
                 ORDER BY gm.id ASC
                 LIMIT ? OFFSET ?",
                [$treeId, $limit, $offset]
            );

            $samples = array_map(
                fn (object $row): array => [
                    'kind' => 'unlinked_photo_media',
                    'status' => self::COVERAGE_UNLINKED_PHOTO_MEDIA,
                    'review_required' => true,
                    'media_id' => (int) $row->media_id,
                    'media_title' => $this->nullableString($row->title ?? null),
                    'media_type' => (string) ($row->media_type ?? 'photo'),
                    'source_folder' => $this->nullableString($row->source_folder ?? null),
                    'analysis_status' => $this->nullableString($row->analysis_status ?? null),
                    'has_faces' => (int) ($row->has_faces ?? 0) === 1,
                    'source_ids' => [],
                    'citation_ids' => [],
                ],
                $rows
            );

            return [
                'scanned' => count($samples),
                'review_required' => count($samples),
                'samples' => $samples,
            ];
        }

        return [
            'scanned' => 0,
            'review_required' => 0,
            'samples' => [],
        ];
    }

    private function isCoverageStatus(?string $status): bool
    {
        return $status !== null && in_array($status, self::COVERAGE_STATUSES, true);
    }

    /**
     * @return array<int, object>
     */
    private function personMediaRows(int $treeId): array
    {
        return DB::select(
            "SELECT
                gpm.id AS person_media_id,
                gpm.person_id,
                gpm.media_id,
                gpm.face_confirmed,
                gpm.face_region_x,
                gpm.face_region_y,
                gpm.face_region_w,
                gpm.face_region_h,
                gp.tree_id,
                TRIM(CONCAT_WS(' ', NULLIF(gp.given_name, ''), NULLIF(gp.surname, ''), NULLIF(gp.suffix, ''))) AS canonical_name,
                gm.title,
                gm.media_type,
                gm.original_path,
                gm.nextcloud_path,
                gm.local_filename,
                gm.file_exists,
                COALESCE(c.citation_count, 0) AS citation_count,
                c.citation_ids,
                c.source_ids,
                c.source_titles
             FROM genealogy_person_media gpm
             JOIN genealogy_persons gp ON gp.id = gpm.person_id
             JOIN genealogy_media gm ON gm.id = gpm.media_id
             LEFT JOIN (
                SELECT
                    gc.person_id,
                    gc.media_id,
                    COUNT(*) AS citation_count,
                    GROUP_CONCAT(DISTINCT gc.id ORDER BY gc.id SEPARATOR ',') AS citation_ids,
                    GROUP_CONCAT(DISTINCT gc.source_id ORDER BY gc.source_id SEPARATOR ',') AS source_ids,
                    GROUP_CONCAT(DISTINCT LEFT(COALESCE(gs.title, ''), 140) ORDER BY gs.id SEPARATOR ' | ') AS source_titles
                FROM genealogy_citations gc
                LEFT JOIN genealogy_sources gs ON gs.id = gc.source_id
                WHERE gc.person_id IS NOT NULL
                  AND gc.media_id IS NOT NULL
                GROUP BY gc.person_id, gc.media_id
             ) c ON c.person_id = gpm.person_id AND c.media_id = gpm.media_id
             WHERE gp.tree_id = ?
               AND gm.tree_id = ?",
            [$treeId, $treeId]
        );
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<string, array<int, object>>
     */
    private function mediaByNormalizedPath(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            foreach ([$row->nextcloud_path ?? null, $row->original_path ?? null, $row->local_filename ?? null] as $path) {
                $key = $this->normalizePath($path);
                if ($key === '') {
                    continue;
                }
                $map[$key][] = $row;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, array<int, object>>  $mediaByPath
     * @param  array<int, mixed>  $paths
     * @return array<int, object>
     */
    private function matchedMediaContexts(array $mediaByPath, array $paths): array
    {
        $matches = [];
        $seen = [];
        foreach ($paths as $path) {
            $key = $this->normalizePath(is_string($path) ? $path : null);
            foreach ($mediaByPath[$key] ?? [] as $row) {
                $id = (int) $row->person_media_id;
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $matches[] = $row;
            }
        }

        return $matches;
    }

    /**
     * @param  array<int, object>  $contexts
     * @return array{source_count:int,source_ids:array<int,int>,source_titles:array<int,string>,citation_ids:array<int,int>,media_ids:array<int,int>}
     */
    private function sourceContextForFace(array $contexts, ?int $targetPersonId, ?int $personFilter): array
    {
        $sourceIds = [];
        $sourceTitles = [];
        $citationIds = [];
        $mediaIds = [];

        foreach ($contexts as $context) {
            $contextPersonId = (int) $context->person_id;
            if ($targetPersonId !== null && $contextPersonId !== $targetPersonId) {
                continue;
            }
            if ($targetPersonId === null && $personFilter !== null && $contextPersonId !== $personFilter) {
                continue;
            }

            $mediaIds[(int) $context->media_id] = (int) $context->media_id;
            foreach ($this->csvInts($context->source_ids ?? null) as $sourceId) {
                $sourceIds[$sourceId] = $sourceId;
            }
            foreach ($this->csvInts($context->citation_ids ?? null) as $citationId) {
                $citationIds[$citationId] = $citationId;
            }
            foreach ($this->sourceTitles($context) as $sourceId => $title) {
                $sourceTitles[$sourceId] = $title;
            }
        }

        return [
            'source_count' => count($sourceIds),
            'source_ids' => array_values($sourceIds),
            'source_titles' => $sourceTitles,
            'citation_ids' => array_values($citationIds),
            'media_ids' => array_values($mediaIds),
        ];
    }

    /**
     * @param  array<int, object>  $contexts
     */
    private function faceMatchesPersonFilter(object $row, array $contexts, int $personId): bool
    {
        if ((int) ($row->genealogy_person_id ?? 0) === $personId
            || (int) ($row->provenance_person_id ?? 0) === $personId) {
            return true;
        }

        foreach ($contexts as $context) {
            if ((int) $context->person_id === $personId) {
                return true;
            }
        }

        return false;
    }

    private function faceTargetPersonId(object $row): ?int
    {
        if ($row->genealogy_person_id !== null) {
            return (int) $row->genealogy_person_id;
        }

        if ($row->provenance_person_id !== null) {
            return (int) $row->provenance_person_id;
        }

        return null;
    }

    private function faceDisplayName(object $row): string
    {
        foreach ([$row->linked_canonical_name ?? null, $row->provenance_canonical_name ?? null, $row->person_name ?? null] as $name) {
            $string = trim((string) $name);
            if ($string !== '') {
                return $string;
            }
        }

        return 'Unlinked face';
    }

    private function faceStatus(object $row, int $sourceCount): string
    {
        $provenanceStatus = $this->nullableString($row->provenance_status ?? null);
        $linkedPersonId = $row->genealogy_person_id !== null ? (int) $row->genealogy_person_id : null;
        $provenancePersonId = $row->provenance_person_id !== null ? (int) $row->provenance_person_id : null;
        if ($linkedPersonId !== null
            && $provenancePersonId !== null
            && $linkedPersonId !== $provenancePersonId
            && in_array($provenanceStatus, self::TRUSTED_STATUSES, true)) {
            return 'face_export_person_conflict';
        }

        if ($provenanceStatus !== null && in_array($provenanceStatus, self::CONFLICT_STATUSES, true)) {
            return 'face_export_person_conflict';
        }

        if (in_array($provenanceStatus, self::TRUSTED_STATUSES, true)
            && ($linkedPersonId === null || $provenancePersonId === null || $linkedPersonId === $provenancePersonId)) {
            return $sourceCount > 0 ? 'face_trusted_export_source_backed' : 'face_trusted_export_no_source';
        }

        $personName = trim((string) ($row->person_name ?? ''));
        $canonical = trim((string) ($row->linked_canonical_name ?? ''));
        if ($linkedPersonId !== null && $personName !== '' && $canonical !== '' && $personName !== $canonical) {
            return 'face_linked_display_mismatch';
        }

        if ($provenanceStatus === null) {
            return 'face_no_export_provenance';
        }

        if (! in_array($provenanceStatus, self::TRUSTED_STATUSES, true)) {
            return 'face_untrusted_export_review';
        }

        return $sourceCount > 0 ? 'face_trusted_export_source_backed' : 'face_trusted_export_no_source';
    }

    private function personMediaStatus(object $row, int $sourceCount): string
    {
        $sourcePart = $sourceCount > 0 ? 'source_backed' : 'unbacked';
        $confirmedPart = (int) ($row->face_confirmed ?? 0) === 1 ? 'confirmed' : 'unconfirmed';

        return 'person_media_'.$sourcePart.'_'.$confirmedPart;
    }

    /**
     * @param  array<string,array<string,mixed>>  $rollups
     */
    private function bumpPersonRollup(array &$rollups, string $key, ?int $personId, string $name, bool $reviewRequired, string $counter): void
    {
        $rollups[$key] ??= [
            'person_id' => $personId,
            'name' => $name,
            'face_rows' => 0,
            'person_media_links' => 0,
            'review_required' => 0,
        ];

        $rollups[$key][$counter] = (int) $rollups[$key][$counter] + 1;
        if ($reviewRequired) {
            $rollups[$key]['review_required'] = (int) $rollups[$key]['review_required'] + 1;
        }
    }

    /**
     * @param  array<string,array<string,mixed>>  $rollups
     * @param  array<int,int>  $sourceIds
     * @param  array<int,string>  $sourceTitles
     */
    private function bumpSourceRollups(array &$rollups, array $sourceIds, array $sourceTitles, bool $reviewRequired, string $counter): void
    {
        if ($sourceIds === []) {
            $sourceIds = [0];
            $sourceTitles[0] = 'No person/media citation source';
        }

        foreach ($sourceIds as $sourceId) {
            $key = (string) $sourceId;
            $rollups[$key] ??= [
                'source_id' => $sourceId > 0 ? $sourceId : null,
                'title' => $sourceTitles[$sourceId] ?? 'Source '.$sourceId,
                'face_rows' => 0,
                'person_media_links' => 0,
                'review_required' => 0,
            ];
            $rollups[$key][$counter] = (int) $rollups[$key][$counter] + 1;
            if ($reviewRequired) {
                $rollups[$key]['review_required'] = (int) $rollups[$key]['review_required'] + 1;
            }
        }
    }

    /**
     * @param  array<string,array<string,mixed>>  $rollups
     * @return array<int,array<string,mixed>>
     */
    private function topRollups(array $rollups, int $limit): array
    {
        usort($rollups, fn (array $a, array $b): int => [
            (int) $b['review_required'],
            (int) $b['face_rows'] + (int) $b['person_media_links'],
            (string) ($a['name'] ?? $a['title'] ?? ''),
        ] <=> [
            (int) $a['review_required'],
            (int) $a['face_rows'] + (int) $a['person_media_links'],
            (string) ($b['name'] ?? $b['title'] ?? ''),
        ]);

        return array_slice(array_values($rollups), 0, $limit);
    }

    /**
     * @return array<int,int>
     */
    private function csvInts(mixed $value): array
    {
        $ids = [];
        foreach (explode(',', (string) $value) as $part) {
            $id = (int) trim($part);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @return array<int,string>
     */
    private function sourceTitles(object $row): array
    {
        $sourceIds = $this->csvInts($row->source_ids ?? null);
        $titles = array_map('trim', explode(' | ', (string) ($row->source_titles ?? '')));
        $result = [];
        foreach ($sourceIds as $index => $sourceId) {
            $title = $titles[$index] ?? '';
            $result[$sourceId] = $title !== '' ? $title : 'Source '.$sourceId;
        }

        return $result;
    }

    private function normalizePath(?string $path): string
    {
        $value = mb_strtolower(trim((string) $path));
        if ($value === '') {
            return '';
        }

        $value = str_replace('\\', '/', $value);
        $value = preg_replace('#/+#', '/', $value) ?? $value;
        $value = ltrim($value, '/');

        foreach (['master/media/', 'master/', 'media/'] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                $value = substr($value, strlen($prefix));
                break;
            }
        }

        $picturesAt = strpos($value, 'pictures/');
        if ($picturesAt !== false) {
            $value = substr($value, $picturesAt);
        }

        return trim($value, '/');
    }

    private function hasFaceRegion(object $row): bool
    {
        return $row->face_region_x !== null
            || $row->face_region_y !== null
            || $row->face_region_w !== null
            || $row->face_region_h !== null;
    }

    private function personRollupKey(?int $personId, string $name): string
    {
        return $personId !== null ? 'person:'.$personId : 'name:'.mb_strtolower(trim($name));
    }

    private function positiveNullableInt(mixed $value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function cleanOptionString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * @param  array<string,int>  $summary
     */
    private function nextAction(array $summary): string
    {
        if ((int) ($summary['review_required_total'] ?? 0) > 0) {
            return 'triage_person_and_source_rollups_before_any_face_writeback_or_recluster';
        }

        return 'all_person_picture_provenance_rows_have_source_backed_trusted_or_confirmed_status';
    }

    /**
     * Compact output is stored in scheduled job history, so avoid row-selector
     * key names that scheduled-output hygiene treats as unsafe.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function compactScheduledSafePayload(array $payload): array
    {
        $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
        $payload['filters'] = [
            'tree' => $filters['tree_id'] ?? null,
            'person_filter_applied' => ($filters['person_id'] ?? null) !== null,
            'source_filter_applied' => ($filters['source_id'] ?? null) !== null,
            'status' => $filters['status'] ?? null,
            'limit' => $filters['limit'] ?? null,
            'offset' => $filters['offset'] ?? null,
        ];

        $payload['person_rollups'] = array_map(
            fn (array $rollup): array => [
                'person_label' => (string) ($rollup['name'] ?? 'Unlinked face'),
                'face_rows' => (int) ($rollup['face_rows'] ?? 0),
                'person_media_links' => (int) ($rollup['person_media_links'] ?? 0),
                'review_required' => (int) ($rollup['review_required'] ?? 0),
            ],
            is_array($payload['person_rollups'] ?? null) ? $payload['person_rollups'] : []
        );

        $payload['source_rollups'] = array_map(
            fn (array $rollup): array => [
                'source_label' => (string) ($rollup['title'] ?? 'No person/media citation source'),
                'face_rows' => (int) ($rollup['face_rows'] ?? 0),
                'person_media_links' => (int) ($rollup['person_media_links'] ?? 0),
                'review_required' => (int) ($rollup['review_required'] ?? 0),
            ],
            is_array($payload['source_rollups'] ?? null) ? $payload['source_rollups'] : []
        );

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function basePayload(int $treeId, ?int $personId, ?int $sourceId, ?string $status, int $limit, int $offset): array
    {
        return [
            'command' => 'ops:face-person-source-provenance-report',
            'mode' => 'observe',
            'dry_run' => false,
            'read_only' => true,
            'mutation_allowed' => false,
            'captured_at' => now()->toIso8601String(),
            'filters' => [
                'tree_id' => $treeId,
                'person_id' => $personId,
                'source_id' => $sourceId,
                'status' => $status,
                'limit' => $limit,
                'offset' => $offset,
            ],
            'posture' => [
                'schema' => 'face_person_source_provenance_report.v1',
                'read_only' => true,
                'operator_review_required' => true,
                'automatic_link_allowed' => false,
                'cluster_merge_allowed' => false,
                'create_person_allowed' => false,
                'metadata_writeback_allowed' => false,
                'samples_included' => true,
                'row_identifiers_included' => true,
                'source_backed_picture_vetting' => true,
                'coverage_counters_create_repair_packets' => false,
            ],
            'summary' => [
                'query_would_run' => true,
                'face_rows_scanned' => 0,
                'face_rows_requiring_review' => 0,
                'person_media_links_scanned' => 0,
                'person_media_links_requiring_review' => 0,
                'coverage_gap_rows_scanned' => 0,
                'coverage_gap_rows_requiring_review' => 0,
                'source_backed_person_media_links' => 0,
                'unbacked_person_media_links' => 0,
                'review_required_total' => 0,
                'persons_with_review_required' => 0,
                'sources_with_review_required' => 0,
            ],
            'coverage' => [
                'scope' => 'not_queried',
                'total_face_rows' => 0,
                'visible_face_rows' => 0,
                'eligible_face_rows' => 0,
                'excluded_visible_unnamed_unlinked_face_rows' => 0,
                'hidden_face_rows' => 0,
                'total_photo_media' => 0,
                'person_media_linked_photo_media' => 0,
                'unlinked_photo_media' => 0,
                'coverage_gap_units' => 0,
                'creates_repair_packets' => false,
                'identity_decision_required' => false,
            ],
            'coverage_gap_rollups' => [
                'read_only' => true,
                'creates_repair_packets' => false,
                'identity_decision_required' => false,
                'visible_unnamed_unlinked_faces' => [],
                'unlinked_photo_media' => [],
            ],
            'status_counts' => [],
            'person_rollups' => [],
            'source_rollups' => [],
            'samples' => [],
            'next_action' => 'run_report',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderPayload(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line(sprintf(
            'face-person-source-provenance-report: face_review=%d person_media_review=%d persons=%d sources=%d mode=observe',
            (int) ($payload['summary']['face_rows_requiring_review'] ?? 0),
            (int) ($payload['summary']['person_media_links_requiring_review'] ?? 0),
            (int) ($payload['summary']['persons_with_review_required'] ?? 0),
            (int) ($payload['summary']['sources_with_review_required'] ?? 0)
        ));
        $this->line((string) ($payload['next_action'] ?? ''));
    }
}
