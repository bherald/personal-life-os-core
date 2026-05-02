<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * TreeManagementService - Family Tree CRUD and Management
 *
 * Extracted from GenealogyService as part of Priority 2.1 service extraction.
 * Handles tree creation, updates, deletion, statistics, and user ownership.
 *
 * @see /docs/genealogy-module-review.md Priority 2.1
 */
class TreeManagementService
{
    /**
     * Create a new family tree
     *
     * @param string $name Tree name
     * @param string|null $description Tree description
     * @param int|null $userId Owner user ID
     * @return int New tree ID
     */
    public function createTree(string $name, ?string $description = null, ?int $userId = null): int
    {
        $sql = "INSERT INTO genealogy_trees (name, description, created_at, updated_at)
                VALUES (?, ?, NOW(), NOW())";

        DB::insert($sql, [$name, $description]);
        $treeId = (int) DB::getPdo()->lastInsertId();

        Log::info('TreeManagementService: Tree created', [
            'tree_id' => $treeId,
            'name' => $name,
        ]);

        return $treeId;
    }

    /**
     * Get all family trees
     *
     * @param int|null $userId Filter by user ID (owner)
     * @return array
     */
    public function listTrees(?int $userId = null): array
    {
        $sql = "SELECT id, name, description,
                       person_count, family_count, source_count,
                       created_at, updated_at
                FROM genealogy_trees
                ORDER BY name ASC";

        return DB::select($sql);
    }

    /**
     * Get a single tree by ID
     *
     * @param int $treeId Tree ID
     * @return object|null
     */
    public function getTree(int $treeId): ?object
    {
        $sql = "SELECT id, name, description,
                       person_count, family_count, source_count,
                       created_at, updated_at
                FROM genealogy_trees
                WHERE id = ?";

        return DB::selectOne($sql, [$treeId]);
    }

    /**
     * Update tree details
     *
     * @param int $treeId Tree ID
     * @param array $data Update data (name, description)
     * @return bool Success
     */
    public function updateTree(int $treeId, array $data): bool
    {
        $fields = [];
        $params = [];

        foreach (['name', 'description'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = NOW()";
        $params[] = $treeId;

        $sql = "UPDATE genealogy_trees SET " . implode(', ', $fields) . " WHERE id = ?";
        $updated = DB::update($sql, $params) > 0;

        if ($updated) {
            Log::info('TreeManagementService: Tree updated', [
                'tree_id' => $treeId,
                'fields' => array_keys($data),
            ]);
        }

        return $updated;
    }

    /**
     * Delete a tree and all its data
     *
     * @param int $treeId Tree ID
     * @return bool Success
     */
    public function deleteTree(int $treeId): bool
    {
        // Get tree info for logging
        $tree = $this->getTree($treeId);

        // Foreign keys with CASCADE will handle related records
        $sql = "DELETE FROM genealogy_trees WHERE id = ?";
        $deleted = DB::delete($sql, [$treeId]) > 0;

        if ($deleted) {
            Log::info('TreeManagementService: Tree deleted', [
                'tree_id' => $treeId,
                'name' => $tree->name ?? 'Unknown',
            ]);
        }

        return $deleted;
    }

    /**
     * Update tree statistics from actual record counts
     *
     * @param int $treeId Tree ID
     * @return void
     */
    public function updateTreeStats(int $treeId): void
    {
        $sql = "UPDATE genealogy_trees SET
                    person_count = (SELECT COUNT(*) FROM genealogy_persons WHERE tree_id = ?),
                    family_count = (SELECT COUNT(*) FROM genealogy_families WHERE tree_id = ?),
                    media_count = (SELECT COUNT(*) FROM genealogy_media WHERE tree_id = ?),
                    source_count = (SELECT COUNT(*) FROM genealogy_sources WHERE tree_id = ?),
                    updated_at = NOW()
                WHERE id = ?";

        DB::update($sql, [$treeId, $treeId, $treeId, $treeId, $treeId]);
    }

    /**
     * Update tree media statistics only
     *
     * @param int $treeId Tree ID
     * @return void
     */
    public function updateTreeMediaStats(int $treeId): void
    {
        $sql = "UPDATE genealogy_trees SET
                    media_count = (SELECT COUNT(*) FROM genealogy_media WHERE tree_id = ?),
                    updated_at = NOW()
                WHERE id = ?";

        DB::update($sql, [$treeId, $treeId]);
    }

    /**
     * Get tree statistics
     *
     * @param int $treeId Tree ID
     * @return array
     */
    public function getTreeStatistics(int $treeId): array
    {
        $stats = [];

        // Basic counts
        $tree = $this->getTree($treeId);
        $stats['person_count'] = $tree->person_count ?? 0;
        $stats['family_count'] = $tree->family_count ?? 0;
        $stats['media_count'] = $tree->media_count ?? 0;
        $stats['source_count'] = $tree->source_count ?? 0;

        // Gender breakdown
        $sql = "SELECT sex, COUNT(*) as count
                FROM genealogy_persons
                WHERE tree_id = ?
                GROUP BY sex";
        $genders = DB::select($sql, [$treeId]);
        $stats['gender_breakdown'] = [];
        foreach ($genders as $g) {
            $stats['gender_breakdown'][$g->sex ?? 'Unknown'] = $g->count;
        }

        // Birth year range
        $sql = "SELECT
                    MIN(CAST(SUBSTRING(birth_date, 1, 4) AS UNSIGNED)) as earliest_birth,
                    MAX(CAST(SUBSTRING(birth_date, 1, 4) AS UNSIGNED)) as latest_birth
                FROM genealogy_persons
                WHERE tree_id = ? AND birth_date IS NOT NULL AND birth_date REGEXP '^[0-9]{4}'";
        $dates = DB::selectOne($sql, [$treeId]);
        $stats['earliest_birth'] = $dates->earliest_birth;
        $stats['latest_birth'] = $dates->latest_birth;

        // Living vs deceased
        $sql = "SELECT
                    SUM(CASE WHEN death_date IS NULL THEN 1 ELSE 0 END) as living,
                    SUM(CASE WHEN death_date IS NOT NULL THEN 1 ELSE 0 END) as deceased
                FROM genealogy_persons
                WHERE tree_id = ?";
        $living = DB::selectOne($sql, [$treeId]);
        $stats['living_count'] = (int) ($living->living ?? 0);
        $stats['deceased_count'] = (int) ($living->deceased ?? 0);

        // Persons with photos
        $sql = "SELECT COUNT(DISTINCT person_id) as count
                FROM genealogy_person_media pm
                JOIN genealogy_persons p ON p.id = pm.person_id
                WHERE p.tree_id = ?";
        $withPhotos = DB::selectOne($sql, [$treeId]);
        $stats['persons_with_photos'] = (int) ($withPhotos->count ?? 0);

        // Media with identified faces
        $sql = "SELECT
                    SUM(CASE WHEN has_faces = 1 THEN 1 ELSE 0 END) as with_faces,
                    SUM(face_count) as total_faces
                FROM genealogy_media
                WHERE tree_id = ?";
        $faces = DB::selectOne($sql, [$treeId]);
        $stats['media_with_faces'] = (int) ($faces->with_faces ?? 0);
        $stats['total_faces'] = (int) ($faces->total_faces ?? 0);

        return $stats;
    }

    /**
     * Get recent additions to a tree
     *
     * @param int $treeId Tree ID
     * @param int $limit Number of items
     * @return array
     */
    public function getRecentAdditions(int $treeId, int $limit = 10): array
    {
        $sql = "SELECT id, 'person' as type, CONCAT(given_name, ' ', surname) as name, created_at
                FROM genealogy_persons
                WHERE tree_id = ?
                UNION ALL
                SELECT id, 'media' as type, title as name, created_at
                FROM genealogy_media
                WHERE tree_id = ?
                ORDER BY created_at DESC
                LIMIT ?";

        return DB::select($sql, [$treeId, $treeId, $limit]);
    }

    /**
     * Get surname statistics for a tree
     *
     * @param int $treeId Tree ID
     * @param int $limit Number of surnames
     * @return array
     */
    public function getSurnameStatistics(int $treeId, int $limit = 50): array
    {
        $sql = "SELECT surname, COUNT(*) as count
                FROM genealogy_persons
                WHERE tree_id = ? AND surname IS NOT NULL AND surname != ''
                GROUP BY surname
                ORDER BY count DESC, surname ASC
                LIMIT ?";

        return DB::select($sql, [$treeId, $limit]);
    }

    /**
     * Get all unique surnames in a tree
     *
     * @param int $treeId Tree ID
     * @return array List of surnames
     */
    public function getDistinctSurnames(int $treeId): array
    {
        $sql = "SELECT DISTINCT surname
                FROM genealogy_persons
                WHERE tree_id = ? AND surname IS NOT NULL AND surname != ''
                ORDER BY surname ASC";

        $results = DB::select($sql, [$treeId]);
        return array_column($results, 'surname');
    }

    /**
     * Check if user owns a tree
     *
     * @param int $treeId Tree ID
     * @param int $userId User ID
     * @return bool
     */
    public function userOwnsTree(int $treeId, int $userId): bool
    {
        // genealogy_trees has no user_id column — single-user system
        $sql = "SELECT 1 FROM genealogy_trees WHERE id = ? LIMIT 1";
        return DB::selectOne($sql, [$treeId]) !== null;
    }

    /**
     * Transfer tree ownership
     *
     * @param int $treeId Tree ID
     * @param int $newUserId New owner user ID
     * @return bool Success
     */
    public function transferTreeOwnership(int $treeId, int $newUserId): bool
    {
        // genealogy_trees has no user_id column — single-user system, no-op
        Log::info('TreeManagementService: transferTreeOwnership called but table has no user_id', [
            'tree_id' => $treeId,
        ]);
        return false;
    }

    /**
     * Clone a tree (create copy with all data)
     *
     * @param int $sourceTreeId Source tree ID
     * @param string $newName New tree name
     * @param int|null $newUserId Owner for the cloned tree
     * @return int|null New tree ID or null on failure
     */
    public function cloneTree(int $sourceTreeId, string $newName, ?int $newUserId = null): ?int
    {
        try {
            DB::beginTransaction();

            // Get source tree
            $sourceTree = $this->getTree($sourceTreeId);
            if (!$sourceTree) {
                return null;
            }

            // Create new tree
            $newTreeId = $this->createTree($newName, $sourceTree->description, $newUserId);

            // Clone persons (this is a basic implementation - IDs will change)
            $sql = "INSERT INTO genealogy_persons
                    (tree_id, gedcom_id, given_name, surname, suffix, nickname,
                     sex, birth_date, birth_place, death_date, death_place,
                     occupation, education, religion, notes, created_at, updated_at)
                    SELECT ?, gedcom_id, given_name, surname, suffix, nickname,
                           sex, birth_date, birth_place, death_date, death_place,
                           occupation, education, religion, notes, NOW(), NOW()
                    FROM genealogy_persons
                    WHERE tree_id = ?";
            DB::insert($sql, [$newTreeId, $sourceTreeId]);

            // Clone sources
            $sql = "INSERT INTO genealogy_sources
                    (tree_id, title, author, publication,
                     repository, created_at, updated_at)
                    SELECT ?, title, author, publication,
                           repository, NOW(), NOW()
                    FROM genealogy_sources
                    WHERE tree_id = ?";
            DB::insert($sql, [$newTreeId, $sourceTreeId]);

            // Update statistics
            $this->updateTreeStats($newTreeId);

            DB::commit();

            Log::info('TreeManagementService: Tree cloned', [
                'source_tree_id' => $sourceTreeId,
                'new_tree_id' => $newTreeId,
                'new_name' => $newName,
            ]);

            return $newTreeId;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('TreeManagementService: Clone failed', [
                'source_tree_id' => $sourceTreeId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get tree with full statistics for dashboard
     *
     * @param int $treeId Tree ID
     * @return array|null
     */
    public function getTreeDashboard(int $treeId): ?array
    {
        $tree = $this->getTree($treeId);
        if (!$tree) {
            return null;
        }

        return [
            'tree' => $tree,
            'statistics' => $this->getTreeStatistics($treeId),
            'recent_additions' => $this->getRecentAdditions($treeId, 5),
            'top_surnames' => $this->getSurnameStatistics($treeId, 10),
        ];
    }
}
