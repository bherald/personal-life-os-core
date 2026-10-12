<?php

namespace App\Console\Commands;

use App\Services\FaceEmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Orchestrates face clustering: named faces (PHP) + unnamed faces (Python HDBSCAN).
 *
 * Named: report stored labels as proposals without confirming identities.
 * Unnamed: Export pgvector embeddings → HDBSCAN → import cluster assignments.
 *
 * Requires: faces:migrate-embeddings to have been run first.
 */
class FaceClusterCommand extends Command
{
    protected $signature = 'faces:cluster
                            {--named-only : Only cluster named faces}
                            {--unnamed-only : Only cluster unnamed faces}
                            {--dry-run : Show stats without clustering}
                            {--reset-clusters : Clear cluster assignments and delete cluster/history rows; dry-run unless --confirm-reset is passed}
                            {--confirm-reset : Actually write --reset-clusters changes}
                            {--rebuild-after-reset : Rebuild named and unnamed clusters after a confirmed reset}
                            {--min-cluster-size=2 : HDBSCAN min_cluster_size}
                            {--backfill : Find unclustered faces and assign them}
                            {--optimize : Suggest regrouping and anchor matches; refresh derived centroids}
                            {--recluster-singletons : Report preserved small-cluster memberships requiring review}
                            {--dedup : Inspect exact-vector collisions; destructive correction is held}
                            {--purge-bloat : Evict mismatched faces from bloated confirmed clusters}
                            {--similarity=0.92 : Similarity threshold for purge-bloat (default 0.92)}
                            {--stats : Show current clustering stats}';

    protected $description = 'Cluster faces using named pre-clustering + HDBSCAN for unnamed';

    public function handle(): int
    {
        $faceService = app(FaceEmbeddingService::class);

        if ($this->option('stats')) {
            return $this->showStats($faceService);
        }

        if ($this->option('reset-clusters')) {
            return $this->resetClusters($faceService);
        }

        if ($this->option('dedup')) {
            return $this->deduplicateEmbeddings();
        }

        if ($this->option('purge-bloat')) {
            return $this->purgeBloatedClusters($faceService);
        }

        if ($this->option('backfill') && $this->option('optimize')) {
            // Combined: backfill then optimize (for scheduled job)
            $result = $this->backfillUnclustered($faceService);
            if ($result !== Command::SUCCESS) {
                return $result;
            }
            $this->info('');

            return $this->runOptimize($faceService);
        }

        if ($this->option('recluster-singletons') && $this->option('optimize')) {
            // Combined: recluster singletons then optimize centroids + cleanup (for face_recluster_full job)
            $rc = $this->reclusterSingletons($faceService);
            if ($rc !== Command::SUCCESS) {
                return $rc;
            }
            $this->info('');

            return $this->runOptimize($faceService);
        }

        if ($this->option('backfill')) {
            return $this->backfillUnclustered($faceService);
        }

        if ($this->option('optimize')) {
            return $this->runOptimize($faceService);
        }

        if ($this->option('recluster-singletons')) {
            return $this->reclusterSingletons($faceService);
        }

        $dryRun = $this->option('dry-run');

        // Check prerequisites
        $pgCount = DB::connection('pgsql_rag')->selectOne('SELECT COUNT(*) as cnt FROM face_embeddings')->cnt;
        if ($pgCount === 0) {
            $this->error('No embeddings in pgvector. Run faces:migrate-embeddings first.');

            return Command::FAILURE;
        }

        $startTime = microtime(true);

        // Phase 1: Named faces
        if (! $this->option('unnamed-only')) {
            $this->info('Phase 1: Clustering named faces...');

            if ($dryRun) {
                $namedCount = DB::selectOne("
                    SELECT COUNT(DISTINCT person_name) as cnt
                    FROM file_registry_faces
                    WHERE person_name != '' AND hidden = 0
                ")->cnt;
                $this->info("  Would report ~{$namedCount} named groups for operator review; memberships preserved");
            } else {
                $result = $faceService->clusterNamedFaces();
                $this->info("  People found: {$result['people_found']}");
                $this->info("  Clusters created: {$result['clusters_created']}");
                $this->info("  Faces linked: {$result['faces_linked']}");
                if ($result['errors'] > 0) {
                    $this->warn("  Errors: {$result['errors']}");
                }
            }
        }

        // Phase 2: Unnamed faces via HDBSCAN
        if (! $this->option('named-only')) {
            $this->info('');
            $this->info('Phase 2: Clustering unnamed faces with HDBSCAN...');

            $selection = $this->anonymousClusterCohorts($faceService);
            $this->info('  Eligible anonymous embeddings: '.$selection['eligible']);
            $this->info('  Held selected input records: '.$selection['held']);
            if ($dryRun) {
                foreach ($selection['cohorts'] as $model => $data) {
                    $this->info('  [DRY RUN] Model '.$model.': '.count($data).' eligible inputs');
                }
                $this->info('  Input admission only; no assignments, clustering quality or identity measured.');
            } elseif ($selection['eligible'] === 0) {
                $this->info('  No eligible anonymous faces. Skipping HDBSCAN.');
            } else {
                $result = $this->runHdbscan($faceService, (int) $this->option('min-cluster-size'), $selection);
                if (! $result['success']) {
                    $this->error('  HDBSCAN held: '.$result['error']);
                    $this->info('  Already published faces: '.($result['faces_assigned'] ?? 0));

                    return Command::FAILURE;
                }
                $this->info('  Clusters created: '.$result['clusters_created']);
                $this->info('  Faces assigned: '.$result['faces_assigned']);
            }
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->info('');
        $this->info("Done in {$elapsed}s");
        $this->line('[ITEMS_PROCESSED:1]');

        return Command::SUCCESS;
    }

    /** Keep unsafe vectors out of algorithm neighborhoods, not merely out of publication. */
    private function anonymousClusterCohorts(FaceEmbeddingService $faceService): array
    {
        $rows = DB::connection('pgsql_rag')->select("SELECT id, embedding::text AS embedding_str, embedding_model, file_registry_id, file_registry_face_id, source_sha256, face_region_convention, source_current, superseded_at, person_cluster_id, region_x, region_y, region_w, region_h
            FROM face_embeddings WHERE person_cluster_id IS NULL
            AND source_current = TRUE AND superseded_at IS NULL
            AND file_registry_face_id IS NOT NULL AND source_sha256 IS NOT NULL
            AND face_region_convention = 'normalized_top_left' ORDER BY id");
        $cohorts = $snapshots = [];
        $held = $eligible = 0;
        foreach ($rows as $row) {
            $vector = json_decode($row->embedding_str, true);
            $norm = 0.0;
            $valid = is_array($vector) && array_is_list($vector) && count($vector) === 128;
            foreach ($valid ? $vector : [] as $value) {
                if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                    $valid = false;
                    break;
                }
                $norm += $value * $value;
            }
            $model = $row->embedding_model ?? null;
            if (! $valid || ! is_finite($norm) || $norm <= 0 || ! is_string($model) || trim($model) === ''
                || ! $faceService->canAutomaticallyGroupFace((int) $row->id)) {
                $held++;

                continue;
            }
            $cohorts[$model][] = ['id' => (int) $row->id, 'embedding' => $vector];
            $snapshots[(int) $row->id] = (array) $row;
            $eligible++;
        }

        return ['cohorts' => $cohorts, 'snapshots' => $snapshots, 'held' => $held, 'eligible' => $eligible];
    }

    private function runHdbscan(FaceEmbeddingService $faceService, int $minClusterSize, ?array $selection = null): array
    {
        $selection ??= $this->anonymousClusterCohorts($faceService);
        $totals = ['success' => true, 'clusters_created' => 0, 'faces_assigned' => 0, 'errors' => 0];
        if ($minClusterSize < 2) {
            return ['success' => false, 'error' => 'invalid_min_cluster_size'];
        }
        foreach ($selection['cohorts'] as $data) {
            if (count($data) < $minClusterSize) {
                return ['success' => false, 'error' => 'insufficient_model_cohort'];
            }
        }
        foreach ($selection['cohorts'] as $model => $data) {
            $inputFile = $outputFile = null;
            try {
                $inputFile = tempnam(sys_get_temp_dir(), 'face_cluster_input_');
                $outputFile = tempnam(sys_get_temp_dir(), 'face_cluster_output_');
                if (! $inputFile || ! $outputFile || ! chmod($inputFile, 0600) || ! chmod($outputFile, 0600)) {
                    throw new \RuntimeException('private_cluster_artifact_unavailable');
                }
                $bytes = json_encode($data, JSON_THROW_ON_ERROR);
                if (file_put_contents($inputFile, $bytes) !== strlen($bytes)) {
                    throw new \RuntimeException('private_cluster_artifact_unavailable');
                }
                $process = Process::timeout(300)->run(['python3', base_path('scripts/face_clusterer.py'),
                    '--input', $inputFile, '--output', $outputFile, '--min-cluster-size', (string) $minClusterSize]);
                if (! $process->successful()) {
                    throw new \RuntimeException('cluster_process_failed');
                }
                clearstatcache(true, $outputFile);
                if (is_link($outputFile) || ! is_file($outputFile) || (fileperms($outputFile) & 0777) !== 0600) {
                    throw new \RuntimeException('private_cluster_artifact_unavailable');
                }
                $bytes = file_get_contents($outputFile);
                $result = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
                $ids = array_column($data, 'id');
                $assignments = $this->validatedClusterAssignments($result, $bytes, $ids);
                if ($assignments === null) {
                    throw new \RuntimeException('invalid_cluster_assignments');
                }
                // Losing any member invalidates this cohort's neighborhoods, even
                // when later per-face publication would reject only that member.
                foreach ($ids as $id) {
                    if (! $faceService->canAutomaticallyGroupFace($id)) {
                        throw new \RuntimeException('cluster_cohort_changed');
                    }
                }
                $current = DB::connection('pgsql_rag')->select('SELECT id, embedding::text AS embedding_str, embedding_model, file_registry_id, file_registry_face_id, source_sha256, face_region_convention, source_current, superseded_at, person_cluster_id, region_x, region_y, region_w, region_h FROM face_embeddings WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).') ORDER BY id', $ids);
                if (count($current) !== count($data)) {
                    throw new \RuntimeException('cluster_cohort_changed');
                }
                foreach ($current as $row) {
                    if (($selection['snapshots'][(int) $row->id] ?? null) !== (array) $row) {
                        throw new \RuntimeException('cluster_cohort_changed');
                    }
                }
                $import = $faceService->importClusterAssignments($assignments);
                foreach (['clusters_created', 'faces_assigned', 'errors'] as $counter) {
                    $totals[$counter] += $import[$counter];
                }
                if ($import['errors'] > 0) {
                    throw new \RuntimeException('cluster_publication_errors');
                }
            } catch (\Throwable $exception) {
                return array_replace($totals, ['success' => false, 'error' => $exception instanceof \RuntimeException
                    ? $exception->getMessage() : 'invalid_cluster_result']);
            } finally {
                foreach ([$inputFile, $outputFile] as $path) {
                    if (is_string($path)) {
                        @unlink($path);
                    }
                }
            }
        }

        return $totals;
    }

    /** JSON decoding alone loses duplicate keys; accept the producer's flat canonical ID map. */
    private function validatedClusterAssignments(mixed $result, string $bytes, array $ids): ?array
    {
        $assignments = is_array($result) ? ($result['assignments'] ?? null) : null;
        if (($result['success'] ?? false) !== true || ! is_array($assignments) || count($assignments) !== count($ids)
            || preg_match_all('/"assignments"\s*:\s*\{([^{}]*)\}/s', $bytes, $maps) !== 1) {
            return null;
        }
        $seen = [];
        foreach (explode(',', $maps[1][0]) as $entry) {
            if (! preg_match('/^\s*"([1-9][0-9]*)"\s*:\s*(0|[1-9][0-9]*)\s*$/D', $entry, $match)) {
                return null;
            }
            $id = (int) $match[1];
            $label = $assignments[$id] ?? null;
            if ((string) $id !== $match[1] || isset($seen[$id]) || ! in_array($id, $ids, true)
                || ! is_int($label) || $label < 0 || (string) $label !== $match[2]) {
                return null;
            }
            $seen[$id] = true;
        }

        return count($seen) === count($ids) ? $assignments : null;
    }

    private function reclusterSingletons(FaceEmbeddingService $faceService): int
    {
        $row = DB::connection('pgsql_rag')->selectOne("SELECT COUNT(*) AS cnt FROM face_embeddings fe
            JOIN person_clusters pc ON pc.id = fe.person_cluster_id
            WHERE pc.status = 'unreviewed' AND pc.face_count <= 3");
        $count = (int) ($row->cnt ?? 0);
        $this->info("Small-cluster faces preserved: {$count}");
        $this->info('Existing memberships require operator review before regrouping; no anchor merges or assignments applied.');

        return Command::SUCCESS;
    }

    private function backfillUnclustered(FaceEmbeddingService $faceService): int
    {
        $this->info('Backfilling unclustered faces...');

        // Only current vectors with an unambiguous current MySQL face row may
        // enter assignment maintenance. Historical rows stay untouched.
        $unclustered = DB::connection('pgsql_rag')->select('
            SELECT fe.id, fe.embedding::text as embedding_str, fe.file_registry_id, fe.file_registry_face_id,
                   COUNT(*) OVER (PARTITION BY fe.file_registry_face_id) AS current_face_link_count
            FROM face_embeddings fe
            WHERE fe.person_cluster_id IS NULL
              AND fe.source_current = TRUE
              AND fe.superseded_at IS NULL
              AND fe.file_registry_face_id IS NOT NULL
            ORDER BY fe.id
            LIMIT 5000
        ');

        if (! empty($unclustered)) {
            $ids = array_values(array_unique(array_map(fn ($row) => (int) $row->file_registry_face_id, $unclustered)));
            $currentFaces = DB::select('SELECT id, file_registry_id, source_current, superseded_at
                FROM file_registry_faces
                WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')
                  AND source_current = 1 AND superseded_at IS NULL', $ids);
            $currentById = [];
            foreach ($currentFaces as $face) {
                $currentById[(int) $face->id] = $face;
            }
            $unclustered = array_values(array_filter($unclustered, function ($vector) use ($currentById) {
                $face = $currentById[(int) $vector->file_registry_face_id] ?? null;

                return $face
                    && (int) $face->file_registry_id === (int) $vector->file_registry_id
                    && (int) $face->source_current === 1
                    && $face->superseded_at === null
                    && (int) ($vector->current_face_link_count ?? 0) === 1;
            }));
        }

        if (empty($unclustered)) {
            // Only current MySQL rows are eligible for missing-vector backfill.
            // Old provenance stays unknown.
            $mysqlUnclustered = DB::select("
                SELECT id, file_registry_id, embedding, region_x, region_y, region_w, region_h, confidence
                FROM file_registry_faces
                WHERE cluster_id IS NULL
                AND embedding IS NOT NULL AND embedding != '' AND embedding != '[]'
                AND source_current = 1 AND superseded_at IS NULL
                LIMIT 1000
            ");

            $inserted = 0;
            foreach ($mysqlUnclustered as $face) {
                // Check if pgvector row exists
                $exists = DB::connection('pgsql_rag')->selectOne('
                    SELECT id, file_registry_id FROM face_embeddings
                    WHERE file_registry_face_id = ? AND source_current = TRUE AND superseded_at IS NULL
                ', [$face->id]);

                if (! $exists) {
                    $current = DB::selectOne('SELECT id, file_registry_id, embedding, region_x, region_y, region_w, region_h, confidence
                        FROM file_registry_faces
                        WHERE id = ? AND file_registry_id = ? AND source_current = 1 AND superseded_at IS NULL',
                        [$face->id, $face->file_registry_id]);
                    if (! $current || $current->embedding !== $face->embedding) {
                        continue;
                    }
                    $face = $current;
                    $embData = json_decode($face->embedding, true);
                    if (is_array($embData) && count($embData) === 128) {
                        $embStr = '['.implode(',', $embData).']';
                        try {
                            DB::connection('pgsql_rag')->insert('
                                INSERT INTO face_embeddings
                                (file_registry_id, file_registry_face_id, embedding,
                                 region_x, region_y, region_w, region_h, quality_score,
                                 source_current, superseded_at, created_at, updated_at)
                                VALUES (?, ?, ?::vector, ?, ?, ?, ?, ?, TRUE, NULL, NOW(), NOW())
                                ON CONFLICT (file_registry_face_id)
                                WHERE file_registry_face_id IS NOT NULL AND source_current IS TRUE AND superseded_at IS NULL
                                DO NOTHING
                            ', [
                                $face->file_registry_id, $face->id, $embStr,
                                (float) $face->region_x, (float) $face->region_y,
                                (float) $face->region_w, (float) $face->region_h,
                                (float) ($face->confidence ?? 0.9),
                            ]);
                            $inserted++;
                        } catch (\Exception $e) {
                            // Skip errors
                        }
                    }
                } elseif ((int) $exists->file_registry_id !== (int) $face->file_registry_id) {
                    $this->warn('A current vector link conflicts with the source registry; it was left unchanged.');
                }
            }

            if ($inserted > 0) {
                $this->info("Inserted {$inserted} missing pgvector rows. Re-run --backfill to assign clusters.");

                return Command::SUCCESS;
            }

            $this->info('No unclustered faces found.');

            return Command::SUCCESS;
        }

        $this->info('Found '.count($unclustered).' unclustered pgvector embeddings');

        $assigned = 0;
        $singletons = 0;
        $preserved = 0;
        $unknown = 0;
        $suggestions = 0;

        foreach ($unclustered as $fe) {
            $vec = array_map('floatval', explode(',', trim($fe->embedding_str, '[]')));
            if (count($vec) !== 128) {
                continue;
            }

            $result = $faceService->assignToCluster($fe->id, $vec);
            if ($result['action'] === 'assigned') {
                $assigned++;
            } elseif ($result['action'] === 'singleton') {
                $singletons++;
            } elseif ($result['action'] === 'unknown') {
                $unknown++;
                $this->warn('Automatic grouping outcome is partial or unknown for vector '.(int) $fe->id.'; no automatic replay is authorized.');
                break;
            } else {
                $preserved++;
            }
            $suggestions += count($result['suggestions'] ?? []);
        }

        $this->info("  Assigned to existing: {$assigned}");
        $this->info("  New singletons: {$singletons}");
        $this->info("  Preserved or awaiting review: {$preserved}");
        $this->info("  Partial or unknown publication outcomes: {$unknown}");
        $this->info("  Anchor suggestions for review: {$suggestions}");

        return $unknown > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function runOptimize(FaceEmbeddingService $faceService): int
    {
        $dryRun = $this->option('dry-run');
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        $this->info("{$prefix}Running cluster optimization...");
        $startTime = microtime(true);

        $results = $faceService->optimizeClusters($dryRun);

        if (isset($results['error'])) {
            $this->error("Optimization failed: {$results['error']}");

            return Command::FAILURE;
        }

        $this->info("{$prefix}  Unreviewed clusters merged: {$results['unreviewed_merges']}");
        $this->info("{$prefix}  Anchor auto-merges: {$results['anchor_merges']}");
        $this->info("{$prefix}  Anchor suggestions: ".count($results['anchor_suggestions']));
        $this->info("{$prefix}  Unreviewed regrouping suggestions: ".count($results['unreviewed_suggestions'] ?? []));
        $this->info("{$prefix}  Centroids updated: {$results['centroids_updated']}");
        $this->info("{$prefix}  Empty clusters purged: {$results['empty_purged']}");
        $this->info("{$prefix}  Skipped (retry limit): {$results['skipped_retry_limit']}");

        if (! empty($results['anchor_suggestions'])) {
            $this->info('');
            $this->info('Anchor suggestions for human review:');
            foreach (array_slice($results['anchor_suggestions'], 0, 10) as $s) {
                $this->info(sprintf(
                    '  Cluster #%d (%d faces) → %s (#%d) [sim=%.3f]',
                    $s['unreviewed_cluster_id'],
                    $s['unreviewed_face_count'],
                    $s['suggested_name'],
                    $s['suggested_anchor_id'],
                    $s['similarity']
                ));
            }
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->info("Optimization done in {$elapsed}s");

        return Command::SUCCESS;
    }

    private function resetClusters(FaceEmbeddingService $faceService): int
    {
        $dryRun = $this->option('dry-run') || ! $this->option('confirm-reset');
        $rebuild = (bool) $this->option('rebuild-after-reset');
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        $this->info("{$prefix}Face cluster reset");
        $this->warn('Preserves face detections, crops, embeddings, names, hidden flags, and genealogy person IDs.');
        $this->warn('Clears only cluster assignments, match confidence links, cluster candidates, merge history, and cluster rows.');

        $before = $this->clusterResetSnapshot();
        $this->displayClusterResetSnapshot($before, 'Current cluster state');

        if ($dryRun) {
            $this->info('');
            $this->info('Would clear MySQL file_registry_faces.cluster_id for clustered face rows.');
            $this->info('Would clear pgvector face_embeddings.person_cluster_id, matched_face_id, match_confidence, and representative flags.');
            $this->info('Would delete face_match_candidates tied to clusters, cluster_merge_history rows, and all person_clusters rows.');
            if (! $this->option('confirm-reset')) {
                $this->warn('Rerun with --confirm-reset to write the reset.');
            }
            if ($rebuild) {
                $this->warn('--rebuild-after-reset is skipped during dry-run.');
            }

            return Command::SUCCESS;
        }

        $startTime = microtime(true);
        $mysqlFacesCleared = 0;
        $pgFacesCleared = 0;
        $candidatesDeleted = 0;
        $mergeHistoryDeleted = 0;
        $clustersDeleted = 0;

        try {
            $mysqlFacesCleared = DB::update('
                UPDATE file_registry_faces
                SET cluster_id = NULL, updated_at = NOW()
                WHERE cluster_id IS NOT NULL
            ');

            DB::connection('pgsql_rag')->transaction(function () use (
                &$pgFacesCleared,
                &$candidatesDeleted,
                &$mergeHistoryDeleted,
                &$clustersDeleted
            ): void {
                $mergeHistoryDeleted = DB::connection('pgsql_rag')->delete('DELETE FROM cluster_merge_history');

                $candidatesDeleted = DB::connection('pgsql_rag')->delete('
                    DELETE FROM face_match_candidates
                    WHERE candidate_cluster_id IS NOT NULL
                ');

                $pgFacesCleared = DB::connection('pgsql_rag')->update('
                    UPDATE face_embeddings
                    SET person_cluster_id = NULL,
                        matched_face_id = NULL,
                        match_confidence = NULL,
                        is_representative = FALSE,
                        updated_at = NOW()
                    WHERE person_cluster_id IS NOT NULL
                       OR matched_face_id IS NOT NULL
                       OR match_confidence IS NOT NULL
                       OR is_representative IS TRUE
                ');

                DB::connection('pgsql_rag')->update('
                    UPDATE person_clusters
                    SET merged_into_id = NULL
                    WHERE merged_into_id IS NOT NULL
                ');

                $clustersDeleted = DB::connection('pgsql_rag')->delete('DELETE FROM person_clusters');
            });
        } catch (\Throwable $e) {
            Log::error('FaceCluster: reset failed', [
                'error' => $e->getMessage(),
                'mysql_faces_cleared' => $mysqlFacesCleared,
                'pg_faces_cleared' => $pgFacesCleared,
            ]);
            $this->error("Face cluster reset failed: {$e->getMessage()}");

            return Command::FAILURE;
        }

        $this->info('');
        $this->info('Reset applied:');
        $this->info("  MySQL face cluster IDs cleared: {$mysqlFacesCleared}");
        $this->info("  pgvector face assignments cleared: {$pgFacesCleared}");
        $this->info("  face match candidates deleted: {$candidatesDeleted}");
        $this->info("  merge history rows deleted: {$mergeHistoryDeleted}");
        $this->info("  person clusters deleted: {$clustersDeleted}");

        $after = $this->clusterResetSnapshot();
        $this->displayClusterResetSnapshot($after, 'Cluster state after reset');

        Log::warning('FaceCluster: clusters reset', [
            'before' => $before,
            'after' => $after,
            'mysql_faces_cleared' => $mysqlFacesCleared,
            'pg_faces_cleared' => $pgFacesCleared,
            'candidates_deleted' => $candidatesDeleted,
            'merge_history_deleted' => $mergeHistoryDeleted,
            'clusters_deleted' => $clustersDeleted,
            'rebuild_after_reset' => $rebuild,
        ]);

        if ($rebuild) {
            $this->info('');
            $rebuildResult = $this->rebuildClustersAfterReset($faceService);
            if ($rebuildResult !== Command::SUCCESS) {
                return $rebuildResult;
            }
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->info("Face cluster reset done in {$elapsed}s");

        return Command::SUCCESS;
    }

    private function rebuildClustersAfterReset(FaceEmbeddingService $faceService): int
    {
        $this->info('Rebuilding clusters after reset...');

        if (! $this->option('unnamed-only')) {
            $this->info('Phase 1: Clustering named faces...');
            $result = $faceService->clusterNamedFaces();
            $this->info("  People found: {$result['people_found']}");
            $this->info("  Clusters created: {$result['clusters_created']}");
            $this->info("  Faces linked: {$result['faces_linked']}");
            if ($result['errors'] > 0) {
                $this->warn("  Errors: {$result['errors']}");
            }
        }

        if (! $this->option('named-only')) {
            $this->info('');
            $this->info('Phase 2: Clustering unnamed faces with HDBSCAN...');

            $unclusteredCount = DB::connection('pgsql_rag')->selectOne('
                SELECT COUNT(*) as cnt FROM face_embeddings WHERE person_cluster_id IS NULL
            ')->cnt;

            $this->info("  Unclustered embeddings: {$unclusteredCount}");
            if ($unclusteredCount > 0) {
                $result = $this->runHdbscan($faceService, (int) $this->option('min-cluster-size'));
                if (! $result['success']) {
                    $this->error('  HDBSCAN failed');

                    return Command::FAILURE;
                }

                $this->info("  Clusters created: {$result['clusters_created']}");
                $this->info("  Faces assigned: {$result['faces_assigned']}");
                if (($result['errors'] ?? 0) > 0) {
                    $this->warn("  Errors: {$result['errors']}");
                }
            }
        }

        return Command::SUCCESS;
    }

    private function clusterResetSnapshot(): array
    {
        $pgFaces = DB::connection('pgsql_rag')->selectOne('
            SELECT
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE person_cluster_id IS NOT NULL) as clustered,
                COUNT(DISTINCT person_cluster_id) as distinct_clusters,
                COUNT(*) FILTER (WHERE matched_face_id IS NOT NULL) as matched,
                COUNT(*) FILTER (WHERE match_confidence IS NOT NULL) as with_confidence,
                COUNT(*) FILTER (WHERE is_representative IS TRUE) as representatives
            FROM face_embeddings
        ');

        $pgClusters = DB::connection('pgsql_rag')->selectOne("
            SELECT
                COUNT(*) as total,
                COALESCE(SUM(face_count), 0) as recorded_faces,
                COUNT(*) FILTER (WHERE status = 'unreviewed') as unreviewed,
                COUNT(*) FILTER (WHERE status = 'confirmed') as confirmed,
                COUNT(*) FILTER (WHERE status = 'merged') as merged,
                COUNT(*) FILTER (WHERE status = 'ignored') as ignored,
                COUNT(*) FILTER (WHERE genealogy_person_id IS NOT NULL) as linked
            FROM person_clusters
        ");

        $mergeHistory = DB::connection('pgsql_rag')->selectOne('
            SELECT COUNT(*) as total FROM cluster_merge_history
        ');

        $candidates = DB::connection('pgsql_rag')->selectOne('
            SELECT COUNT(*) as total
            FROM face_match_candidates
            WHERE candidate_cluster_id IS NOT NULL
        ');

        $mysqlFaces = DB::selectOne("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN cluster_id IS NOT NULL THEN 1 ELSE 0 END) as clustered,
                SUM(CASE WHEN person_name != '' THEN 1 ELSE 0 END) as named,
                SUM(CASE WHEN genealogy_person_id IS NOT NULL THEN 1 ELSE 0 END) as linked,
                SUM(CASE WHEN hidden = 1 THEN 1 ELSE 0 END) as hidden
            FROM file_registry_faces
        ");

        $scheduledJobs = DB::selectOne("
            SELECT COUNT(*) as enabled
            FROM scheduled_jobs
            WHERE enabled = 1
              AND (command LIKE 'faces:cluster%' OR name LIKE 'face%cluster%')
        ");

        return [
            'pg_faces_total' => (int) ($pgFaces->total ?? 0),
            'pg_faces_clustered' => (int) ($pgFaces->clustered ?? 0),
            'pg_faces_distinct_clusters' => (int) ($pgFaces->distinct_clusters ?? 0),
            'pg_faces_matched' => (int) ($pgFaces->matched ?? 0),
            'pg_faces_with_confidence' => (int) ($pgFaces->with_confidence ?? 0),
            'pg_faces_representatives' => (int) ($pgFaces->representatives ?? 0),
            'clusters_total' => (int) ($pgClusters->total ?? 0),
            'clusters_recorded_faces' => (int) ($pgClusters->recorded_faces ?? 0),
            'clusters_unreviewed' => (int) ($pgClusters->unreviewed ?? 0),
            'clusters_confirmed' => (int) ($pgClusters->confirmed ?? 0),
            'clusters_merged' => (int) ($pgClusters->merged ?? 0),
            'clusters_ignored' => (int) ($pgClusters->ignored ?? 0),
            'clusters_linked' => (int) ($pgClusters->linked ?? 0),
            'merge_history_total' => (int) ($mergeHistory->total ?? 0),
            'match_candidates_total' => (int) ($candidates->total ?? 0),
            'mysql_faces_total' => (int) ($mysqlFaces->total ?? 0),
            'mysql_faces_clustered' => (int) ($mysqlFaces->clustered ?? 0),
            'mysql_faces_named' => (int) ($mysqlFaces->named ?? 0),
            'mysql_faces_linked' => (int) ($mysqlFaces->linked ?? 0),
            'mysql_faces_hidden' => (int) ($mysqlFaces->hidden ?? 0),
            'scheduled_face_jobs_enabled' => (int) ($scheduledJobs->enabled ?? 0),
        ];
    }

    private function displayClusterResetSnapshot(array $snapshot, string $title): void
    {
        $this->info('');
        $this->info($title);
        $this->info('─────────────────────');
        $this->table(
            ['Area', 'Count', 'Reset impact'],
            [
                ['pgvector face embeddings', $snapshot['pg_faces_total'], 'preserved'],
                ['pgvector clustered embeddings', $snapshot['pg_faces_clustered'], 'person_cluster_id cleared'],
                ['pgvector distinct cluster refs', $snapshot['pg_faces_distinct_clusters'], 'cleared from embeddings'],
                ['pgvector matched faces', $snapshot['pg_faces_matched'], 'matched_face_id cleared'],
                ['pgvector confidence values', $snapshot['pg_faces_with_confidence'], 'match_confidence cleared'],
                ['pgvector representatives', $snapshot['pg_faces_representatives'], 'is_representative cleared'],
                ['person_clusters rows', $snapshot['clusters_total'], 'deleted'],
                ['person_clusters recorded face_count', $snapshot['clusters_recorded_faces'], 'deleted with rows'],
                ['confirmed clusters', $snapshot['clusters_confirmed'], 'recreated from named faces on rebuild'],
                ['unreviewed clusters', $snapshot['clusters_unreviewed'], 'recreated by HDBSCAN on rebuild'],
                ['merged clusters', $snapshot['clusters_merged'], 'deleted'],
                ['ignored clusters', $snapshot['clusters_ignored'], 'deleted'],
                ['clusters linked to genealogy', $snapshot['clusters_linked'], 'cluster link deleted; face/person IDs preserved'],
                ['cluster merge history', $snapshot['merge_history_total'], 'deleted'],
                ['face match candidates', $snapshot['match_candidates_total'], 'deleted when tied to clusters'],
                ['MySQL face rows', $snapshot['mysql_faces_total'], 'preserved'],
                ['MySQL clustered faces', $snapshot['mysql_faces_clustered'], 'cluster_id cleared'],
                ['MySQL named faces', $snapshot['mysql_faces_named'], 'preserved'],
                ['MySQL genealogy-linked faces', $snapshot['mysql_faces_linked'], 'preserved'],
                ['MySQL hidden faces', $snapshot['mysql_faces_hidden'], 'preserved'],
                ['enabled face recluster jobs', $snapshot['scheduled_face_jobs_enabled'], 'disable during reset/rebuild if greater than 0'],
            ]
        );
    }

    private function showStats(FaceEmbeddingService $faceService): int
    {
        $stats = $faceService->getStats();

        $this->info('Face Clustering Stats');
        $this->info('─────────────────────');
        $this->info("pgvector faces: {$stats['total_faces']}");
        $this->info("Total clusters: {$stats['total_clusters']}");
        $this->info("  Unreviewed: {$stats['clusters_unreviewed']}");
        $this->info("  Confirmed: {$stats['clusters_confirmed']}");
        $this->info("  Merged: {$stats['clusters_merged']}");
        $this->info("  Ignored: {$stats['clusters_ignored']}");
        $this->info("  Linked to genealogy: {$stats['clusters_linked']}");
        $this->info("Files with faces: {$stats['files_with_faces']}");

        // MySQL stats
        $mysqlStats = DB::selectOne("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN person_name != '' THEN 1 ELSE 0 END) as named,
                SUM(CASE WHEN cluster_id IS NOT NULL THEN 1 ELSE 0 END) as clustered,
                SUM(CASE WHEN hidden = 1 THEN 1 ELSE 0 END) as hidden
            FROM file_registry_faces
        ");

        $this->info('');
        $this->info('MySQL file_registry_faces');
        $this->info('────────────────────────');
        $this->info("Total: {$mysqlStats->total}");
        $this->info("Named: {$mysqlStats->named}");
        $this->info("Clustered: {$mysqlStats->clustered}");
        $this->info("Hidden: {$mysqlStats->hidden}");
        $this->info('Unclustered: '.($mysqlStats->total - $mysqlStats->clustered));

        return Command::SUCCESS;
    }

    /** Equal embedding values can belong to distinct valid photos; never delete by numeric equality. */
    private function deduplicateEmbeddings(): int
    {
        $this->info('Inspecting exact-vector collisions; numeric equality does not authorize face or history deletion.');
        $dupeGroups = DB::connection('pgsql_rag')->select('
            SELECT COUNT(*) AS cnt, COUNT(DISTINCT file_registry_id) AS unique_files
            FROM face_embeddings
            GROUP BY embedding::text
            HAVING COUNT(DISTINCT file_registry_id) > 1
            ORDER BY COUNT(*) DESC
        ');

        if (empty($dupeGroups)) {
            $this->info('No cross-registry exact-vector collisions found.');

            return Command::SUCCESS;
        }

        $this->warn('Cross-registry exact-vector collisions: '.count($dupeGroups).'. No rows were changed; use source-duplicate review/repair with source evidence.');

        return Command::FAILURE;
    }

    /**
     * Purge faces from bloated confirmed clusters that don't match the
     * authoritative centroid (computed from XMP-named faces only).
     */
    private function purgeBloatedClusters(FaceEmbeddingService $faceService): int
    {
        $dryRun = $this->option('dry-run');
        $similarity = (float) $this->option('similarity');
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        $this->info("{$prefix}Purging bloated confirmed clusters (similarity >= {$similarity})...");
        $startTime = microtime(true);

        $results = $faceService->purgeConfirmedBloat(10.0, $similarity, $dryRun);

        if (isset($results['error'])) {
            $this->error("Failed: {$results['error']}");

            return Command::FAILURE;
        }

        $this->info("{$prefix}Clusters checked: {$results['clusters_checked']}");
        $this->info("{$prefix}Clusters purged: {$results['clusters_purged']}");
        $this->info("{$prefix}Faces evicted: {$results['faces_evicted']}");

        if (! empty($results['details'])) {
            $this->info('');
            $this->table(
                ['Cluster', 'Name', 'Was', 'Named', 'Auth', 'Ratio', 'Evicted'],
                array_map(fn ($d) => [
                    $d['cluster_id'],
                    substr($d['name'], 0, 25),
                    $d['cluster_faces'],
                    $d['named_faces'],
                    $d['authoritative_embeddings'],
                    $d['ratio'].'x',
                    $d['evictable'],
                ], $results['details'])
            );
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->info("Purge done in {$elapsed}s");

        return Command::SUCCESS;
    }
}
