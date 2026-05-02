<?php

namespace App\Console\Commands;

use App\Services\FileRegistryService;
use App\Services\FaceMatcherService;
use App\Services\FaceEmbeddingService;
use App\Services\ImageAnalyzerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MediaScanCommand - Scan media files for faces and metadata
 *
 * Supports two face detection modes:
 * --faces : Extract MWG face regions from EXIF/XMP metadata (source of truth)
 * --detect-faces : AI detection using Python face_recognition + pgvector clustering
 *
 * Can optionally run face matching to link to genealogy_persons.
 */
class MediaScanCommand extends Command
{
    protected $signature = 'media:scan
                            {--faces : Scan for face regions in EXIF/XMP metadata}
                            {--detect-faces : AI face detection and clustering (Python face_recognition)}
                            {--detect-objects : Run AI object/scene detection}
                            {--match : Run face matching after scan}
                            {--path= : Limit scan to specific path prefix}
                            {--limit=500 : Max files to scan per batch}
                            {--new-only : Only scan files not yet scanned}
                            {--rescan : Rescan already scanned files}
                            {--tree-id=4 : Genealogy tree ID for matching}
                            {--until-complete : Keep running batches until no more files to process}
                            {--stats : Show statistics only}
                            {--dry-run : Show what would be done without doing it}';

    protected $description = 'Scan media files for faces and metadata';

    public function handle(
        FileRegistryService $fileRegistry,
        FaceMatcherService $faceMatcher,
        FaceEmbeddingService $faceEmbedding,
        ImageAnalyzerService $imageAnalyzer
    ): int {
        if ($this->option('stats')) {
            return $this->showStats($faceMatcher, $faceEmbedding);
        }

        $limit = (int) $this->option('limit');
        $path = $this->option('path');
        $rescan = $this->option('rescan');
        $newOnly = $this->option('new-only') || !$rescan;
        $runMatch = $this->option('match');
        $treeId = (int) $this->option('tree-id');
        $dryRun = $this->option('dry-run');
        $untilComplete = $this->option('until-complete');

        $this->info('Media Scanner');
        $this->info('=============');

        if ($untilComplete && !$dryRun) {
            return $this->runUntilComplete($fileRegistry, $faceMatcher, $faceEmbedding, $limit, $path, $newOnly, $runMatch, $treeId);
        }

        // EXIF/XMP metadata face extraction (source of truth)
        if ($this->option('faces')) {
            $this->scanFaces($fileRegistry, $limit, $path, $newOnly, $dryRun);
        }

        // AI face detection and clustering (Python face_recognition)
        if ($this->option('detect-faces') && !$dryRun) {
            $this->detectFacesAI($faceEmbedding, $limit, $path, $newOnly);
        }

        if ($this->option('detect-objects') && !$dryRun) {
            $this->scanObjects($imageAnalyzer, $limit, $path, $newOnly);
        }

        if ($runMatch && !$dryRun) {
            $this->runMatching($faceMatcher, $limit, $treeId);
        }

        return Command::SUCCESS;
    }

    /**
     * Run batches until no more files to process
     */
    private function runUntilComplete(
        FileRegistryService $fileRegistry,
        FaceMatcherService $faceMatcher,
        FaceEmbeddingService $faceEmbedding,
        int $limit,
        ?string $path,
        bool $newOnly,
        bool $runMatch,
        int $treeId
    ): int {
        $batchNum = 0;
        $totalScanned = 0;
        $totalFaces = 0;
        $totalMatched = 0;
        $totalAIDetected = 0;

        $this->info("Running until complete (batch size: {$limit})");
        $this->info('Press Ctrl+C to stop gracefully');
        $this->newLine();

        while (true) {
            $batchNum++;
            $this->info("=== Batch {$batchNum} ===");

            // Count remaining files
            $remaining = $this->countRemainingFiles($path, $newOnly);
            if ($remaining === 0) {
                $this->info('No more files to process!');
                break;
            }

            $this->info("Remaining files: {$remaining}");

            // Scan faces from EXIF/XMP
            if ($this->option('faces')) {
                $scanResult = $this->scanFacesBatch($fileRegistry, $limit, $path, $newOnly);
                $totalScanned += $scanResult['scanned'];
                $totalFaces += $scanResult['faces_found'];

                if ($scanResult['scanned'] === 0) {
                    $this->info('No files scanned in this batch.');
                    break;
                }
            }

            // AI face detection
            if ($this->option('detect-faces')) {
                $aiResult = $this->detectFacesAIBatch($faceEmbedding, $limit, $path, $newOnly);
                $totalAIDetected += $aiResult['faces_detected'];
            }

            // Match faces
            if ($runMatch) {
                $matchResult = $faceMatcher->processBatch($limit, $treeId);
                $totalMatched += $matchResult['auto_linked'];
            }

            $this->newLine();

            // Brief pause to prevent overwhelming the system
            usleep(100000); // 100ms
        }

        $this->newLine();
        $this->info('=== COMPLETE ===');
        $this->table(['Metric', 'Total'], [
            ['Batches Run', $batchNum],
            ['Files Scanned', $totalScanned],
            ['EXIF Faces Found', $totalFaces],
            ['AI Faces Detected', $totalAIDetected],
            ['Auto-Matched', $totalMatched],
        ]);

        return Command::SUCCESS;
    }

    /**
     * Count remaining files to scan
     */
    private function countRemainingFiles(?string $path, bool $newOnly): int
    {
        $query = "
            SELECT COUNT(*) as cnt
            FROM file_registry
            WHERE status = 'active'
            AND LOWER(SUBSTRING_INDEX(filename, '.', -1)) IN ('jpg', 'jpeg', 'png', 'gif', 'heic', 'tiff', 'tif')
        ";
        $params = [];

        if ($path) {
            $query .= " AND current_path LIKE ?";
            $params[] = $path . '%';
        }

        if ($newOnly) {
            $query .= " AND face_scan_at IS NULL";
        }

        $result = DB::selectOne($query, $params);
        return (int) ($result->cnt ?? 0);
    }

    /**
     * Scan a batch of files and return stats
     */
    private function scanFacesBatch(
        FileRegistryService $fileRegistry,
        int $limit,
        ?string $path,
        bool $newOnly
    ): array {
        $query = "
            SELECT asset_uuid, filename, current_path
            FROM file_registry
            WHERE status = 'active'
            AND LOWER(SUBSTRING_INDEX(filename, '.', -1)) IN ('jpg', 'jpeg', 'png', 'gif', 'heic', 'tiff', 'tif')
        ";
        $params = [];

        if ($path) {
            $query .= " AND current_path LIKE ?";
            $params[] = $path . '%';
        }

        if ($newOnly) {
            $query .= " AND face_scan_at IS NULL";
        }

        $query .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;

        $files = DB::select($query, $params);
        $stats = ['scanned' => 0, 'faces_found' => 0, 'errors' => 0];

        foreach ($files as $file) {
            try {
                $result = $fileRegistry->scanFileFaces($file->asset_uuid);
                if ($result['success']) {
                    $stats['scanned']++;
                    $stats['faces_found'] += $result['faces_found'];
                } else {
                    $stats['errors']++;
                }
            } catch (\Exception $e) {
                $stats['errors']++;
            }
        }

        $this->line("  Scanned: {$stats['scanned']}, Faces: {$stats['faces_found']}, Errors: {$stats['errors']}");
        return $stats;
    }

    /**
     * Scan files for face regions
     */
    private function scanFaces(
        FileRegistryService $fileRegistry,
        int $limit,
        ?string $path,
        bool $newOnly,
        bool $dryRun
    ): void {
        $this->info('Scanning for face regions...');

        // Build query based on options
        $query = "
            SELECT asset_uuid, filename, current_path
            FROM file_registry
            WHERE status = 'active'
            AND LOWER(SUBSTRING_INDEX(filename, '.', -1)) IN ('jpg', 'jpeg', 'png', 'gif', 'heic', 'tiff', 'tif')
        ";
        $params = [];

        if ($path) {
            $query .= " AND current_path LIKE ?";
            $params[] = $path . '%';
        }

        if ($newOnly) {
            $query .= " AND face_scan_at IS NULL";
        }

        $query .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;

        $files = DB::select($query, $params);

        if (empty($files)) {
            $this->info('No files to scan.');
            return;
        }

        $this->info("Found " . count($files) . " files to scan.");

        if ($dryRun) {
            $this->warn('Dry run - no files will be scanned.');
            foreach (array_slice($files, 0, 10) as $file) {
                $this->line("  Would scan: {$file->filename}");
            }
            if (count($files) > 10) {
                $this->line("  ... and " . (count($files) - 10) . " more");
            }
            return;
        }

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        $stats = ['scanned' => 0, 'faces_found' => 0, 'errors' => 0];

        foreach ($files as $file) {
            try {
                $result = $fileRegistry->scanFileFaces($file->asset_uuid);
                if ($result['success']) {
                    $stats['scanned']++;
                    $stats['faces_found'] += $result['faces_found'];
                } else {
                    $stats['errors']++;
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::warning('MediaScan: Error scanning file', [
                    'asset_uuid' => $file->asset_uuid,
                    'error' => $e->getMessage(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Files Scanned', $stats['scanned']],
                ['Faces Found', $stats['faces_found']],
                ['Errors', $stats['errors']],
            ]
        );
    }

    /**
     * Run face matching to link to genealogy
     */
    private function runMatching(FaceMatcherService $faceMatcher, int $limit, int $treeId): void
    {
        $this->newLine();
        $this->info('Running face matching...');

        $results = $faceMatcher->processBatch($limit, $treeId);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $results['processed']],
                ['Auto-linked', $results['auto_linked']],
                ['Queued for Review', $results['queued']],
                ['Skipped', $results['skipped']],
            ]
        );

        if ($results['queued'] > 0) {
            $this->info('');
            $this->info('Review queued matches with:');
            $this->info("  php artisan genealogy:review-face-matches --tree-id={$treeId}");
        }
    }

    /**
     * AI face detection using Python face_recognition
     */
    private function detectFacesAI(
        FaceEmbeddingService $faceEmbedding,
        int $limit,
        ?string $path,
        bool $newOnly
    ): void {
        $this->info('Running AI face detection (Python face_recognition)...');

        // Check if face_recognition is available
        if (!$faceEmbedding->isAvailable()) {
            $this->error('Python face_recognition not available. Install with:');
            $this->line('  pip install face_recognition pillow numpy');
            return;
        }

        // Get already processed file IDs from pgvector (separate database)
        $processedIds = [];
        if ($newOnly) {
            try {
                $processed = DB::connection('pgsql_rag')->select("
                    SELECT DISTINCT file_registry_id FROM face_embeddings
                ");
                $processedIds = array_map(fn($r) => $r->file_registry_id, $processed);
            } catch (\Exception $e) {
                // Table might not exist yet, continue
            }
        }

        // Get images not yet processed for AI face detection
        $query = "
            SELECT fr.id, fr.asset_uuid, fr.filename, fr.current_path
            FROM file_registry fr
            WHERE fr.status = 'active'
            AND LOWER(SUBSTRING_INDEX(fr.filename, '.', -1)) IN ('jpg', 'jpeg', 'png', 'gif', 'heic', 'tiff', 'tif')
        ";
        $params = [];

        if ($path) {
            $query .= " AND fr.current_path LIKE ?";
            $params[] = $path . '%';
        }

        // Exclude already processed files
        if ($newOnly && !empty($processedIds)) {
            $placeholders = implode(',', array_fill(0, count($processedIds), '?'));
            $query .= " AND fr.id NOT IN ({$placeholders})";
            $params = array_merge($params, $processedIds);
        }

        $query .= " ORDER BY fr.created_at DESC LIMIT ?";
        $params[] = $limit;

        $files = DB::select($query, $params);

        if (empty($files)) {
            $this->info('No files to process for AI face detection.');
            return;
        }

        $this->info("Found " . count($files) . " files for AI face detection.");

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        $stats = ['processed' => 0, 'faces_detected' => 0, 'matched' => 0, 'new_clusters' => 0, 'errors' => 0];

        foreach ($files as $file) {
            try {
                $localPath = file_exists($file->current_path) ? $file->current_path : null;

                if (!$localPath) {
                    $stats['errors']++;
                    $bar->advance();
                    continue;
                }

                $result = $faceEmbedding->processImage($file->id, $localPath);

                if ($result['success']) {
                    $stats['processed']++;
                    $stats['faces_detected'] += $result['faces_detected'] ?? 0;
                    $stats['matched'] += $result['faces_matched'] ?? 0;
                    $stats['new_clusters'] += $result['faces_new'] ?? 0;
                } else {
                    $stats['errors']++;
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::warning('MediaScan: AI face detection error', [
                    'asset_uuid' => $file->asset_uuid,
                    'error' => $e->getMessage(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Files Processed', $stats['processed']],
                ['Faces Detected', $stats['faces_detected']],
                ['Matched to Existing', $stats['matched']],
                ['New Clusters', $stats['new_clusters']],
                ['Errors', $stats['errors']],
            ]
        );

        // Show cluster review hint
        if ($stats['new_clusters'] > 0) {
            $this->newLine();
            $this->info('Review new face clusters in the Media Browser UI or with:');
            $this->info('  php artisan media:review-clusters');
        }
    }

    /**
     * AI face detection batch (for until-complete mode)
     */
    private function detectFacesAIBatch(
        FaceEmbeddingService $faceEmbedding,
        int $limit,
        ?string $path,
        bool $newOnly
    ): array {
        if (!$faceEmbedding->isAvailable()) {
            return ['processed' => 0, 'faces_detected' => 0, 'errors' => 1];
        }

        // Get already processed file IDs from pgvector (separate database)
        $processedIds = [];
        if ($newOnly) {
            try {
                $processed = DB::connection('pgsql_rag')->select("
                    SELECT DISTINCT file_registry_id FROM face_embeddings
                ");
                $processedIds = array_map(fn($r) => $r->file_registry_id, $processed);
            } catch (\Exception $e) {
                // Table might not exist yet, continue
            }
        }

        $query = "
            SELECT fr.id, fr.current_path
            FROM file_registry fr
            WHERE fr.status = 'active'
            AND LOWER(SUBSTRING_INDEX(fr.filename, '.', -1)) IN ('jpg', 'jpeg', 'png', 'gif', 'heic', 'tiff', 'tif')
        ";
        $params = [];

        if ($path) {
            $query .= " AND fr.current_path LIKE ?";
            $params[] = $path . '%';
        }

        // Exclude already processed files
        if ($newOnly && !empty($processedIds)) {
            $placeholders = implode(',', array_fill(0, count($processedIds), '?'));
            $query .= " AND fr.id NOT IN ({$placeholders})";
            $params = array_merge($params, $processedIds);
        }

        $query .= " ORDER BY fr.created_at DESC LIMIT ?";
        $params[] = $limit;

        $files = DB::select($query, $params);
        $stats = ['processed' => 0, 'faces_detected' => 0, 'errors' => 0];

        foreach ($files as $file) {
            try {
                if (!file_exists($file->current_path)) {
                    $stats['errors']++;
                    continue;
                }

                $result = $faceEmbedding->processImage($file->id, $file->current_path);
                if ($result['success']) {
                    $stats['processed']++;
                    $stats['faces_detected'] += $result['faces_detected'] ?? 0;
                } else {
                    $stats['errors']++;
                }
            } catch (\Exception $e) {
                $stats['errors']++;
            }
        }

        $this->line("  AI Detection: {$stats['processed']} files, {$stats['faces_detected']} faces, {$stats['errors']} errors");
        return $stats;
    }

    /**
     * Show statistics
     */
    private function showStats(FaceMatcherService $faceMatcher, FaceEmbeddingService $faceEmbedding): int
    {
        $this->info('Media Face Statistics');
        $this->info('=====================');

        // File registry face counts
        $faceCounts = DB::selectOne("
            SELECT
                COUNT(*) as total_files,
                SUM(CASE WHEN face_scan_at IS NOT NULL THEN 1 ELSE 0 END) as scanned,
                SUM(CASE WHEN face_count > 0 THEN 1 ELSE 0 END) as with_faces,
                SUM(COALESCE(face_count, 0)) as total_faces
            FROM file_registry
            WHERE status = 'active'
            AND LOWER(SUBSTRING_INDEX(filename, '.', -1)) IN ('jpg', 'jpeg', 'png', 'gif', 'heic', 'tiff', 'tif')
        ");

        $this->newLine();
        $this->info('File Registry (EXIF/XMP Metadata):');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Image Files', number_format($faceCounts->total_files ?? 0)],
                ['Scanned for Faces', number_format($faceCounts->scanned ?? 0)],
                ['Files with Faces', number_format($faceCounts->with_faces ?? 0)],
                ['Total Face Regions', number_format($faceCounts->total_faces ?? 0)],
            ]
        );

        // Face linking status
        $linkingStats = DB::selectOne("
            SELECT
                COUNT(*) as total_faces,
                SUM(CASE WHEN genealogy_person_id IS NOT NULL THEN 1 ELSE 0 END) as linked,
                SUM(CASE WHEN genealogy_person_id IS NULL THEN 1 ELSE 0 END) as unlinked
            FROM file_registry_faces
        ");

        $this->newLine();
        $this->info('Face Linking (Metadata):');
        $this->table(
            ['Status', 'Count'],
            [
                ['Total Face Records', number_format($linkingStats->total_faces ?? 0)],
                ['Linked to Genealogy', number_format($linkingStats->linked ?? 0)],
                ['Unlinked', number_format($linkingStats->unlinked ?? 0)],
            ]
        );

        // AI Face Embedding Statistics (from pgvector)
        $embeddingStats = $faceEmbedding->getStats();
        $this->newLine();
        $this->info('AI Face Detection (pgvector):');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Face Embeddings', number_format($embeddingStats['total_faces'] ?? 0)],
                ['Unique Clusters', number_format($embeddingStats['total_clusters'] ?? 0)],
                ['Files with AI Faces', number_format($embeddingStats['files_with_faces'] ?? 0)],
                ['Clusters Unreviewed', number_format($embeddingStats['clusters_unreviewed'] ?? 0)],
                ['Clusters Confirmed', number_format($embeddingStats['clusters_confirmed'] ?? 0)],
                ['Clusters Linked to Genealogy', number_format($embeddingStats['clusters_linked'] ?? 0)],
            ]
        );

        // Python face_recognition availability
        $available = $faceEmbedding->isAvailable();
        $this->newLine();
        $this->info('AI Detection Status:');
        $this->line('  Python face_recognition: ' . ($available ? '✓ Available' : '✗ Not installed'));

        // Unique persons in faces
        $uniquePersons = DB::select("
            SELECT person_name, COUNT(*) as count, genealogy_person_id
            FROM file_registry_faces
            GROUP BY person_name, genealogy_person_id
            ORDER BY count DESC
            LIMIT 10
        ");

        if (!empty($uniquePersons)) {
            $this->newLine();
            $this->info('Top 10 Persons in Media:');
            $rows = [];
            foreach ($uniquePersons as $p) {
                $linked = $p->genealogy_person_id ? "✓ #{$p->genealogy_person_id}" : '✗';
                $rows[] = [$p->person_name, $p->count, $linked];
            }
            $this->table(['Person Name', 'Files', 'Linked'], $rows);
        }

        // Queue stats
        $queueStats = $faceMatcher->getQueueStats();
        $this->newLine();
        $this->info('Match Queue:');
        $this->table(
            ['Status', 'Count'],
            [
                ['Pending', $queueStats['pending']],
                ['Approved', $queueStats['approved']],
                ['Rejected', $queueStats['rejected']],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * Scan images for AI object/scene detection
     */
    private function scanObjects(
        ImageAnalyzerService $imageAnalyzer,
        int $limit,
        ?string $path,
        bool $newOnly
    ): void {
        $this->info('Running AI object/scene detection...');

        // Get images without AI tags
        $query = "
            SELECT id, asset_uuid, filename, current_path
            FROM file_registry
            WHERE status = 'active'
            AND LOWER(SUBSTRING_INDEX(filename, '.', -1)) IN ('jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'tiff', 'tif')
        ";
        $params = [];

        if ($path) {
            $query .= " AND current_path LIKE ?";
            $params[] = $path . '%';
        }

        if ($newOnly) {
            $query .= " AND (ai_tags IS NULL OR ai_tags = '')";
        }

        $query .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;

        $files = DB::select($query, $params);

        if (empty($files)) {
            $this->info('No files to scan for objects.');
            return;
        }

        $this->info("Found " . count($files) . " files for object detection.");

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        $stats = ['scanned' => 0, 'objects_found' => 0, 'errors' => 0];

        foreach ($files as $file) {
            try {
                $localPath = file_exists($file->current_path) ? $file->current_path : null;

                if (!$localPath) {
                    $stats['errors']++;
                    $bar->advance();
                    continue;
                }

                $result = $imageAnalyzer->detectObjects($localPath);

                if ($result['success'] && !empty($result['objects'])) {
                    // Extract object names for tagging
                    $objectNames = array_map(fn($obj) => $obj['name'], $result['objects']);
                    $tags = implode(', ', array_unique($objectNames));
                    $sceneType = $result['scene_type'] ?? 'unknown';

                    // Update file_registry with detected objects
                    DB::update("
                        UPDATE file_registry
                        SET ai_tags = ?,
                            ai_document_type = ?,
                            ai_analyzed_at = NOW(),
                            updated_at = NOW()
                        WHERE id = ?
                    ", [$tags, "scene:{$sceneType}", $file->id]);

                    $stats['scanned']++;
                    $stats['objects_found'] += count($result['objects']);
                } else {
                    $stats['errors']++;
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::warning('MediaScan: Object detection error', [
                    'asset_uuid' => $file->asset_uuid,
                    'error' => $e->getMessage(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Files Scanned', $stats['scanned']],
                ['Objects Found', $stats['objects_found']],
                ['Errors', $stats['errors']],
            ]
        );
    }
}
