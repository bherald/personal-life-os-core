<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuteFileCatalogRagSync;
use App\Jobs\ExecuteFileRegistryScan;
use App\Jobs\ThumbnailGenerateJob;
use App\Services\FileCategorizationRAGService;
use App\Services\FileRegistryService;
use App\Services\ThumbnailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * FileCatalogController
 *
 * Read-only File Catalog API for browsing, searching, and managing
 * the configured Nextcloud library root with RAG integration.
 *
 * Replaces the old WindowsFileOrganizerController with simplified
 * catalog-focused functionality (no file movement or action queue).
 */
class FileCatalogController extends Controller
{
    private FileRegistryService $fileRegistry;

    private FileCategorizationRAGService $ragService;

    public function __construct(
        FileRegistryService $fileRegistry,
        FileCategorizationRAGService $ragService
    ) {
        $this->fileRegistry = $fileRegistry;
        $this->ragService = $ragService;
    }

    /**
     * Get dashboard overview
     *
     * GET /api/file-catalog/dashboard
     */
    public function dashboard(): JsonResponse
    {
        try {
            $stats = $this->fileRegistry->getStatistics();
            $ragStats = $this->ragService->getStats();

            // Get recent activity
            $recentScans = DB::select('
                SELECT id, run_type, scope_path, status, files_scanned, files_registered,
                       started_at, completed_at
                FROM file_registry_sync_runs
                ORDER BY started_at DESC
                LIMIT 5
            ');

            // Get last scan timestamp
            $lastScan = DB::selectOne("
                SELECT MAX(started_at) as last_scan
                FROM file_registry_sync_runs
                WHERE status = 'completed'
            ");

            // Get running scan if any
            $runningScan = DB::selectOne("
                SELECT id, run_type, scope_path, files_scanned, started_at
                FROM file_registry_sync_runs
                WHERE status = 'running'
                ORDER BY started_at DESC
                LIMIT 1
            ");

            return response()->json([
                'success' => true,
                'data' => [
                    'fileStats' => $stats,
                    'ragStats' => $ragStats,
                    'recentScans' => $recentScans,
                    'lastScan' => $lastScan->last_scan ?? null,
                    'runningScan' => $runningScan,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Dashboard error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed statistics
     *
     * GET /api/file-catalog/stats
     */
    public function stats(): JsonResponse
    {
        try {
            $stats = $this->fileRegistry->getStatistics();
            $ragStats = $this->ragService->getStats();
            $duplicatesStats = $this->fileRegistry->getDuplicatesStats();

            return response()->json([
                'success' => true,
                'data' => [
                    'registry' => $stats,
                    'rag' => $ragStats,
                    'duplicates' => $duplicatesStats,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Stats error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List cataloged files with pagination and filtering
     *
     * GET /api/file-catalog/files
     */
    public function listFiles(Request $request): JsonResponse
    {
        try {
            $filters = [
                'search' => $request->input('search'),
                'category' => $request->input('category'),
                'extension' => $request->input('extension'),
                'status' => $request->input('status', 'active'),
                'path_prefix' => $request->input('path_prefix'),
                'has_duplicates' => $request->boolean('has_duplicates'),
                'needs_rag_sync' => $request->boolean('needs_rag_sync'),
            ];

            $limit = min((int) $request->input('limit', 50), 200);
            $offset = max((int) $request->input('offset', 0), 0);

            $result = $this->fileRegistry->listFiles($filters, $limit, $offset);

            return response()->json([
                'success' => true,
                'data' => $result['files'],
                'total' => $result['total'],
                'limit' => $limit,
                'offset' => $offset,
            ]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: List files error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get single file details
     *
     * GET /api/file-catalog/files/{uuid}
     */
    public function getFile(string $uuid): JsonResponse
    {
        try {
            $file = $this->fileRegistry->getFile($uuid);

            if (! $file) {
                return response()->json([
                    'success' => false,
                    'error' => 'File not found',
                ], 404);
            }

            // Get path history
            $pathHistory = DB::select('
                SELECT previous_path AS old_path, new_path, moved_by AS change_type, moved_at AS changed_at, move_reason AS change_reason
                FROM file_registry_path_history
                WHERE file_registry_id = (SELECT id FROM file_registry WHERE asset_uuid = ?)
                ORDER BY moved_at DESC
                LIMIT 10
            ', [$uuid]);

            // Get duplicates
            $duplicates = DB::select('
                SELECT fr.asset_uuid, fr.current_path, fr.file_size, fr.created_at
                FROM file_registry_duplicates frd
                JOIN file_registry fr ON fr.id = frd.duplicate_file_id
                WHERE frd.canonical_file_id = (SELECT id FROM file_registry WHERE asset_uuid = ?)
                ORDER BY frd.created_at DESC
            ', [$uuid]);

            return response()->json([
                'success' => true,
                'data' => [
                    'file' => $file,
                    'path_history' => $pathHistory,
                    'duplicates' => $duplicates,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Get file error', ['error' => $e->getMessage(), 'uuid' => $uuid]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get download URL for a file
     *
     * GET /api/file-catalog/files/{uuid}/download
     */
    public function downloadUrl(string $uuid): JsonResponse
    {
        try {
            $result = $this->fileRegistry->resolveAsset($uuid);

            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Could not resolve file',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'download_url' => $result['direct_url'] ?? null,
                    'webdav_url' => $result['webdav_url'] ?? null,
                    'path' => $result['path'] ?? null,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Download URL error', ['error' => $e->getMessage(), 'uuid' => $uuid]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Trigger a manual scan
     *
     * POST /api/file-catalog/scan
     */
    public function triggerScan(Request $request): JsonResponse
    {
        try {
            $path = $request->input('path', $this->nextcloudLibraryRoot());
            $limit = min((int) $request->input('limit', 500), 2000);

            ExecuteFileRegistryScan::dispatch($path, $limit);

            return response()->json([
                'success' => true,
                'data' => [
                    'queued' => true,
                    'queue' => 'long-running',
                    'path' => $path,
                    'limit' => $limit,
                ],
                'message' => 'File registry scan queued',
            ], 202);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Trigger scan error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get scan history
     *
     * GET /api/file-catalog/scan/history
     */
    public function scanHistory(Request $request): JsonResponse
    {
        try {
            $limit = min((int) $request->input('limit', 20), 100);
            $runs = $this->fileRegistry->getSyncRuns($limit);

            return response()->json([
                'success' => true,
                'data' => $runs,
            ]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Scan history error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cleanup stuck scan runs
     *
     * POST /api/file-catalog/scan/cleanup
     */
    public function cleanupStuck(Request $request): JsonResponse
    {
        try {
            $stuckMinutes = (int) $request->input('stuck_minutes', 60);
            $cleaned = $this->fileRegistry->cleanupStuckSyncRuns($stuckMinutes);

            return response()->json([
                'success' => true,
                'data' => [
                    'cleaned' => $cleaned,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Cleanup stuck error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get RAG sync status
     *
     * GET /api/file-catalog/rag/status
     */
    public function ragStatus(): JsonResponse
    {
        try {
            $stats = $this->ragService->getStats();

            // Get files needing sync
            $pending = DB::selectOne("
                SELECT COUNT(*) as cnt
                FROM file_registry
                WHERE rag_indexed_at IS NULL
                  AND status = 'active'
            ");

            // Get recently synced
            $recentlySynced = DB::select('
                SELECT asset_uuid, current_path, rag_indexed_at
                FROM file_registry
                WHERE rag_indexed_at IS NOT NULL
                ORDER BY rag_indexed_at DESC
                LIMIT 10
            ');

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => $stats,
                    'pending_sync' => (int) ($pending->cnt ?? 0),
                    'recently_synced' => $recentlySynced,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: RAG status error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Trigger RAG sync
     *
     * POST /api/file-catalog/rag/sync
     */
    public function ragSync(Request $request): JsonResponse
    {
        try {
            $limit = min((int) $request->input('limit', 50), 200);
            ExecuteFileCatalogRagSync::dispatch($limit);

            return response()->json([
                'success' => true,
                'data' => [
                    'queued' => true,
                    'queue' => 'long-running',
                    'limit' => $limit,
                ],
                'message' => 'File catalog RAG sync queued',
            ], 202);
        } catch (\Exception $e) {
            Log::error('FileCatalog: RAG sync error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search indexed files via RAG
     *
     * GET /api/file-catalog/rag/search
     */
    public function ragSearch(Request $request): JsonResponse
    {
        try {
            $query = $request->input('q');
            if (empty($query)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Query parameter "q" is required',
                ], 400);
            }

            $limit = min((int) $request->input('limit', 10), 50);
            $filters = [
                'category' => $request->input('category'),
                'extension' => $request->input('extension'),
            ];

            $result = $this->ragService->searchFiles($query, $limit, $filters);

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('FileCatalog: RAG search error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List duplicate files
     *
     * GET /api/file-catalog/duplicates
     */
    public function listDuplicates(Request $request): JsonResponse
    {
        try {
            $report = $this->fileRegistry->getDuplicatesReport();

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: List duplicates error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get duplicate statistics
     *
     * GET /api/file-catalog/duplicates/stats
     */
    public function duplicateStats(): JsonResponse
    {
        try {
            $stats = $this->fileRegistry->getDuplicatesStats();

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Duplicate stats error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get catalog settings (exclusion patterns, etc.)
     *
     * GET /api/file-catalog/settings
     */
    // ==========================================
    // Thumbnail/Preview Endpoints
    // ==========================================

    /**
     * Get a file thumbnail
     *
     * GET /api/file-catalog/files/{uuid}/thumbnail/{size?}
     */
    public function thumbnail(string $uuid, string $size = 'medium'): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $service = app(ThumbnailService::class);
            $result = $service->getThumbnail($uuid, $size);

            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Thumbnail not available',
                ], 404);
            }

            return response()->file($result['path'], [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'public, must-revalidate',
                'ETag' => '"'.md5_file($result['path']).'"',
            ]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Thumbnail error', ['error' => $e->getMessage(), 'uuid' => $uuid]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Trigger thumbnail generation for files
     *
     * POST /api/file-catalog/thumbnails/generate
     */
    public function generateThumbnails(Request $request): JsonResponse
    {
        try {
            $uuid = $request->input('uuid');
            $limit = min((int) $request->input('limit', 20), 100);
            $type = $request->input('type');
            $queue = $request->boolean('queue', false);

            if ($uuid) {
                if ($queue) {
                    ThumbnailGenerateJob::dispatch($uuid);

                    return response()->json([
                        'success' => true,
                        'message' => 'Thumbnail generation queued',
                    ]);
                }

                $service = app(ThumbnailService::class);
                $results = $service->generateAllSizes($uuid);

                return response()->json([
                    'success' => true,
                    'data' => $results,
                ]);
            }

            // Batch generate
            $service = app(ThumbnailService::class);
            $filters = [];
            if ($type) {
                $filters['type'] = $type;
            }

            $stats = $service->batchGenerate($filters, $limit);

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Generate thumbnails error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get thumbnail statistics
     *
     * GET /api/file-catalog/thumbnails/stats
     */
    public function thumbnailStats(): JsonResponse
    {
        try {
            $service = app(ThumbnailService::class);
            $stats = $service->getStats();

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Thumbnail stats error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // QUARANTINE
    // =========================================================================

    public function listQuarantined(Request $request): JsonResponse
    {
        try {
            $service = app(\App\Services\FileQuarantineService::class);
            $filters = $request->only(['status', 'reason', 'limit', 'offset']);
            $results = $service->getQuarantinedFiles($filters);

            return response()->json(['success' => true, 'data' => $results]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: List quarantined error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function quarantineFile(Request $request): JsonResponse
    {
        try {
            $fileRegistryId = (int) $request->input('file_registry_id');
            $reason = $request->input('reason', 'manual');
            $details = $request->input('details', []);

            if (! $fileRegistryId) {
                return response()->json(['success' => false, 'error' => 'file_registry_id required'], 422);
            }

            $service = app(\App\Services\FileQuarantineService::class);
            $id = $service->quarantineFile($fileRegistryId, $reason, 'manual', $details);

            return response()->json(['success' => true, 'quarantine_id' => $id]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Quarantine file error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function reviewQuarantined(Request $request, int $id): JsonResponse
    {
        try {
            $action = $request->input('action'); // release or delete
            $reviewedBy = $request->input('reviewed_by', 'user');

            if (! in_array($action, ['release', 'delete'])) {
                return response()->json(['success' => false, 'error' => 'action must be release or delete'], 422);
            }

            $service = app(\App\Services\FileQuarantineService::class);
            $result = $service->reviewFile($id, $action, $reviewedBy);

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Review quarantined error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // BUNDLES
    // =========================================================================

    public function listBundles(Request $request): JsonResponse
    {
        try {
            $service = app(\App\Services\FileBundleService::class);
            $filters = $request->only(['type', 'limit', 'offset']);
            $results = $service->getBundles($filters);

            return response()->json(['success' => true, 'data' => $results]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: List bundles error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getBundle(int $id): JsonResponse
    {
        try {
            $service = app(\App\Services\FileBundleService::class);
            $bundle = $service->getBundle($id);

            if (! $bundle) {
                return response()->json(['success' => false, 'error' => 'Bundle not found'], 404);
            }

            return response()->json(['success' => true, 'data' => $bundle]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Get bundle error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function detectBundles(Request $request): JsonResponse
    {
        try {
            $path = $request->input('path', $this->nextcloudLibraryRoot());
            $dryRun = (bool) $request->input('dry_run', true);

            $service = app(\App\Services\FileBundleService::class);
            $results = $service->autoDetectBundles($path, $dryRun);

            return response()->json(['success' => true, 'data' => $results]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Detect bundles error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // COLLECTIONS
    // =========================================================================

    public function listCollections(Request $request): JsonResponse
    {
        try {
            $service = app(\App\Services\FileCollectionService::class);
            $filters = $request->only(['type', 'limit', 'offset']);
            $results = $service->getCollections($filters);

            return response()->json(['success' => true, 'data' => $results]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: List collections error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function createCollection(Request $request): JsonResponse
    {
        try {
            $name = $request->input('name');
            $description = $request->input('description');
            $type = $request->input('collection_type', 'album');
            $smartCriteria = $request->input('smart_criteria');

            if (! $name) {
                return response()->json(['success' => false, 'error' => 'name required'], 422);
            }

            $service = app(\App\Services\FileCollectionService::class);
            $id = $service->createCollection($name, $description, $type, $smartCriteria);

            return response()->json(['success' => true, 'collection_id' => $id]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Create collection error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function collectionItems(Request $request, int $id): JsonResponse
    {
        try {
            $service = app(\App\Services\FileCollectionService::class);
            $items = $service->getCollectionItems($id);

            return response()->json(['success' => true, 'data' => $items]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Collection items error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function evaluateCollection(int $id): JsonResponse
    {
        try {
            $service = app(\App\Services\FileCollectionService::class);
            $result = $service->evaluateSmartCollection($id);

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Evaluate collection error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function deleteCollection(int $id): JsonResponse
    {
        try {
            $service = app(\App\Services\FileCollectionService::class);
            DB::delete('DELETE FROM file_collection_items WHERE collection_id = ?', [$id]);
            DB::delete('DELETE FROM file_collections WHERE id = ?', [$id]);

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Delete collection error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // VERSIONS
    // =========================================================================

    public function fileVersions(int $fileRegistryId): JsonResponse
    {
        try {
            $service = app(\App\Services\FileVersionService::class);
            $versions = $service->getVersionHistory($fileRegistryId);

            return response()->json(['success' => true, 'data' => $versions]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: File versions error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // SEMANTIC SEARCH
    // =========================================================================

    public function semanticSearch(Request $request): JsonResponse
    {
        try {
            $query = $request->input('query');
            if (! $query) {
                return response()->json(['success' => false, 'error' => 'query required'], 422);
            }

            $service = app(\App\Services\FileSemanticSearchService::class);
            $results = $service->searchFiles($query);

            return response()->json(['success' => true, 'data' => $results]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Semantic search error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function generateDescription(Request $request, int $fileRegistryId): JsonResponse
    {
        try {
            $service = app(\App\Services\FileSemanticSearchService::class);
            $result = $service->generateDescription($fileRegistryId);

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Generate description error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * N55: List contents of an archive file (ZIP, 7z, ISO)
     *
     * GET /api/file-catalog/files/{uuid}/archive
     */
    public function listArchiveContents(string $uuid): JsonResponse
    {
        try {
            $file = DB::selectOne(
                "SELECT id, asset_uuid, current_path, filename, extension, mime_type, file_size
                 FROM file_registry WHERE asset_uuid = ? AND status = 'active'",
                [$uuid]
            );

            if (! $file) {
                return response()->json(['success' => false, 'error' => 'File not found'], 404);
            }

            $ext = strtolower($file->extension ?? '');
            $supportedExts = ['zip', '7z', 'tar', 'gz', 'tgz', 'bz2', 'iso'];

            if (! in_array($ext, $supportedExts)) {
                return response()->json([
                    'success' => false,
                    'error' => "Unsupported archive format: {$ext}. Supported: ".implode(', ', $supportedExts),
                ], 400);
            }

            // Resolve filesystem path
            $nextcloudPath = config('services.nextcloud.data_path');
            $fullPath = $nextcloudPath ? ($nextcloudPath.'/'.ltrim($file->current_path, '/')) : null;

            if (! $fullPath || ! file_exists($fullPath)) {
                return response()->json(['success' => false, 'error' => 'File not found on disk'], 404);
            }

            $entries = [];

            if ($ext === 'zip') {
                $entries = $this->listZipContents($fullPath);
            } elseif (in_array($ext, ['tar', 'gz', 'tgz', 'bz2'])) {
                $entries = $this->listTarContents($fullPath);
            } elseif ($ext === '7z') {
                $entries = $this->list7zContents($fullPath);
            } elseif ($ext === 'iso') {
                $entries = $this->listIsoContents($fullPath);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'uuid' => $uuid,
                    'filename' => $file->filename,
                    'archive_size' => $file->file_size,
                    'entry_count' => count($entries),
                    'entries' => $entries,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('FileCatalog: Archive listing error', ['uuid' => $uuid, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function listZipContents(string $path): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return [];
        }

        $entries = [];
        for ($i = 0; $i < min($zip->numFiles, 1000); $i++) {
            $stat = $zip->statIndex($i);
            if ($stat) {
                $entries[] = [
                    'name' => $stat['name'],
                    'size' => $stat['size'],
                    'compressed' => $stat['comp_size'],
                    'is_dir' => str_ends_with($stat['name'], '/'),
                    'modified' => date('Y-m-d H:i:s', $stat['mtime']),
                ];
            }
        }

        $zip->close();

        return $entries;
    }

    private function listTarContents(string $path): array
    {
        try {
            $result = Process::timeout(30)->run(['tar', 'tf', $path]);
            if (! $result->successful()) {
                return [];
            }

            $output = preg_split('/\r\n|\r|\n/', trim($result->output())) ?: [];
            $output = array_values(array_filter(array_slice($output, 0, 1000)));

            return array_map(fn ($line) => [
                'name' => $line,
                'is_dir' => str_ends_with($line, '/'),
            ], $output);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function list7zContents(string $path): array
    {
        try {
            $result = Process::timeout(30)->run(['7z', 'l', $path]);
            if (! $result->successful()) {
                return [];
            }

            $output = preg_split('/\r\n|\r|\n/', trim($result->output())) ?: [];

            $entries = [];
            $inList = false;

            foreach ($output as $line) {
                if (str_contains($line, '-------------------')) {
                    $inList = ! $inList;

                    continue;
                }
                if ($inList && strlen($line) > 20) {
                    // Parse 7z list output: Date Time Attr Size Compressed Name
                    $name = trim(substr($line, 53));
                    if ($name) {
                        $entries[] = [
                            'name' => $name,
                            'is_dir' => str_contains(substr($line, 20, 5), 'D'),
                        ];
                    }
                }
            }

            return array_slice($entries, 0, 1000);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function listIsoContents(string $path): array
    {
        try {
            $result = Process::timeout(30)->run(['isoinfo', '-l', '-i', $path]);
            if (! $result->successful()) {
                return [];
            }

            $output = preg_split('/\r\n|\r|\n/', trim($result->output())) ?: [];
            $output = array_slice($output, 0, 500);

            $entries = [];
            foreach ($output as $line) {
                if (preg_match('/^\s*\d+\s+\S+\s+\S+\s+\d+\s+.*\s+\[\s*\d+\s+\d+\]\s+(.+)$/', $line, $m)) {
                    $entries[] = ['name' => trim($m[1]), 'is_dir' => false];
                } elseif (preg_match('/^Directory listing of (.+)/', $line, $m)) {
                    $entries[] = ['name' => trim($m[1]), 'is_dir' => true];
                }
            }

            return $entries;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * N56: Inline file preview — serve file content for in-browser viewing.
     *
     * Returns appropriate content type for inline display:
     * - Text/code: raw text with monospace rendering
     * - Images: binary with image content-type
     * - PDF: binary with application/pdf
     * - Video/audio: binary with streaming support
     * - CSV: parsed as JSON array for table rendering
     * - JSON: pretty-printed
     *
     * GET /api/file-catalog/files/{uuid}/preview
     */
    public function previewFile(string $uuid): mixed
    {
        try {
            $file = DB::selectOne(
                "SELECT id, asset_uuid, current_path, filename, extension, mime_type, file_size
                 FROM file_registry WHERE asset_uuid = ? AND status = 'active'",
                [$uuid]
            );

            if (! $file) {
                return response()->json(['success' => false, 'error' => 'File not found'], 404);
            }

            $nextcloudPath = config('services.nextcloud.data_path');
            $fullPath = $nextcloudPath ? ($nextcloudPath.'/'.ltrim($file->current_path, '/')) : null;

            if (! $fullPath || ! file_exists($fullPath)) {
                return response()->json(['success' => false, 'error' => 'File not found on disk'], 404);
            }

            $ext = strtolower($file->extension ?? '');
            $previewType = $this->getPreviewType($ext);

            if (! $previewType) {
                return response()->json([
                    'success' => false,
                    'error' => "No preview available for .{$ext} files",
                    'download_available' => true,
                ], 400);
            }

            // Size guard: don't serve files > 50MB inline
            $maxSize = 50 * 1024 * 1024;
            if ($file->file_size > $maxSize) {
                return response()->json([
                    'success' => false,
                    'error' => 'File too large for inline preview ('.round($file->file_size / 1048576).'MB)',
                    'download_available' => true,
                ], 400);
            }

            switch ($previewType) {
                case 'text':
                    // Text/code files: serve as plain text (capped at 1MB for safety)
                    $content = file_get_contents($fullPath, false, null, 0, 1048576);

                    return response($content, 200, [
                        'Content-Type' => 'text/plain; charset=utf-8',
                        'X-Preview-Type' => 'text',
                        'X-File-Extension' => $ext,
                    ]);

                case 'csv':
                    // CSV: parse and return as JSON for table rendering
                    $rows = [];
                    $handle = fopen($fullPath, 'r');
                    $lineCount = 0;
                    while (($row = fgetcsv($handle)) !== false && $lineCount < 500) {
                        $rows[] = $row;
                        $lineCount++;
                    }
                    fclose($handle);

                    return response()->json([
                        'success' => true,
                        'preview_type' => 'csv',
                        'data' => $rows,
                        'truncated' => $lineCount >= 500,
                    ]);

                case 'json':
                    // JSON: pretty-print
                    $content = file_get_contents($fullPath, false, null, 0, 1048576);
                    $decoded = json_decode($content);

                    return response()->json([
                        'success' => true,
                        'preview_type' => 'json',
                        'data' => $decoded,
                    ]);

                case 'image':
                case 'pdf':
                case 'video':
                case 'audio':
                    // Binary files: serve with correct MIME type for browser rendering
                    $mimeType = $file->mime_type ?: $this->guessMimeType($ext);

                    return response()->file($fullPath, [
                        'Content-Type' => $mimeType,
                        'Content-Disposition' => 'inline; filename="'.$file->filename.'"',
                        'X-Preview-Type' => $previewType,
                    ]);

                default:
                    return response()->json(['success' => false, 'error' => 'Preview not supported'], 400);
            }
        } catch (\Exception $e) {
            Log::error('FileCatalog: Preview error', ['uuid' => $uuid, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Determine preview type from extension.
     */
    private function getPreviewType(string $ext): ?string
    {
        $textExts = array_merge(config('file_types.code', []), config('file_types.text', []), ['log']);
        if (in_array($ext, $textExts)) {
            return 'text';
        }
        if ($ext === 'csv' || $ext === 'tsv') {
            return 'csv';
        }
        if ($ext === 'json') {
            return 'json';
        }
        if (in_array($ext, config('file_types.image', []))) {
            return 'image';
        }
        if ($ext === 'svg') {
            return 'image';
        }
        if ($ext === 'pdf') {
            return 'pdf';
        }
        if (in_array($ext, config('file_types.video', []))) {
            return 'video';
        }
        if (in_array($ext, config('file_types.audio', []))) {
            return 'audio';
        }

        return null;
    }

    private function guessMimeType(string $ext): string
    {
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            'm4a' => 'audio/mp4',
            default => 'application/octet-stream',
        };
    }

    private function nextcloudLibraryRoot(): string
    {
        return '/'.trim((string) config('services.nextcloud.library_root', '/Library'), '/');
    }
}
