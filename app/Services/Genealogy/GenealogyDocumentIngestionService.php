<?php

namespace App\Services\Genealogy;

use App\Services\AIService;
use App\Services\Genealogy\Support\GenealogyDocumentExtensions;
use App\Services\NextcloudFileApiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * N135 — Document Ingestion Pipeline
 *
 * Scans Nextcloud folders for genealogy documents (PDFs, scanned images)
 * and imports them into genealogy_media so the N140 enrichment pipeline
 * can process them.
 *
 * Pipeline position: N135 seeds genealogy_media → N140 enriches.
 */
class GenealogyDocumentIngestionService
{
    // Extension allowlist is the union of config('file_types.document') and
    // config('file_types.image'), resolved through GenealogyDocumentExtensions.
    // GenealogyDocumentExtensions owns the merged document/image extension rules.

    // Folder-name skip list moved to config('genealogy.ingest.skip_folders') in Phase 3.3.
    // Default is `['thumbnails']` only — operator-preferred, so portraits stay ingestable
    // for EXIF face-data bonding to tree persons. Override per-install via config/genealogy.php.

    // Evidence-classification keyword map has moved to GenealogyEvidenceClassifierService.
    // Separation of concerns: extension classifier (GenealogyDocumentExtensions) answers
    // "can I ingest this?"; evidence classifier answers "what kind of evidence is this?".

    private const AGENT_ID = 'n135-document-ingestion';

    private ?GenealogyEvidenceClassifierService $evidenceClassifier = null;

    public function __construct(
        private NextcloudFileApiService $nc,
        private AIService $ai,
    ) {}

    private function evidence(): GenealogyEvidenceClassifierService
    {
        return $this->evidenceClassifier ??= app(GenealogyEvidenceClassifierService::class);
    }

    /**
     * Return the lowercased skip-folder list from config with a safe
     * default of ['thumbnails'] if the config key is missing or empty.
     *
     * @return list<string>
     */
    private function skipFolders(): array
    {
        $configured = (array) config('genealogy.ingest.skip_folders', []);
        $normalized = array_values(array_filter(array_map(
            static fn ($v) => is_string($v) ? strtolower(trim($v)) : null,
            $configured
        )));

        return $normalized === [] ? ['thumbnails'] : $normalized;
    }

    public function setEvidenceClassifier(GenealogyEvidenceClassifierService $classifier): void
    {
        $this->evidenceClassifier = $classifier;
    }

    /**
     * Ingest all new documents from a Nextcloud folder into genealogy_media.
     *
     * @param  int  $treeId  Target tree ID
     * @param  string  $folder  Nextcloud folder to scan (e.g., /Library/Media)
     * @param  int  $limit  Max new records to create per run
     * @param  bool  $dryRun  Show eligible files without inserting
     * @param  bool  $aiClassify  Use AI to classify ambiguous files
     * @return array {scanned, imported, skipped, failed, errors[]}
     */
    public function ingestFolder(int $treeId, string $folder, int $limit, bool $dryRun, bool $aiClassify): array
    {
        $result = ['scanned' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'dry_run' => []];

        $tree = DB::selectOne('SELECT id, name FROM genealogy_trees WHERE id = ?', [$treeId]);
        if (! $tree) {
            return array_merge($result, ['error' => "Tree #{$treeId} not found"]);
        }

        // listFiles(recursive=true) uses filesystem-first (RecursiveDirectoryIterator via
        // NEXTCLOUD_DATA_PATH) — orders of magnitude faster than WebDAV PROPFIND.
        // Falls back to WebDAV only if NEXTCLOUD_DATA_PATH is not set or path doesn't exist.
        if (! $this->nc->hasDirectAccess()) {
            Log::warning('N135: NEXTCLOUD_DATA_PATH not configured — falling back to WebDAV (slow)');
        }

        $listing = $this->nc->listFiles($folder, true, 300, 0);
        if (! ($listing['success'] ?? false)) {
            return array_merge($result, ['error' => $listing['error'] ?? 'Nextcloud listing failed']);
        }

        $files = $listing['files'] ?? [];

        foreach ($files as $file) {
            if ($result['imported'] >= $limit) {
                break;
            }

            if ($file['is_directory'] ?? false) {
                continue;
            }

            $result['scanned']++;
            $path = $file['path'];
            $name = $file['name'] ?? basename($path);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            // Skip non-document extensions
            if (! GenealogyDocumentExtensions::isAllowed($ext)) {
                $result['skipped']++;

                continue;
            }

            // Skip folders listed in config('genealogy.ingest.skip_folders').
            // Default: thumbnails-only — portraits stay ingestable so the
            // face-data pipeline can still bond them to persons.
            $parentFolder = strtolower(basename(dirname($path)));
            if (in_array($parentFolder, $this->skipFolders(), true)) {
                $result['skipped']++;

                continue;
            }

            // Deduplicate
            if ($this->alreadyIngested($path)) {
                $result['skipped']++;

                continue;
            }

            $mediaType = $this->classifyMediaType($path, $name, $ext, $aiClassify);

            if ($dryRun) {
                $result['dry_run'][] = ['path' => $path, 'type' => $mediaType, 'size' => $file['size'] ?? 0];
                $result['imported']++;

                continue;
            }

            try {
                $mediaId = $this->insertMediaRecord($treeId, $file, $mediaType);
                $result['imported']++;
                Log::info('N135: ingested document', ['media_id' => $mediaId, 'path' => $path, 'type' => $mediaType]);
            } catch (\Exception $e) {
                $result['failed']++;
                $result['errors'][] = ['path' => $path, 'error' => $e->getMessage()];
                Log::error('N135: ingest failed', ['path' => $path, 'error' => $e->getMessage()]);
            }
        }

        if (! $dryRun) {
            Log::info('N135: folder ingest complete', ['tree_id' => $treeId, 'folder' => $folder, 'result' => $result]);
        }

        return $result;
    }

    /**
     * Ingest a single file by Nextcloud path.
     */
    public function ingestFile(int $treeId, string $nextcloudPath, bool $aiClassify): array
    {
        if ($this->alreadyIngested($nextcloudPath)) {
            return ['success' => false, 'reason' => 'already_ingested'];
        }

        $name = basename($nextcloudPath);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (! GenealogyDocumentExtensions::isAllowed($ext)) {
            return ['success' => false, 'reason' => 'unsupported_extension', 'ext' => $ext];
        }

        $fileInfo = $this->nc->getFileInfo($nextcloudPath);
        if (! ($fileInfo['success'] ?? false)) {
            return ['success' => false, 'reason' => 'file_not_found'];
        }

        $mediaType = $this->classifyMediaType($nextcloudPath, $name, $ext, $aiClassify);

        try {
            $mediaId = $this->insertMediaRecord($treeId, [
                'path' => $nextcloudPath,
                'name' => $name,
                'size' => $fileInfo['size'] ?? 0,
                'mime_type' => $fileInfo['mime_type'] ?? null,
            ], $mediaType);

            return ['success' => true, 'media_id' => $mediaId, 'media_type' => $mediaType];
        } catch (\Exception $e) {
            return ['success' => false, 'reason' => $e->getMessage()];
        }
    }

    /**
     * Return stats for the --status display.
     */
    public function getStats(int $treeId): array
    {
        $byType = DB::select(
            "SELECT media_type, COUNT(*) as total,
                    SUM(CASE WHEN enrichment_status = 'completed' THEN 1 ELSE 0 END) as enriched,
                    SUM(CASE WHEN enrichment_status IS NULL THEN 1 ELSE 0 END) as pending_enrichment,
                    SUM(CASE WHEN enrichment_status = 'failed' THEN 1 ELSE 0 END) as failed
             FROM genealogy_media
             WHERE tree_id = ?
               AND media_type IN ('obituary','census','certificate','document','military','headstone')
             GROUP BY media_type
             ORDER BY media_type",
            [$treeId]
        );

        $recent = DB::select(
            "SELECT id, media_type, local_filename, imported_at, enrichment_status
             FROM genealogy_media
             WHERE tree_id = ?
               AND media_type IN ('obituary','census','certificate','document','military','headstone')
             ORDER BY imported_at DESC
             LIMIT 10",
            [$treeId]
        );

        return ['by_type' => $byType, 'recent' => $recent];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Check if this Nextcloud path already has a genealogy_media record.
     */
    private function alreadyIngested(string $nextcloudPath): bool
    {
        return (bool) DB::selectOne(
            'SELECT id FROM genealogy_media WHERE nextcloud_path = ? LIMIT 1',
            [$nextcloudPath]
        );
    }

    /**
     * Classify a document as a specific genealogy media type.
     *
     * Priority: path/filename keyword heuristics → AI fallback.
     * Falls back to 'document' if no strong signal found.
     */
    private function classifyMediaType(string $path, string $filename, string $ext, bool $aiClassify): string
    {
        $classified = $this->evidence()->classify($path, $filename);
        if ($classified['media_type'] !== 'document' || ! empty($classified['matched_keywords'])) {
            return $classified['media_type'];
        }

        // AI fallback for image types (plus PDF, which may contain scans) when caller requests it.
        // Image-type membership is derived from config('file_types.image') so adding a new
        // image format in central config automatically covers AI vision classification here.
        if ($aiClassify && (GenealogyDocumentExtensions::isImage($ext) || $ext === 'pdf')) {
            return $this->aiClassifyDocument($path, $filename);
        }

        return 'document';
    }

    /**
     * Use AIService text completion to classify a document by filename/path context.
     * Does NOT download the file — uses only path/filename as context (cheap call).
     */
    private function aiClassifyDocument(string $path, string $filename): string
    {
        $prompt = 'Classify this genealogy document file into one of these types: '.
                  "obituary, census, certificate, military, document.\n".
                  "File path: {$path}\nFilename: {$filename}\n".
                  'Reply with only the single type word. If uncertain, reply: document';

        $response = $this->ai->query($prompt, 'fast');
        $raw = (string) ($response['response'] ?? 'document');

        return $this->evidence()->normalize($raw);
    }

    /**
     * Insert a new genealogy_media record for an ingested document.
     *
     * Sets analysis_status='completed' and enrichment_status=NULL so the
     * N140 GenealogyMediaEnrichmentService picks it up immediately.
     */
    private function insertMediaRecord(int $treeId, array $file, string $mediaType): int
    {
        $path = $file['path'];
        $filename = $file['name'] ?? basename($path);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = $file['mime_type'] ?? $this->mimeFromExt($ext);
        $size = $file['size'] ?? 0;
        $title = $this->buildTitle($filename);
        $folder = dirname($path);

        DB::insert(
            "INSERT INTO genealogy_media
             (tree_id, nextcloud_path, local_filename, file_format, mime_type, file_size,
              title, media_type, file_exists, source_folder, analysis_status,
              has_faces, face_count, imported_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 'completed', 0, 0, NOW(), NOW(), NOW())",
            [$treeId, $path, $filename, $ext, $mime, $size, $title, $mediaType, $folder]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Convert a filename into a human-readable title.
     * "1920_census_doe_family.pdf" → "1920 Census Doe Family"
     */
    private function buildTitle(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $title = str_replace(['_', '-', '.'], ' ', $base);

        return mb_convert_case($title, MB_CASE_TITLE);
    }

    /**
     * Derive MIME type from file extension. Routes through
     * `GenealogyDocumentExtensions::mimeFromExtension()` so every extension
     * in the unified allowlist has a mime mapping in one place.
     */
    private function mimeFromExt(string $ext): string
    {
        return GenealogyDocumentExtensions::mimeFromExtension($ext);
    }
}
