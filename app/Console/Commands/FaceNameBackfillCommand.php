<?php

namespace App\Console\Commands;

use App\Services\FaceRegionService;
use App\Services\FileRegistryService;
use App\Services\NextcloudFileApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Import embedded XMP source evidence without assigning names to detector regions.
 */
class FaceNameBackfillCommand extends Command
{
    protected $signature = 'faces:backfill-names
                            {--limit=500 : Max files to process per run}
                            {--dry-run : Show what would be updated without changing data}
                            {--stats : Show current backfill status}
                            {--iou=0.15 : Legacy compatibility option; overlap does not approve identity}';

    protected $description = 'Import unverified XMP face evidence without naming detector faces';

    public function handle(): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $faceService = app(FaceRegionService::class);
        if (! $faceService->isAvailable()) {
            $this->error('ExifTool not available');

            return Command::FAILURE;
        }

        // Find files that:
        // 1. Have AI-detected faces with empty person_name
        // 2. Are known to have XMP face data (in genealogy_media_scan_log)
        // 3. Haven't been backfilled yet (no source='xmp' faces for this file)
        $files = DB::select("
            SELECT DISTINCT fr.id as file_id, fr.current_path, fr.asset_uuid,
                   sl.face_count as xmp_face_count, sl.face_names as xmp_names
            FROM file_registry fr
            JOIN file_registry_faces frf ON frf.file_registry_id = fr.id
            JOIN genealogy_media_scan_log sl ON sl.nextcloud_path = fr.current_path
            WHERE frf.person_name = ''
              AND frf.source = 'ai_detection'
              AND sl.has_faces = 1
              AND fr.status = 'active'
            ORDER BY sl.face_count ASC
            LIMIT ?
        ", [$limit]);

        if (empty($files)) {
            $this->info('No files need backfill.');

            return Command::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '').'Processing '.count($files).' files for source evidence review');

        $stats = [
            'files_processed' => 0,
            'files_with_matches' => 0,
            'faces_named' => 0,
            'xmp_read_errors' => 0,
            'no_match' => 0,
            'files_not_found' => 0,
            'files_with_evidence' => 0,
            'source_regions_imported' => 0,
            'regions_held' => 0,
        ];

        foreach ($files as $file) {
            $stats['files_processed']++;
            try {
                $path = is_file($file->current_path) ? $file->current_path
                    : app(NextcloudFileApiService::class)->localPath($file->current_path);
                if (! $path || ! is_file($path)) {
                    $stats['files_not_found']++;

                    continue;
                }

                if ($dryRun) {
                    // Read-only preview: original embedded labels stay in their source file.
                    $regions = $faceService->readFaceRegions($path, true, true);
                    $found = count($regions);
                } else {
                    $result = app(FileRegistryService::class)->scanFileFaces($file->asset_uuid, $path);
                    if (($result['status'] ?? null) === 'held') {
                        $stats['regions_held'] += (int) ($result['regions_held'] ?? 0);
                        $stats['source_regions_imported'] += (int) ($result['faces_stored'] ?? 0);
                        $stats['files_with_evidence'] += (int) (($result['faces_found'] ?? 0) > 0);

                        continue;
                    }
                    if (! ($result['success'] ?? false)) {
                        $stats['xmp_read_errors']++;

                        continue;
                    }
                    $found = (int) ($result['faces_found'] ?? 0);
                    $stats['source_regions_imported'] += (int) ($result['faces_stored'] ?? 0);
                    $stats['regions_held'] += (int) ($result['regions_held'] ?? 0);
                }
                if ($found > 0) {
                    $stats['files_with_evidence']++;
                } else {
                    $stats['no_match']++;
                }
            } catch (\Exception $e) {
                $stats['xmp_read_errors']++;
                Log::debug('faces:backfill-names evidence read failed', ['file_id' => $file->file_id, 'error' => $e->getMessage()]);
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Files Processed', $stats['files_processed']],
                ['Files with Matches', $stats['files_with_matches']],
                ['Faces Named', $stats['faces_named']],
                ['No Region Match', $stats['no_match']],
                ['XMP Read Errors', $stats['xmp_read_errors']],
                ['Files Not Found', $stats['files_not_found']],
                ['Files with Source Evidence', $stats['files_with_evidence']],
                ['Source Regions Imported', $stats['source_regions_imported']],
                ['Regions Held for Review', $stats['regions_held']],
            ]
        );

        if (! $dryRun) {
            Log::info('faces:backfill-names completed', $stats);
        }

        return Command::SUCCESS;
    }

    private function showStats(): int
    {
        $total = DB::selectOne('SELECT COUNT(*) as cnt FROM file_registry_faces');
        $named = DB::selectOne("SELECT COUNT(*) as cnt FROM file_registry_faces WHERE person_name != ''");
        $unnamed = DB::selectOne("SELECT COUNT(*) as cnt FROM file_registry_faces WHERE person_name = ''");
        $xmpSource = DB::selectOne("SELECT COUNT(*) as cnt FROM file_registry_faces WHERE source = 'xmp'");
        $aiSource = DB::selectOne("SELECT COUNT(*) as cnt FROM file_registry_faces WHERE source = 'ai_detection'");

        $pendingBackfill = DB::selectOne("
            SELECT COUNT(DISTINCT fr.id) as cnt
            FROM file_registry fr
            JOIN file_registry_faces frf ON frf.file_registry_id = fr.id
            JOIN genealogy_media_scan_log sl ON sl.nextcloud_path = fr.current_path
            WHERE frf.person_name = '' AND frf.source = 'ai_detection' AND sl.has_faces = 1
        ");

        $uniquePeople = DB::selectOne("SELECT COUNT(DISTINCT person_name) as cnt FROM file_registry_faces WHERE person_name != ''");

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total faces', $total->cnt],
                ['Named', $named->cnt],
                ['Unnamed', $unnamed->cnt],
                ['Source: ai_detection', $aiSource->cnt],
                ['Source: xmp', $xmpSource->cnt],
                ['Unique people', $uniquePeople->cnt],
                ['Files pending backfill', $pendingBackfill->cnt],
            ]
        );

        return Command::SUCCESS;
    }
}
