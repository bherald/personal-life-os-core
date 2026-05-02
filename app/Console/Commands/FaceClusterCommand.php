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
 * Named: GROUP BY person_name → one confirmed person_cluster per name (~246 clusters).
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
                            {--min-cluster-size=2 : HDBSCAN min_cluster_size}
                            {--backfill : Find unclustered faces and assign them}
                            {--optimize : Run cluster optimization pass (merge similar, anchor matching, cleanup)}
                            {--recluster-singletons : Re-cluster singletons and small clusters with confirmed anchors}
                            {--dedup : Remove duplicate face embeddings from copied files and fix cluster counts}
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

        if ($this->option('dedup')) {
            return $this->deduplicateEmbeddings();
        }

        if ($this->option('purge-bloat')) {
            return $this->purgeBloatedClusters($faceService);
        }

        if ($this->option('backfill') && $this->option('optimize')) {
            // Combined: backfill then optimize (for scheduled job)
            $this->backfillUnclustered($faceService);
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
        $pgCount = DB::connection('pgsql_rag')->selectOne("SELECT COUNT(*) as cnt FROM face_embeddings")->cnt;
        if ($pgCount === 0) {
            $this->error('No embeddings in pgvector. Run faces:migrate-embeddings first.');
            return Command::FAILURE;
        }

        $startTime = microtime(true);

        // Phase 1: Named faces
        if (!$this->option('unnamed-only')) {
            $this->info('Phase 1: Clustering named faces...');

            if ($dryRun) {
                $namedCount = DB::selectOne("
                    SELECT COUNT(DISTINCT person_name) as cnt
                    FROM file_registry_faces
                    WHERE person_name != '' AND hidden = 0
                ")->cnt;
                $this->info("  Would create ~{$namedCount} confirmed clusters from named faces");
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
        if (!$this->option('named-only')) {
            $this->info('');
            $this->info('Phase 2: Clustering unnamed faces with HDBSCAN...');

            $unclusteredCount = DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(*) as cnt FROM face_embeddings WHERE person_cluster_id IS NULL
            ")->cnt;

            $this->info("  Unclustered embeddings: {$unclusteredCount}");

            if ($unclusteredCount === 0) {
                $this->info('  No unclustered faces. Skipping HDBSCAN.');
            } elseif ($dryRun) {
                $this->info("  [DRY RUN] Would cluster {$unclusteredCount} faces with HDBSCAN");
                $dryResult = $this->runHdbscanDryRun($unclusteredCount);
                if ($dryResult) {
                    $this->info("  Expected clusters: see Python output for estimates");
                }
            } else {
                $result = $this->runHdbscan($faceService, (int) $this->option('min-cluster-size'));
                if ($result['success']) {
                    $this->info("  Clusters created: {$result['clusters_created']}");
                    $this->info("  Faces assigned: {$result['faces_assigned']}");

                    if (isset($result['stats'])) {
                        $stats = $result['stats'];
                        $this->info("  Singletons: " . ($stats['singletons'] ?? 'N/A'));
                        $this->info("  Large clusters (>50): " . ($stats['large_clusters_gt50'] ?? 'N/A'));
                        $this->info("  Median size: " . ($stats['median_cluster_size'] ?? 'N/A'));

                        if (isset($stats['size_distribution'])) {
                            $this->info("  Size distribution:");
                            foreach ($stats['size_distribution'] as $range => $count) {
                                $this->info("    {$range}: {$count}");
                            }
                        }
                    }
                } else {
                    $this->error("  HDBSCAN failed: " . ($result['error'] ?? 'unknown'));
                }
            }
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->info('');
        $this->info("Done in {$elapsed}s");
        $this->line('[ITEMS_PROCESSED:1]');

        return Command::SUCCESS;
    }

    private function runHdbscan(FaceEmbeddingService $faceService, int $minClusterSize): array
    {
        // Export unclustered embeddings to temp JSON
        $embeddings = DB::connection('pgsql_rag')->select("
            SELECT id, embedding::text as embedding_str
            FROM face_embeddings
            WHERE person_cluster_id IS NULL
            ORDER BY id
        ");

        if (empty($embeddings)) {
            return ['success' => true, 'clusters_created' => 0, 'faces_assigned' => 0];
        }

        $data = [];
        foreach ($embeddings as $e) {
            $vec = array_map('floatval', explode(',', trim($e->embedding_str, '[]')));
            if (count($vec) === 128) {
                $data[] = ['id' => $e->id, 'embedding' => $vec];
            }
        }

        $inputFile = sys_get_temp_dir() . '/face_cluster_input_' . uniqid() . '.json';
        $outputFile = sys_get_temp_dir() . '/face_cluster_output_' . uniqid() . '.json';

        file_put_contents($inputFile, json_encode($data));

        $this->info("  Exported " . count($data) . " embeddings to temp file");
        $this->info("  Running HDBSCAN (min_cluster_size={$minClusterSize})...");

        $scriptPath = base_path('scripts/face_clusterer.py');
        $output = Process::timeout(300)->run([
            'python3',
            $scriptPath,
            '--input',
            $inputFile,
            '--output',
            $outputFile,
            '--min-cluster-size',
            (string) $minClusterSize,
        ])->output();

        // Clean up input
        @unlink($inputFile);

        // Parse result
        if (!file_exists($outputFile)) {
            $parsed = json_decode($output, true);
            @unlink($outputFile);
            return ['success' => false, 'error' => $parsed['error'] ?? $output];
        }

        $result = json_decode(file_get_contents($outputFile), true);
        @unlink($outputFile);

        if (!$result || !($result['success'] ?? false) || empty($result['assignments'])) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'No assignments returned',
                'stats' => $result['stats'] ?? null,
            ];
        }

        $this->info("  HDBSCAN complete: " . count($result['assignments']) . " assignments");
        $this->info("  Importing cluster assignments...");

        // Import into database
        $importResult = $faceService->importClusterAssignments($result['assignments']);

        return [
            'success' => true,
            'clusters_created' => $importResult['clusters_created'],
            'faces_assigned' => $importResult['faces_assigned'],
            'errors' => $importResult['errors'],
            'stats' => $result['stats'] ?? null,
        ];
    }

    private function reclusterSingletons(FaceEmbeddingService $faceService): int
    {
        $dryRun = $this->option('dry-run');
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        $this->info("{$prefix}Re-clustering singletons and small clusters with anchors...");
        $startTime = microtime(true);

        // Get embeddings from small unreviewed clusters (face_count <= 3)
        $smallClusters = DB::connection('pgsql_rag')->select("
            SELECT fe.id, fe.embedding::text as embedding_str, fe.person_cluster_id
            FROM face_embeddings fe
            INNER JOIN person_clusters pc ON pc.id = fe.person_cluster_id
            WHERE pc.status = 'unreviewed'
            AND pc.face_count <= 3
            ORDER BY fe.id
        ");

        if (empty($smallClusters)) {
            $this->info('  No small unreviewed clusters found.');
            return Command::SUCCESS;
        }

        $this->info("  Found " . count($smallClusters) . " faces in small clusters");

        // Get confirmed cluster centroids as anchors
        $confirmedAnchors = DB::connection('pgsql_rag')->select("
            SELECT id as cluster_id, name, centroid::text as centroid_str
            FROM person_clusters
            WHERE status = 'confirmed'
            AND centroid IS NOT NULL
            AND face_count > 0
        ");

        $this->info("  Confirmed anchors: " . count($confirmedAnchors));

        // Prepare data for Python
        $embedData = [];
        $clusterMap = []; // face_id -> old_cluster_id
        foreach ($smallClusters as $sc) {
            $vec = array_map('floatval', explode(',', trim($sc->embedding_str, '[]')));
            if (count($vec) === 128) {
                $embedData[] = ['id' => $sc->id, 'embedding' => $vec];
                $clusterMap[$sc->id] = $sc->person_cluster_id;
            }
        }

        $anchorsData = [];
        foreach ($confirmedAnchors as $a) {
            $vec = array_map('floatval', explode(',', trim($a->centroid_str, '[]')));
            if (count($vec) === 128) {
                $anchorsData[] = ['cluster_id' => $a->cluster_id, 'centroid' => $vec, 'name' => $a->name];
            }
        }

        if (empty($embedData)) {
            $this->info('  No valid embeddings to cluster.');
            return Command::SUCCESS;
        }

        $inputFile = sys_get_temp_dir() . '/face_recluster_input_' . uniqid() . '.json';
        $anchorsFile = sys_get_temp_dir() . '/face_recluster_anchors_' . uniqid() . '.json';
        $outputFile = sys_get_temp_dir() . '/face_recluster_output_' . uniqid() . '.json';

        file_put_contents($inputFile, json_encode($embedData));
        file_put_contents($anchorsFile, json_encode($anchorsData));

        $this->info("  Running HDBSCAN with anchors...");

        $minClusterSize = max(2, (int) $this->option('min-cluster-size'));
        $scriptPath = base_path('scripts/face_clusterer.py');

        $command = [
            'python3',
            $scriptPath,
            '--input',
            $inputFile,
            '--output',
            $outputFile,
            '--anchors',
            $anchorsFile,
            '--min-cluster-size',
            (string) $minClusterSize,
        ];

        if ($dryRun) {
            $command[] = '--dry-run';
        }

        $output = Process::timeout(300)->run($command)->output();
        @unlink($inputFile);
        @unlink($anchorsFile);

        if (!file_exists($outputFile)) {
            $parsed = json_decode($output, true);
            $this->error('HDBSCAN failed: ' . ($parsed['error'] ?? $output));
            return Command::FAILURE;
        }

        $result = json_decode(file_get_contents($outputFile), true);
        @unlink($outputFile);

        if (!$result || !($result['success'] ?? false)) {
            $this->error('HDBSCAN failed: ' . ($result['error'] ?? 'Unknown'));
            return Command::FAILURE;
        }

        $stats = $result['stats'] ?? [];
        $this->info("  HDBSCAN clusters: " . ($stats['hdbscan_clusters'] ?? 'N/A'));
        $this->info("  Anchor-matched clusters: " . ($stats['anchor_matched_clusters'] ?? 'N/A'));
        $this->info("  Anchor-matched faces: " . ($stats['anchor_matched_faces'] ?? 'N/A'));
        $this->info("  New clusters: " . ($stats['new_clusters'] ?? 'N/A'));

        if ($dryRun) {
            $elapsed = round(microtime(true) - $startTime, 1);
            $this->info("Done (dry run) in {$elapsed}s");
            return Command::SUCCESS;
        }

        // Apply anchor merges: move faces into existing confirmed clusters
        $anchorMerges = $result['anchor_merges'] ?? [];
        $mergedCount = 0;
        // Group by target cluster for efficiency
        $mergeByCluster = [];
        foreach ($anchorMerges as $feId => $targetClusterId) {
            $mergeByCluster[$targetClusterId][] = (int) $feId;
        }

        foreach ($mergeByCluster as $targetClusterId => $faceIds) {
            $ph = implode(',', array_fill(0, count($faceIds), '?'));
            DB::connection('pgsql_rag')->update("
                UPDATE face_embeddings
                SET person_cluster_id = ?, updated_at = NOW()
                WHERE id IN ({$ph})
            ", array_merge([$targetClusterId], $faceIds));

            // Update face count
            DB::connection('pgsql_rag')->update("
                UPDATE person_clusters
                SET face_count = (SELECT COUNT(*) FROM face_embeddings WHERE person_cluster_id = ?),
                    updated_at = NOW()
                WHERE id = ?
            ", [$targetClusterId, $targetClusterId]);

            // Update MySQL cluster_id
            $feRows = DB::connection('pgsql_rag')->select("
                SELECT file_registry_face_id FROM face_embeddings
                WHERE id IN ({$ph}) AND file_registry_face_id IS NOT NULL
            ", $faceIds);
            if (!empty($feRows)) {
                $mysqlIds = array_map(fn($r) => $r->file_registry_face_id, $feRows);
                $mph = implode(',', array_fill(0, count($mysqlIds), '?'));
                DB::update("UPDATE file_registry_faces SET cluster_id = ? WHERE id IN ({$mph})",
                    array_merge([$targetClusterId], $mysqlIds));
            }

            $faceService->updateClusterCentroid($targetClusterId);
            $mergedCount += count($faceIds);
        }

        // Import new cluster assignments (same as regular HDBSCAN import)
        $newAssignments = $result['assignments'] ?? [];
        $importResult = ['clusters_created' => 0, 'faces_assigned' => 0, 'errors' => 0];
        if (!empty($newAssignments)) {
            $importResult = $faceService->importClusterAssignments($newAssignments);
        }

        // Clean up old clusters that lost all their faces — delete empties, update counts on the rest
        $oldClusterIds = array_unique(array_values($clusterMap));
        $emptyOldIds = [];
        foreach ($oldClusterIds as $oldId) {
            $remaining = DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(*) as cnt FROM face_embeddings WHERE person_cluster_id = ?
            ", [$oldId]);
            if ((int) $remaining->cnt === 0) {
                $emptyOldIds[] = $oldId;
            } else {
                DB::connection('pgsql_rag')->update("
                    UPDATE person_clusters
                    SET face_count = ?, updated_at = NOW()
                    WHERE id = ?
                ", [(int) $remaining->cnt, $oldId]);
            }
        }
        $emptyCleaned = 0;
        if (!empty($emptyOldIds)) {
            $ph = implode(',', array_fill(0, count($emptyOldIds), '?'));
            $emptyCleaned = DB::connection('pgsql_rag')->delete("
                DELETE FROM person_clusters WHERE id IN ({$ph}) AND status = 'unreviewed'
            ", $emptyOldIds);
        }

        $this->info("  Faces merged into confirmed: {$mergedCount}");
        $this->info("  New clusters created: {$importResult['clusters_created']}");
        $this->info("  Faces re-assigned: {$importResult['faces_assigned']}");
        $this->info("  Empty old clusters deleted: {$emptyCleaned}");

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->info("Re-clustering done in {$elapsed}s");

        return Command::SUCCESS;
    }

    private function runHdbscanDryRun(int $count): bool
    {
        $scriptPath = base_path('scripts/face_clusterer.py');
        if (!file_exists($scriptPath)) {
            $this->warn("  Python script not found: {$scriptPath}");
            return false;
        }

        // Quick check that hdbscan is installed
        $check = Process::timeout(15)->run(['python3', '-c', 'import hdbscan; print("OK")']);
        if (trim($check->output()) !== 'OK') {
            $this->warn("  hdbscan not installed: pip install hdbscan");
            return false;
        }

        $this->info("  hdbscan available, would cluster {$count} faces");
        return true;
    }

    private function backfillUnclustered(FaceEmbeddingService $faceService): int
    {
        $this->info('Backfilling unclustered faces...');

        // Find MySQL faces with cluster_id NULL that have embeddings in pgvector
        $unclustered = DB::connection('pgsql_rag')->select("
            SELECT fe.id, fe.embedding::text as embedding_str
            FROM face_embeddings fe
            WHERE fe.person_cluster_id IS NULL
            ORDER BY fe.id
            LIMIT 5000
        ");

        if (empty($unclustered)) {
            // Cross-DB check: find MySQL faces not yet in pgvector (app logic)
            $mysqlUnclustered = DB::select("
                SELECT id, file_registry_id, embedding, region_x, region_y, region_w, region_h, confidence
                FROM file_registry_faces
                WHERE cluster_id IS NULL
                AND embedding IS NOT NULL AND embedding != '' AND embedding != '[]'
                LIMIT 1000
            ");

            $inserted = 0;
            foreach ($mysqlUnclustered as $face) {
                // Check if pgvector row exists
                $exists = DB::connection('pgsql_rag')->selectOne("
                    SELECT id FROM face_embeddings WHERE file_registry_face_id = ?
                ", [$face->id]);

                if (!$exists) {
                    $embData = json_decode($face->embedding, true);
                    if (is_array($embData) && count($embData) === 128) {
                        $embStr = '[' . implode(',', $embData) . ']';
                        try {
                            DB::connection('pgsql_rag')->insert("
                                INSERT INTO face_embeddings
                                (file_registry_id, file_registry_face_id, embedding,
                                 region_x, region_y, region_w, region_h, quality_score,
                                 created_at, updated_at)
                                VALUES (?, ?, ?::vector, ?, ?, ?, ?, ?, NOW(), NOW())
                                ON CONFLICT (file_registry_face_id)
                                WHERE file_registry_face_id IS NOT NULL
                                DO NOTHING
                            ", [
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
                }
            }

            if ($inserted > 0) {
                $this->info("Inserted {$inserted} missing pgvector rows. Re-run --backfill to assign clusters.");
                return Command::SUCCESS;
            }

            $this->info('No unclustered faces found.');
            return Command::SUCCESS;
        }

        $this->info("Found " . count($unclustered) . " unclustered pgvector embeddings");

        $assigned = 0;
        $singletons = 0;

        foreach ($unclustered as $fe) {
            $vec = array_map('floatval', explode(',', trim($fe->embedding_str, '[]')));
            if (count($vec) !== 128) continue;

            $result = $faceService->assignToCluster($fe->id, $vec);
            if ($result['action'] === 'assigned') {
                $assigned++;
            } else {
                $singletons++;
            }
        }

        $this->info("  Assigned to existing: {$assigned}");
        $this->info("  New singletons: {$singletons}");

        return Command::SUCCESS;
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
        $this->info("{$prefix}  Anchor suggestions: " . count($results['anchor_suggestions']));
        $this->info("{$prefix}  Centroids updated: {$results['centroids_updated']}");
        $this->info("{$prefix}  Empty clusters purged: {$results['empty_purged']}");
        $this->info("{$prefix}  Skipped (retry limit): {$results['skipped_retry_limit']}");

        if (!empty($results['anchor_suggestions'])) {
            $this->info('');
            $this->info('Anchor suggestions for human review:');
            foreach (array_slice($results['anchor_suggestions'], 0, 10) as $s) {
                $this->info(sprintf(
                    "  Cluster #%d (%d faces) → %s (#%d) [sim=%.3f]",
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
        $this->info("Unclustered: " . ($mysqlStats->total - $mysqlStats->clustered));

        return Command::SUCCESS;
    }

    /**
     * Remove duplicate face embeddings caused by the same physical image being
     * registered under multiple file_registry IDs (genealogy tree copies, media sort folders, etc).
     *
     * Strategy: group embeddings by exact vector match. For each group spanning multiple
     * file_registry_ids, keep the one linked to the oldest (canonical) file record.
     * Delete the rest from pgvector. MySQL file_registry_faces rows are preserved
     * (they belong to specific tree folders and are needed for self-contained trees).
     */
    private function deduplicateEmbeddings(): int
    {
        $this->info('Deduplicating face embeddings...');
        $startTime = microtime(true);
        $dryRun = $this->option('dry-run');
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        // Find groups of identical embeddings across different file_registry_ids
        $dupeGroups = DB::connection('pgsql_rag')->select("
            SELECT embedding::text as emb_text, COUNT(*) as cnt,
                   COUNT(DISTINCT file_registry_id) as unique_files,
                   array_agg(id ORDER BY id) as id_list,
                   array_agg(file_registry_id ORDER BY id) as file_ids
            FROM face_embeddings
            GROUP BY embedding::text
            HAVING COUNT(DISTINCT file_registry_id) > 1
            ORDER BY COUNT(*) DESC
        ");

        if (empty($dupeGroups)) {
            $this->info('No duplicate embeddings found.');
            return Command::SUCCESS;
        }

        $totalExcess = 0;
        $totalDeleted = 0;
        $clustersToFix = [];

        foreach ($dupeGroups as $group) {
            // Parse the postgres array format: {1,2,3} → [1,2,3]
            $ids = array_map('intval', explode(',', trim($group->id_list, '{}')));
            $fileIds = array_map('intval', explode(',', trim($group->file_ids, '{}')));
            $excess = count($ids) - 1;
            $totalExcess += $excess;

            if (!$dryRun) {
                // Keep the first (oldest) embedding, delete the rest
                $keepId = $ids[0];
                $deleteIds = array_slice($ids, 1);

                // Record which clusters need face_count recalculation
                $affectedClusters = DB::connection('pgsql_rag')->select("
                    SELECT DISTINCT person_cluster_id FROM face_embeddings
                    WHERE id IN (" . implode(',', $deleteIds) . ")
                    AND person_cluster_id IS NOT NULL
                ");
                foreach ($affectedClusters as $ac) {
                    $clustersToFix[$ac->person_cluster_id] = true;
                }

                // Delete duplicate rows
                $deleted = DB::connection('pgsql_rag')->delete("
                    DELETE FROM face_embeddings
                    WHERE id IN (" . implode(',', $deleteIds) . ")
                ");
                $totalDeleted += $deleted;
            }
        }

        $this->info("{$prefix}Duplicate groups found: " . count($dupeGroups));
        $this->info("{$prefix}Excess embeddings: {$totalExcess}");

        if (!$dryRun) {
            $this->info("  Deleted: {$totalDeleted} pgvector rows");

            // Fix face_count on affected clusters
            $fixedClusters = 0;
            foreach (array_keys($clustersToFix) as $clusterId) {
                DB::connection('pgsql_rag')->update("
                    UPDATE person_clusters
                    SET face_count = (SELECT COUNT(*) FROM face_embeddings WHERE person_cluster_id = ?),
                        updated_at = NOW()
                    WHERE id = ?
                ", [$clusterId, $clusterId]);
                $fixedClusters++;
            }
            $this->info("  Cluster face counts fixed: {$fixedClusters}");
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->info("Dedup done in {$elapsed}s");

        return Command::SUCCESS;
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

        if (!empty($results['details'])) {
            $this->info('');
            $this->table(
                ['Cluster', 'Name', 'Was', 'Named', 'Auth', 'Ratio', 'Evicted'],
                array_map(fn($d) => [
                    $d['cluster_id'],
                    substr($d['name'], 0, 25),
                    $d['cluster_faces'],
                    $d['named_faces'],
                    $d['authoritative_embeddings'],
                    $d['ratio'] . 'x',
                    $d['evictable'],
                ], $results['details'])
            );
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->info("Purge done in {$elapsed}s");

        return Command::SUCCESS;
    }
}
