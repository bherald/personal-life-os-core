<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\JoplinFilesService;
use App\Services\JoplinWriteService;
use App\Services\JoplinTagsService;
use App\Services\JoplinLockHandler;
use App\Services\JoplinQueueService;
use App\Services\JoplinMetadataCacheService;
use App\Services\JoplinSyncService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JoplinController extends Controller
{
    public function __construct(
        private JoplinFilesService $filesService,
        private JoplinWriteService $writeService,
        private JoplinTagsService $tagsService,
        private JoplinLockHandler $lockHandler,
        private JoplinQueueService $queueService,
        private JoplinMetadataCacheService $cacheService,
        private JoplinSyncService $syncService
    ) {}

    /**
     * List all notes
     * GET /api/joplin/notes?limit=100&use_cache=true&force_refresh=false
     */
    public function listNotes(Request $request): JsonResponse
    {
        try {
            $limit = $request->input('limit', 100);
            $useCache = $request->input('use_cache', true);
            $forceRefresh = $request->input('force_refresh', false);
            $parentId = $request->input('parent_id', null);

            // Use cache by default for speed
            if ($useCache && !$forceRefresh) {
                $cachedNotes = $this->cacheService->getCachedNotes($limit, $parentId);

                // If cache is empty, fallback to WebDAV
                if ($cachedNotes->isEmpty()) {
                    Log::info('Joplin cache empty, falling back to WebDAV');
                    return $this->listNotesFromWebDAV($request);
                }

                // Return cached data (fast!)
                $notesData = $cachedNotes->map(function($note) {
                    return [
                        'id' => $note->id,
                        'title' => $note->title,
                        'preview' => $note->preview,
                        'parent_id' => $note->parent_id,
                        'created_time' => $note->created_time?->toIso8601String(),
                        'updated_time' => $note->updated_time?->toIso8601String(),
                        'from_cache' => true,
                        'cached_at' => $note->cached_at?->toIso8601String(),
                    ];
                })->toArray();

                return response()->json([
                    'success' => true,
                    'data' => $notesData,
                    'cache_stats' => $this->cacheService->getStats(),
                ]);
            }

            // Force refresh or cache disabled - read from WebDAV
            return $this->listNotesFromWebDAV($request);

        } catch (\Exception $e) {
            Log::error('Failed to list Joplin notes', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List notes from WebDAV (slower but always fresh)
     */
    private function listNotesFromWebDAV(Request $request): JsonResponse
    {
        $notes = $this->filesService->listNotes();
        $notesData = [];
        $limit = $request->input('limit', 100);
        $noteCount = 0;

        foreach ($notes as $filename) {
            if ($limit && $noteCount >= $limit) {
                break;
            }

            $noteId = str_replace('.md', '', $filename);
            $note = $this->filesService->getNote($noteId);

            if ($note && $note['type'] == 1) {
                $notesData[] = [
                    'id' => $note['id'],
                    'title' => $note['title'],
                    'preview' => substr($note['content'], 0, 200),
                    'parent_id' => $note['parent_id'] ?? null,
                    'created_time' => $note['created_time'],
                    'updated_time' => $note['updated_time'],
                    'from_cache' => false,
                ];
                $noteCount++;
            }
        }

        return response()->json([
            'success' => true,
            'data' => $notesData,
        ]);
    }

    /**
     * Get single note
     * GET /api/joplin/notes/{id}
     */
    public function getNote(string $id): JsonResponse
    {
        try {
            $note = $this->filesService->getNote($id);

            if (!$note) {
                return response()->json([
                    'success' => false,
                    'error' => 'Note not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $note,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create note
     * POST /api/joplin/notes
     */
    public function createNote(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string',
            'content' => 'required|string',
            'parent_id' => 'nullable|string',
        ]);

        try {
            $result = $this->writeService->createNote(
                $request->input('title'),
                $request->input('content'),
                $request->input('parent_id'),
                $request->input('options', [])
            );

            return response()->json($result, $result['success'] ? 200 : 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update note
     * PUT /api/joplin/notes/{id}
     */
    public function updateNote(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'title' => 'sometimes|string',
            'content' => 'sometimes|string',
            'parent_id' => 'nullable|string',
        ]);

        try {
            $updates = $request->only(['title', 'content', 'parent_id']);

            $result = $this->writeService->updateNote($id, $updates);

            return response()->json($result, $result['success'] ? 200 : 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete note
     * DELETE /api/joplin/notes/{id}
     */
    public function deleteNote(string $id): JsonResponse
    {
        try {
            $result = $this->writeService->deleteNote($id);

            return response()->json($result, $result['success'] ? 200 : 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List notebooks
     * GET /api/joplin/notebooks
     */
    public function listNotebooks(): JsonResponse
    {
        try {
            // Use cached notebooks from joplin_metadata_cache table
            $notebooks = DB::select('
                SELECT id, title, parent_id, created_time, updated_time, cached_at
                FROM joplin_metadata_cache
                WHERE type = 2 AND is_deleted = 0
                ORDER BY title ASC
            ');

            // Get note counts per notebook (including orphaned notes with NULL parent_id)
            $noteCounts = DB::select('
                SELECT parent_id, COUNT(*) as count
                FROM joplin_metadata_cache
                WHERE type = 1 AND is_deleted = 0
                GROUP BY parent_id
            ');
            $countMap = [];
            $orphanedCount = 0;
            foreach ($noteCounts as $row) {
                if ($row->parent_id === null) {
                    $orphanedCount = $row->count;
                } else {
                    $countMap[$row->parent_id] = $row->count;
                }
            }

            // Add note counts to notebooks
            $notebooksWithCounts = array_map(function ($notebook) use ($countMap) {
                return [
                    'id' => $notebook->id,
                    'title' => $notebook->title,
                    'parent_id' => $notebook->parent_id,
                    'created_time' => $notebook->created_time,
                    'updated_time' => $notebook->updated_time,
                    'cached_at' => $notebook->cached_at,
                    'note_count' => $countMap[$notebook->id] ?? 0,
                ];
            }, $notebooks);

            return response()->json([
                'success' => true,
                'data' => $notebooksWithCounts,
                'orphaned_notes' => $orphanedCount,
                'cache_stats' => $this->cacheService->getStats(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list notebooks', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create notebook
     * POST /api/joplin/notebooks
     */
    public function createNotebook(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string',
            'parent_id' => 'nullable|string',
        ]);

        try {
            $result = $this->writeService->createNotebook(
                $request->input('title'),
                $request->input('parent_id')
            );

            return response()->json($result, $result['success'] ? 200 : 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List all tags
     * GET /api/joplin/tags
     */
    public function listTags(): JsonResponse
    {
        try {
            // CT-1: Return tags from Redis cache (refreshed by background job).
            // WebDAV iteration is too slow for API response — getAllTags() iterates
            // every .md file via PROPFIND + N GETs to find type_=5 (Joplin tag type).
            // Cache is populated by joplin_sync workflow or manual refresh.
            $cacheKey = 'joplin_tags_cache';
            $tags = Cache::get($cacheKey, []);

            return response()->json([
                'success' => true,
                'data' => $tags,
                'cached' => true,
                'cached_count' => count($tags),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Refresh tag cache from WebDAV (background call, not for UI)
     * POST /api/joplin/tags/refresh
     */
    public function refreshTagCache(): JsonResponse
    {
        try {
            $tagsService = app(JoplinTagsService::class);
            $tags = $tagsService->getAllTags();

            Cache::put('joplin_tags_cache', $tags, 86400); // 24h TTL, refreshed by sync

            return response()->json([
                'success' => true,
                'data' => $tags,
                'count' => count($tags),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create tag
     * POST /api/joplin/tags
     */
    public function createTag(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string',
        ]);

        try {
            $result = $this->tagsService->createTag($request->input('title'));

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get tags for note
     * GET /api/joplin/notes/{id}/tags
     */
    public function getNoteTags(string $id): JsonResponse
    {
        try {
            $tags = $this->tagsService->getTagsForNote($id);

            return response()->json([
                'success' => true,
                'data' => $tags,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add tag to note
     * POST /api/joplin/notes/{id}/tags
     */
    public function addTagToNote(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'tag_id' => 'required|string',
        ]);

        try {
            $result = $this->tagsService->addTagToNote($id, $request->input('tag_id'));

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search notes
     * GET /api/joplin/search
     */
    public function searchNotes(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:2',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        try {
            $results = $this->filesService->searchNotes(
                $request->input('q'),
                $request->input('limit', 20)
            );

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get lock status
     * GET /api/joplin/lock-status
     */
    public function getLockStatus(): JsonResponse
    {
        try {
            // CT-2: Cache lock status in Redis with short TTL (8 seconds).
            // Lock state is time-sensitive (Joplin spec: 180s lock TTL) but UI polls
            // frequently — 8s stale cache is acceptable for display, prevents
            // hammering WebDAV with PROPFIND + N GETs per poll.
            $data = Cache::remember('joplin_lock_status', 8, function () {
                return app(JoplinLockHandler::class)->getLockStatus();
            });

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get queue statistics
     * GET /api/joplin/queue-stats
     */
    public function getQueueStats(): JsonResponse
    {
        try {
            // Queue stats should be fast - this is DB-based, not WebDAV
            $stats = $this->queueService->getStatistics();

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            // If it fails, return empty stats instead of hanging
            Log::warning('Failed to get queue stats', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => true,
                'data' => ['pending' => 0, 'failed' => 0],
            ]);
        }
    }

    /**
     * Get attachments for a note (E17/EA1)
     * GET /api/joplin/notes/{id}/attachments
     *
     * Returns attachments from the joplin_attachment_index with media URLs
     */
    public function getNoteAttachments(string $id): JsonResponse
    {
        try {
            $attachments = \DB::select(
                "SELECT id, resource_id, filename, extension, file_size, sync_status, media_url, last_processed_at FROM joplin_attachment_index WHERE note_id = ? ORDER BY filename",
                [$id]
            );

            return response()->json([
                'success' => true,
                'data' => $attachments,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get note attachments', ['note_id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Joplin sync health status
     * GET /api/joplin/health
     *
     * Returns comprehensive health information including:
     * - Overall status (healthy, degraded, unhealthy)
     * - Last sync timestamp and result
     * - Nextcloud/WebDAV connection status
     * - Queue statistics
     */
    public function getHealth(): JsonResponse
    {
        try {
            $health = $this->syncService->getHealth();

            // Determine HTTP status code based on health status
            $httpStatus = match($health['status']) {
                'healthy' => 200,
                'degraded' => 200,  // Still return 200 for degraded (operational but with issues)
                'unhealthy' => 503,
                default => 200,
            };

            return response()->json([
                'success' => true,
                'data' => $health,
            ], $httpStatus);
        } catch (\Exception $e) {
            Log::error('Failed to get Joplin health status', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'data' => [
                    'status' => 'error',
                    'message' => 'Failed to retrieve health status',
                ],
            ], 500);
        }
    }
}
