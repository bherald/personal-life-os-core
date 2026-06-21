<?php

namespace App\Console\Commands;

use App\Services\Genealogy\PersonFaceAppearanceProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FaceDataTriageCommand extends Command
{
    private const GENEALOGY_TREE_ID = 4;

    protected $signature = 'faces:triage-data
                            {--clean-cluster-names : Remove comma-separated Unknown tokens from person cluster names}
                            {--confirm-clean-names : Actually write --clean-cluster-names changes}
                            {--clear-same-photo-duplicates : Clear duplicate same-photo identity/cluster assignments while preserving face rows}
                            {--confirm-clear-duplicates : Actually write --clear-same-photo-duplicates changes}
                            {--repair-linked-mismatches : Repair face rows whose visible name disagrees with their genealogy link}
                            {--confirm-repair-mismatches : Actually write --repair-linked-mismatches changes}
                            {--detach-mixed-cluster-names : Clear cluster assignment for named faces that disagree with their confirmed cluster name}
                            {--confirm-detach-mixed : Actually write --detach-mixed-cluster-names changes}
                            {--rebuild-appearance-profiles : Rebuild appearance profiles for identities touched by visible face rows}
                            {--confirm-rebuild-profiles : Actually write --rebuild-appearance-profiles changes}
                            {--tree-id=4 : Genealogy tree ID for FT-linked mismatch repairs}
                            {--limit=25 : Sample rows to display}
                            {--json : Output compact JSON summary}';

    protected $description = 'Triage and clean bad face cluster labels and impossible same-photo duplicate assignments';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $specific = $this->option('clean-cluster-names')
            || $this->option('clear-same-photo-duplicates')
            || $this->option('repair-linked-mismatches')
            || $this->option('detach-mixed-cluster-names')
            || $this->option('rebuild-appearance-profiles');
        $runNames = $this->option('clean-cluster-names') || ! $specific;
        $runDuplicates = $this->option('clear-same-photo-duplicates') || ! $specific;

        $summary = [];

        if ($runNames) {
            $summary['cluster_names'] = $this->triageClusterNames($limit);
        }

        if ($runDuplicates) {
            $summary['same_photo_duplicates'] = $this->triageSamePhotoDuplicates($limit);
        }

        if ($this->option('repair-linked-mismatches')) {
            $summary['linked_mismatches'] = $this->triageLinkedMismatches($limit);
        }

        if ($this->option('detach-mixed-cluster-names')) {
            $summary['mixed_cluster_names'] = $this->triageMixedClusterNames($limit);
        }

        if ($this->option('rebuild-appearance-profiles')) {
            $summary['appearance_profiles'] = $this->triageAppearanceProfiles($limit);
        }

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return Command::SUCCESS;
    }

    private function triageClusterNames(int $limit): array
    {
        $confirm = (bool) $this->option('confirm-clean-names');
        $rows = DB::connection('pgsql_rag')->select("\n            SELECT id, name, status, face_count, genealogy_person_id\n            FROM person_clusters\n            WHERE name IS NOT NULL\n              AND (name ~* 'unknown' OR name ~ '^\\s*,' OR name ~ ',\\s*$' OR name LIKE '%,,%')\n            ORDER BY face_count DESC NULLS LAST, id\n        ");

        $changes = [];
        foreach ($rows as $row) {
            $cleaned = $this->cleanClusterName((string) $row->name);
            if ($cleaned === (string) $row->name) {
                continue;
            }

            $changes[] = [
                'id' => (int) $row->id,
                'old_name' => (string) $row->name,
                'new_name' => $cleaned,
                'status' => (string) $row->status,
                'face_count' => (int) $row->face_count,
                'genealogy_person_id' => $row->genealogy_person_id === null ? null : (int) $row->genealogy_person_id,
            ];
        }

        $summary = [
            'mode' => $confirm ? 'write' : 'dry_run',
            'candidate_clusters' => count($rows),
            'clusters_to_rename' => count($changes),
            'clusters_set_null' => count(array_filter($changes, fn (array $change): bool => $change['new_name'] === null)),
            'duplicate_clean_names' => $this->duplicateCleanNameSummary($changes),
            'sample' => array_slice($changes, 0, $limit),
        ];

        if ($this->option('json')) {
            if ($confirm) {
                $summary['updated_clusters'] = $this->applyClusterNameChanges($changes);
            }

            return $summary;
        }

        $this->info(($confirm ? '[WRITE] ' : '[DRY RUN] ').'Face cluster name cleanup');
        $this->info("Candidate clusters: {$summary['candidate_clusters']}");
        $this->info("Clusters to rename: {$summary['clusters_to_rename']}");
        $this->info("Clusters set to unnamed: {$summary['clusters_set_null']}");

        if ($changes !== []) {
            $this->table(
                ['Cluster', 'Status', 'Faces', 'Old name', 'New name'],
                array_map(fn (array $change): array => [
                    $change['id'],
                    $change['status'],
                    $change['face_count'],
                    $this->shorten($change['old_name'], 60),
                    $change['new_name'] ?? '(unnamed)',
                ], array_slice($changes, 0, $limit))
            );
        }

        if ($summary['duplicate_clean_names'] !== []) {
            $this->warn('Multiple clusters will share these cleaned labels; this command does not merge clusters:');
            $this->table(
                ['Cleaned name', 'Clusters', 'Cluster IDs'],
                array_map(fn (array $row): array => [
                    $row['name'] ?? '(unnamed)',
                    $row['count'],
                    implode(', ', $row['ids']),
                ], array_slice($summary['duplicate_clean_names'], 0, $limit))
            );
        }

        if ($confirm) {
            $summary['updated_clusters'] = $this->applyClusterNameChanges($changes);
            $this->info("Updated clusters: {$summary['updated_clusters']}");
        } else {
            $this->warn('Rerun with --clean-cluster-names --confirm-clean-names to write these label changes.');
        }

        return $summary;
    }

    private function triageSamePhotoDuplicates(int $limit): array
    {
        $confirm = (bool) $this->option('confirm-clear-duplicates');
        $namedRows = $this->mysqlNamedDuplicateRows();
        $namedIds = array_fill_keys(array_map(fn ($row): int => (int) $row->id, $namedRows), true);
        $clusterRows = array_values(array_filter(
            $this->mysqlClusterDuplicateRows(),
            fn ($row): bool => ! isset($namedIds[(int) $row->id])
        ));
        $pgRows = $this->pgClusterDuplicateRows();

        $pgLinkedFaceIds = array_values(array_unique(array_filter(
            array_map(fn ($row): ?int => $row->file_registry_face_id === null ? null : (int) $row->file_registry_face_id, $pgRows)
        )));
        $pgAffectedClusters = array_values(array_unique(array_map(fn ($row): int => (int) $row->person_cluster_id, $pgRows)));

        $summary = [
            'mode' => $confirm ? 'write' : 'dry_run',
            'mysql_named_duplicate_rows_to_clear' => count($namedRows),
            'mysql_cluster_duplicate_rows_to_unassign' => count($clusterRows),
            'pg_duplicate_embeddings_to_unassign' => count($pgRows),
            'pg_linked_mysql_faces_to_unassign' => count($pgLinkedFaceIds),
            'affected_pg_clusters' => count($pgAffectedClusters),
            'samples' => [
                'named_duplicates' => $this->sampleRows($namedRows, $limit, true),
                'cluster_duplicates' => $this->sampleRows($clusterRows, $limit, false),
                'pg_duplicates' => $this->samplePgRows($pgRows, $limit),
            ],
        ];

        if ($this->option('json')) {
            if ($confirm) {
                $summary['applied'] = $this->applySamePhotoDuplicatePlan($namedRows, $clusterRows, $pgRows, $pgLinkedFaceIds, $pgAffectedClusters);
            }

            return $summary;
        }

        $this->info('');
        $this->info(($confirm ? '[WRITE] ' : '[DRY RUN] ').'Same-photo duplicate assignment cleanup');
        $this->info("MySQL duplicate named rows to clear: {$summary['mysql_named_duplicate_rows_to_clear']}");
        $this->info("MySQL duplicate cluster rows to unassign: {$summary['mysql_cluster_duplicate_rows_to_unassign']}");
        $this->info("pgvector duplicate embeddings to unassign: {$summary['pg_duplicate_embeddings_to_unassign']}");
        $this->info("Linked MySQL face rows from pgvector to unassign: {$summary['pg_linked_mysql_faces_to_unassign']}");
        $this->info("Affected pgvector clusters to recount: {$summary['affected_pg_clusters']}");

        if ($namedRows !== []) {
            $this->warn('Named duplicate rows keep the face box but clear person_name, genealogy_person_id, verified, and cluster_id.');
            $this->table(
                ['Face ID', 'File ID', 'Name', 'Cluster', 'Dupes', 'Rank'],
                array_map(fn (array $row): array => [
                    $row['id'],
                    $row['file_registry_id'],
                    $this->shorten($row['person_name'] ?? '', 35),
                    $row['cluster_id'] ?? '(none)',
                    $row['duplicate_count'],
                    $row['rn'],
                ], $summary['samples']['named_duplicates'])
            );
        }

        if ($clusterRows !== []) {
            $this->warn('Cluster duplicate rows keep the face box and any explicit name, but clear cluster_id.');
            $this->table(
                ['Face ID', 'File ID', 'Cluster', 'Name', 'Dupes', 'Rank'],
                array_map(fn (array $row): array => [
                    $row['id'],
                    $row['file_registry_id'],
                    $row['cluster_id'],
                    $this->shorten($row['person_name'] ?? '', 35),
                    $row['duplicate_count'],
                    $row['rn'],
                ], $summary['samples']['cluster_duplicates'])
            );
        }

        if ($pgRows !== []) {
            $this->table(
                ['Embedding', 'File ID', 'Face ID', 'Cluster', 'Dupes', 'Rank'],
                array_map(fn (array $row): array => [
                    $row['id'],
                    $row['file_registry_id'],
                    $row['file_registry_face_id'] ?? '(none)',
                    $row['person_cluster_id'],
                    $row['duplicate_count'],
                    $row['rn'],
                ], $summary['samples']['pg_duplicates'])
            );
        }

        if ($confirm) {
            $summary['applied'] = $this->applySamePhotoDuplicatePlan($namedRows, $clusterRows, $pgRows, $pgLinkedFaceIds, $pgAffectedClusters);
            $this->info('Applied duplicate cleanup: '.json_encode($summary['applied'], JSON_UNESCAPED_SLASHES));
        } else {
            $this->warn('Rerun with --clear-same-photo-duplicates --confirm-clear-duplicates to write these assignment changes.');
        }

        return $summary;
    }

    private function triageLinkedMismatches(int $limit): array
    {
        $confirm = (bool) $this->option('confirm-repair-mismatches');
        $treeId = $this->treeId();
        $uniqueRows = $this->linkedMismatchUniqueRows($treeId);
        $noMatchRows = $this->linkedMismatchNoMatchRows($treeId);

        $summary = [
            'mode' => $confirm ? 'write' : 'dry_run',
            'tree_id' => $treeId,
            'unique_ft_name_rows_to_remap' => count($uniqueRows),
            'no_ft_match_rows_to_unlink' => count($noMatchRows),
            'samples' => [
                'unique_ft_name_rows' => array_slice($uniqueRows, 0, $limit),
                'no_ft_match_rows' => array_slice($noMatchRows, 0, $limit),
            ],
        ];

        if ($confirm) {
            $summary['applied'] = $this->applyLinkedMismatchPlan($uniqueRows, $noMatchRows);
        }

        return $summary;
    }

    private function triageMixedClusterNames(int $limit): array
    {
        $confirm = (bool) $this->option('confirm-detach-mixed');
        $rows = $this->mixedClusterNameRows();
        $affectedClusters = array_values(array_unique(array_map(fn (array $row): int => $row['cluster_id'], $rows)));

        $summary = [
            'mode' => $confirm ? 'write' : 'dry_run',
            'named_rows_to_detach' => count($rows),
            'affected_confirmed_clusters' => count($affectedClusters),
            'samples' => array_slice($rows, 0, $limit),
            'by_cluster' => array_slice($this->mixedClusterRowsByCluster($rows), 0, $limit),
        ];

        if ($confirm) {
            $summary['applied'] = $this->applyMixedClusterNamePlan($rows, $affectedClusters);
        }

        return $summary;
    }

    private function triageAppearanceProfiles(int $limit): array
    {
        $confirm = (bool) $this->option('confirm-rebuild-profiles');
        $personIds = array_map(
            fn (object $row): int => (int) $row->genealogy_person_id,
            DB::select('SELECT DISTINCT genealogy_person_id FROM file_registry_faces WHERE genealogy_person_id IS NOT NULL AND hidden = 0 ORDER BY genealogy_person_id')
        );
        $nameGroups = $this->nameOnlyIdentityGroups();

        $summary = [
            'mode' => $confirm ? 'write' : 'dry_run',
            'genealogy_people_to_rebuild' => count($personIds),
            'name_only_identities_to_rebuild' => count($nameGroups),
            'name_only_name_variants_to_rebuild' => array_sum(array_map(fn (array $group): int => $group['variant_count'], $nameGroups)),
            'sample_person_ids' => array_slice($personIds, 0, $limit),
            'sample_names' => array_slice($nameGroups, 0, $limit),
        ];

        if ($confirm) {
            $summary['applied'] = $this->applyAppearanceProfilePlan($personIds, $nameGroups);
        }

        return $summary;
    }

    private function treeId(): int
    {
        $treeId = (int) $this->option('tree-id');

        return max(1, $treeId ?: self::GENEALOGY_TREE_ID);
    }

    private function nameOnlyIdentityGroups(): array
    {
        $rows = DB::select("SELECT DISTINCT person_name FROM file_registry_faces WHERE genealogy_person_id IS NULL AND hidden = 0 AND TRIM(COALESCE(person_name, '')) <> '' ORDER BY person_name");
        $groups = [];

        foreach ($rows as $row) {
            $rawName = (string) $row->person_name;
            $normalized = $this->normalizeIdentityName($rawName);
            if ($normalized === '') {
                continue;
            }

            $groups[$normalized] ??= [
                'identity_key' => 'name:'.$normalized,
                'display_name' => $this->normalizeDisplayName($rawName),
                'variants' => [],
                'variant_count' => 0,
            ];
            $groups[$normalized]['variants'][$rawName] = $rawName;
        }

        foreach ($groups as &$group) {
            $group['variants'] = array_values($group['variants']);
            natcasesort($group['variants']);
            $group['variants'] = array_values($group['variants']);
            $group['variant_count'] = count($group['variants']);
        }
        unset($group);

        usort($groups, fn (array $a, array $b): int => strcasecmp($a['display_name'], $b['display_name']));

        return array_values($groups);
    }

    private function normalizeIdentityName(string $name): string
    {
        return strtolower($this->normalizeDisplayName($name));
    }

    private function normalizeDisplayName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }

    private function linkedMismatchUniqueRows(int $treeId): array
    {
        return array_map(fn (object $row): array => [
            'face_id' => (int) $row->face_id,
            'file_registry_id' => (int) $row->file_registry_id,
            'person_name' => (string) $row->person_name,
            'current_person_id' => (int) $row->current_person_id,
            'current_canonical' => (string) $row->current_canonical,
            'target_person_id' => (int) $row->target_person_id,
            'cluster_id' => $row->cluster_id === null ? null : (int) $row->cluster_id,
        ], DB::select($this->linkedMismatchUniqueSql(), [$treeId, $treeId]));
    }

    private function linkedMismatchNoMatchRows(int $treeId): array
    {
        return array_map(fn (object $row): array => [
            'face_id' => (int) $row->face_id,
            'file_registry_id' => (int) $row->file_registry_id,
            'person_name' => (string) $row->person_name,
            'current_person_id' => (int) $row->current_person_id,
            'current_canonical' => (string) $row->current_canonical,
            'cluster_id' => $row->cluster_id === null ? null : (int) $row->cluster_id,
        ], DB::select($this->linkedMismatchNoMatchSql(), [$treeId, $treeId]));
    }

    private function mixedClusterNameRows(): array
    {
        $clusters = DB::connection('pgsql_rag')->select(
            "SELECT id, name FROM person_clusters WHERE status = ? AND name IS NOT NULL AND TRIM(name) <> '' ORDER BY face_count DESC, id",
            ['confirmed']
        );

        $rows = [];
        foreach ($clusters as $cluster) {
            $matches = DB::select(
                "SELECT id AS face_id, file_registry_id, person_name, genealogy_person_id, cluster_id
                 FROM file_registry_faces
                 WHERE hidden = 0
                   AND cluster_id = ?
                   AND TRIM(COALESCE(person_name, '')) <> ''
                   AND LOWER(REGEXP_REPLACE(TRIM(person_name), '[[:space:]]+', ' ')) <> LOWER(REGEXP_REPLACE(TRIM(?), '[[:space:]]+', ' '))
                 ORDER BY id",
                [(int) $cluster->id, (string) $cluster->name]
            );

            foreach ($matches as $row) {
                $rows[] = [
                    'face_id' => (int) $row->face_id,
                    'file_registry_id' => (int) $row->file_registry_id,
                    'cluster_id' => (int) $row->cluster_id,
                    'cluster_name' => (string) $cluster->name,
                    'person_name' => (string) $row->person_name,
                    'genealogy_person_id' => $row->genealogy_person_id === null ? null : (int) $row->genealogy_person_id,
                ];
            }
        }

        return $rows;
    }

    private function mixedClusterRowsByCluster(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = (string) $row['cluster_id'];
            $groups[$key] ??= [
                'cluster_id' => $row['cluster_id'],
                'cluster_name' => $row['cluster_name'],
                'rows_to_detach' => 0,
                'off_names' => [],
            ];
            $groups[$key]['rows_to_detach']++;
            $groups[$key]['off_names'][$row['person_name']] = true;
        }

        foreach ($groups as &$group) {
            $group['off_names'] = array_keys($group['off_names']);
            sort($group['off_names']);
        }
        unset($group);

        usort($groups, fn (array $a, array $b): int => $b['rows_to_detach'] <=> $a['rows_to_detach']);

        return array_values($groups);
    }

    private function applyLinkedMismatchPlan(array $uniqueRows, array $noMatchRows): array
    {
        $uniqueFaceIds = array_column($uniqueRows, 'face_id');
        $noMatchFaceIds = array_column($noMatchRows, 'face_id');
        $allFaceIds = array_values(array_unique(array_merge($uniqueFaceIds, $noMatchFaceIds)));
        $fileIds = array_values(array_unique(array_merge(
            array_column($uniqueRows, 'file_registry_id'),
            array_column($noMatchRows, 'file_registry_id')
        )));
        $affectedClusters = array_values(array_unique(array_filter(array_merge(
            array_column($uniqueRows, 'cluster_id'),
            array_column($noMatchRows, 'cluster_id')
        ))));
        $pgUnassigned = $this->updatePgFaceEmbeddingsByFaceIds($allFaceIds);
        $clusterCounts = $this->recalculatePgClusterCounts($affectedClusters);
        $remapped = 0;
        $unlinked = 0;
        $filesTouched = 0;

        DB::transaction(function () use ($uniqueRows, $noMatchFaceIds, $fileIds, &$remapped, &$unlinked, &$filesTouched): void {
            $remapped = $this->updateLinkedMismatchUniqueRows($uniqueRows);
            $unlinked = $this->updateMysqlRows($noMatchFaceIds, 'UPDATE file_registry_faces SET genealogy_person_id = NULL, verified = 0, cluster_id = NULL, updated_at = NOW() WHERE id IN (%s)');
            $filesTouched = $this->markFilesForExifFaceWriteback($fileIds);
        });

        Log::warning('FaceDataTriage: repaired linked display mismatches', [
            'unique_rows_remapped' => $remapped,
            'no_match_rows_unlinked' => $unlinked,
            'pg_embeddings_unassigned' => $pgUnassigned,
            'clusters_recounted' => $clusterCounts,
        ]);

        return [
            'unique_rows_remapped' => $remapped,
            'no_match_rows_unlinked' => $unlinked,
            'pg_embeddings_unassigned' => $pgUnassigned,
            'clusters_recounted' => $clusterCounts,
            'files_marked_for_writeback' => $filesTouched,
        ];
    }

    private function applyMixedClusterNamePlan(array $rows, array $affectedClusters): array
    {
        $faceIds = array_column($rows, 'face_id');
        $fileIds = array_values(array_unique(array_column($rows, 'file_registry_id')));

        $pgDetached = $this->updatePgFaceEmbeddingsByFaceIds($faceIds);
        $clusterCounts = $this->recalculatePgClusterCounts($affectedClusters);
        $mysqlDetached = 0;
        $filesTouched = 0;

        DB::transaction(function () use ($faceIds, $fileIds, &$mysqlDetached, &$filesTouched): void {
            $mysqlDetached = $this->updateMysqlClusterRows($faceIds);
            $filesTouched = $this->markFilesForExifFaceWriteback($fileIds);
        });

        Log::warning('FaceDataTriage: detached off-name faces from confirmed clusters', [
            'mysql_rows_detached' => $mysqlDetached,
            'pg_embeddings_detached' => $pgDetached,
            'clusters_recounted' => $clusterCounts,
        ]);

        return [
            'mysql_rows_detached' => $mysqlDetached,
            'pg_embeddings_detached' => $pgDetached,
            'clusters_recounted' => $clusterCounts,
            'files_marked_for_writeback' => $filesTouched,
        ];
    }

    private function applyAppearanceProfilePlan(array $personIds, array $nameGroups): array
    {
        $service = app(PersonFaceAppearanceProfileService::class);
        $summary = [
            'person_profiles_written' => 0,
            'name_profiles_written' => 0,
            'links_written' => 0,
            'identities_rebuilt' => 0,
            'stale_profiles_deleted' => 0,
            'skipped' => 0,
        ];

        foreach ($personIds as $personId) {
            $result = $service->rebuildForPerson((int) $personId);
            $summary['person_profiles_written'] += (int) $result['profiles_written'];
            $summary['links_written'] += (int) $result['links_written'];
            $summary['identities_rebuilt'] += (int) $result['identities_rebuilt'];
            $summary['stale_profiles_deleted'] += (int) $result['stale_profiles_deleted'];
            $summary['skipped'] += (int) $result['skipped'];
        }

        foreach ($nameGroups as $group) {
            $result = $service->rebuildForNameVariants($group['variants']);
            $summary['name_profiles_written'] += (int) $result['profiles_written'];
            $summary['links_written'] += (int) $result['links_written'];
            $summary['identities_rebuilt'] += (int) $result['identities_rebuilt'];
            $summary['stale_profiles_deleted'] += (int) $result['stale_profiles_deleted'];
            $summary['skipped'] += (int) $result['skipped'];
        }

        Log::info('FaceDataTriage: rebuilt appearance profiles', $summary);

        return $summary;
    }

    private function updateLinkedMismatchUniqueRows(array $rows): int
    {
        $updated = 0;

        foreach ($rows as $row) {
            $updated += DB::update(
                'UPDATE file_registry_faces SET genealogy_person_id = ?, verified = 1, cluster_id = NULL, updated_at = NOW() WHERE id = ? AND genealogy_person_id = ?',
                [$row['target_person_id'], $row['face_id'], $row['current_person_id']]
            );
        }

        return $updated;
    }

    private function markFilesForExifFaceWriteback(array $fileIds): int
    {
        return $this->updateMysqlRows($fileIds, 'UPDATE file_registry SET exif_faces_written = 0, updated_at = NOW() WHERE id IN (%s)');
    }

    private function updatePgFaceEmbeddingsByFaceIds(array $faceIds): int
    {
        $updated = 0;
        foreach (array_chunk(array_values(array_unique(array_filter($faceIds))), 500) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $updated += DB::connection('pgsql_rag')->update("
                UPDATE face_embeddings
                SET person_cluster_id = NULL,
                    matched_face_id = NULL,
                    match_confidence = NULL,
                    is_representative = FALSE,
                    updated_at = NOW()
                WHERE file_registry_face_id IN ({$placeholders})
            ", $chunk);
        }

        return $updated;
    }

    private function linkedMismatchUniqueSql(): string
    {
        return "SELECT f.id AS face_id,
                       f.file_registry_id,
                       f.person_name,
                       f.genealogy_person_id AS current_person_id,
                       TRIM(CONCAT_WS(' ', NULLIF(TRIM(p.given_name), ''), NULLIF(TRIM(p.surname), ''), NULLIF(TRIM(p.suffix), ''))) AS current_canonical,
                       m.target_person_id,
                       f.cluster_id
                FROM file_registry_faces f
                JOIN genealogy_persons p ON p.id = f.genealogy_person_id
                JOIN (
                    SELECT canonical_key, MIN(id) AS target_person_id
                    FROM (
                        SELECT id,
                               LOWER(REGEXP_REPLACE(TRIM(CONCAT_WS(' ', NULLIF(TRIM(given_name), ''), NULLIF(TRIM(surname), ''), NULLIF(TRIM(suffix), ''))), '[[:space:]]+', ' ')) AS canonical_key
                        FROM genealogy_persons
                        WHERE tree_id = ?
                    ) names
                    WHERE canonical_key <> ''
                    GROUP BY canonical_key
                    HAVING COUNT(*) = 1
                ) m ON m.canonical_key = LOWER(REGEXP_REPLACE(TRIM(COALESCE(f.person_name, '')), '[[:space:]]+', ' '))
                WHERE f.genealogy_person_id IS NOT NULL
                  AND p.tree_id = ?
                  AND LOWER(REGEXP_REPLACE(TRIM(COALESCE(f.person_name, '')), '[[:space:]]+', ' ')) <> ''
                  AND LOWER(REGEXP_REPLACE(TRIM(COALESCE(f.person_name, '')), '[[:space:]]+', ' ')) <> LOWER(REGEXP_REPLACE(TRIM(CONCAT_WS(' ', NULLIF(TRIM(p.given_name), ''), NULLIF(TRIM(p.surname), ''), NULLIF(TRIM(p.suffix), ''))), '[[:space:]]+', ' '))
                  AND f.genealogy_person_id <> m.target_person_id
                ORDER BY f.id";
    }

    private function linkedMismatchNoMatchSql(): string
    {
        return "SELECT f.id AS face_id,
                       f.file_registry_id,
                       f.person_name,
                       f.genealogy_person_id AS current_person_id,
                       TRIM(CONCAT_WS(' ', NULLIF(TRIM(p.given_name), ''), NULLIF(TRIM(p.surname), ''), NULLIF(TRIM(p.suffix), ''))) AS current_canonical,
                       f.cluster_id
                FROM file_registry_faces f
                JOIN genealogy_persons p ON p.id = f.genealogy_person_id
                LEFT JOIN (
                    SELECT canonical_key, COUNT(*) AS match_count
                    FROM (
                        SELECT id,
                               LOWER(REGEXP_REPLACE(TRIM(CONCAT_WS(' ', NULLIF(TRIM(given_name), ''), NULLIF(TRIM(surname), ''), NULLIF(TRIM(suffix), ''))), '[[:space:]]+', ' ')) AS canonical_key
                        FROM genealogy_persons
                        WHERE tree_id = ?
                    ) names
                    WHERE canonical_key <> ''
                    GROUP BY canonical_key
                ) m ON m.canonical_key = LOWER(REGEXP_REPLACE(TRIM(COALESCE(f.person_name, '')), '[[:space:]]+', ' '))
                WHERE f.genealogy_person_id IS NOT NULL
                  AND p.tree_id = ?
                  AND LOWER(REGEXP_REPLACE(TRIM(COALESCE(f.person_name, '')), '[[:space:]]+', ' ')) <> ''
                  AND LOWER(REGEXP_REPLACE(TRIM(COALESCE(f.person_name, '')), '[[:space:]]+', ' ')) <> LOWER(REGEXP_REPLACE(TRIM(CONCAT_WS(' ', NULLIF(TRIM(p.given_name), ''), NULLIF(TRIM(p.surname), ''), NULLIF(TRIM(p.suffix), ''))), '[[:space:]]+', ' '))
                  AND COALESCE(m.match_count, 0) = 0
                ORDER BY f.id";
    }

    private function cleanClusterName(string $name): ?string
    {
        $parts = [];
        $seen = [];

        foreach (explode(',', $name) as $part) {
            $trimmed = trim($part);
            if ($trimmed === '' || strcasecmp($trimmed, 'unknown') === 0) {
                continue;
            }

            $key = mb_strtolower($trimmed);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $parts[] = $trimmed;
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function duplicateCleanNameSummary(array $changes): array
    {
        $groups = [];
        foreach ($changes as $change) {
            $key = $change['new_name'] ?? '(unnamed)';
            $groups[$key] ??= ['name' => $change['new_name'], 'count' => 0, 'ids' => []];
            $groups[$key]['count']++;
            $groups[$key]['ids'][] = $change['id'];
        }

        return array_values(array_filter($groups, fn (array $group): bool => $group['count'] > 1));
    }

    private function applyClusterNameChanges(array $changes): int
    {
        $updated = 0;
        foreach ($changes as $change) {
            $updated += DB::connection('pgsql_rag')->update(
                'UPDATE person_clusters SET name = ?, updated_at = NOW() WHERE id = ?',
                [$change['new_name'], $change['id']]
            );
        }

        if ($updated > 0) {
            Log::info('FaceDataTriage: cleaned cluster names', ['updated' => $updated]);
        }

        return $updated;
    }

    private function mysqlNamedDuplicateRows(): array
    {
        return DB::select("\n            SELECT * FROM (\n                SELECT id, file_registry_id, person_name, genealogy_person_id, cluster_id, source, verified, favorite, confidence, face_index,\n                       ROW_NUMBER() OVER (\n                           PARTITION BY file_registry_id, LOWER(TRIM(person_name))\n                           ORDER BY favorite DESC, verified DESC, (genealogy_person_id IS NOT NULL) DESC,\n                                    COALESCE(confidence, 0) DESC, (region_w * region_h) DESC, id ASC\n                       ) AS rn,\n                       COUNT(*) OVER (PARTITION BY file_registry_id, LOWER(TRIM(person_name))) AS duplicate_count\n                FROM file_registry_faces\n                WHERE hidden = 0\n                  AND person_name IS NOT NULL\n                  AND TRIM(person_name) <> ''\n            ) ranked\n            WHERE duplicate_count > 1 AND rn > 1\n            ORDER BY duplicate_count DESC, file_registry_id, person_name, rn\n        ");
    }

    private function mysqlClusterDuplicateRows(): array
    {
        return DB::select("\n            SELECT * FROM (\n                SELECT id, file_registry_id, person_name, genealogy_person_id, cluster_id, source, verified, favorite, confidence, face_index,\n                       ROW_NUMBER() OVER (\n                           PARTITION BY file_registry_id, cluster_id\n                           ORDER BY (TRIM(COALESCE(person_name, '')) <> '') DESC, favorite DESC, verified DESC,\n                                    (genealogy_person_id IS NOT NULL) DESC, COALESCE(confidence, 0) DESC,\n                                    (region_w * region_h) DESC, id ASC\n                       ) AS rn,\n                       COUNT(*) OVER (PARTITION BY file_registry_id, cluster_id) AS duplicate_count\n                FROM file_registry_faces\n                WHERE hidden = 0\n                  AND cluster_id IS NOT NULL\n            ) ranked\n            WHERE duplicate_count > 1 AND rn > 1\n            ORDER BY duplicate_count DESC, file_registry_id, cluster_id, rn\n        ");
    }

    private function pgClusterDuplicateRows(): array
    {
        return DB::connection('pgsql_rag')->select("\n            SELECT * FROM (\n                SELECT id, file_registry_id, file_registry_face_id, person_cluster_id, quality_score, match_confidence,\n                       ROW_NUMBER() OVER (\n                           PARTITION BY file_registry_id, person_cluster_id\n                           ORDER BY quality_score DESC NULLS LAST, match_confidence DESC NULLS LAST, id ASC\n                       ) AS rn,\n                       COUNT(*) OVER (PARTITION BY file_registry_id, person_cluster_id) AS duplicate_count\n                FROM face_embeddings\n                WHERE person_cluster_id IS NOT NULL\n            ) ranked\n            WHERE duplicate_count > 1 AND rn > 1\n            ORDER BY duplicate_count DESC, file_registry_id, person_cluster_id, rn\n        ");
    }

    private function applySamePhotoDuplicatePlan(array $namedRows, array $clusterRows, array $pgRows, array $pgLinkedFaceIds, array $pgAffectedClusters): array
    {
        $namedIds = array_map(fn ($row): int => (int) $row->id, $namedRows);
        $clusterIds = array_map(fn ($row): int => (int) $row->id, $clusterRows);
        $pgIds = array_map(fn ($row): int => (int) $row->id, $pgRows);

        $applied = [
            'mysql_named_rows_cleared' => $this->updateMysqlNamedRows($namedIds),
            'mysql_cluster_rows_unassigned' => $this->updateMysqlClusterRows($clusterIds),
            'pg_embeddings_unassigned' => $this->updatePgEmbeddingRows($pgIds),
            'pg_linked_mysql_rows_unassigned' => $this->updateMysqlClusterRows($pgLinkedFaceIds),
            'pg_cluster_counts_recalculated' => $this->recalculatePgClusterCounts($pgAffectedClusters),
        ];

        Log::warning('FaceDataTriage: cleared same-photo duplicate assignments', $applied);

        return $applied;
    }

    private function updateMysqlNamedRows(array $ids): int
    {
        return $this->updateMysqlRows($ids, 'UPDATE file_registry_faces SET person_name = \'\', genealogy_person_id = NULL, verified = 0, cluster_id = NULL, updated_at = NOW() WHERE id IN (%s)');
    }

    private function updateMysqlClusterRows(array $ids): int
    {
        return $this->updateMysqlRows($ids, 'UPDATE file_registry_faces SET cluster_id = NULL, updated_at = NOW() WHERE id IN (%s)');
    }

    private function updateMysqlRows(array $ids, string $sqlTemplate): int
    {
        $updated = 0;
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $updated += DB::update(sprintf($sqlTemplate, $placeholders), $chunk);
        }

        return $updated;
    }

    private function updatePgEmbeddingRows(array $ids): int
    {
        $updated = 0;
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $updated += DB::connection('pgsql_rag')->update("\n                UPDATE face_embeddings\n                SET person_cluster_id = NULL,\n                    matched_face_id = NULL,\n                    match_confidence = NULL,\n                    is_representative = FALSE,\n                    updated_at = NOW()\n                WHERE id IN ({$placeholders})\n            ", $chunk);
        }

        return $updated;
    }

    private function recalculatePgClusterCounts(array $clusterIds): int
    {
        $updated = 0;
        foreach (array_chunk(array_values(array_unique($clusterIds)), 500) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $updated += DB::connection('pgsql_rag')->update("\n                UPDATE person_clusters pc\n                SET face_count = (\n                        SELECT COUNT(*) FROM face_embeddings fe WHERE fe.person_cluster_id = pc.id\n                    ),\n                    updated_at = NOW()\n                WHERE pc.id IN ({$placeholders})\n            ", $chunk);
        }

        return $updated;
    }

    private function sampleRows(array $rows, int $limit, bool $includeName): array
    {
        return array_map(function ($row) use ($includeName): array {
            $sample = [
                'id' => (int) $row->id,
                'file_registry_id' => (int) $row->file_registry_id,
                'cluster_id' => $row->cluster_id === null ? null : (int) $row->cluster_id,
                'duplicate_count' => (int) $row->duplicate_count,
                'rn' => (int) $row->rn,
            ];
            if ($includeName || trim((string) ($row->person_name ?? '')) !== '') {
                $sample['person_name'] = (string) ($row->person_name ?? '');
            }

            return $sample;
        }, array_slice($rows, 0, $limit));
    }

    private function samplePgRows(array $rows, int $limit): array
    {
        return array_map(fn ($row): array => [
            'id' => (int) $row->id,
            'file_registry_id' => (int) $row->file_registry_id,
            'file_registry_face_id' => $row->file_registry_face_id === null ? null : (int) $row->file_registry_face_id,
            'person_cluster_id' => (int) $row->person_cluster_id,
            'duplicate_count' => (int) $row->duplicate_count,
            'rn' => (int) $row->rn,
        ], array_slice($rows, 0, $limit));
    }

    private function shorten(?string $value, int $limit): string
    {
        $value = (string) ($value ?? '');
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, max(0, $limit - 3)).'...';
    }
}
