<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InternetArchiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Internet Archive API Controller
 *
 * Provides search, browse, and download capabilities for archive.org content.
 * Primary use: genealogy research documents, historical records, reference materials.
 */
class InternetArchiveController extends Controller
{
    private InternetArchiveService $archiveService;

    public function __construct(InternetArchiveService $archiveService)
    {
        $this->archiveService = $archiveService;
    }

    /**
     * Search the Internet Archive.
     * GET /api/internet-archive/search?q=...&rows=20&page=1&mediatype=texts&collection=genealogy
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        if (empty($query)) {
            return response()->json(['success' => false, 'error' => 'Query required'], 400);
        }

        $options = array_filter([
            'rows' => $request->integer('rows', 20),
            'page' => $request->integer('page', 1),
            'sort' => $request->input('sort', ''),
            'mediatype' => $request->input('mediatype', ''),
            'collection' => $request->input('collection', ''),
        ]);

        if ($request->has('year_from') || $request->has('year_to')) {
            $options['year_range'] = [
                $request->input('year_from', '*'),
                $request->input('year_to', '*'),
            ];
        }

        return response()->json($this->archiveService->search($query, $options));
    }

    /**
     * Search genealogy collections specifically.
     * GET /api/internet-archive/genealogy?q=Doe+family&rows=20
     */
    public function searchGenealogy(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        if (empty($query)) {
            return response()->json(['success' => false, 'error' => 'Query required'], 400);
        }

        $options = array_filter([
            'rows' => $request->integer('rows', 20),
            'page' => $request->integer('page', 1),
        ]);

        return response()->json($this->archiveService->searchGenealogy($query, $options));
    }

    /**
     * Search for family history by surname.
     * GET /api/internet-archive/family?surname=Doe&location=Pennsylvania
     */
    public function searchFamily(Request $request): JsonResponse
    {
        $surname = $request->input('surname', '');
        if (empty($surname)) {
            return response()->json(['success' => false, 'error' => 'Surname required'], 400);
        }

        $location = $request->input('location', '');
        $options = array_filter([
            'rows' => $request->integer('rows', 20),
            'page' => $request->integer('page', 1),
        ]);

        return response()->json($this->archiveService->searchFamilyHistory($surname, $location, $options));
    }

    /**
     * Get metadata for an archive.org item.
     * GET /api/internet-archive/item/{identifier}
     */
    public function item(string $identifier): JsonResponse
    {
        return response()->json($this->archiveService->getMetadata($identifier));
    }

    /**
     * Get downloadable files for an item.
     * GET /api/internet-archive/item/{identifier}/files?formats=PDF,Text
     */
    public function files(Request $request, string $identifier): JsonResponse
    {
        $formats = $request->input('formats', '');
        $formatArray = $formats ? explode(',', $formats) : [];

        return response()->json($this->archiveService->getDownloadableFiles($identifier, $formatArray));
    }

    /**
     * Download a file from archive.org.
     * POST /api/internet-archive/download
     * Body: { identifier, filename, family_surname? }
     */
    public function download(Request $request): JsonResponse
    {
        $identifier = $request->input('identifier', '');
        $filename = $request->input('filename', '');

        if (empty($identifier) || empty($filename)) {
            return response()->json(['success' => false, 'error' => 'Identifier and filename required'], 400);
        }

        $familySurname = $request->input('family_surname');

        return response()->json($this->archiveService->downloadFile(
            $identifier,
            $filename,
            null,
            $familySurname
        ));
    }

    /**
     * Download best format for an item.
     * POST /api/internet-archive/download-best
     * Body: { identifier, family_surname? }
     */
    public function downloadBest(Request $request): JsonResponse
    {
        $identifier = $request->input('identifier', '');
        if (empty($identifier)) {
            return response()->json(['success' => false, 'error' => 'Identifier required'], 400);
        }

        return response()->json($this->archiveService->downloadBestFormat(
            $identifier,
            $request->input('family_surname')
        ));
    }

    /**
     * Copy a downloaded file into a genealogy tree in Nextcloud.
     * POST /api/internet-archive/copy-to-tree
     * Body: { local_path, tree_id, subfolder? }
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

        $subfolder = $request->input('subfolder', 'documents');

        return response()->json($this->archiveService->copyToGenealogyTree($localPath, $treeId, $subfolder));
    }
}
