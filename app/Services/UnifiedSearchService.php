<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * UnifiedSearchService - Cross-domain semantic search
 *
 * Provides unified search across:
 * - RAG documents (Joplin notes, YouTube transcripts, documents) in PostgreSQL
 * - Media files (photos, videos) in MySQL file_registry
 *
 * Features:
 * - Hybrid search: semantic vectors + keyword matching
 * - Reciprocal Rank Fusion (RRF) for combining result sets
 * - Content-type filtering with tabs (All, Media, Documents)
 * - Rich metadata previews per content type
 * - Date-based filtering
 * - Person/face filtering for media
 *
 * Research sources:
 * - https://superlinked.com/vectorhub/articles/optimizing-rag-with-hybrid-search-reranking
 * - https://machinelearningplus.com/gen-ai/hybrid-search-vector-keyword-techniques-for-better-rag/
 * - https://www.designmonks.co/blog/search-ux-best-practices
 */
class UnifiedSearchService
{
    private RAGService $ragService;

    private AIService $aiService;

    // RRF constant - higher values reduce impact of ranking position
    private const RRF_K = 60;

    // Alpha for hybrid weighting (0 = keyword only, 1 = semantic only)
    private const DEFAULT_ALPHA = 0.6;

    // Content type constants
    public const TYPE_ALL = 'all';

    public const TYPE_MEDIA = 'media';

    public const TYPE_DOCUMENTS = 'documents';

    public const TYPE_PHOTOS = 'photos';

    public const TYPE_VIDEOS = 'videos';

    public const TYPE_NOTES = 'notes';

    public const TYPE_FILES = 'files';  // All files in registry (not just media)

    public const TYPE_TRANSCRIPTS = 'transcripts';

    public const TYPE_RESEARCH = 'research';

    public const TYPE_CHAT = 'chat';

    public function __construct(RAGService $ragService, AIService $aiService)
    {
        $this->ragService = $ragService;
        $this->aiService = $aiService;
    }

    /**
     * Unified search across all content types
     *
     * @param  string  $query  Natural language search query
     * @param  array  $options  Search options:
     *                          - type: 'all', 'media', 'documents', 'photos', 'videos', 'notes'
     *                          - limit: Max results (default 30)
     *                          - date_from: Filter by date (YYYY-MM-DD)
     *                          - date_to: Filter by date (YYYY-MM-DD)
     *                          - person_id: Filter by genealogy person (media only)
     *                          - folder: Filter by folder path (media only)
     *                          - alpha: Hybrid search weight (0-1, default 0.6)
     * @return array Search results with metadata
     */
    public function search(string $query, array $options = []): array
    {
        $startTime = microtime(true);

        $type = $options['type'] ?? self::TYPE_ALL;
        $limit = min((int) ($options['limit'] ?? 30), 100);
        $offset = max(0, (int) ($options['offset'] ?? 0));
        $alpha = (float) ($options['alpha'] ?? self::DEFAULT_ALPHA);
        $dateFrom = $options['date_from'] ?? null;
        $dateTo = $options['date_to'] ?? null;
        $personId = $options['person_id'] ?? null;
        $personName = $options['person_name'] ?? null;
        $folder = $options['folder'] ?? null;
        $notebook = $options['notebook'] ?? null;
        $mediaSubtype = $options['media_subtype'] ?? null;
        $isBrowseMode = ($query === '*');

        Log::info('UnifiedSearch: Starting search', [
            'query' => substr($query, 0, 100),
            'type' => $type,
            'limit' => $limit,
        ]);

        $results = [];
        $counts = ['total' => 0, 'media' => 0, 'documents' => 0];

        try {
            // Determine which sources to search
            $searchMedia = in_array($type, [self::TYPE_ALL, self::TYPE_MEDIA, self::TYPE_PHOTOS, self::TYPE_VIDEOS, self::TYPE_FILES]);
            $searchDocs = in_array($type, [self::TYPE_ALL, self::TYPE_DOCUMENTS, self::TYPE_NOTES, self::TYPE_TRANSCRIPTS, self::TYPE_RESEARCH, self::TYPE_CHAT]);

            // Domain-specific filters suppress irrelevant sources:
            // Folder filter only applies to file_registry → skip documents
            // Notebook filter only applies to rag_documents → skip media
            if ($folder) {
                $searchDocs = false;
            }
            if ($notebook) {
                $searchMedia = false;
            }

            // Collect results from each source
            $allResults = [];

            if ($searchMedia) {
                // Determine file type filter: 'image', 'video', 'all' (any extension), or null (media only)
                $fileTypeFilter = match ($type) {
                    self::TYPE_PHOTOS => 'image',
                    self::TYPE_VIDEOS => 'video',
                    self::TYPE_FILES, self::TYPE_ALL => 'all',  // Include all file types
                    default => null,  // Media only (image + video)
                };

                // media_subtype overrides the general file type filter for facet type narrowing
                $effectiveFileType = $mediaSubtype
                    ? match ($mediaSubtype) {
                        'photo' => 'image',
                        'video' => 'video',
                        'audio' => 'audio',
                        'document', 'spreadsheet', 'presentation', 'code', 'archive', 'ebook' => $mediaSubtype,
                        default => $fileTypeFilter,
                    }
                : $fileTypeFilter;

                $mediaResults = $this->searchMedia($query, $limit * 2, [
                    'type' => $effectiveFileType,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'person_id' => $personId,
                    'person_name' => $personName,
                    'folder' => $folder,
                    'browse_mode' => $isBrowseMode,
                    'offset' => $offset,
                ]);
                foreach ($mediaResults as $rank => $result) {
                    $result['_source'] = 'media';
                    $result['_rank'] = $rank;
                    $allResults[] = $result;
                }
                $counts['media'] = count($mediaResults);

                // Semantic RAG search for files (skip in browse mode)
                if (! $isBrowseMode) {
                    $semanticResults = $this->searchMediaSemantic($query, $limit);
                    foreach ($semanticResults as $rank => $result) {
                        $result['_source'] = 'media_semantic';
                        $result['_rank'] = $rank;
                        $allResults[] = $result;
                    }
                }
            }

            if ($searchDocs) {
                $docTypeFilter = match ($type) {
                    self::TYPE_NOTES => 'joplin_note',
                    self::TYPE_TRANSCRIPTS => 'youtube_video',
                    self::TYPE_RESEARCH => 'research',
                    self::TYPE_CHAT => 'chat',
                    default => null,
                };
                $docResults = $this->searchDocuments($query, $limit * 2, [
                    'type' => $docTypeFilter,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'browse_mode' => $isBrowseMode,
                    'offset' => $offset,
                    'notebook' => $notebook,
                ]);
                foreach ($docResults as $rank => $result) {
                    $result['_source'] = 'documents';
                    $result['_rank'] = $rank;
                    $allResults[] = $result;
                }
                $counts['documents'] = count($docResults);
            }

            // Apply Reciprocal Rank Fusion if searching multiple sources
            if ($searchMedia && $searchDocs) {
                $results = $this->applyRRF($allResults, $limit);
            } else {
                // Single source - just take top results
                $results = array_slice($allResults, 0, $limit);
            }

            $counts['total'] = count($results);

            $elapsed = round((microtime(true) - $startTime) * 1000);

            Log::info('UnifiedSearch: Completed', [
                'query' => substr($query, 0, 50),
                'results' => $counts['total'],
                'elapsed_ms' => $elapsed,
            ]);

            return [
                'success' => true,
                'results' => $results,
                'counts' => $counts,
                'query' => $query,
                'elapsed_ms' => $elapsed,
                'options' => [
                    'type' => $type,
                    'limit' => $limit,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('UnifiedSearch: Failed', [
                'error' => $e->getMessage(),
                'query' => substr($query, 0, 100),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'results' => [],
                'counts' => $counts,
            ];
        }
    }

    /**
     * Search media files (photos, videos) in file_registry
     */
    private function searchMedia(string $query, int $limit, array $filters = []): array
    {
        $results = [];
        $params = [];
        $browseMode = ! empty($filters['browse_mode']);
        $offset = (int) ($filters['offset'] ?? 0);

        // Check if date_taken columns exist (they may not in all environments)
        $hasDateTaken = $this->hasColumn('file_registry', 'date_taken');

        // Build dynamic column list
        $selectCols = '
                fr.id,
                fr.asset_uuid,
                fr.filename,
                fr.current_path,
                fr.extension,
                fr.mime_type,
                fr.file_size,
                fr.nextcloud_modified_at,
                fr.title,
                fr.tags';

        if ($hasDateTaken) {
            $selectCols .= ',
                fr.date_taken,
                fr.date_taken_source,
                fr.date_taken_confidence,
                fr.date_taken_reasoning';
        }

        $selectCols .= ",
                fr.ai_description,
                (SELECT COUNT(*) FROM file_registry_faces frf WHERE frf.file_registry_id = fr.id) as face_count,
                (SELECT GROUP_CONCAT(DISTINCT frf.person_name SEPARATOR ', ')
                 FROM file_registry_faces frf
                 WHERE frf.file_registry_id = fr.id AND frf.person_name IS NOT NULL) as people";

        // Build base query with search
        $sql = "SELECT {$selectCols} FROM file_registry fr WHERE fr.status = 'active'";

        // Type filter
        if (! empty($filters['type'])) {
            if ($filters['type'] === 'image') {
                $sql .= " AND fr.extension IN ('jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp', 'heic', 'tiff', 'bmp', 'jp2', 'j2k', 'jpf', 'jpx')";
            } elseif ($filters['type'] === 'video') {
                $sql .= " AND fr.extension IN ('mp4', 'mov', 'avi', 'mkv', 'webm', 'wmv', 'flv', 'm4v')";
            } elseif ($filters['type'] === 'audio') {
                $sql .= " AND fr.extension IN ('mp3', 'wav', 'flac', 'aac', 'ogg', 'wma', 'm4a', 'opus', 'aiff')";
            } elseif ($filters['type'] === 'document') {
                $sql .= " AND fr.extension IN ('pdf', 'doc', 'docx', 'odt', 'rtf', 'txt')";
            } elseif ($filters['type'] === 'spreadsheet') {
                $sql .= " AND fr.extension IN ('xls', 'xlsx', 'ods', 'csv')";
            } elseif ($filters['type'] === 'presentation') {
                $sql .= " AND fr.extension IN ('ppt', 'pptx', 'odp')";
            } elseif ($filters['type'] === 'archive') {
                $sql .= " AND fr.extension IN ('zip', 'tar', 'gz', 'rar', '7z', 'bz2')";
            } elseif ($filters['type'] === 'code') {
                $sql .= " AND fr.extension IN ('php', 'js', 'ts', 'py', 'java', 'css', 'html', 'json', 'xml', 'yaml', 'yml', 'sh', 'sql', 'vue')";
            } elseif ($filters['type'] === 'ebook') {
                $sql .= " AND fr.extension IN ('epub', 'mobi', 'azw3')";
            } elseif ($filters['type'] === 'all') {
                // No extension filter - include all files in registry
            }
        } else {
            // Default: images and videos only (media)
            $sql .= " AND fr.extension IN ('jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp', 'heic', 'tiff', 'bmp', 'jp2', 'j2k', 'jpf', 'jpx', 'mp4', 'mov', 'avi', 'mkv', 'webm')";
        }

        // Text search - skip in browse mode (return all items)
        if (! $browseMode) {
            $searchTerms = '%'.$query.'%';
            // Note: current_path excluded from text search — matching folder names
            // (e.g. a person name in a path) produces massive false positives.
            // Folder filtering is handled via the folder browser sidebar.
            $searchConditions = 'fr.filename LIKE ? OR fr.title LIKE ? OR fr.tags LIKE ?';
            $params = [$searchTerms, $searchTerms, $searchTerms];

            if ($hasDateTaken) {
                $searchConditions .= ' OR fr.date_taken_reasoning LIKE ?';
                $params[] = $searchTerms;
            }

            // FULLTEXT search on AI descriptions and OCR-extracted text
            $searchConditions .= ' OR MATCH(fr.ai_description, fr.ai_detected_text) AGAINST(? IN BOOLEAN MODE)';
            $params[] = $query; // raw query, not LIKE-wrapped — BOOLEAN MODE handles tokenization

            // Also search person names from face tags
            $searchConditions .= ' OR EXISTS (SELECT 1 FROM file_registry_faces frf WHERE frf.file_registry_id = fr.id AND frf.person_name LIKE ?)';
            $params[] = $searchTerms;

            // Also search genealogy person links (person name → person_media → media → file_registry)
            // Exact path match via EXISTS (uses current_path index)
            $searchConditions .= " OR EXISTS (
                SELECT 1 FROM genealogy_person_media gpm
                JOIN genealogy_media gm ON gpm.media_id = gm.id
                JOIN genealogy_persons gp ON gpm.person_id = gp.id
                WHERE gm.nextcloud_path = fr.current_path
                AND (CONCAT(gp.given_name, ' ', gp.surname) LIKE ? OR gp.given_name LIKE ? OR gp.surname LIKE ?)
            )";
            $params[] = $searchTerms;
            $params[] = $searchTerms;
            $params[] = $searchTerms;

            // Fuzzy filename fallback: pre-fetch filenames from genealogy person links
            // for files copied to family tree folder with different paths
            $personFilenames = DB::select("
                SELECT DISTINCT SUBSTRING_INDEX(gm.nextcloud_path, '/', -1) as filename
                FROM genealogy_person_media gpm
                JOIN genealogy_media gm ON gpm.media_id = gm.id
                JOIN genealogy_persons gp ON gpm.person_id = gp.id
                WHERE CONCAT(gp.given_name, ' ', gp.surname) LIKE ? OR gp.given_name LIKE ? OR gp.surname LIKE ?
            ", [$searchTerms, $searchTerms, $searchTerms]);

            if (! empty($personFilenames)) {
                $filenames = array_column(array_map(fn ($r) => (array) $r, $personFilenames), 'filename');
                $placeholders = implode(',', array_fill(0, count($filenames), '?'));
                $searchConditions .= " OR fr.filename IN ({$placeholders})";
                $params = array_merge($params, $filenames);
            }

            $sql .= " AND ({$searchConditions})";
        }

        // Date filters - use date_taken if available, fallback to nextcloud_modified_at
        $dateCol = $hasDateTaken ? 'COALESCE(fr.date_taken, fr.nextcloud_modified_at)' : 'fr.nextcloud_modified_at';
        if (! empty($filters['date_from'])) {
            $sql .= " AND {$dateCol} >= ?";
            $params[] = $filters['date_from'];
        }
        if (! empty($filters['date_to'])) {
            $sql .= " AND {$dateCol} <= ?";
            $params[] = $filters['date_to'].' 23:59:59';
        }

        // Person filter (by ID or name)
        if (! empty($filters['person_id'])) {
            $sql .= ' AND EXISTS (SELECT 1 FROM file_registry_faces frf WHERE frf.file_registry_id = fr.id AND frf.genealogy_person_id = ?)';
            $params[] = $filters['person_id'];
        } elseif (! empty($filters['person_name'])) {
            $sql .= ' AND EXISTS (SELECT 1 FROM file_registry_faces frf WHERE frf.file_registry_id = fr.id AND frf.person_name = ?)';
            $params[] = $filters['person_name'];
        }

        // Folder filter — paths in file_registry have leading slash
        if (! empty($filters['folder'])) {
            $folder = $filters['folder'];
            // Ensure leading slash to match file_registry paths
            if (! str_starts_with($folder, '/')) {
                $folder = '/'.$folder;
            }
            $sql .= ' AND fr.current_path LIKE ?';
            $params[] = $folder.'%';
        }

        // Order by date with offset pagination
        $sql .= " ORDER BY {$dateCol} DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $rows = DB::select($sql, $params);

        foreach ($rows as $row) {
            $results[] = [
                'id' => 'media_'.$row->id,
                'type' => $this->getMediaType($row->extension),
                'content_type' => 'media',
                'title' => $row->title ?: $row->filename,
                'filename' => $row->filename,
                'path' => $row->current_path,
                'asset_uuid' => $row->asset_uuid,
                'thumbnail_url' => '/api/media/'.$row->asset_uuid.'/thumbnail/medium',
                'preview_url' => '/api/media/'.$row->asset_uuid,
                'date' => $hasDateTaken ? ($row->date_taken ?: $row->nextcloud_modified_at) : $row->nextcloud_modified_at,
                'date_source' => $hasDateTaken ? ($row->date_taken_source ?? null) : null,
                'date_confidence' => $hasDateTaken ? ($row->date_taken_confidence ?? null) : null,
                'date_reasoning' => $hasDateTaken ? ($row->date_taken_reasoning ?? null) : null,
                'file_size' => $row->file_size,
                'extension' => $row->extension,
                'mime_type' => $row->mime_type,
                'face_count' => (int) $row->face_count,
                'people' => $row->people,
                'tags' => $row->tags,
                'description' => $row->ai_description,
                'metadata' => [
                    'faces' => (int) $row->face_count,
                    'people' => $row->people ? explode(', ', $row->people) : [],
                    'ai_description' => $row->ai_description,
                ],
            ];
        }

        return $results;
    }

    /**
     * Semantic RAG search for files — complements keyword searchMedia().
     * Returns results in the same format as searchMedia() for RRF merging.
     */
    private function searchMediaSemantic(string $query, int $limit): array
    {
        try {
            $ragService = app(FileCategorizationRAGService::class);
            $result = $ragService->searchFiles($query, $limit);

            if (! $result['success'] || empty($result['files'])) {
                return [];
            }

            // Hydrate RAG results with file_registry data for consistent format
            $results = [];
            foreach ($result['files'] as $ragFile) {
                if (empty($ragFile['asset_uuid'])) {
                    continue;
                }

                $file = DB::selectOne("
                    SELECT fr.id, fr.asset_uuid, fr.filename, fr.current_path, fr.extension,
                           fr.mime_type, fr.file_size, fr.nextcloud_modified_at, fr.title,
                           fr.tags, fr.ai_description, fr.date_taken, fr.date_taken_source,
                           fr.date_taken_confidence, fr.date_taken_reasoning,
                           (SELECT COUNT(*) FROM file_registry_faces frf WHERE frf.file_registry_id = fr.id) as face_count,
                           (SELECT GROUP_CONCAT(DISTINCT frf.person_name SEPARATOR ', ')
                            FROM file_registry_faces frf
                            WHERE frf.file_registry_id = fr.id AND frf.person_name IS NOT NULL) as people
                    FROM file_registry fr
                    WHERE fr.asset_uuid = ? AND fr.status = 'active'
                ", [$ragFile['asset_uuid']]);

                if (! $file) {
                    continue;
                }

                $results[] = [
                    'id' => 'media_'.$file->id,
                    'type' => $this->getMediaType($file->extension),
                    'content_type' => 'media',
                    'title' => $file->title ?: $file->filename,
                    'filename' => $file->filename,
                    'path' => $file->current_path,
                    'asset_uuid' => $file->asset_uuid,
                    'thumbnail_url' => '/api/media/'.$file->asset_uuid.'/thumbnail/medium',
                    'preview_url' => '/api/media/'.$file->asset_uuid,
                    'date' => $file->date_taken ?: $file->nextcloud_modified_at,
                    'date_source' => $file->date_taken_source ?? null,
                    'date_confidence' => $file->date_taken_confidence ?? null,
                    'date_reasoning' => $file->date_taken_reasoning ?? null,
                    'file_size' => $file->file_size,
                    'extension' => $file->extension,
                    'mime_type' => $file->mime_type,
                    'face_count' => (int) $file->face_count,
                    'people' => $file->people,
                    'tags' => $file->tags,
                    'description' => $file->ai_description,
                    'similarity' => $ragFile['similarity'] ?? null,
                    'metadata' => [
                        'faces' => (int) $file->face_count,
                        'people' => $file->people ? explode(', ', $file->people) : [],
                        'ai_description' => $file->ai_description,
                        'semantic_match' => true,
                    ],
                ];
            }

            return $results;
        } catch (\Exception $e) {
            Log::debug('UnifiedSearch: semantic media search failed', ['error' => $e->getMessage()]);

            return []; // Graceful degradation — keyword search still works
        }
    }

    /**
     * Check if a column exists in a table (cached)
     */
    private function hasColumn(string $table, string $column): bool
    {
        $cacheKey = "schema_has_column:{$table}:{$column}";

        return Cache::remember($cacheKey, 3600, function () use ($table, $column) {
            try {
                $result = DB::select("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column]);

                return ! empty($result);
            } catch (\Exception $e) {
                Log::debug('UnifiedSearchService: schema column check failed', ['table' => $table, 'column' => $column, 'error' => $e->getMessage()]);

                return false;
            }
        });
    }

    /**
     * Search RAG documents (Joplin notes, YouTube transcripts, etc.)
     */
    private function searchDocuments(string $query, int $limit, array $filters = []): array
    {
        $results = [];
        $browseMode = ! empty($filters['browse_mode']);
        $offset = (int) ($filters['offset'] ?? 0);

        // Resolve notebook filter to note IDs (cross-DB: MySQL → PostgreSQL)
        $notebookNoteIds = null;
        if (! empty($filters['notebook'])) {
            $notebookNoteIds = $this->getNotebookNoteIds($filters['notebook']);
            if (empty($notebookNoteIds)) {
                return []; // Notebook has no notes
            }
        }

        try {
            // In browse mode, fetch documents directly from DB instead of semantic search
            if ($browseMode) {
                return $this->browseDocuments($limit, $offset, $filters, $notebookNoteIds);
            }

            // Use RAGService hybrid search
            $ragResults = $this->ragService->hybridSearch($query, $limit, $filters['type'] ?? null);

            foreach ($ragResults as $item) {
                $doc = $item['document'];
                $similarity = $item['similarity'] ?? 0;

                // Skip internal/system document types — file_catalog served by searchMedia(),
                // others are agent/system records not user-facing documents
                $internalTypes = ['file_catalog', 'joplin_attachment', 'genealogy_research', 'genealogy_person', 'agent_finding', 'calendar_event', 'contact', 'chat_response', 'test', 'research_fact', 'workflow_output'];
                if (in_array($doc->document_type ?? '', $internalTypes, true)) {
                    continue;
                }

                // Notebook filter: skip docs not from the selected notebook
                if ($notebookNoteIds !== null) {
                    if (($doc->source_type ?? '') !== 'joplin' || ! in_array($doc->source_id ?? '', $notebookNoteIds, true)) {
                        continue;
                    }
                }

                // Extract date from metadata if available
                $metadata = is_string($doc->metadata) ? json_decode($doc->metadata, true) : ($doc->metadata ?? []);
                $date = $metadata['created_at'] ?? $metadata['date'] ?? $doc->created_at ?? null;

                // Apply date filters
                if (! empty($filters['date_from']) && $date && $date < $filters['date_from']) {
                    continue;
                }
                if (! empty($filters['date_to']) && $date && $date > $filters['date_to'].' 23:59:59') {
                    continue;
                }

                // Generate snippet
                $snippet = $this->generateSnippet($doc->content ?? '', $query, 200);

                $results[] = [
                    'id' => 'doc_'.$doc->id,
                    'type' => $this->mapDocumentType($doc->document_type ?? 'document'),
                    'content_type' => 'document',
                    'title' => $doc->title ?? 'Untitled',
                    'snippet' => $snippet,
                    'source_type' => $doc->source_type ?? null,
                    'source_id' => $doc->source_id ?? null,
                    'date' => $date,
                    'similarity' => $similarity,
                    'metadata' => $metadata,
                    'asset_uuid' => $metadata['asset_uuid'] ?? null,
                    'thumbnail_url' => ! empty($metadata['asset_uuid'])
                        ? '/api/media/'.$metadata['asset_uuid'].'/thumbnail/medium'
                        : null,
                    'preview_url' => $this->getDocumentPreviewUrl($doc),
                ];
            }
        } catch (\Exception $e) {
            Log::warning('UnifiedSearch: RAG search failed, continuing with media only', [
                'error' => $e->getMessage(),
            ]);
        }

        return $results;
    }

    /**
     * Browse documents without semantic search (for browse/wildcard mode)
     */
    private function browseDocuments(int $limit, int $offset, array $filters = [], ?array $notebookNoteIds = null): array
    {
        $results = [];

        try {
            $params = [];
            $sql = <<<'SQL'
SELECT id, title, document_type, source_type, source_id, created_at, updated_at,
       metadata, LEFT(content, 300) as snippet
FROM rag_documents
WHERE document_type NOT IN ('file_catalog', 'joplin_attachment', 'genealogy_research', 'genealogy_person', 'agent_finding', 'calendar_event', 'contact', 'chat_response', 'test', 'research_fact', 'workflow_output')
SQL;

            // Document type filter
            $docType = $filters['type'] ?? null;
            if ($docType) {
                $sql .= ' AND document_type = ?';
                $params[] = $docType;
            }

            // Notebook filter: restrict to notes from selected notebook
            if ($notebookNoteIds !== null) {
                $placeholders = implode(',', array_fill(0, count($notebookNoteIds), '?'));
                $sql .= " AND source_type = 'joplin' AND source_id IN ({$placeholders})";
                array_push($params, ...$notebookNoteIds);
            }

            // Date filters
            if (! empty($filters['date_from'])) {
                $sql .= ' AND COALESCE(updated_at, created_at) >= ?';
                $params[] = $filters['date_from'];
            }
            if (! empty($filters['date_to'])) {
                $sql .= ' AND COALESCE(updated_at, created_at) <= ?';
                $params[] = $filters['date_to'].' 23:59:59';
            }

            $sql .= ' ORDER BY COALESCE(updated_at, created_at) DESC NULLS LAST LIMIT ? OFFSET ?';
            $params[] = $limit;
            $params[] = $offset;

            $rows = DB::connection('pgsql_rag')->select($sql, $params);

            foreach ($rows as $doc) {
                $metadata = is_string($doc->metadata) ? json_decode($doc->metadata, true) : ($doc->metadata ?? []);
                $date = $metadata['created_at'] ?? $metadata['date'] ?? $doc->updated_at ?? $doc->created_at ?? null;

                $results[] = [
                    'id' => 'doc_'.$doc->id,
                    'type' => $this->mapDocumentType($doc->document_type ?? 'document'),
                    'content_type' => 'document',
                    'title' => $doc->title ?? 'Untitled',
                    'snippet' => trim($doc->snippet ?? ''),
                    'source_type' => $doc->source_type ?? null,
                    'source_id' => $doc->source_id ?? null,
                    'date' => $date,
                    'similarity' => null,
                    'metadata' => $metadata,
                    'asset_uuid' => $metadata['asset_uuid'] ?? null,
                    'thumbnail_url' => ! empty($metadata['asset_uuid'])
                        ? '/api/media/'.$metadata['asset_uuid'].'/thumbnail/medium'
                        : null,
                    'preview_url' => $this->getDocumentPreviewUrl($doc),
                ];
            }
        } catch (\Exception $e) {
            Log::warning('UnifiedSearch: Browse documents failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $results;
    }

    /**
     * Apply Reciprocal Rank Fusion to combine result sets
     *
     * RRF Score = sum(1 / (k + rank)) across all result sets
     * This gives a smooth combination that doesn't overly favor top results
     */
    private function applyRRF(array $allResults, int $limit): array
    {
        $scores = [];
        $items = [];

        foreach ($allResults as $result) {
            $id = $result['id'];
            $rank = $result['_rank'];

            // Calculate RRF contribution from this ranking
            $rrfScore = 1 / (self::RRF_K + $rank + 1);

            if (! isset($scores[$id])) {
                $scores[$id] = 0;
                $items[$id] = $result;
            }
            $scores[$id] += $rrfScore;
        }

        // Sort by RRF score descending
        arsort($scores);

        // Build final results array
        $results = [];
        $count = 0;
        foreach ($scores as $id => $score) {
            if ($count >= $limit) {
                break;
            }

            $item = $items[$id];
            $item['rrf_score'] = $score;
            unset($item['_source'], $item['_rank']);
            $results[] = $item;
            $count++;
        }

        return $results;
    }

    /**
     * Get file type from extension
     */
    private function getMediaType(string $extension): string
    {
        $ext = strtolower($extension);

        // Images
        if (in_array($ext, ['jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp', 'heic', 'tiff', 'bmp', 'jp2', 'j2k', 'jpf', 'jpx', 'svg', 'ico', 'raw', 'cr2', 'nef', 'arw'])) {
            return 'photo';
        }
        // Videos
        if (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'flv', 'm4v', 'wmv', 'mpg', 'mpeg', '3gp'])) {
            return 'video';
        }
        // Audio
        if (in_array($ext, ['mp3', 'wav', 'flac', 'm4a', 'ogg', 'aac', 'wma', 'aiff'])) {
            return 'audio';
        }
        // Documents
        if (in_array($ext, ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'pages'])) {
            return 'document';
        }
        // Spreadsheets
        if (in_array($ext, ['xls', 'xlsx', 'ods', 'csv', 'numbers'])) {
            return 'spreadsheet';
        }
        // Presentations
        if (in_array($ext, ['ppt', 'pptx', 'odp', 'key'])) {
            return 'presentation';
        }
        // Archives
        if (in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'dmg', 'iso'])) {
            return 'archive';
        }
        // Code
        if (in_array($ext, ['php', 'js', 'ts', 'jsx', 'tsx', 'py', 'rb', 'java', 'c', 'cpp', 'h', 'cs', 'go', 'rs', 'swift', 'kt', 'vue', 'html', 'css', 'scss', 'less', 'json', 'xml', 'yaml', 'yml', 'md', 'sql', 'sh', 'bash', 'zsh'])) {
            return 'code';
        }
        // Ebooks
        if (in_array($ext, ['epub', 'mobi', 'azw', 'azw3', 'fb2'])) {
            return 'ebook';
        }
        // Fonts
        if (in_array($ext, ['ttf', 'otf', 'woff', 'woff2', 'eot'])) {
            return 'font';
        }
        // 3D/CAD
        if (in_array($ext, ['obj', 'stl', 'fbx', 'blend', 'dwg', 'dxf', 'skp'])) {
            return '3d';
        }

        return 'file';
    }

    /**
     * Map RAG document type to display type
     */
    private function mapDocumentType(?string $docType): string
    {
        $map = [
            'joplin' => 'note',
            'joplin_note' => 'note',
            'youtube_transcript' => 'transcript',
            'youtube' => 'transcript',
            'email' => 'email',
            'document' => 'document',
            'pdf' => 'document',
            'web' => 'webpage',
        ];

        return $map[strtolower($docType ?? '')] ?? 'document';
    }

    /**
     * Generate a search-relevant snippet from content
     */
    private function generateSnippet(string $content, string $query, int $maxLength = 200): string
    {
        // Try to find query terms in content
        $queryTerms = preg_split('/\s+/', strtolower($query));
        $lowerContent = strtolower($content);

        $bestPos = 0;
        $bestScore = 0;

        // Find position with most query term matches
        foreach ($queryTerms as $term) {
            if (strlen($term) < 3) {
                continue;
            }

            $pos = strpos($lowerContent, $term);
            if ($pos !== false) {
                // Count matches near this position
                $window = substr($lowerContent, max(0, $pos - 100), 300);
                $score = 0;
                foreach ($queryTerms as $t) {
                    if (strlen($t) >= 3 && strpos($window, $t) !== false) {
                        $score++;
                    }
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestPos = max(0, $pos - 50);
                }
            }
        }

        // Extract snippet
        $snippet = substr($content, $bestPos, $maxLength);

        // Clean up - start at word boundary
        if ($bestPos > 0) {
            $firstSpace = strpos($snippet, ' ');
            if ($firstSpace !== false && $firstSpace < 30) {
                $snippet = '...'.substr($snippet, $firstSpace + 1);
            }
        }

        // End at word boundary
        $lastSpace = strrpos($snippet, ' ');
        if ($lastSpace !== false && $lastSpace > $maxLength - 30) {
            $snippet = substr($snippet, 0, $lastSpace).'...';
        } elseif (strlen($content) > $bestPos + $maxLength) {
            $snippet .= '...';
        }

        return trim($snippet);
    }

    /**
     * Get preview URL for a document
     */
    private function getDocumentPreviewUrl(object $doc): ?string
    {
        $sourceType = $doc->source_type ?? null;
        $sourceId = $doc->source_id ?? null;

        if ($sourceType === 'joplin' && $sourceId) {
            return '/api/joplin/notes/'.$sourceId;
        }

        if ($sourceType === 'youtube' && $sourceId) {
            return 'https://youtube.com/watch?v='.$sourceId;
        }

        return null;
    }

    /**
     * Get note IDs belonging to a notebook (including child notebooks)
     * Cross-DB helper: queries MySQL joplin_metadata_cache for note IDs
     */
    private function getNotebookNoteIds(string $notebookId): array
    {
        // Recursively collect this notebook and ALL descendant notebook IDs
        $notebookIds = [$notebookId];
        $queue = [$notebookId];

        while (! empty($queue)) {
            $currentId = array_shift($queue);
            $childNotebooks = DB::select(
                'SELECT id FROM joplin_metadata_cache WHERE type = 2 AND is_deleted = 0 AND parent_id = ?',
                [$currentId]
            );
            foreach ($childNotebooks as $child) {
                $notebookIds[] = $child->id;
                $queue[] = $child->id;
            }
        }

        // Get all note IDs in these notebooks
        $placeholders = implode(',', array_fill(0, count($notebookIds), '?'));
        $notes = DB::select(
            "SELECT id FROM joplin_metadata_cache WHERE type = 1 AND is_deleted = 0 AND parent_id IN ({$placeholders})",
            $notebookIds
        );

        return array_map(fn ($n) => $n->id, $notes);
    }

    /**
     * Get search suggestions based on query prefix
     */
    public function getSuggestions(string $prefix, int $limit = 10): array
    {
        $suggestions = [];
        $prefix = trim($prefix);

        if (strlen($prefix) < 2) {
            return $suggestions;
        }

        // Cache key for suggestions
        $cacheKey = 'unified_search_suggestions:'.md5($prefix);

        return Cache::remember($cacheKey, 300, function () use ($prefix, $limit) {
            $suggestions = [];

            // Get filename suggestions from media
            $mediaFiles = DB::select("
                SELECT DISTINCT filename
                FROM file_registry
                WHERE status = 'active'
                AND filename LIKE ?
                LIMIT ?
            ", [$prefix.'%', $limit]);

            foreach ($mediaFiles as $file) {
                $suggestions[] = [
                    'text' => $file->filename,
                    'type' => 'filename',
                ];
            }

            // Get folder suggestions
            $folders = DB::select("
                SELECT DISTINCT
                    SUBSTRING_INDEX(current_path, '/', 3) as folder
                FROM file_registry
                WHERE status = 'active'
                AND current_path LIKE ?
                GROUP BY folder
                LIMIT ?
            ", ['%'.$prefix.'%', $limit]);

            foreach ($folders as $folder) {
                $suggestions[] = [
                    'text' => $folder->folder,
                    'type' => 'folder',
                ];
            }

            // Get person name suggestions
            $people = DB::select('
                SELECT DISTINCT person_name
                FROM file_registry_faces
                WHERE person_name IS NOT NULL
                AND person_name LIKE ?
                LIMIT ?
            ', [$prefix.'%', $limit]);

            foreach ($people as $person) {
                $suggestions[] = [
                    'text' => $person->person_name,
                    'type' => 'person',
                ];
            }

            // Sort and dedupe
            $seen = [];
            $unique = [];
            foreach ($suggestions as $s) {
                $key = strtolower($s['text']);
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $unique[] = $s;
                }
            }

            return array_slice($unique, 0, $limit);
        });
    }

    /**
     * Get faceted counts for search results
     */
    public function getFacets(string $query): array
    {
        $facets = [
            'types' => [],
            'years' => [],
            'people' => [],
            'folders' => [],
        ];

        $hasDateTaken = $this->hasColumn('file_registry', 'date_taken');
        $isBrowseMode = ($query === '*');
        $searchTerm = '%'.$query.'%';

        // Get type counts
        try {
            $searchFilter = $isBrowseMode ? '' : 'AND (filename LIKE ? OR current_path LIKE ?)';
            $searchParams = $isBrowseMode ? [] : [$searchTerm, $searchTerm];

            $typeCounts = DB::select("
                SELECT
                    CASE
                        WHEN extension IN ('jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp', 'heic', 'tiff', 'bmp', 'jp2', 'j2k', 'jpf', 'jpx', 'svg', 'raw', 'cr2', 'nef', 'arw') THEN 'photo'
                        WHEN extension IN ('mp4', 'mov', 'avi', 'mkv', 'webm', 'flv', 'm4v', 'wmv', 'mpg', 'mpeg') THEN 'video'
                        WHEN extension IN ('mp3', 'wav', 'flac', 'm4a', 'ogg', 'aac', 'wma') THEN 'audio'
                        WHEN extension IN ('pdf', 'doc', 'docx', 'odt', 'rtf', 'txt') THEN 'document'
                        WHEN extension IN ('xls', 'xlsx', 'ods', 'csv') THEN 'spreadsheet'
                        WHEN extension IN ('ppt', 'pptx', 'odp') THEN 'presentation'
                        WHEN extension IN ('zip', 'rar', '7z', 'tar', 'gz') THEN 'archive'
                        WHEN extension IN ('epub', 'mobi', 'azw3', 'azw', 'fb2') THEN 'ebook'
                        WHEN extension IN ('php', 'js', 'ts', 'py', 'java', 'c', 'cpp', 'go', 'rs', 'rb', 'swift', 'kt', 'vue', 'html', 'css', 'json', 'xml', 'yaml', 'md', 'sql', 'sh') THEN 'code'
                        ELSE 'other'
                    END as type,
                    COUNT(*) as count
                FROM file_registry
                WHERE status = 'active'
                {$searchFilter}
                GROUP BY type
            ", $searchParams);

            foreach ($typeCounts as $tc) {
                $facets['types'][$tc->type] = $tc->count;
            }
        } catch (\Exception $e) {
            Log::debug('getFacets: type counts failed', ['error' => $e->getMessage()]);
        }

        // Get year facets
        try {
            $dateCol = $hasDateTaken ? 'COALESCE(date_taken, nextcloud_modified_at)' : 'nextcloud_modified_at';
            $yearCounts = DB::select("
                SELECT
                    YEAR({$dateCol}) as year,
                    COUNT(*) as count
                FROM file_registry
                WHERE status = 'active'
                {$searchFilter}
                AND {$dateCol} IS NOT NULL
                GROUP BY year
                ORDER BY year DESC
            ", $searchParams);

            foreach ($yearCounts as $yc) {
                if ($yc->year) {
                    $facets['years'][(string) $yc->year] = $yc->count;
                }
            }
        } catch (\Exception $e) {
            Log::debug('getFacets: year counts failed', ['error' => $e->getMessage()]);
        }

        // Get top people
        try {
            $peopleCounts = DB::select("
                SELECT frf.person_name, COUNT(DISTINCT frf.file_registry_id) as count
                FROM file_registry_faces frf
                JOIN file_registry fr ON fr.id = frf.file_registry_id
                WHERE fr.status = 'active'
                AND frf.person_name IS NOT NULL
                {$searchFilter}
                GROUP BY frf.person_name
                ORDER BY count DESC
                LIMIT 10
            ", $searchParams);

            foreach ($peopleCounts as $pc) {
                $facets['people'][$pc->person_name] = $pc->count;
            }
        } catch (\Exception $e) {
            Log::debug('getFacets: people counts failed', ['error' => $e->getMessage()]);
        }

        // Add RAG document type counts
        try {
            $ragSql = $isBrowseMode
                ? 'SELECT document_type, COUNT(*) as count FROM rag_documents GROUP BY document_type'
                : 'SELECT document_type, COUNT(*) as count FROM rag_documents WHERE title ILIKE ? OR content ILIKE ? GROUP BY document_type';
            $ragParams = $isBrowseMode ? [] : [$searchTerm, $searchTerm];
            $ragCounts = DB::connection('pgsql_rag')->select($ragSql, $ragParams);

            foreach ($ragCounts as $rc) {
                $mappedType = $this->mapDocumentType($rc->document_type);
                $key = 'rag_'.$mappedType;
                $facets['types'][$key] = ($facets['types'][$key] ?? 0) + $rc->count;
            }
        } catch (\Exception $e) {
            Log::debug('getFacets: RAG doc counts failed', ['error' => $e->getMessage()]);
        }

        return $facets;
    }

    /**
     * Get landing page data (recent files, notes, stats)
     */
    public function getLandingData(): array
    {
        $data = [
            'recent_files' => [],
            'recent_notes' => [],
            'face_queue_count' => 0,
            'stats' => [
                'total_files' => 0,
                'total_docs' => 0,
                'total_notes' => 0,
            ],
        ];

        // Recent files from file_registry
        try {
            $recentFiles = DB::select("
                SELECT id, asset_uuid, filename, current_path, extension, mime_type, file_size,
                       nextcloud_modified_at, thumbnail_sizes, title
                FROM file_registry
                WHERE status = 'active'
                ORDER BY nextcloud_modified_at DESC
                LIMIT 10
            ");

            $data['recent_files'] = array_map(function ($f) {
                return [
                    'id' => $f->id,
                    'asset_uuid' => $f->asset_uuid,
                    'title' => $f->title ?: $f->filename,
                    'filename' => $f->filename,
                    'path' => $f->current_path,
                    'type' => $this->getMediaType($f->extension),
                    'extension' => $f->extension,
                    'mime_type' => $f->mime_type,
                    'file_size' => $f->file_size,
                    'date' => $f->nextcloud_modified_at,
                    'has_thumbnail' => ! empty($f->thumbnail_sizes),
                    'thumbnail_url' => "/api/media/{$f->asset_uuid}/thumbnail/medium",
                ];
            }, $recentFiles);
        } catch (\Exception $e) {
            Log::debug('getLandingData: recent files failed', ['error' => $e->getMessage()]);
        }

        // Recent Joplin notes from RAG
        try {
            $recentNotes = DB::connection('pgsql_rag')->select("
                SELECT id, title, document_type, source_id, created_at, updated_at,
                       LEFT(content, 200) as snippet
                FROM rag_documents
                WHERE document_type IN ('joplin', 'joplin_note')
                ORDER BY updated_at DESC NULLS LAST
                LIMIT 10
            ");

            $data['recent_notes'] = array_map(function ($n) {
                return [
                    'id' => $n->id,
                    'title' => $n->title ?? 'Untitled',
                    'type' => 'note',
                    'source_id' => $n->source_id,
                    'date' => $n->updated_at ?? $n->created_at,
                    'snippet' => trim($n->snippet ?? ''),
                    'preview_url' => $n->source_id ? "/api/joplin/notes/{$n->source_id}" : null,
                ];
            }, $recentNotes);
        } catch (\Exception $e) {
            Log::debug('getLandingData: recent notes failed', ['error' => $e->getMessage()]);
        }

        // Face review queue count — unreviewed clusters in pgvector DB
        try {
            $data['face_queue_count'] = (int) (DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(*) as cnt FROM person_clusters WHERE status = 'unreviewed'
            ")->cnt ?? 0);
        } catch (\Exception $e) {
            Log::debug('getLandingData: face queue count failed', ['error' => $e->getMessage()]);
        }

        // Quick stats
        try {
            $data['stats']['total_files'] = (int) (DB::selectOne("
                SELECT COUNT(*) as cnt FROM file_registry WHERE status = 'active'
            ")->cnt ?? 0);
        } catch (\Exception $e) {
            Log::debug('UnifiedSearchService: landing stats total_files query failed', ['error' => $e->getMessage()]);
        }

        try {
            $data['stats']['total_docs'] = (int) (DB::connection('pgsql_rag')->selectOne('
                SELECT COUNT(*) as cnt FROM rag_documents
            ')->cnt ?? 0);
        } catch (\Exception $e) {
            Log::debug('UnifiedSearchService: landing stats total_docs query failed', ['error' => $e->getMessage()]);
        }

        try {
            $data['stats']['total_notes'] = (int) (DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(*) as cnt FROM rag_documents WHERE document_type IN ('joplin', 'joplin_note')
            ")->cnt ?? 0);
        } catch (\Exception $e) {
            Log::debug('UnifiedSearchService: landing stats total_notes query failed', ['error' => $e->getMessage()]);
        }

        return $data;
    }

    /**
     * Index a media file's metadata into RAG for semantic search
     */
    public function indexMediaToRAG(int $fileRegistryId): bool
    {
        try {
            $file = DB::selectOne("
                SELECT *
                FROM file_registry
                WHERE id = ? AND status = 'active'
            ", [$fileRegistryId]);

            if (! $file) {
                return false;
            }

            // Build searchable content from metadata
            $content = implode(' | ', array_filter([
                'Filename: '.$file->filename,
                $file->title ? 'Title: '.$file->title : null,
                $file->tags ? 'Tags: '.$file->tags : null,
                $file->date_taken_reasoning ? 'Date context: '.$file->date_taken_reasoning : null,
                'Path: '.$file->current_path,
                $file->date_taken ? 'Date: '.substr($file->date_taken, 0, 10) : null,
            ]));

            // Add face/people info
            $faces = DB::select('
                SELECT person_name
                FROM file_registry_faces
                WHERE file_registry_id = ? AND person_name IS NOT NULL
            ', [$fileRegistryId]);

            if (! empty($faces)) {
                $names = array_column($faces, 'person_name');
                $content .= ' | People: '.implode(', ', $names);
            }

            // Index to RAG
            $this->ragService->indexDocument(
                content: $content,
                title: $file->filename,
                documentType: 'media',
                sourceId: (string) $file->id,
                sourceType: 'file_registry',
                metadata: [
                    'asset_uuid' => $file->asset_uuid,
                    'extension' => $file->extension,
                    'mime_type' => $file->mime_type,
                    'date_taken' => $file->date_taken,
                    'path' => $file->current_path,
                ]
            );

            Log::info('UnifiedSearch: Indexed media to RAG', [
                'file_id' => $fileRegistryId,
                'filename' => $file->filename,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('UnifiedSearch: Failed to index media to RAG', [
                'file_id' => $fileRegistryId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
