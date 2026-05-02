<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Genealogy\GenealogySourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NARA (National Archives) API Controller
 *
 * Provides search, browse digital objects, download, and genealogy tree integration
 * for National Archives catalog records (37M+ records: military, census, immigration,
 * court, land patents, presidential documents, photos, maps).
 */
class NaraController extends Controller
{
    private GenealogySourceService $sourceService;

    public function __construct(GenealogySourceService $sourceService)
    {
        $this->sourceService = $sourceService;
    }

    /**
     * Search the NARA catalog.
     * GET /api/nara/search?q=...&limit=20&page=1&record_type=census
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        if (empty($query)) {
            return response()->json(['success' => false, 'error' => 'Query required'], 400);
        }

        $options = array_filter([
            'limit' => $request->integer('limit', 20),
            'page' => $request->integer('page', 1),
            'record_type' => $request->input('record_type', ''),
        ]);

        return response()->json($this->sourceService->searchNARA($query, $options));
    }

    /**
     * Get digital objects (downloadable files) for a NARA record.
     * GET /api/nara/{naId}/objects
     */
    public function objects(string $naId): JsonResponse
    {
        if (empty($naId)) {
            return response()->json(['success' => false, 'error' => 'NARA ID required'], 400);
        }

        return response()->json($this->sourceService->getNaraDigitalObjects($naId));
    }

    /**
     * Download a specific digital object from NARA.
     * POST /api/nara/download
     * Body: { na_id, download_url, filename?, family_surname? }
     */
    public function download(Request $request): JsonResponse
    {
        $naId = $request->input('na_id', '');
        $downloadUrl = $request->input('download_url', '');

        if (empty($naId) || empty($downloadUrl)) {
            return response()->json(['success' => false, 'error' => 'na_id and download_url required'], 400);
        }

        // Validate URL is from NARA domain or NARA's S3 storage
        $host = parse_url($downloadUrl, PHP_URL_HOST);
        $path = parse_url($downloadUrl, PHP_URL_PATH) ?? '';
        $isNaraDomain = $host && str_ends_with($host, '.archives.gov');
        $isNaraS3 = $host && str_contains($host, 'amazonaws.com') && str_contains($path, 'NARAprodstorage');
        if (!$isNaraDomain && !$isNaraS3) {
            return response()->json(['success' => false, 'error' => 'URL must be from archives.gov or NARA S3 storage'], 400);
        }

        return response()->json($this->sourceService->downloadNaraObject(
            $naId,
            $downloadUrl,
            $request->input('filename'),
            $request->input('family_surname')
        ));
    }

    /**
     * Download the best available format for a NARA record.
     * POST /api/nara/download-best
     * Body: { na_id, family_surname? }
     */
    public function downloadBest(Request $request): JsonResponse
    {
        $naId = $request->input('na_id', '');
        if (empty($naId)) {
            return response()->json(['success' => false, 'error' => 'na_id required'], 400);
        }

        return response()->json($this->sourceService->downloadBestNaraObject(
            $naId,
            $request->input('family_surname')
        ));
    }

    /**
     * Copy a downloaded NARA file into a genealogy tree in Nextcloud.
     * POST /api/nara/copy-to-tree
     * Body: { local_path, tree_id, subfolder?, metadata? }
     */
    public function copyToTree(Request $request): JsonResponse
    {
        $localPath = $request->input('local_path', '');
        $treeId = $request->integer('tree_id');

        if (empty($localPath) || !$treeId) {
            return response()->json(['success' => false, 'error' => 'local_path and tree_id required'], 400);
        }

        // Prevent path traversal
        if (str_contains($localPath, '..')) {
            return response()->json(['success' => false, 'error' => 'Invalid path'], 400);
        }

        // Only allow paths under nara/ directory
        if (!str_starts_with($localPath, 'nara/')) {
            return response()->json(['success' => false, 'error' => 'Path must be under nara/ directory'], 400);
        }

        $subfolder = $request->input('subfolder', 'documents');
        $metadata = $request->input('metadata', []);

        return response()->json($this->sourceService->copyNaraToTree(
            $localPath,
            $treeId,
            $subfolder,
            is_array($metadata) ? $metadata : []
        ));
    }
}
