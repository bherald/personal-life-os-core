<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Caches parsed metadata from an operator-managed Joplin sync target.
 * This service does not use upstream Joplin application or server source code.
 */
class JoplinMetadataCacheService
{
    protected $filesService;

    public function __construct(JoplinFilesService $filesService)
    {
        $this->filesService = $filesService;
    }

    /**
     * Get cached notes with optional limit
     */
    public function getCachedNotes(?int $limit = null, ?string $parentId = null)
    {
        $sql = 'SELECT * FROM joplin_metadata_cache WHERE type = 1 AND is_deleted = 0';
        $params = [];

        if ($parentId) {
            $sql .= ' AND parent_id = ?';
            $params[] = $parentId;
        }

        $sql .= ' ORDER BY updated_time DESC';

        if ($limit) {
            $sql .= ' LIMIT ?';
            $params[] = $limit;
        }

        return DB::select($sql, $params);
    }

    /**
     * Get cached notebooks
     */
    public function getCachedNotebooks()
    {
        return DB::select('SELECT * FROM joplin_metadata_cache WHERE type = 2 AND is_deleted = 0 ORDER BY title ASC');
    }

    /**
     * Get single cached note by ID
     */
    public function getCachedNote(string $noteId)
    {
        return DB::selectOne('SELECT * FROM joplin_metadata_cache WHERE id = ?', [$noteId]);
    }

    /**
     * Refresh metadata for a single note
     */
    public function refreshNote(string $noteId): ?object
    {
        try {
            $note = $this->filesService->getNote($noteId);

            if (! $note) {
                return $this->markAsDeleted($noteId);
            }

            return $this->updateOrCreateCache($note);
        } catch (\Exception $e) {
            Log::error("Failed to refresh note metadata: {$noteId}", [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Refresh all metadata from WebDAV (background job)
     */
    public function refreshAllMetadata(int $limit = 500): array
    {
        $stats = [
            'processed' => 0,
            'updated' => 0,
            'created' => 0,
            'deleted' => 0,
            'errors' => 0,
        ];

        try {
            $notes = $this->filesService->listNotes();
            $noteIds = array_map(fn ($filename) => str_replace('.md', '', $filename), $notes);
            $noteIds = array_slice($noteIds, 0, $limit);

            foreach ($noteIds as $noteId) {
                try {
                    $note = $this->filesService->getNote($noteId);

                    if ($note && in_array($note['type'], [1, 2])) {
                        $existing = DB::selectOne('SELECT id FROM joplin_metadata_cache WHERE id = ?', [$noteId]);
                        $this->updateOrCreateCache($note);

                        if ($existing) {
                            $stats['updated']++;
                        } else {
                            $stats['created']++;
                        }
                        $stats['processed']++;
                    }
                } catch (\Exception $e) {
                    $stats['errors']++;
                    Log::warning("Error caching note {$noteId}: ".$e->getMessage());
                }

                if ($stats['processed'] % 50 == 0) {
                    usleep(100000);
                }
            }

            $stats['deleted'] = $this->markMissingAsDeleted($noteIds);
        } catch (\Exception $e) {
            Log::error('Failed to refresh all metadata', ['error' => $e->getMessage()]);
        }

        return $stats;
    }

    /**
     * Update or create cache entry
     */
    protected function updateOrCreateCache(array $note): object
    {
        $id = $note['id'];
        $title = $note['title'] ?? 'Untitled';
        $preview = $this->generatePreview($note['body'] ?? '');
        $parentId = $note['parent_id'] ?? null;
        $type = $note['type'] ?? 1;
        $createdTime = isset($note['created_time']) ? Carbon::parse($note['created_time']) : null;
        $updatedTime = isset($note['updated_time']) ? Carbon::parse($note['updated_time']) : null;
        $userCreatedTime = isset($note['user_created_time']) ? Carbon::parse($note['user_created_time']) : null;
        $userUpdatedTime = isset($note['user_updated_time']) ? Carbon::parse($note['user_updated_time']) : null;
        $isConflict = $note['is_conflict'] ?? false;
        $markupLanguage = $note['markup_language'] ?? 'markdown';

        $existing = DB::selectOne('SELECT id FROM joplin_metadata_cache WHERE id = ?', [$id]);

        if ($existing) {
            DB::update('
                UPDATE joplin_metadata_cache SET
                    title = ?, preview = ?, parent_id = ?, type = ?,
                    created_time = ?, updated_time = ?, user_created_time = ?, user_updated_time = ?,
                    is_conflict = ?, is_deleted = 0, markup_language = ?, cached_at = NOW()
                WHERE id = ?
            ', [$title, $preview, $parentId, $type, $createdTime, $updatedTime, $userCreatedTime, $userUpdatedTime, $isConflict, $markupLanguage, $id]);
        } else {
            DB::insert('
                INSERT INTO joplin_metadata_cache
                    (id, title, preview, parent_id, type, created_time, updated_time, user_created_time, user_updated_time, is_conflict, is_deleted, markup_language, cached_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW())
            ', [$id, $title, $preview, $parentId, $type, $createdTime, $updatedTime, $userCreatedTime, $userUpdatedTime, $isConflict, $markupLanguage]);
        }

        return DB::selectOne('SELECT * FROM joplin_metadata_cache WHERE id = ?', [$id]);
    }

    /**
     * Mark a note as deleted in cache
     */
    protected function markAsDeleted(string $noteId): ?object
    {
        DB::update('UPDATE joplin_metadata_cache SET is_deleted = 1, cached_at = NOW() WHERE id = ?', [$noteId]);

        return DB::selectOne('SELECT * FROM joplin_metadata_cache WHERE id = ?', [$noteId]);
    }

    /**
     * Mark notes that are missing from WebDAV as deleted
     */
    protected function markMissingAsDeleted(array $existingNoteIds): int
    {
        if (empty($existingNoteIds)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($existingNoteIds), '?'));

        return DB::update(
            "UPDATE joplin_metadata_cache SET is_deleted = 1, cached_at = NOW() WHERE type = 1 AND id NOT IN ({$placeholders})",
            $existingNoteIds
        );
    }

    /**
     * Generate preview text from note body
     */
    protected function generatePreview(string $body, int $length = 200): string
    {
        $text = preg_replace('/[#*_`\[\]()]/u', '', $body);
        $text = preg_replace('/\s+/u', ' ', $text);

        return mb_substr(trim($text), 0, $length);
    }

    /**
     * Search cached notes
     */
    public function searchCached(string $query, int $limit = 50)
    {
        return DB::select(
            'SELECT * FROM joplin_metadata_cache WHERE type = 1 AND is_deleted = 0
             AND (title LIKE ? OR preview LIKE ?)
             ORDER BY updated_time DESC LIMIT ?',
            ["%{$query}%", "%{$query}%", $limit]
        );
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        $stats = DB::selectOne('
            SELECT
                SUM(CASE WHEN type = 1 AND is_deleted = 0 THEN 1 ELSE 0 END) as total_notes,
                SUM(CASE WHEN type = 2 AND is_deleted = 0 THEN 1 ELSE 0 END) as total_notebooks,
                SUM(CASE WHEN is_deleted = 1 THEN 1 ELSE 0 END) as deleted_notes,
                SUM(CASE WHEN cached_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 1 ELSE 0 END) as stale_count,
                MAX(cached_at) as last_cache_update
            FROM joplin_metadata_cache
        ');

        return [
            'total_notes' => (int) ($stats->total_notes ?? 0),
            'total_notebooks' => (int) ($stats->total_notebooks ?? 0),
            'deleted_notes' => (int) ($stats->deleted_notes ?? 0),
            'stale_count' => (int) ($stats->stale_count ?? 0),
            'last_cache_update' => $stats->last_cache_update,
        ];
    }

    /**
     * Clear deleted entries older than 7 days
     */
    public function pruneDeleted(): int
    {
        return DB::delete('DELETE FROM joplin_metadata_cache WHERE is_deleted = 1 AND cached_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
    }
}
