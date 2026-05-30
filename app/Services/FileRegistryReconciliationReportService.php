<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class FileRegistryReconciliationReportService
{
    private const VERSION = 1;

    private const DOWNSTREAM_MYSQL_SURFACES = [
        'file_registry_faces' => 'file_registry_id',
        'file_registry_tags' => 'file_registry_id',
        'file_registry_perceptual_hashes' => 'file_registry_id',
        'file_registry_video_hashes' => 'file_registry_id',
        'file_bundle_members' => 'file_registry_id',
        'file_collection_items' => 'file_registry_id',
        'file_versions' => 'file_registry_id',
    ];

    public function collect(int $sampleLimit = 10, bool $includeSamples = false): array
    {
        $sampleLimit = max(0, min(100, $sampleLimit));

        $identity = $this->collectIdentityCounts($sampleLimit, $includeSamples);
        $downstream = $this->collectDownstreamCounts();
        $rag = $this->collectRagCounts($sampleLimit);
        $errors = array_values(array_filter(array_merge(
            $identity['errors'],
            $downstream['errors'],
            $rag['errors']
        )));

        $counts = [
            'total_files' => $identity['counts']['total_files'],
            'active_files' => $identity['counts']['active_files'],
            'orphaned_files' => $identity['counts']['orphaned_files'],
            'deleted_files' => $identity['counts']['deleted_files'],
            'active_missing_identity_or_path' => $identity['counts']['active_missing_identity_or_path'],
            'duplicate_asset_uuid_groups' => $identity['counts']['duplicate_asset_uuid_groups'],
            'duplicate_nextcloud_fileid_groups' => $identity['counts']['duplicate_nextcloud_fileid_groups'],
            'same_content_multi_path_groups' => $identity['counts']['same_content_multi_path_groups'],
            'same_filename_content_multi_path_groups' => $identity['counts']['same_filename_content_multi_path_groups'],
            'same_filename_content_materialized_groups' => $identity['counts']['same_filename_content_materialized_groups'],
            'same_filename_content_unmaterialized_groups' => $identity['counts']['same_filename_content_unmaterialized_groups'],
            'pending_duplicate_review_pairs' => $identity['counts']['pending_duplicate_review_pairs'],
            'keep_both_duplicate_pairs' => $identity['counts']['keep_both_duplicate_pairs'],
            'mysql_downstream_orphan_rows' => array_sum($downstream['counts']),
            'rag_file_documents' => $rag['counts']['file_documents'],
            'rag_source_id_checked_sample' => $rag['counts']['source_id_checked_sample'],
            'rag_source_id_missing_registry_in_sample' => $rag['counts']['source_id_missing_registry_in_sample'],
            'rag_asset_uuid_checked_sample' => $rag['counts']['asset_uuid_checked_sample'],
            'rag_asset_uuid_missing_registry_in_sample' => $rag['counts']['asset_uuid_missing_registry_in_sample'],
        ];

        $attentionCount = $counts['active_missing_identity_or_path']
            + $counts['duplicate_asset_uuid_groups']
            + $counts['duplicate_nextcloud_fileid_groups']
            + $counts['same_filename_content_unmaterialized_groups']
            + $counts['pending_duplicate_review_pairs']
            + $counts['mysql_downstream_orphan_rows']
            + $counts['rag_source_id_missing_registry_in_sample']
            + $counts['rag_asset_uuid_missing_registry_in_sample']
            + count($errors);

        return [
            'version' => self::VERSION,
            'mode' => 'observe',
            'status' => $attentionCount > 0 ? 'observe_warning' : 'observe_ok',
            'captured_at' => now()->toIso8601String(),
            'sample_limit' => $sampleLimit,
            'samples_included' => $includeSamples,
            'posture' => [
                'scope' => 'aggregate_or_sample_only',
                'read_only' => true,
                'writes_enabled' => false,
                'move_apply_enabled' => false,
                'delete_apply_enabled' => false,
                'rag_cleanup_enabled' => false,
                'face_cleanup_enabled' => false,
                'canonical_writeback_enabled' => false,
            ],
            'counts' => $counts,
            'identity' => $identity['counts'],
            'downstream_mysql_orphans' => $downstream['counts'],
            'pgsql_rag' => $rag['counts'],
            'samples' => $includeSamples ? [
                'same_filename_content_multi_path_groups' => $identity['samples'],
                'rag_missing_source_ids' => $rag['samples']['missing_source_ids'],
                'rag_missing_asset_uuids' => $rag['samples']['missing_asset_uuids'],
            ] : [],
            'errors' => $errors,
            'next_actions' => [
                'Materialize unreviewed exact duplicate groups with files:materialize-duplicate-candidates before any move/delete planning.',
                'Treat same-content multi-path rows as duplicate review candidates, not automatic moves.',
                'Keep canonical path remap, delete cascade, RAG cleanup, and face cleanup disabled until a later approval gate.',
            ],
        ];
    }

    public function compactPayload(array $payload): array
    {
        $counts = is_array($payload['counts'] ?? null) ? $payload['counts'] : [];

        return [
            'version' => $payload['version'] ?? self::VERSION,
            'mode' => 'observe',
            'compact' => true,
            'status' => $payload['status'] ?? 'observe_warning',
            'captured_at' => $payload['captured_at'] ?? null,
            'posture' => $payload['posture'] ?? [],
            'counts' => [
                'active_files' => (int) ($counts['active_files'] ?? 0),
                'move_or_duplicate_candidate_groups' => (int) ($counts['same_filename_content_unmaterialized_groups'] ?? $counts['same_filename_content_multi_path_groups'] ?? 0),
                'duplicate_review_pending_pairs' => (int) ($counts['pending_duplicate_review_pairs'] ?? 0),
                'duplicate_keep_both_pairs' => (int) ($counts['keep_both_duplicate_pairs'] ?? 0),
                'same_filename_content_multi_path_groups' => (int) ($counts['same_filename_content_multi_path_groups'] ?? 0),
                'same_filename_content_materialized_groups' => (int) ($counts['same_filename_content_materialized_groups'] ?? 0),
                'identity_conflict_groups' => (int) ($counts['duplicate_asset_uuid_groups'] ?? 0)
                    + (int) ($counts['duplicate_nextcloud_fileid_groups'] ?? 0),
                'missing_identity_or_path' => (int) ($counts['active_missing_identity_or_path'] ?? 0),
                'mysql_downstream_orphan_rows' => (int) ($counts['mysql_downstream_orphan_rows'] ?? 0),
                'rag_checked_sample' => (int) ($counts['rag_source_id_checked_sample'] ?? 0)
                    + (int) ($counts['rag_asset_uuid_checked_sample'] ?? 0),
                'rag_missing_registry_in_sample' => (int) ($counts['rag_source_id_missing_registry_in_sample'] ?? 0)
                    + (int) ($counts['rag_asset_uuid_missing_registry_in_sample'] ?? 0),
                'error_count' => is_countable($payload['errors'] ?? null) ? count($payload['errors']) : 0,
            ],
        ];
    }

    public function toMarkdown(array $payload): string
    {
        $counts = $payload['counts'] ?? [];
        $posture = $payload['posture'] ?? [];
        $moveOrDuplicateGroups = $counts['same_filename_content_multi_path_groups']
            ?? $counts['move_or_duplicate_candidate_groups']
            ?? 0;
        $unmaterializedGroups = $counts['same_filename_content_unmaterialized_groups']
            ?? $counts['move_or_duplicate_candidate_groups']
            ?? $moveOrDuplicateGroups;
        $identityConflictGroups = isset($counts['identity_conflict_groups'])
            ? (int) $counts['identity_conflict_groups']
            : ((int) ($counts['duplicate_asset_uuid_groups'] ?? 0)) + ((int) ($counts['duplicate_nextcloud_fileid_groups'] ?? 0));
        $ragMissingRegistry = $counts['rag_missing_registry_in_sample']
            ?? (((int) ($counts['rag_source_id_missing_registry_in_sample'] ?? 0)) + ((int) ($counts['rag_asset_uuid_missing_registry_in_sample'] ?? 0)));

        return implode("\n", [
            '# File Lifecycle Reconciliation Report',
            '',
            '- Status: `'.($payload['status'] ?? 'unknown').'`',
            '- Mode: `observe`',
            '- Captured: `'.($payload['captured_at'] ?? '-').'`',
            '- Read-only: `'.(($posture['read_only'] ?? true) ? 'yes' : 'no').'`',
            '- Writes enabled: `'.(($posture['writes_enabled'] ?? false) ? 'yes' : 'no').'`',
            '',
            '## Counts',
            '',
            '- Active files: `'.($counts['active_files'] ?? 0).'`',
            '- Move/duplicate candidate groups: `'.$unmaterializedGroups.'` unmaterialized of `'.$moveOrDuplicateGroups.'` exact same-name groups',
            '- Pending duplicate review pairs: `'.($counts['pending_duplicate_review_pairs'] ?? 0).'`',
            '- Keep-both duplicate pairs: `'.($counts['keep_both_duplicate_pairs'] ?? 0).'`',
            '- Identity conflict groups: `'.$identityConflictGroups.'`',
            '- MySQL downstream orphan rows: `'.($counts['mysql_downstream_orphan_rows'] ?? 0).'`',
            '- RAG missing registry in checked sample: `'.$ragMissingRegistry.'`',
            '',
            '## Guardrails',
            '',
            '- No canonical path remap is performed.',
            '- No delete cascade is performed.',
            '- No RAG, face, thumbnail, or genealogy cleanup is performed.',
        ])."\n";
    }

    private function collectIdentityCounts(int $sampleLimit, bool $includeSamples): array
    {
        $counts = [
            'total_files' => $this->mysqlCount('SELECT COUNT(*) AS count FROM file_registry'),
            'active_files' => $this->mysqlCount("SELECT COUNT(*) AS count FROM file_registry WHERE status = 'active'"),
            'orphaned_files' => $this->mysqlCount("SELECT COUNT(*) AS count FROM file_registry WHERE status = 'orphaned'"),
            'deleted_files' => $this->mysqlCount("SELECT COUNT(*) AS count FROM file_registry WHERE status = 'deleted'"),
            'active_missing_identity_or_path' => $this->mysqlCount(
                "SELECT COUNT(*) AS count
                 FROM file_registry
                 WHERE status = 'active'
                   AND (
                     asset_uuid IS NULL OR asset_uuid = ''
                     OR current_path IS NULL OR current_path = ''
                   )"
            ),
            'duplicate_asset_uuid_groups' => $this->mysqlCount(
                "SELECT COUNT(*) AS count
                 FROM (
                   SELECT asset_uuid
                   FROM file_registry
                   WHERE status = 'active'
                     AND asset_uuid IS NOT NULL AND asset_uuid <> ''
                   GROUP BY asset_uuid
                   HAVING COUNT(*) > 1
                 ) duplicate_assets"
            ),
            'duplicate_nextcloud_fileid_groups' => $this->mysqlCount(
                "SELECT COUNT(*) AS count
                 FROM (
                   SELECT nextcloud_fileid
                   FROM file_registry
                   WHERE status = 'active'
                     AND nextcloud_fileid IS NOT NULL
                   GROUP BY nextcloud_fileid
                   HAVING COUNT(*) > 1
                 ) duplicate_fileids"
            ),
            'same_content_multi_path_groups' => $this->mysqlCount(
                "SELECT COUNT(*) AS count
                 FROM (
                   SELECT content_hash
                   FROM file_registry
                   WHERE status = 'active'
                     AND content_hash IS NOT NULL
                     AND content_hash <> ''
                   GROUP BY content_hash
                   HAVING COUNT(*) > 1 AND COUNT(DISTINCT current_path) > 1
                 ) same_content"
            ),
            'same_filename_content_multi_path_groups' => $this->mysqlCount(
                "SELECT COUNT(*) AS count
                 FROM (
                   SELECT content_hash, filename, file_size
                   FROM file_registry
                   WHERE status = 'active'
                     AND content_hash IS NOT NULL
                     AND content_hash <> ''
                     AND filename IS NOT NULL
                   GROUP BY content_hash, filename, file_size
                   HAVING COUNT(*) > 1 AND COUNT(DISTINCT current_path) > 1
                 ) same_named_content"
            ),
            'same_filename_content_materialized_groups' => $this->sameFilenameContentMaterializedGroups(),
            'pending_duplicate_review_pairs' => $this->mysqlCount(
                "SELECT COUNT(*) AS count
                 FROM file_registry_duplicates d
                 JOIN file_registry c ON c.id = d.canonical_file_id
                 JOIN file_registry dup ON dup.id = d.duplicate_file_id
                 WHERE d.status = 'pending_review'
                   AND c.status = 'active'
                   AND dup.status = 'active'"
            ),
            'keep_both_duplicate_pairs' => $this->mysqlCount(
                "SELECT COUNT(*) AS count
                 FROM file_registry_duplicates d
                 JOIN file_registry c ON c.id = d.canonical_file_id
                 JOIN file_registry dup ON dup.id = d.duplicate_file_id
                 WHERE d.status = 'keep_both'
                   AND c.status = 'active'
                   AND dup.status = 'active'"
            ),
        ];
        $counts['same_filename_content_unmaterialized_groups'] = max(
            0,
            $counts['same_filename_content_multi_path_groups'] - $counts['same_filename_content_materialized_groups']
        );

        return [
            'counts' => $counts,
            'samples' => $includeSamples ? $this->collectIdentitySamples($sampleLimit) : [],
            'errors' => [],
        ];
    }

    private function sameFilenameContentMaterializedGroups(): int
    {
        return $this->mysqlCount(
            "SELECT COUNT(*) AS count
             FROM (
               SELECT same_named.content_hash,
                      same_named.filename,
                      same_named.file_size
               FROM (
                 SELECT content_hash, filename, file_size
                 FROM file_registry
                 WHERE status = 'active'
                   AND content_hash IS NOT NULL
                   AND content_hash <> ''
                   AND filename IS NOT NULL
                 GROUP BY content_hash, filename, file_size
                 HAVING COUNT(*) > 1 AND COUNT(DISTINCT current_path) > 1
               ) same_named
               WHERE EXISTS (
                 SELECT 1
                 FROM file_registry_duplicates d
                 JOIN file_registry c ON c.id = d.canonical_file_id
                 JOIN file_registry dup ON dup.id = d.duplicate_file_id
                 WHERE d.content_hash = same_named.content_hash
                   AND c.status = 'active'
                   AND dup.status = 'active'
                   AND c.filename = same_named.filename
                   AND dup.filename = same_named.filename
                   AND c.file_size <=> same_named.file_size
                   AND dup.file_size <=> same_named.file_size
               )
             ) materialized_same_named"
        );
    }

    private function collectIdentitySamples(int $sampleLimit): array
    {
        if ($sampleLimit <= 0) {
            return [];
        }

        try {
            return array_map(
                fn (object $row): array => [
                    'content_hash_prefix' => (string) ($row->content_hash_prefix ?? ''),
                    'filename' => (string) ($row->filename ?? ''),
                    'file_size' => (int) ($row->file_size ?? 0),
                    'file_count' => (int) ($row->file_count ?? 0),
                    'path_count' => (int) ($row->path_count ?? 0),
                    'sample_paths' => array_values(array_filter(explode(' | ', (string) ($row->sample_paths ?? '')))),
                ],
                DB::select(
                    "SELECT LEFT(content_hash, 12) AS content_hash_prefix,
                            filename,
                            file_size,
                            COUNT(*) AS file_count,
                            COUNT(DISTINCT current_path) AS path_count,
                            GROUP_CONCAT(current_path ORDER BY current_path SEPARATOR ' | ') AS sample_paths
                     FROM file_registry
                     WHERE status = 'active'
                       AND content_hash IS NOT NULL
                       AND content_hash <> ''
                       AND filename IS NOT NULL
                     GROUP BY content_hash, filename, file_size
                     HAVING COUNT(*) > 1 AND COUNT(DISTINCT current_path) > 1
                     ORDER BY file_count DESC, path_count DESC
                     LIMIT ?",
                    [$sampleLimit]
                )
            );
        } catch (Throwable) {
            return [];
        }
    }

    private function collectDownstreamCounts(): array
    {
        $counts = [];
        $errors = [];

        foreach (self::DOWNSTREAM_MYSQL_SURFACES as $table => $column) {
            try {
                $counts[$table] = $this->mysqlCount(
                    "SELECT COUNT(*) AS count
                     FROM {$table} surface
                     LEFT JOIN file_registry fr ON fr.id = surface.{$column}
                     WHERE surface.{$column} IS NOT NULL
                       AND fr.id IS NULL"
                );
            } catch (Throwable $e) {
                $counts[$table] = 0;
                $errors[] = "{$table}: ".$e->getMessage();
            }
        }

        return [
            'counts' => $counts,
            'errors' => $errors,
        ];
    }

    private function collectRagCounts(int $sampleLimit): array
    {
        $counts = [
            'file_documents' => 0,
            'source_id_checked_sample' => 0,
            'source_id_missing_registry_in_sample' => 0,
            'asset_uuid_checked_sample' => 0,
            'asset_uuid_missing_registry_in_sample' => 0,
        ];
        $samples = [
            'missing_source_ids' => [],
            'missing_asset_uuids' => [],
        ];
        $errors = [];

        try {
            $counts['file_documents'] = $this->pgsqlCount(
                "SELECT COUNT(*) AS count
                 FROM rag_documents
                 WHERE source_type IN ('file_registry', 'file_catalog')"
            );

            if ($sampleLimit > 0) {
                $sourceRows = DB::connection('pgsql_rag')->select(
                    "SELECT source_id
                     FROM rag_documents
                     WHERE source_type = 'file_registry'
                       AND source_id IS NOT NULL
                     ORDER BY id DESC
                     LIMIT ?",
                    [$sampleLimit]
                );
                $sourceIds = array_values(array_unique(array_filter(array_map(
                    fn (object $row): string => (string) ($row->source_id ?? ''),
                    $sourceRows
                ), fn (string $value): bool => ctype_digit($value))));
                $counts['source_id_checked_sample'] = count($sourceIds);
                $missingSourceIds = $this->missingFileRegistryIds($sourceIds);
                $counts['source_id_missing_registry_in_sample'] = count($missingSourceIds);
                $samples['missing_source_ids'] = $missingSourceIds;

                $assetRows = DB::connection('pgsql_rag')->select(
                    "SELECT metadata->>'asset_uuid' AS asset_uuid
                     FROM rag_documents
                     WHERE source_type IN ('file_registry', 'file_catalog')
                       AND metadata->>'asset_uuid' IS NOT NULL
                       AND metadata->>'asset_uuid' <> ''
                     ORDER BY id DESC
                     LIMIT ?",
                    [$sampleLimit]
                );
                $assetUuids = array_values(array_unique(array_filter(array_map(
                    fn (object $row): string => (string) ($row->asset_uuid ?? ''),
                    $assetRows
                ))));
                $counts['asset_uuid_checked_sample'] = count($assetUuids);
                $missingAssetUuids = $this->missingAssetUuids($assetUuids);
                $counts['asset_uuid_missing_registry_in_sample'] = count($missingAssetUuids);
                $samples['missing_asset_uuids'] = $missingAssetUuids;
            }
        } catch (Throwable $e) {
            $errors[] = 'pgsql_rag: '.$e->getMessage();
        }

        return [
            'counts' => $counts,
            'samples' => $samples,
            'errors' => $errors,
        ];
    }

    private function missingFileRegistryIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $existing = DB::table('file_registry')
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return array_values(array_diff($ids, $existing));
    }

    private function missingAssetUuids(array $assetUuids): array
    {
        if ($assetUuids === []) {
            return [];
        }

        $existing = DB::table('file_registry')
            ->whereIn('asset_uuid', $assetUuids)
            ->pluck('asset_uuid')
            ->map(fn (mixed $assetUuid): string => (string) $assetUuid)
            ->all();

        return array_values(array_diff($assetUuids, $existing));
    }

    private function mysqlCount(string $sql, array $bindings = []): int
    {
        try {
            $row = DB::selectOne($sql, $bindings);

            return (int) ($row->count ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private function pgsqlCount(string $sql, array $bindings = []): int
    {
        $row = DB::connection('pgsql_rag')->selectOne($sql, $bindings);

        return (int) ($row->count ?? 0);
    }
}
