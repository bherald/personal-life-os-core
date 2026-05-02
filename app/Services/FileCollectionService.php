<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class FileCollectionService
{
    public function createCollection(string $name, ?string $description = null, string $type = 'album', ?array $smartCriteria = null): int
    {
        $isSmart = $smartCriteria !== null ? 1 : 0;

        DB::insert(
            "INSERT INTO file_collections (name, description, collection_type, is_smart, smart_criteria, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
            [$name, $description, $type, $isSmart, $smartCriteria ? json_encode($smartCriteria) : null]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    public function addItems(int $collectionId, array $fileRegistryIds): int
    {
        $added = 0;
        $maxSort = DB::selectOne(
            "SELECT MAX(sort_order) as max_sort FROM file_collection_items WHERE collection_id = ?",
            [$collectionId]
        );
        $sortOrder = ($maxSort->max_sort ?? 0) + 1;

        foreach ($fileRegistryIds as $fileId) {
            try {
                DB::insert(
                    "INSERT IGNORE INTO file_collection_items (collection_id, file_registry_id, sort_order, added_at)
                     VALUES (?, ?, ?, NOW())",
                    [$collectionId, $fileId, $sortOrder++]
                );
                $added++;
            } catch (Exception $e) {
                // Duplicate, skip
            }
        }

        $this->updateItemCount($collectionId);
        return $added;
    }

    public function removeItems(int $collectionId, array $fileRegistryIds): int
    {
        if (empty($fileRegistryIds)) return 0;

        $placeholders = implode(',', array_fill(0, count($fileRegistryIds), '?'));
        $params = array_merge([$collectionId], $fileRegistryIds);

        $removed = DB::delete(
            "DELETE FROM file_collection_items WHERE collection_id = ? AND file_registry_id IN ({$placeholders})",
            $params
        );

        $this->updateItemCount($collectionId);
        return $removed;
    }

    public function getCollections(?string $type = null, int $limit = 50): array
    {
        $params = [];
        $where = '';
        if ($type) {
            $where = 'WHERE collection_type = ?';
            $params[] = $type;
        }
        $params[] = $limit;

        return DB::select(
            "SELECT * FROM file_collections {$where} ORDER BY updated_at DESC LIMIT ?",
            $params
        );
    }

    public function getCollectionItems(int $collectionId, int $limit = 100, int $offset = 0): array
    {
        return DB::select(
            "SELECT fci.*, fr.current_path, fr.filename, fr.file_size, fr.mime_type, fr.asset_uuid, fr.ai_description
             FROM file_collection_items fci
             JOIN file_registry fr ON fr.id = fci.file_registry_id
             WHERE fci.collection_id = ?
             ORDER BY fci.sort_order ASC
             LIMIT ? OFFSET ?",
            [$collectionId, $limit, $offset]
        );
    }

    public function evaluateSmartCollection(int $collectionId): array
    {
        $collection = DB::selectOne("SELECT * FROM file_collections WHERE id = ? AND is_smart = 1", [$collectionId]);
        if (!$collection) {
            return ['error' => 'Not a smart collection'];
        }

        $criteria = json_decode($collection->smart_criteria, true);
        if (!$criteria) {
            return ['error' => 'Invalid smart criteria'];
        }

        $where = ['1=1'];
        $params = [];

        if (!empty($criteria['mime_type'])) {
            $where[] = 'mime_type LIKE ?';
            $params[] = $criteria['mime_type'] . '%';
        }
        if (!empty($criteria['path_prefix'])) {
            $where[] = 'current_path LIKE ?';
            $params[] = $criteria['path_prefix'] . '%';
        }
        if (!empty($criteria['min_size'])) {
            $where[] = 'file_size >= ?';
            $params[] = $criteria['min_size'];
        }
        if (!empty($criteria['max_size'])) {
            $where[] = 'file_size <= ?';
            $params[] = $criteria['max_size'];
        }
        if (!empty($criteria['extension'])) {
            $exts = (array) $criteria['extension'];
            $placeholders = implode(',', array_fill(0, count($exts), '?'));
            $where[] = "LOWER(SUBSTRING_INDEX(filename, '.', -1)) IN ({$placeholders})";
            $params = array_merge($params, $exts);
        }
        if (!empty($criteria['created_after'])) {
            $where[] = 'created_at >= ?';
            $params[] = $criteria['created_after'];
        }
        if (!empty($criteria['keywords'])) {
            $where[] = '(search_keywords LIKE ? OR ai_description LIKE ?)';
            $kw = '%' . $criteria['keywords'] . '%';
            $params[] = $kw;
            $params[] = $kw;
        }

        $whereClause = implode(' AND ', $where);
        $files = DB::select("SELECT id FROM file_registry WHERE {$whereClause} LIMIT 5000", $params);
        $fileIds = array_column($files, 'id');

        // Clear and re-populate
        DB::delete("DELETE FROM file_collection_items WHERE collection_id = ?", [$collectionId]);

        $added = 0;
        foreach ($fileIds as $i => $fileId) {
            DB::insert(
                "INSERT INTO file_collection_items (collection_id, file_registry_id, sort_order, added_at) VALUES (?, ?, ?, NOW())",
                [$collectionId, $fileId, $i + 1]
            );
            $added++;
        }

        $this->updateItemCount($collectionId);

        return ['evaluated' => true, 'matched' => count($fileIds), 'added' => $added];
    }

    public function updateItemCount(int $collectionId): void
    {
        $count = DB::selectOne(
            "SELECT COUNT(*) as count FROM file_collection_items WHERE collection_id = ?",
            [$collectionId]
        );

        DB::update(
            "UPDATE file_collections SET item_count = ?, updated_at = NOW() WHERE id = ?",
            [$count->count, $collectionId]
        );
    }

    public function deleteCollection(int $collectionId): bool
    {
        return DB::delete("DELETE FROM file_collections WHERE id = ?", [$collectionId]) > 0;
    }
}
