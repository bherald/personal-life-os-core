<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OpsFaceBridgeAlignmentReportCommand extends Command
{
    protected $signature = 'ops:face-bridge-alignment-report
        {--tree= : Restrict to one genealogy tree id}
        {--limit=25 : Maximum gap rows to sample}
        {--include-deleted : Include non-active file_registry rows}
        {--exact-counts : Run slower exact aggregate counts}
        {--compact : Omit row samples from JSON output}
        {--json : Emit machine-readable JSON}
        {--dry-run : Validate command shape without querying row data}';

    protected $description = 'Read-only bounded classifier for face genealogy_media/person_media bridge alignment gaps';

    public function handle(): int
    {
        $treeId = $this->positiveIntOption('tree');
        $limit = max(1, min(200, (int) $this->option('limit')));
        $includeDeleted = (bool) $this->option('include-deleted');
        $exactCounts = (bool) $this->option('exact-counts');

        $payload = $this->basePayload($treeId, $limit, $includeDeleted, $exactCounts);

        if ((bool) $this->option('dry-run')) {
            $payload['dry_run'] = true;
            $payload['summary']['query_would_run'] = false;
            $this->renderPayload($payload);

            return self::SUCCESS;
        }

        $samples = $this->gapSamples($treeId, $limit, $includeDeleted);
        $sampleCounts = [];
        foreach ($samples as $sample) {
            $gapType = (string) ($sample['gap_type'] ?? 'unknown');
            $sampleCounts[$gapType] = ($sampleCounts[$gapType] ?? 0) + 1;
        }

        $payload['summary']['query_would_run'] = true;
        $payload['summary']['sample_count'] = count($samples);
        $payload['summary']['sample_gap_counts'] = $sampleCounts;
        $payload['summary']['sample_person_count'] = count(array_unique(array_column($samples, 'person_id')));
        $payload['summary']['sample_media_count'] = count(array_filter(array_unique(array_column($samples, 'genealogy_media_id'))));
        $payload['rows'] = (bool) $this->option('compact') ? [] : $samples;

        if ($exactCounts) {
            $payload['exact_counts'] = $this->exactCounts($treeId, $includeDeleted);
        }

        $payload['next_action'] = $this->nextAction($sampleCounts, $payload['exact_counts'] ?? null);

        $this->renderPayload($payload);

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function gapSamples(?int $treeId, int $limit, bool $includeDeleted): array
    {
        [$filters, $params] = $this->baseFilters($treeId, $includeDeleted);
        $params[] = $limit;

        return array_map(
            fn (object $row): array => [
                'face_id' => (int) ($row->face_id ?? 0),
                'file_registry_id' => (int) ($row->file_registry_id ?? 0),
                'person_id' => (int) ($row->person_id ?? 0),
                'tree_id' => (int) ($row->tree_id ?? 0),
                'genealogy_media_id' => isset($row->genealogy_media_id) ? (int) $row->genealogy_media_id : null,
                'gap_type' => (string) ($row->gap_type ?? 'unknown'),
                'file_status' => (string) ($row->file_status ?? ''),
                'face_source' => (string) ($row->face_source ?? ''),
                'verified_face' => ((int) ($row->verified_face ?? 0)) === 1,
                'has_face_region' => ((int) ($row->has_face_region ?? 0)) === 1,
                'has_registry_path' => ((int) ($row->has_registry_path ?? 0)) === 1,
                'backlog_age_hours' => (int) ($row->backlog_age_hours ?? 0),
            ],
            DB::select(
                "SELECT
                    frf.id AS face_id,
                    frf.file_registry_id,
                    frf.genealogy_person_id AS person_id,
                    gp.tree_id,
                    gm.id AS genealogy_media_id,
                    CASE
                        WHEN gm.id IS NULL THEN 'missing_genealogy_media'
                        WHEN gpm.id IS NULL THEN 'missing_person_media'
                        ELSE 'aligned'
                    END AS gap_type,
                    fr.status AS file_status,
                    frf.source AS face_source,
                    frf.verified AS verified_face,
                    CASE
                        WHEN frf.region_x IS NOT NULL
                         AND frf.region_y IS NOT NULL
                         AND frf.region_w IS NOT NULL
                         AND frf.region_h IS NOT NULL
                        THEN 1 ELSE 0
                    END AS has_face_region,
                    CASE
                        WHEN COALESCE(NULLIF(fr.current_path, ''), fr.original_path) IS NULL
                          OR COALESCE(NULLIF(fr.current_path, ''), fr.original_path) = ''
                        THEN 0 ELSE 1
                    END AS has_registry_path,
                    IFNULL(GREATEST(TIMESTAMPDIFF(HOUR, frf.updated_at, NOW()), 0), 0) AS backlog_age_hours
                 FROM file_registry_faces frf
                 JOIN file_registry fr ON fr.id = frf.file_registry_id
                 JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
                 LEFT JOIN genealogy_media gm
                   ON gm.tree_id = gp.tree_id
                  AND (
                    gm.nextcloud_path = COALESCE(NULLIF(fr.current_path, ''), fr.original_path)
                    OR gm.original_path = fr.original_path
                  )
                 LEFT JOIN genealogy_person_media gpm
                   ON gpm.person_id = frf.genealogy_person_id
                  AND gpm.media_id = gm.id
                 WHERE ".implode(' AND ', $filters).'
                   AND (gm.id IS NULL OR gpm.id IS NULL)
                 ORDER BY frf.updated_at DESC, frf.id DESC
                 LIMIT ?',
                $params
            )
        );
    }

    /**
     * @return array<string, int>
     */
    private function exactCounts(?int $treeId, bool $includeDeleted): array
    {
        [$filters, $params] = $this->baseFilters($treeId, $includeDeleted);

        $row = DB::selectOne(
            "SELECT
                COUNT(DISTINCT frf.id) AS linked_faces,
                COUNT(DISTINCT CASE WHEN gm.id IS NULL THEN frf.id ELSE NULL END) AS missing_genealogy_media,
                COUNT(DISTINCT CASE WHEN gm.id IS NOT NULL AND gpm.id IS NULL THEN frf.id ELSE NULL END) AS missing_person_media,
                COUNT(DISTINCT CASE WHEN gpm.id IS NOT NULL THEN frf.id ELSE NULL END) AS aligned_faces
             FROM file_registry_faces frf
             JOIN file_registry fr ON fr.id = frf.file_registry_id
             JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
             LEFT JOIN genealogy_media gm
               ON gm.tree_id = gp.tree_id
              AND (
                gm.nextcloud_path = COALESCE(NULLIF(fr.current_path, ''), fr.original_path)
                OR gm.original_path = fr.original_path
              )
             LEFT JOIN genealogy_person_media gpm
               ON gpm.person_id = frf.genealogy_person_id
              AND gpm.media_id = gm.id
             WHERE ".implode(' AND ', $filters),
            $params
        );

        return [
            'linked_faces' => (int) ($row->linked_faces ?? 0),
            'aligned_faces' => (int) ($row->aligned_faces ?? 0),
            'missing_genealogy_media' => (int) ($row->missing_genealogy_media ?? 0),
            'missing_person_media' => (int) ($row->missing_person_media ?? 0),
        ];
    }

    /**
     * @return array{0:array<int,string>,1:array<int,mixed>}
     */
    private function baseFilters(?int $treeId, bool $includeDeleted): array
    {
        $filters = [
            'frf.hidden = 0',
            'frf.genealogy_person_id IS NOT NULL',
        ];
        $params = [];

        if (! $includeDeleted) {
            $filters[] = "fr.status = 'active'";
        }

        if ($treeId !== null) {
            $filters[] = 'gp.tree_id = ?';
            $params[] = $treeId;
        }

        return [$filters, $params];
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(?int $treeId, int $limit, bool $includeDeleted, bool $exactCounts): array
    {
        return [
            'command' => 'ops:face-bridge-alignment-report',
            'mode' => 'observe',
            'dry_run' => false,
            'read_only' => true,
            'mutation_allowed' => false,
            'captured_at' => now()->toIso8601String(),
            'filters' => [
                'tree_id' => $treeId,
                'limit' => $limit,
                'active_only' => ! $includeDeleted,
                'exact_counts' => $exactCounts,
            ],
            'summary' => [
                'query_would_run' => true,
                'sample_count' => 0,
                'sample_gap_counts' => [],
                'sample_person_count' => 0,
                'sample_media_count' => 0,
                'row_identifiers_included' => true,
                'raw_paths_included' => false,
            ],
            'posture' => [
                'schema' => 'face_bridge_alignment.ds.v1',
                'purpose' => 'classify_bridge_gaps_before_repair',
                'operator_review_required' => true,
                'automation_allowed' => false,
                'automatic_link_allowed' => false,
                'create_person_allowed' => false,
                'writeback_allowed' => false,
                'canonical_genealogy_write_allowed' => false,
                'row_identifiers_included' => true,
                'raw_paths_included' => false,
            ],
            'exact_counts' => null,
            'rows' => [],
            'next_action' => 'inspect_samples_before_repair',
        ];
    }

    /**
     * @param  array<string, int>  $sampleCounts
     * @param  array<string, int>|null  $exactCounts
     */
    private function nextAction(array $sampleCounts, ?array $exactCounts): string
    {
        $missingMedia = $exactCounts['missing_genealogy_media'] ?? ($sampleCounts['missing_genealogy_media'] ?? 0);
        $missingPersonMedia = $exactCounts['missing_person_media'] ?? ($sampleCounts['missing_person_media'] ?? 0);

        if ($missingMedia > 0) {
            return 'review_missing_media_samples_then_use_ops_repair_face_bridge_media_dry_run';
        }

        if ($missingPersonMedia > 0) {
            return 'review_missing_person_media_samples_before_authorizing_any_targeted_repair';
        }

        return 'no_bridge_gap_samples_found';
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
            'face-bridge-alignment-report: samples=%d gaps=%s exact=%s rows_are_review_only=yes',
            $payload['summary']['sample_count'] ?? 0,
            json_encode($payload['summary']['sample_gap_counts'] ?? []),
            ($payload['exact_counts'] ?? null) !== null ? json_encode($payload['exact_counts']) : 'skipped'
        ));

        if (($payload['rows'] ?? []) !== []) {
            $this->table(
                ['face_id', 'file_registry_id', 'person_id', 'media_id', 'gap_type', 'age_h'],
                array_map(
                    fn (array $row): array => [
                        $row['face_id'],
                        $row['file_registry_id'],
                        $row['person_id'],
                        $row['genealogy_media_id'] ?? '',
                        $row['gap_type'],
                        $row['backlog_age_hours'],
                    ],
                    $payload['rows']
                )
            );
        }

        $this->line((string) ($payload['next_action'] ?? ''));
    }
}
