<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UnifiedSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * UnifiedSearchController - API for cross-domain search
 *
 * Provides endpoints for:
 * - Unified search across media and documents
 * - Search suggestions/autocomplete
 * - Faceted counts for filters
 *
 * Routes:
 *   GET  /api/search              - Main search endpoint
 *   GET  /api/search/suggestions  - Autocomplete suggestions
 *   GET  /api/search/facets       - Get facet counts for a query
 */
class UnifiedSearchController extends Controller
{
    public function __construct(
        private UnifiedSearchService $searchService
    ) {}

    /**
     * Main unified search endpoint
     *
     * GET /api/search
     *
     * Query parameters:
     *   q         - Search query (required)
     *   type      - Filter: all, media, documents, photos, videos, notes
     *   limit     - Max results (default 30, max 100)
     *   date_from - Filter by date (YYYY-MM-DD)
     *   date_to   - Filter by date (YYYY-MM-DD)
     *   person_id - Filter by person (media only)
     *   folder    - Filter by folder path (media only)
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim($request->get('q', ''));

        // Allow '*' as browse mode (return all items without text filter)
        $isBrowseMode = ($query === '*');

        if (!$isBrowseMode && strlen($query) < 2) {
            return response()->json([
                'success' => false,
                'error' => 'Query must be at least 2 characters',
                'results' => [],
                'counts' => ['total' => 0, 'media' => 0, 'documents' => 0],
            ], 400);
        }

        $options = [
            'type' => $request->get('type', 'all'),
            'media_subtype' => $request->get('media_subtype'),
            'limit' => (int) $request->get('limit', 30),
            'offset' => (int) $request->get('offset', 0),
            'date_from' => $request->get('date_from'),
            'date_to' => $request->get('date_to'),
            'person_id' => $request->get('person_id'),
            'person_name' => $request->get('person_name'),
            'folder' => $request->get('folder'),
            'notebook' => $request->get('notebook'),
        ];

        $result = $this->searchService->search($query, $options);

        return response()->json($result);
    }

    /**
     * Get search suggestions for autocomplete
     *
     * GET /api/search/suggestions
     *
     * Query parameters:
     *   q     - Query prefix (min 2 chars)
     *   limit - Max suggestions (default 10)
     */
    public function suggestions(Request $request): JsonResponse
    {
        $prefix = trim($request->get('q', ''));
        $limit = min((int) $request->get('limit', 10), 20);

        if (strlen($prefix) < 2) {
            return response()->json([
                'suggestions' => [],
            ]);
        }

        $suggestions = $this->searchService->getSuggestions($prefix, $limit);

        return response()->json([
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * Get faceted counts for a search query
     *
     * GET /api/search/facets
     *
     * Query parameters:
     *   q - Search query
     */
    public function facets(Request $request): JsonResponse
    {
        $query = trim($request->get('q', ''));

        if ($query !== '*' && strlen($query) < 2) {
            return response()->json([
                'facets' => [
                    'types' => [],
                    'years' => [],
                    'people' => [],
                    'folders' => [],
                ],
            ]);
        }

        $facets = $this->searchService->getFacets($query);

        return response()->json([
            'facets' => $facets,
        ]);
    }

    /**
     * Get landing page data (recent files, notes, stats)
     *
     * GET /api/search/landing
     */
    public function landing(): JsonResponse
    {
        $data = $this->searchService->getLandingData();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get search statistics and health
     *
     * GET /api/search/stats
     */
    public function stats(): JsonResponse
    {
        $stats = [
            'total_files' => 0,
            'media_files' => 0,
            'photos_with_dates' => 0,
            'photos_with_faces' => 0,
            'total_faces' => 0,
            'identified_faces' => 0,
            'rag_documents' => 0,
        ];

        // Get total file count (all files in registry)
        try {
            $stats['total_files'] = \DB::selectOne("
                SELECT COUNT(*) as count
                FROM file_registry
                WHERE status = 'active'
            ")->count ?? 0;
        } catch (\Exception $e) {
            Log::debug('Could not get total file count', ['error' => $e->getMessage()]);
        }

        // Get media count (images + videos only)
        try {
            $stats['media_files'] = \DB::selectOne("
                SELECT COUNT(*) as count
                FROM file_registry
                WHERE status = 'active'
                AND extension IN ('jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'tiff', 'mp4', 'mov', 'avi', 'mkv', 'webm')
            ")->count ?? 0;
        } catch (\Exception $e) {
            Log::debug('Could not get media count', ['error' => $e->getMessage()]);
        }

        // Get photos with dates (column may not exist in all environments)
        try {
            $stats['photos_with_dates'] = \DB::selectOne("
                SELECT COUNT(*) as count
                FROM file_registry
                WHERE status = 'active'
                AND date_taken IS NOT NULL
            ")->count ?? 0;
        } catch (\Exception $e) {
            Log::debug('Could not get photos with dates', ['error' => $e->getMessage()]);
        }

        // Get photos with faces
        try {
            $stats['photos_with_faces'] = \DB::selectOne("
                SELECT COUNT(DISTINCT file_registry_id) as count
                FROM file_registry_faces
            ")->count ?? 0;
        } catch (\Exception $e) {
            Log::debug('Could not get photos with faces', ['error' => $e->getMessage()]);
        }

        // Get total faces
        try {
            $stats['total_faces'] = \DB::selectOne("
                SELECT COUNT(*) as count
                FROM file_registry_faces
            ")->count ?? 0;
        } catch (\Exception $e) {
            Log::debug('Could not get total faces', ['error' => $e->getMessage()]);
        }

        // Get identified faces
        try {
            $stats['identified_faces'] = \DB::selectOne("
                SELECT COUNT(*) as count
                FROM file_registry_faces
                WHERE person_name IS NOT NULL OR genealogy_person_id IS NOT NULL
            ")->count ?? 0;
        } catch (\Exception $e) {
            Log::debug('Could not get identified faces', ['error' => $e->getMessage()]);
        }

        // Get RAG doc count
        try {
            $stats['rag_documents'] = \DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(*) as count FROM rag_documents
            ")->count ?? 0;
        } catch (\Exception $e) {
            Log::debug('Could not get RAG doc count', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'stats' => $stats,
        ]);
    }
}
