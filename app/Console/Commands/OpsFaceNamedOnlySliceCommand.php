<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OpsFaceNamedOnlySliceCommand extends Command
{
    private const DECISION_ACTIONS = [
        'keep_name_only',
        'outside_tree',
        'too_vague',
        'not_this_person',
        'defer',
        'link_existing_person',
    ];

    protected $signature = 'ops:face-named-only-slice
        {--limit=25 : Maximum named-only faces to include}
        {--offset=0 : Offset into the matching review set}
        {--hours=24 : Stale threshold in hours}
        {--cluster= : Restrict to one face cluster id}
        {--decision-state=open : open, decided, or all}
        {--cluster-scope=all : all or mixed}
        {--sort=oldest : oldest or recent}
        {--all-ages : Include non-stale named-only faces}
        {--include-inactive : Include non-active file_registry rows}
        {--compact : Omit row samples from JSON output}
        {--json : Emit machine-readable JSON}
        {--dry-run : Validate command shape without querying row data}';

    protected $description = 'Read-only bounded slice of named-only faces for operator review without linking or writeback';

    public function handle(): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $offset = max(0, (int) $this->option('offset'));
        $hours = max(1, min(24 * 365, (int) $this->option('hours')));
        $clusterId = $this->positiveIntOption('cluster');
        $decisionState = (string) $this->option('decision-state');
        $clusterScope = (string) $this->option('cluster-scope');
        $sort = (string) $this->option('sort');
        $allAges = (bool) $this->option('all-ages');
        $includeInactive = (bool) $this->option('include-inactive');

        if (! in_array($decisionState, ['open', 'decided', 'all'], true)) {
            $this->error('Invalid --decision-state. Use open, decided, or all.');

            return self::FAILURE;
        }

        if (! in_array($clusterScope, ['all', 'mixed'], true)) {
            $this->error('Invalid --cluster-scope. Use all or mixed.');

            return self::FAILURE;
        }

        if (! in_array($sort, ['oldest', 'recent'], true)) {
            $this->error('Invalid --sort. Use oldest or recent.');

            return self::FAILURE;
        }

        $payload = $this->basePayload($limit, $offset, $hours, $clusterId, $decisionState, $clusterScope, $sort, $allAges, $includeInactive);

        if ((bool) $this->option('dry-run')) {
            $payload['dry_run'] = true;
            $payload['summary']['query_would_run'] = false;
            $payload['summary']['slice_count'] = 0;
            $payload['summary']['total_matching'] = null;
            $payload['rows'] = [];
            $this->renderPayload($payload);

            return self::SUCCESS;
        }

        [$joins, $whereSql, $whereParams] = $this->queryParts(
            $hours,
            $clusterId,
            $decisionState,
            $clusterScope,
            $allAges,
            $includeInactive
        );

        $sortSql = $sort === 'oldest'
            ? 'is_mixed_name_cluster DESC, has_photo_date DESC, frf.updated_at ASC, frf.id ASC'
            : 'is_mixed_name_cluster DESC, has_photo_date DESC, frf.updated_at DESC, frf.id DESC';

        $rows = DB::select(
            "SELECT
                frf.id AS face_id,
                frf.file_registry_id,
                frf.person_name,
                frf.region_x,
                frf.region_y,
                frf.region_w,
                frf.region_h,
                frf.confidence,
                frf.source,
                frf.verified,
                frf.favorite,
                frf.cluster_id,
                fr.asset_uuid,
                fr.filename,
                fr.status AS file_status,
                fr.date_taken,
                IF(fr.date_taken IS NULL, 0, 1) AS has_photo_date,
                IFNULL(GREATEST(TIMESTAMPDIFF(HOUR, frf.updated_at, NOW()), 0), 0) AS backlog_age_hours,
                CASE WHEN frf.updated_at < DATE_SUB(NOW(), INTERVAL ? HOUR) THEN 1 ELSE 0 END AS is_stale_named_only,
                CASE WHEN mixed_names.cluster_id IS NOT NULL THEN 1 ELSE 0 END AS is_mixed_name_cluster,
                q.status AS candidate_decision_status,
                JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.action')) AS candidate_decision_action,
                JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.terminal')) AS candidate_decision_terminal,
                JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.decided_at')) AS candidate_decision_at
             FROM file_registry_faces frf
             JOIN file_registry fr ON fr.id = frf.file_registry_id
             {$joins}
             WHERE {$whereSql}
             ORDER BY {$sortSql}
             LIMIT ? OFFSET ?",
            array_merge([$hours], $whereParams, [$limit, $offset])
        );

        $total = DB::selectOne(
            "SELECT COUNT(*) AS cnt
             FROM file_registry_faces frf
             JOIN file_registry fr ON fr.id = frf.file_registry_id
             {$joins}
             WHERE {$whereSql}",
            $whereParams
        );

        $formattedRows = array_map(fn (object $row): array => $this->formatRow($row), $rows);
        $payload['summary']['query_would_run'] = true;
        $payload['summary']['slice_count'] = count($formattedRows);
        $payload['summary']['total_matching'] = (int) ($total->cnt ?? 0);
        $payload['summary']['mixed_name_rows'] = count(array_filter($formattedRows, fn (array $row): bool => (bool) $row['is_mixed_name_cluster']));
        $payload['summary']['with_photo_date_rows'] = count(array_filter($formattedRows, fn (array $row): bool => (bool) $row['photo_date_context']['available']));
        $payload['rows'] = (bool) $this->option('compact') ? [] : $formattedRows;

        $this->renderPayload($payload);

        return self::SUCCESS;
    }

    /**
     * @return array{0:string,1:string,2:array<int,mixed>}
     */
    private function queryParts(
        int $hours,
        ?int $clusterId,
        string $decisionState,
        string $clusterScope,
        bool $allAges,
        bool $includeInactive
    ): array {
        $allowedDecisionActionsSql = implode(', ', array_map(
            fn (string $action): string => "'{$action}'",
            self::DECISION_ACTIONS
        ));

        $activeFilter = $includeInactive ? '' : "AND fr.status = 'active'";
        $mixedClusterActiveSql = $includeInactive ? '' : "AND fr_mix.status = 'active'";
        $staleFilter = $allAges ? '' : 'AND frf.updated_at < DATE_SUB(NOW(), INTERVAL ? HOUR)';
        $clusterFilter = $clusterId !== null ? 'AND frf.cluster_id = ?' : '';
        $clusterScopeFilter = $clusterScope === 'mixed' ? 'AND mixed_names.cluster_id IS NOT NULL' : '';
        $whereParams = [];
        if ($clusterId !== null) {
            $whereParams[] = $clusterId;
        }
        if (! $allAges) {
            $whereParams[] = $hours;
        }

        $decisionFilter = match ($decisionState) {
            'open' => "AND (
                q.id IS NULL
                OR JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.terminal')) IS NULL
                OR JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.terminal')) != 'true'
            )",
            'decided' => "AND JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.terminal')) = 'true'",
            default => '',
        };

        $joins = "
            LEFT JOIN (
                SELECT frf_mix.cluster_id
                FROM file_registry_faces frf_mix
                JOIN file_registry fr_mix ON fr_mix.id = frf_mix.file_registry_id
                WHERE frf_mix.hidden = 0
                  AND frf_mix.cluster_id IS NOT NULL
                  AND NULLIF(TRIM(frf_mix.person_name), '') IS NOT NULL
                  AND LOWER(TRIM(frf_mix.person_name)) != 'unknown'
                  {$mixedClusterActiveSql}
                GROUP BY frf_mix.cluster_id
                HAVING COUNT(DISTINCT LOWER(TRIM(frf_mix.person_name))) > 1
            ) mixed_names ON mixed_names.cluster_id = frf.cluster_id
            LEFT JOIN genealogy_face_match_queue q ON q.file_registry_face_id = frf.id
              AND JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.action')) IN ({$allowedDecisionActionsSql})
              AND NOT EXISTS (
                SELECT 1
                FROM genealogy_face_match_queue q2
                WHERE q2.file_registry_face_id = frf.id
                  AND JSON_UNQUOTE(JSON_EXTRACT(q2.match_details, '$.latest_candidate_decision.action')) IN ({$allowedDecisionActionsSql})
                  AND (
                    q2.updated_at > q.updated_at
                    OR (q2.updated_at = q.updated_at AND q2.id > q.id)
                  )
              )
        ";

        $whereSql = "frf.hidden = 0
              AND frf.genealogy_person_id IS NULL
              AND NULLIF(TRIM(frf.person_name), '') IS NOT NULL
              AND LOWER(TRIM(frf.person_name)) != 'unknown'
              {$activeFilter}
              {$decisionFilter}
              {$clusterScopeFilter}
              {$clusterFilter}
              {$staleFilter}";

        return [$joins, $whereSql, $whereParams];
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(
        int $limit,
        int $offset,
        int $hours,
        ?int $clusterId,
        string $decisionState,
        string $clusterScope,
        string $sort,
        bool $allAges,
        bool $includeInactive
    ): array {
        return [
            'command' => 'ops:face-named-only-slice',
            'mode' => 'observe',
            'dry_run' => false,
            'read_only' => true,
            'mutation_allowed' => false,
            'captured_at' => now()->toIso8601String(),
            'filters' => [
                'limit' => $limit,
                'offset' => $offset,
                'stale_threshold_hours' => $hours,
                'cluster_id' => $clusterId,
                'stale_only' => ! $allAges,
                'decision_state' => $decisionState,
                'cluster_scope' => $clusterScope,
                'sort' => $sort,
                'active_only' => ! $includeInactive,
            ],
            'summary' => [
                'query_would_run' => true,
                'slice_count' => 0,
                'total_matching' => 0,
                'mixed_name_rows' => 0,
                'with_photo_date_rows' => 0,
                'row_identifiers_included' => true,
                'raw_paths_included' => false,
            ],
            'posture' => [
                'schema' => 'face_named_only_slice.ds.v1',
                'purpose' => 'operator_review_slice',
                'operator_review_required' => true,
                'automation_allowed' => false,
                'automatic_link_allowed' => false,
                'create_person_allowed' => false,
                'writeback_allowed' => false,
                'canonical_genealogy_write_allowed' => false,
                'row_identifiers_included' => true,
                'raw_paths_included' => false,
            ],
            'rows' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRow(object $row): array
    {
        $dateTaken = $this->safeDateTaken($row->date_taken ?? null);

        return [
            'face_id' => (int) ($row->face_id ?? 0),
            'file_registry_id' => (int) ($row->file_registry_id ?? 0),
            'asset_uuid' => (string) ($row->asset_uuid ?? ''),
            'person_name' => (string) ($row->person_name ?? ''),
            'filename' => (string) ($row->filename ?? ''),
            'file_status' => (string) ($row->file_status ?? ''),
            'cluster_id' => isset($row->cluster_id) ? (int) $row->cluster_id : null,
            'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
            'source' => (string) ($row->source ?? ''),
            'verified' => ((int) ($row->verified ?? 0)) === 1,
            'favorite' => ((int) ($row->favorite ?? 0)) === 1,
            'backlog_age_hours' => (int) ($row->backlog_age_hours ?? 0),
            'is_stale_named_only' => ((int) ($row->is_stale_named_only ?? 0)) === 1,
            'is_mixed_name_cluster' => ((int) ($row->is_mixed_name_cluster ?? 0)) === 1,
            'photo_date_context' => [
                'available' => $dateTaken !== null,
                'date_taken' => $dateTaken,
                'photo_year' => $dateTaken !== null ? (int) substr($dateTaken, 0, 4) : null,
                'source' => $dateTaken !== null ? 'file_registry.date_taken' : null,
            ],
            'candidate_decision' => [
                'status' => $this->nullableScalar($row->candidate_decision_status ?? null),
                'action' => $this->nullableScalar($row->candidate_decision_action ?? null),
                'terminal' => $this->nullableScalar($row->candidate_decision_terminal ?? null),
                'decided_at' => $this->nullableScalar($row->candidate_decision_at ?? null),
            ],
            'review_hints' => [
                'face_crop_url' => '/api/media/faces/registry-crop/'.(int) ($row->face_id ?? 0),
                'named_only_tab' => '/media/faces?tab=named_only',
                'candidate_api_url' => '/api/media/faces/'.(int) ($row->face_id ?? 0).'/candidates',
            ],
        ];
    }

    private function safeDateTaken(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_scalar($value)) {
            return null;
        }

        $date = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date) !== 1) {
            return null;
        }

        return substr($date, 0, 10);
    }

    private function positiveIntOption(string $name): ?int
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
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
            'face-named-only-slice: total=%s slice=%d stale_only=%s decision=%s scope=%s sort=%s rows_are_review_only=yes',
            $payload['summary']['total_matching'] ?? 'unknown',
            $payload['summary']['slice_count'] ?? 0,
            $payload['filters']['stale_only'] ? 'yes' : 'no',
            $payload['filters']['decision_state'],
            $payload['filters']['cluster_scope'],
            $payload['filters']['sort']
        ));

        if (($payload['rows'] ?? []) !== []) {
            $this->table(
                ['face_id', 'file_registry_id', 'name', 'filename', 'age_h', 'mixed', 'date'],
                array_map(
                    fn (array $row): array => [
                        $row['face_id'],
                        $row['file_registry_id'],
                        $row['person_name'],
                        $row['filename'],
                        $row['backlog_age_hours'],
                        $row['is_mixed_name_cluster'] ? 'yes' : 'no',
                        $row['photo_date_context']['date_taken'] ?? '',
                    ],
                    $payload['rows']
                )
            );
        }
    }
}
