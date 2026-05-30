<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OpsFaceMixedClusterReportCommand extends Command
{
    protected $signature = 'ops:face-mixed-cluster-report
        {--limit=25 : Maximum mixed-name clusters to include}
        {--offset=0 : Offset into ranked mixed-name clusters}
        {--min-distinct=2 : Minimum distinct displayed names in a cluster}
        {--sample-faces=0 : Include this many sanitized face samples per cluster}
        {--include-inactive : Include non-active file_registry rows}
        {--compact : Omit per-name breakdowns from JSON output}
        {--json : Emit machine-readable JSON}
        {--dry-run : Validate command shape without querying row data}';

    protected $description = 'Read-only bounded report of face clusters containing multiple displayed names';

    public function handle(): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $offset = max(0, (int) $this->option('offset'));
        $minDistinct = max(2, min(25, (int) $this->option('min-distinct')));
        $sampleFaces = max(0, min(10, (int) $this->option('sample-faces')));
        $includeInactive = (bool) $this->option('include-inactive');
        $compact = (bool) $this->option('compact');

        $payload = $this->basePayload($limit, $offset, $minDistinct, $sampleFaces, $includeInactive);

        if ((bool) $this->option('dry-run')) {
            $payload['dry_run'] = true;
            $payload['summary']['query_would_run'] = false;
            $this->renderPayload($payload);

            return self::SUCCESS;
        }

        $clusters = $this->clusterRows($limit, $offset, $minDistinct, $includeInactive);
        $clusterIds = array_map(fn (array $row): int => (int) $row['cluster_id'], $clusters);
        $nameCounts = $clusterIds === [] ? [] : $this->nameCounts($clusterIds, $includeInactive);
        $faceSamples = $clusterIds === [] || $sampleFaces === 0 || $compact
            ? []
            : $this->faceSamples($clusterIds, $sampleFaces, $includeInactive);
        $total = $this->totalMixedClusters($minDistinct, $includeInactive);

        foreach ($clusters as &$cluster) {
            $clusterId = (int) $cluster['cluster_id'];
            $cluster['names'] = $compact ? [] : ($nameCounts[$clusterId] ?? []);
            $cluster['face_samples'] = $compact ? [] : ($faceSamples[$clusterId] ?? []);
            $cluster['review_hints'] = [
                'named_only_slice_command' => sprintf(
                    'php artisan ops:face-named-only-slice --cluster=%d --limit=25 --json',
                    $clusterId
                ),
                'mixed_named_only_slice_command' => sprintf(
                    'php artisan ops:face-named-only-slice --cluster=%d --cluster-scope=mixed --limit=25 --json',
                    $clusterId
                ),
                'face_clusters_url' => '/media/face-clusters',
                'named_only_url' => '/media/faces?tab=named_only',
            ];
        }
        unset($cluster);

        $payload['summary']['query_would_run'] = true;
        $payload['summary']['total_mixed_clusters'] = $total;
        $payload['summary']['slice_count'] = count($clusters);
        $payload['summary']['cluster_ids'] = $clusterIds;
        $payload['summary']['unlinked_named_faces'] = array_sum(array_map(fn (array $row): int => (int) $row['unlinked_named_faces'], $clusters));
        $payload['summary']['named_faces'] = array_sum(array_map(fn (array $row): int => (int) $row['named_faces'], $clusters));
        $payload['summary']['face_samples'] = array_sum(array_map(fn (array $row): int => count($row['face_samples'] ?? []), $clusters));
        $payload['clusters'] = $clusters;

        $this->renderPayload($payload);

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function clusterRows(int $limit, int $offset, int $minDistinct, bool $includeInactive): array
    {
        $activeFilter = $includeInactive ? '' : "AND fr.status = 'active'";

        return array_map(
            fn (object $row): array => [
                'cluster_id' => (int) ($row->cluster_id ?? 0),
                'named_faces' => (int) ($row->named_faces ?? 0),
                'distinct_names' => (int) ($row->distinct_names ?? 0),
                'unlinked_named_faces' => (int) ($row->unlinked_named_faces ?? 0),
                'linked_named_faces' => (int) ($row->linked_named_faces ?? 0),
                'with_photo_date' => (int) ($row->with_photo_date ?? 0),
                'without_photo_date' => (int) ($row->without_photo_date ?? 0),
                'oldest_face_updated_at' => $this->nullableScalar($row->oldest_face_updated_at ?? null),
                'newest_face_updated_at' => $this->nullableScalar($row->newest_face_updated_at ?? null),
                'earliest_photo_date' => $this->safeDate($row->earliest_photo_date ?? null),
                'latest_photo_date' => $this->safeDate($row->latest_photo_date ?? null),
                'names' => [],
                'face_samples' => [],
            ],
            DB::select(
                "SELECT
                    frf.cluster_id,
                    COUNT(*) AS named_faces,
                    COUNT(DISTINCT LOWER(TRIM(frf.person_name))) AS distinct_names,
                    SUM(CASE WHEN frf.genealogy_person_id IS NULL THEN 1 ELSE 0 END) AS unlinked_named_faces,
                    SUM(CASE WHEN frf.genealogy_person_id IS NOT NULL THEN 1 ELSE 0 END) AS linked_named_faces,
                    SUM(CASE WHEN fr.date_taken IS NOT NULL THEN 1 ELSE 0 END) AS with_photo_date,
                    SUM(CASE WHEN fr.date_taken IS NULL THEN 1 ELSE 0 END) AS without_photo_date,
                    MIN(frf.updated_at) AS oldest_face_updated_at,
                    MAX(frf.updated_at) AS newest_face_updated_at,
                    MIN(fr.date_taken) AS earliest_photo_date,
                    MAX(fr.date_taken) AS latest_photo_date
                 FROM file_registry_faces frf
                 JOIN file_registry fr ON fr.id = frf.file_registry_id
                 WHERE frf.hidden = 0
                   AND frf.cluster_id IS NOT NULL
                   AND NULLIF(TRIM(frf.person_name), '') IS NOT NULL
                   AND LOWER(TRIM(frf.person_name)) != 'unknown'
                   {$activeFilter}
                 GROUP BY frf.cluster_id
                 HAVING COUNT(DISTINCT LOWER(TRIM(frf.person_name))) >= ?
                 ORDER BY distinct_names DESC, unlinked_named_faces DESC, named_faces DESC, frf.cluster_id ASC
                 LIMIT ? OFFSET ?",
                [$minDistinct, $limit, $offset]
            )
        );
    }

    /**
     * @param  array<int, int>  $clusterIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function nameCounts(array $clusterIds, bool $includeInactive): array
    {
        $placeholders = implode(',', array_fill(0, count($clusterIds), '?'));
        $activeFilter = $includeInactive ? '' : "AND fr.status = 'active'";

        $rows = DB::select(
            "SELECT
                frf.cluster_id,
                LOWER(TRIM(frf.person_name)) AS normalized_name,
                MIN(TRIM(frf.person_name)) AS display_name,
                COUNT(*) AS named_faces,
                SUM(CASE WHEN frf.genealogy_person_id IS NULL THEN 1 ELSE 0 END) AS unlinked_named_faces,
                SUM(CASE WHEN frf.genealogy_person_id IS NOT NULL THEN 1 ELSE 0 END) AS linked_named_faces,
                SUM(CASE WHEN fr.date_taken IS NOT NULL THEN 1 ELSE 0 END) AS with_photo_date
             FROM file_registry_faces frf
             JOIN file_registry fr ON fr.id = frf.file_registry_id
             WHERE frf.hidden = 0
               AND frf.cluster_id IN ({$placeholders})
               AND NULLIF(TRIM(frf.person_name), '') IS NOT NULL
               AND LOWER(TRIM(frf.person_name)) != 'unknown'
               {$activeFilter}
             GROUP BY frf.cluster_id, normalized_name
             ORDER BY frf.cluster_id ASC, named_faces DESC, display_name ASC",
            $clusterIds
        );

        $grouped = [];
        foreach ($rows as $row) {
            $clusterId = (int) ($row->cluster_id ?? 0);
            $grouped[$clusterId][] = [
                'display_name' => (string) ($row->display_name ?? ''),
                'normalized_name' => (string) ($row->normalized_name ?? ''),
                'named_faces' => (int) ($row->named_faces ?? 0),
                'unlinked_named_faces' => (int) ($row->unlinked_named_faces ?? 0),
                'linked_named_faces' => (int) ($row->linked_named_faces ?? 0),
                'with_photo_date' => (int) ($row->with_photo_date ?? 0),
            ];
        }

        return $grouped;
    }

    /**
     * @param  array<int, int>  $clusterIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function faceSamples(array $clusterIds, int $sampleFaces, bool $includeInactive): array
    {
        $activeFilter = $includeInactive ? '' : "AND fr.status = 'active'";
        $grouped = [];

        foreach ($clusterIds as $clusterId) {
            $rows = DB::select(
                "SELECT
                    frf.id AS face_id,
                    frf.file_registry_id,
                    frf.person_name,
                    frf.cluster_id,
                    frf.genealogy_person_id,
                    frf.confidence,
                    frf.source,
                    frf.verified,
                    fr.asset_uuid,
                    fr.filename,
                    fr.status AS file_status,
                    fr.date_taken,
                    IFNULL(GREATEST(TIMESTAMPDIFF(HOUR, frf.updated_at, NOW()), 0), 0) AS backlog_age_hours
                 FROM file_registry_faces frf
                 JOIN file_registry fr ON fr.id = frf.file_registry_id
                 WHERE frf.hidden = 0
                   AND frf.cluster_id = ?
                   AND NULLIF(TRIM(frf.person_name), '') IS NOT NULL
                   AND LOWER(TRIM(frf.person_name)) != 'unknown'
                   {$activeFilter}
                 ORDER BY
                   CASE WHEN frf.genealogy_person_id IS NULL THEN 0 ELSE 1 END ASC,
                   CASE WHEN fr.date_taken IS NULL THEN 1 ELSE 0 END ASC,
                   frf.updated_at ASC,
                   frf.id ASC
                 LIMIT ?",
                [$clusterId, $sampleFaces]
            );

            $grouped[$clusterId] = array_map(
                fn (object $row): array => $this->formatFaceSample($row),
                $rows
            );
        }

        return $grouped;
    }

    private function totalMixedClusters(int $minDistinct, bool $includeInactive): int
    {
        $activeFilter = $includeInactive ? '' : "AND fr.status = 'active'";

        $row = DB::selectOne(
            "SELECT COUNT(*) AS cnt
             FROM (
                SELECT frf.cluster_id
                FROM file_registry_faces frf
                JOIN file_registry fr ON fr.id = frf.file_registry_id
                WHERE frf.hidden = 0
                  AND frf.cluster_id IS NOT NULL
                  AND NULLIF(TRIM(frf.person_name), '') IS NOT NULL
                  AND LOWER(TRIM(frf.person_name)) != 'unknown'
                  {$activeFilter}
                GROUP BY frf.cluster_id
                HAVING COUNT(DISTINCT LOWER(TRIM(frf.person_name))) >= ?
             ) mixed",
            [$minDistinct]
        );

        return (int) ($row->cnt ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(int $limit, int $offset, int $minDistinct, int $sampleFaces, bool $includeInactive): array
    {
        return [
            'command' => 'ops:face-mixed-cluster-report',
            'mode' => 'observe',
            'dry_run' => false,
            'read_only' => true,
            'mutation_allowed' => false,
            'captured_at' => now()->toIso8601String(),
            'filters' => [
                'limit' => $limit,
                'offset' => $offset,
                'min_distinct_names' => $minDistinct,
                'sample_faces_per_cluster' => $sampleFaces,
                'active_only' => ! $includeInactive,
            ],
            'summary' => [
                'query_would_run' => true,
                'total_mixed_clusters' => 0,
                'slice_count' => 0,
                'cluster_ids' => [],
                'named_faces' => 0,
                'unlinked_named_faces' => 0,
                'face_samples' => 0,
                'raw_paths_included' => false,
            ],
            'posture' => [
                'schema' => 'face_mixed_cluster_report.ds.v1',
                'purpose' => 'rank_mixed_name_clusters_for_operator_review',
                'operator_review_required' => true,
                'automation_allowed' => false,
                'automatic_link_allowed' => false,
                'cluster_merge_allowed' => false,
                'cluster_split_allowed' => false,
                'create_person_allowed' => false,
                'writeback_allowed' => false,
                'canonical_genealogy_write_allowed' => false,
                'row_identifiers_included' => true,
                'face_samples_are_review_only' => true,
                'raw_paths_included' => false,
            ],
            'clusters' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatFaceSample(object $row): array
    {
        $dateTaken = $this->safeDate($row->date_taken ?? null);
        $faceId = (int) ($row->face_id ?? 0);

        return [
            'face_id' => $faceId,
            'file_registry_id' => (int) ($row->file_registry_id ?? 0),
            'asset_uuid' => (string) ($row->asset_uuid ?? ''),
            'person_name' => (string) ($row->person_name ?? ''),
            'filename' => (string) ($row->filename ?? ''),
            'file_status' => (string) ($row->file_status ?? ''),
            'cluster_id' => (int) ($row->cluster_id ?? 0),
            'genealogy_linked' => isset($row->genealogy_person_id),
            'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
            'source' => (string) ($row->source ?? ''),
            'verified' => ((int) ($row->verified ?? 0)) === 1,
            'backlog_age_hours' => (int) ($row->backlog_age_hours ?? 0),
            'photo_date_context' => [
                'available' => $dateTaken !== null,
                'date_taken' => $dateTaken,
                'photo_year' => $dateTaken !== null ? (int) substr($dateTaken, 0, 4) : null,
                'source' => $dateTaken !== null ? 'file_registry.date_taken' : null,
            ],
            'review_hints' => [
                'face_crop_url' => '/api/media/faces/registry-crop/'.$faceId,
                'candidate_api_url' => '/api/media/faces/'.$faceId.'/candidates',
                'named_only_tab' => '/media/faces?tab=named_only',
            ],
        ];
    }

    private function safeDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 ? substr($value, 0, 10) : null;
    }

    private function nullableScalar(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderPayload(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line(sprintf(
            'face-mixed-cluster-report: total=%d slice=%d named=%d unlinked=%d samples=%d rows_are_review_only=yes',
            $payload['summary']['total_mixed_clusters'],
            $payload['summary']['slice_count'],
            $payload['summary']['named_faces'],
            $payload['summary']['unlinked_named_faces'],
            $payload['summary']['face_samples']
        ));

        if (($payload['clusters'] ?? []) !== []) {
            $this->table(
                ['cluster_id', 'names', 'named_faces', 'unlinked', 'with_date'],
                array_map(
                    fn (array $row): array => [
                        $row['cluster_id'],
                        $row['distinct_names'],
                        $row['named_faces'],
                        $row['unlinked_named_faces'],
                        $row['with_photo_date'],
                    ],
                    $payload['clusters']
                )
            );
        }
    }
}
