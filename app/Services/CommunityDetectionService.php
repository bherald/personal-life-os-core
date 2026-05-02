<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Exception;

/**
 * Community Detection Service
 *
 * Orchestrates Leiden community detection on the knowledge graph via Python subprocess.
 * Stores hierarchical community assignments, computes centrality, identifies bridge entities.
 *
 * Pattern: PHP orchestrator + Python igraph/leidenalg subprocess
 * (same as FaceEmbeddingService + face_clusterer.py)
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
class CommunityDetectionService
{
    private const CONNECTION = 'pgsql_rag';
    private const SCRIPT_PATH = 'scripts/community_detection.py';
    private const DEFAULT_RESOLUTIONS = [1.0, 0.5, 0.25];
    private const MIN_COMMUNITY_SIZE = 2;
    private const DEFAULT_TIMEOUT_MINUTES = 120;
    private const TIMEOUT_SAFETY_BUFFER_SECONDS = 300;

    /**
     * Run community detection on the full knowledge graph.
     *
     * @param array $options {
     *   resolutions: float[] (default [1.0, 0.5, 0.25]),
     *   min_community_size: int (default 2),
     *   force_rebuild: bool (default false)
     * }
     * @return array {success, run_id, communities_detected, levels, duration_ms, error?}
     */
    public function detectCommunities(array $options = []): array
    {
        $startTime = microtime(true);
        $resolutions = $options['resolutions'] ?? self::DEFAULT_RESOLUTIONS;
        $minSize = $options['min_community_size'] ?? self::MIN_COMMUNITY_SIZE;
        $forceRebuild = $options['force_rebuild'] ?? false;

        try {
            // Export graph data from DB
            $graphData = $this->exportGraphData();

            if (empty($graphData['edges']) && empty($graphData['entity_ids'])) {
                return [
                    'success' => false,
                    'error' => 'Knowledge graph is empty — no entities or edges to cluster',
                ];
            }

            // Write to temp file
            $inputFile = sys_get_temp_dir() . '/kg_community_input_' . uniqid() . '.json';
            $outputFile = sys_get_temp_dir() . '/kg_community_output_' . uniqid() . '.json';
            file_put_contents($inputFile, json_encode($graphData));

            // Run Python community detection
            $scriptPath = base_path(self::SCRIPT_PATH);
            $resolutionStr = implode(',', $resolutions);

            $result = Process::timeout($this->resolveProcessTimeoutSeconds())->run([
                'python3',
                $scriptPath,
                '--input',
                $inputFile,
                '--output',
                $outputFile,
                '--resolutions',
                $resolutionStr,
                '--min-community-size',
                (string) $minSize,
            ]);
            $exitCode = $result->exitCode();
            $outputStr = trim($result->output() . "\n" . $result->errorOutput());

            // Cleanup input file
            @unlink($inputFile);

            // Check exit code and output file
            if ($exitCode !== 0 || !file_exists($outputFile)) {
                $parsed = json_decode($outputStr, true);
                return [
                    'success' => false,
                    'error' => $parsed['error'] ?? "Python script failed (exit code {$exitCode}): " . substr($outputStr, 0, 500),
                ];
            }

            $result = json_decode(file_get_contents($outputFile), true);
            @unlink($outputFile);

            if (!$result || !($result['success'] ?? false)) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'Community detection returned no results',
                ];
            }

            // Clear previous communities if rebuilding
            if ($forceRebuild) {
                $this->clearCommunities();
            }

            // Create detection run record
            $runId = $this->createDetectionRun($result, $resolutions);

            // Store communities
            $communitiesStored = $this->storeCommunities($result, $runId);

            // Update entity centrality and community membership
            $this->updateEntityCentrality($result);
            $this->storeEntityCommunityMembership($result, $runId);

            $elapsed = (int) ((microtime(true) - $startTime) * 1000);

            // Update run with final stats
            DB::connection(self::CONNECTION)->update(
                "UPDATE knowledge_graph_detection_runs SET duration_ms = ? WHERE id = ?::uuid",
                [$elapsed, $runId]
            );

            Log::info('CommunityDetection: completed', [
                'run_id' => $runId,
                'communities' => $communitiesStored,
                'levels' => count($resolutions),
                'duration_ms' => $elapsed,
            ]);

            return [
                'success' => true,
                'run_id' => $runId,
                'communities_detected' => $communitiesStored,
                'levels' => count($resolutions),
                'duration_ms' => $elapsed,
                'stats' => $result['stats'] ?? [],
            ];
        } catch (Exception $e) {
            Log::error('CommunityDetection: failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function resolveProcessTimeoutSeconds(): int
    {
        try {
            $job = DB::selectOne(
                "SELECT timeout_minutes FROM scheduled_jobs WHERE name = 'community_detection' LIMIT 1"
            );
            $timeoutMinutes = max(1, (int) ($job->timeout_minutes ?? self::DEFAULT_TIMEOUT_MINUTES));
        } catch (\Throwable) {
            $timeoutMinutes = self::DEFAULT_TIMEOUT_MINUTES;
        }

        return max(300, ($timeoutMinutes * 60) - self::TIMEOUT_SAFETY_BUFFER_SECONDS);
    }

    /**
     * Get a specific community with its entities and edges.
     */
    public function getCommunity(int $communityId): ?array
    {
        $community = DB::connection(self::CONNECTION)->selectOne(
            "SELECT * FROM knowledge_graph_communities WHERE id = ?",
            [$communityId]
        );

        if (!$community) {
            return null;
        }

        // Get member entities
        $members = DB::connection(self::CONNECTION)->select(
            "SELECT ec.entity_id, ec.membership_score, ec.is_bridge,
                    e.canonical_name, e.entity_type, e.aliases, e.properties, e.degree, e.pagerank
             FROM knowledge_graph_entity_communities ec
             JOIN knowledge_graph_entities e ON e.id = ec.entity_id
             WHERE ec.community_id = ?
             ORDER BY e.pagerank DESC",
            [$communityId]
        );

        // Get report if exists
        $report = DB::connection(self::CONNECTION)->selectOne(
            "SELECT id, title, summary, key_entities, key_relationships, themes, rating, token_count
             FROM knowledge_graph_community_reports WHERE community_id = ?",
            [$communityId]
        );

        // Get child communities
        $children = DB::connection(self::CONNECTION)->select(
            "SELECT id, community_id, entity_count, level FROM knowledge_graph_communities
             WHERE parent_community_id = ? ORDER BY entity_count DESC",
            [$communityId]
        );

        return [
            'id' => $community->id,
            'community_id' => $community->community_id,
            'level' => $community->level,
            'entity_count' => $community->entity_count,
            'edge_count' => $community->edge_count,
            'modularity_score' => $community->modularity_score,
            'members' => $members,
            'report' => $report,
            'children' => $children,
        ];
    }

    /**
     * Get communities an entity belongs to.
     */
    public function getEntityCommunities(int $entityId): array
    {
        return DB::connection(self::CONNECTION)->select(
            "SELECT ec.community_id, ec.membership_score, ec.is_bridge,
                    c.community_id as cluster_id, c.level, c.entity_count
             FROM knowledge_graph_entity_communities ec
             JOIN knowledge_graph_communities c ON c.id = ec.community_id
             WHERE ec.entity_id = ?
             ORDER BY c.level ASC",
            [$entityId]
        );
    }

    /**
     * Get the full community hierarchy tree.
     */
    public function getCommunityHierarchy(): array
    {
        $communities = DB::connection(self::CONNECTION)->select(
            "SELECT id, community_id, level, parent_community_id, entity_count, edge_count, modularity_score
             FROM knowledge_graph_communities
             ORDER BY level ASC, entity_count DESC"
        );

        // Build tree structure
        $tree = [];
        $byId = [];

        foreach ($communities as $c) {
            $node = [
                'id' => $c->id,
                'community_id' => $c->community_id,
                'level' => $c->level,
                'entity_count' => $c->entity_count,
                'edge_count' => $c->edge_count,
                'modularity_score' => $c->modularity_score,
                'children' => [],
            ];
            $byId[$c->id] = $node;

            if ($c->parent_community_id && isset($byId[$c->parent_community_id])) {
                $byId[$c->parent_community_id]['children'][] = &$byId[$c->id];
            } else {
                $tree[] = &$byId[$c->id];
            }
        }

        return $tree;
    }

    /**
     * Get community detection statistics.
     */
    public function getStatistics(): array
    {
        $stats = DB::connection(self::CONNECTION)->selectOne("
            SELECT
                COUNT(*) as total_communities,
                MAX(level) as max_level,
                AVG(entity_count) as avg_size,
                MAX(entity_count) as largest,
                MIN(entity_count) as smallest,
                AVG(modularity_score) as avg_modularity
            FROM knowledge_graph_communities
        ");

        $entityStats = DB::connection(self::CONNECTION)->selectOne("
            SELECT
                COUNT(*) as total_entities,
                COUNT(CASE WHEN primary_community_id IS NOT NULL THEN 1 END) as entities_in_communities,
                AVG(degree) as avg_degree,
                MAX(degree) as max_degree,
                AVG(pagerank) as avg_pagerank
            FROM knowledge_graph_entities
        ");

        $reportCount = DB::connection(self::CONNECTION)->selectOne(
            "SELECT COUNT(*) as cnt FROM knowledge_graph_community_reports"
        );

        $bridgeCount = DB::connection(self::CONNECTION)->selectOne(
            "SELECT COUNT(*) as cnt FROM knowledge_graph_entity_communities WHERE is_bridge = TRUE"
        );

        $lastRun = DB::connection(self::CONNECTION)->selectOne(
            "SELECT id, communities_detected, levels, duration_ms, reports_generated, created_at
             FROM knowledge_graph_detection_runs ORDER BY created_at DESC LIMIT 1"
        );

        return [
            'total_communities' => (int) ($stats->total_communities ?? 0),
            'max_level' => (int) ($stats->max_level ?? 0),
            'avg_size' => round((float) ($stats->avg_size ?? 0), 1),
            'largest_community' => (int) ($stats->largest ?? 0),
            'smallest_community' => (int) ($stats->smallest ?? 0),
            'avg_modularity' => round((float) ($stats->avg_modularity ?? 0), 4),
            'total_entities' => (int) ($entityStats->total_entities ?? 0),
            'entities_in_communities' => (int) ($entityStats->entities_in_communities ?? 0),
            'avg_degree' => round((float) ($entityStats->avg_degree ?? 0), 1),
            'max_degree' => (int) ($entityStats->max_degree ?? 0),
            'avg_pagerank' => round((float) ($entityStats->avg_pagerank ?? 0), 6),
            'community_reports' => (int) ($reportCount->cnt ?? 0),
            'bridge_entities' => (int) ($bridgeCount->cnt ?? 0),
            'last_run' => $lastRun ? [
                'id' => $lastRun->id,
                'communities' => $lastRun->communities_detected,
                'levels' => $lastRun->levels,
                'duration_ms' => $lastRun->duration_ms,
                'reports' => $lastRun->reports_generated,
                'at' => $lastRun->created_at,
            ] : null,
        ];
    }

    /**
     * Get communities that need reports (no report exists yet).
     */
    public function getCommunitiesNeedingReports(int $minSize = 3): array
    {
        return DB::connection(self::CONNECTION)->select("
            SELECT c.id, c.community_id, c.level, c.entity_count, c.edge_count, c.entity_ids
            FROM knowledge_graph_communities c
            LEFT JOIN knowledge_graph_community_reports cr ON cr.community_id = c.id
            WHERE cr.id IS NULL AND c.entity_count >= ?
            ORDER BY c.entity_count DESC
        ", [$minSize]);
    }

    // ── Private methods ──────────────────────────────────────────────

    /**
     * Export knowledge graph as edge list for Python.
     */
    private function exportGraphData(): array
    {
        // Get all entity IDs
        $entities = DB::connection(self::CONNECTION)->select(
            "SELECT id FROM knowledge_graph_entities"
        );
        $entityIds = array_map(fn($e) => (int) $e->id, $entities);

        // Get all active edges (triples with entity IDs on both sides)
        $edges = DB::connection(self::CONNECTION)->select("
            SELECT subject_entity_id, object_entity_id, confidence
            FROM knowledge_graph
            WHERE subject_entity_id IS NOT NULL AND object_entity_id IS NOT NULL
              AND t_expired IS NULL
        ");

        $edgeList = [];
        foreach ($edges as $edge) {
            $edgeList[] = [
                (int) $edge->subject_entity_id,
                (int) $edge->object_entity_id,
                (float) $edge->confidence,
            ];
        }

        return [
            'edges' => $edgeList,
            'entity_ids' => $entityIds,
        ];
    }

    /**
     * Create a detection run record.
     */
    private function createDetectionRun(array $result, array $resolutions): string
    {
        $stats = $result['stats'] ?? [];
        $communities = $result['communities'] ?? [];

        $totalCommunities = 0;
        $modularityScores = [];
        foreach ($communities as $levelKey => $levelData) {
            $totalCommunities += $levelData['num_communities'] ?? 0;
            $modularityScores[$levelKey] = $levelData['modularity'] ?? 0;
        }

        $runId = Str::uuid()->toString();

        DB::connection(self::CONNECTION)->insert("
            INSERT INTO knowledge_graph_detection_runs
                (id, entity_count, triple_count, communities_detected, levels, resolution_params, modularity_scores, created_at)
            VALUES (?::uuid, ?, ?, ?, ?, ?::jsonb, ?::jsonb, NOW())
        ", [
            $runId,
            $stats['total_nodes'] ?? 0,
            $stats['total_edges'] ?? 0,
            $totalCommunities,
            $stats['levels'] ?? 0,
            json_encode($resolutions),
            json_encode($modularityScores),
        ]);

        return $runId;
    }

    /**
     * Store community assignments in the database.
     */
    private function storeCommunities(array $result, string $runId): int
    {
        $communities = $result['communities'] ?? [];
        $hierarchy = $result['hierarchy'] ?? [];
        $stored = 0;

        // Track DB IDs for parent linking
        $dbIds = []; // "level_community" => db_id

        foreach ($communities as $levelKey => $levelData) {
            $level = (int) filter_var($levelKey, FILTER_SANITIZE_NUMBER_INT);
            $assignments = $levelData['assignments'] ?? [];
            $sizes = $levelData['sizes'] ?? [];
            $modularity = $levelData['modularity'] ?? null;

            // Group entities by community
            $communityMembers = [];
            foreach ($assignments as $entityId => $commId) {
                $communityMembers[$commId][] = (int) $entityId;
            }

            // Batch count internal edges for all communities at this level
            $edgeCounts = $this->batchCountInternalEdges($communityMembers);

            foreach ($communityMembers as $commId => $memberIds) {
                $edgeCount = $edgeCounts[$commId] ?? 0;

                // Find parent community from hierarchy
                $parentDbId = null;
                if ($level > 0) {
                    $hierarchyKey = "level_" . ($level - 1) . "_to_{$level}";
                    $parentMapping = $hierarchy[$hierarchyKey] ?? [];

                    // Find which level-1 communities map to this level community
                    // We need to find any child community that maps to commId
                    // and use its parent's DB ID
                    // Actually, the hierarchy maps child_comm -> parent_comm
                    // So we look for our commId as a parent value
                }

                $dbId = DB::connection(self::CONNECTION)->selectOne("
                    INSERT INTO knowledge_graph_communities
                        (community_id, level, entity_ids, edge_count, entity_count, modularity_score, detection_run_id, created_at, updated_at)
                    VALUES (?, ?, ?::jsonb, ?, ?, ?, ?::uuid, NOW(), NOW())
                    RETURNING id
                ", [
                    $commId,
                    $level,
                    json_encode($memberIds),
                    $edgeCount,
                    count($memberIds),
                    $modularity,
                    $runId,
                ])->id;

                $dbIds["{$level}_{$commId}"] = $dbId;
                $stored++;
            }
        }

        // Set parent links using hierarchy
        foreach ($hierarchy as $hierarchyKey => $mapping) {
            // hierarchyKey = "level_X_to_Y"
            preg_match('/level_(\d+)_to_(\d+)/', $hierarchyKey, $matches);
            if (count($matches) < 3) continue;

            $childLevel = (int) $matches[1];
            $parentLevel = (int) $matches[2];

            foreach ($mapping as $childCommId => $parentCommId) {
                $childDbId = $dbIds["{$childLevel}_{$childCommId}"] ?? null;
                $parentDbId = $dbIds["{$parentLevel}_{$parentCommId}"] ?? null;

                if ($childDbId && $parentDbId) {
                    DB::connection(self::CONNECTION)->update(
                        "UPDATE knowledge_graph_communities SET parent_community_id = ? WHERE id = ?",
                        [$parentDbId, $childDbId]
                    );
                }
            }
        }

        return $stored;
    }

    /**
     * Batch count internal edges for all communities in a single query.
     * Maps entity_id → community_id, then counts edges where both endpoints share a community.
     *
     * @param array $communityMembers [commId => [entityId, ...], ...]
     * @return array [commId => edgeCount, ...]
     */
    private function batchCountInternalEdges(array $communityMembers): array
    {
        // Build entity → community mapping
        $entityToCommunity = [];
        foreach ($communityMembers as $commId => $memberIds) {
            foreach ($memberIds as $memberId) {
                $entityToCommunity[$memberId] = $commId;
            }
        }

        if (count($entityToCommunity) < 2) {
            return array_fill_keys(array_keys($communityMembers), 0);
        }

        // Chunk queries to stay under PostgreSQL's 65,535 parameter limit
        // Each query uses allIds twice (subject IN + object IN), so max chunk = 32000
        $allIds = array_keys($entityToCommunity);
        $chunkSize = 30000;
        $counts = array_fill_keys(array_keys($communityMembers), 0);

        foreach (array_chunk($allIds, $chunkSize) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            $edges = DB::connection(self::CONNECTION)->select("
                SELECT subject_entity_id, object_entity_id FROM knowledge_graph
                WHERE subject_entity_id IN ({$placeholders})
                  AND object_entity_id IN ({$placeholders})
            ", array_merge($chunk, $chunk));

            foreach ($edges as $edge) {
                $subjComm = $entityToCommunity[$edge->subject_entity_id] ?? null;
                $objComm = $entityToCommunity[$edge->object_entity_id] ?? null;
                if ($subjComm !== null && $subjComm === $objComm) {
                    $counts[$subjComm]++;
                }
            }
        }

        return $counts;
    }

    /**
     * Update entity degree and pagerank from Python results.
     */
    private function updateEntityCentrality(array $result): void
    {
        $degrees = $result['entity_degrees'] ?? [];
        $pagerank = $result['pagerank'] ?? [];
        $rows = [];

        foreach ($degrees as $entityId => $degree) {
            $rows[] = [
                (int) $entityId,
                (int) $degree,
                (float) ($pagerank[$entityId] ?? 0.0),
            ];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as [$entityId, $degree, $pr]) {
                $values[] = '(?, ?, ?)';
                array_push($params, $entityId, $degree, $pr);
            }

            DB::connection(self::CONNECTION)->update("
                UPDATE knowledge_graph_entities AS e
                SET degree = v.degree::integer,
                    pagerank = v.pagerank::double precision
                FROM (VALUES " . implode(',', $values) . ") AS v(id, degree, pagerank)
                WHERE e.id = v.id::bigint
            ", $params);
        }
    }

    /**
     * Store entity-community membership links.
     */
    private function storeEntityCommunityMembership(array $result, string $runId): void
    {
        $communities = $result['communities'] ?? [];
        $bridges = array_flip($result['bridge_entities'] ?? []);

        // Use level 0 (most granular) for primary community assignment
        $level0 = $communities['level_0'] ?? [];
        $assignments = $level0['assignments'] ?? [];

        // Get DB IDs for communities
        $communityDbIds = [];
        $rows = DB::connection(self::CONNECTION)->select(
            "SELECT id, community_id, level FROM knowledge_graph_communities WHERE detection_run_id = ?::uuid",
            [$runId]
        );
        foreach ($rows as $row) {
            $communityDbIds["{$row->level}_{$row->community_id}"] = $row->id;
        }

        // Clear previous memberships
        DB::connection(self::CONNECTION)->delete("DELETE FROM knowledge_graph_entity_communities");

        // Insert memberships for all levels
        $membershipRows = [];
        foreach ($communities as $levelKey => $levelData) {
            $level = (int) filter_var($levelKey, FILTER_SANITIZE_NUMBER_INT);
            $levelAssignments = $levelData['assignments'] ?? [];

            foreach ($levelAssignments as $entityId => $commId) {
                $dbCommId = $communityDbIds["{$level}_{$commId}"] ?? null;
                if (!$dbCommId) continue;

                $isBridge = isset($bridges[(int) $entityId]) && $level === 0;
                $membershipRows[] = [(int) $entityId, (int) $dbCommId, $isBridge];
            }
        }
        $this->insertEntityCommunityMembershipRows($membershipRows);

        // Update primary community on entities (level 0)
        $primaryRows = [];
        foreach ($assignments as $entityId => $commId) {
            $dbCommId = $communityDbIds["0_{$commId}"] ?? null;
            if (!$dbCommId) continue;
            $primaryRows[] = [(int) $entityId, (int) $dbCommId];
        }
        $this->updatePrimaryCommunityRows($primaryRows, $runId);
    }

    /**
     * Insert membership rows in chunks; row-by-row inserts dominate daily rebuild time.
     */
    private function insertEntityCommunityMembershipRows(array $rows): void
    {
        foreach (array_chunk($rows, 1000) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as [$entityId, $communityId, $isBridge]) {
                $values[] = '(?, ?, 1.0, ?, NOW())';
                array_push($params, $entityId, $communityId, $isBridge);
            }

            DB::connection(self::CONNECTION)->insert("
                INSERT INTO knowledge_graph_entity_communities
                    (entity_id, community_id, membership_score, is_bridge, created_at)
                VALUES " . implode(',', $values) . "
                ON CONFLICT (entity_id, community_id) DO NOTHING
            ", $params);
        }
    }

    /**
     * Update primary community assignments in chunks instead of one UPDATE per entity.
     */
    private function updatePrimaryCommunityRows(array $rows, string $runId): void
    {
        foreach (array_chunk($rows, 1000) as $chunk) {
            $values = [];
            $params = [$runId];
            foreach ($chunk as [$entityId, $communityId]) {
                $values[] = '(?, ?)';
                array_push($params, $entityId, $communityId);
            }

            DB::connection(self::CONNECTION)->update("
                UPDATE knowledge_graph_entities AS e
                SET primary_community_id = v.community_id::bigint,
                    last_community_run = ?::uuid
                FROM (VALUES " . implode(',', $values) . ") AS v(entity_id, community_id)
                WHERE e.id = v.entity_id::bigint
            ", $params);
        }
    }

    /**
     * Clear all community data (for rebuild).
     */
    private function clearCommunities(): void
    {
        DB::connection(self::CONNECTION)->delete("DELETE FROM knowledge_graph_entity_communities");
        DB::connection(self::CONNECTION)->delete("DELETE FROM knowledge_graph_community_reports");
        DB::connection(self::CONNECTION)->delete("DELETE FROM knowledge_graph_communities");
        DB::connection(self::CONNECTION)->update("UPDATE knowledge_graph_entities SET primary_community_id = NULL, last_community_run = NULL");
    }
}
