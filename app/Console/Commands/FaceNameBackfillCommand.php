<?php

namespace App\Console\Commands;

use App\Services\FaceRegionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfill file_registry_faces.person_name from XMP metadata embedded in source files.
 *
 * The AI face detection pipeline (files:enrich --type=faces) inserts faces with person_name='',
 * ignoring XMP face names already embedded in files. This command reads XMP regions from the
 * physical files and matches them to AI-detected regions by IoU (intersection over union),
 * then sets person_name on the matching file_registry_faces record.
 */
class FaceNameBackfillCommand extends Command
{
    protected $signature = 'faces:backfill-names
                            {--limit=500 : Max files to process per run}
                            {--dry-run : Show what would be updated without changing data}
                            {--stats : Show current backfill status}
                            {--iou=0.15 : Minimum IoU threshold for region matching}';

    protected $description = 'Backfill face names from XMP metadata into file_registry_faces';

    private const IOU_DEFAULT = 0.15;

    public function handle(): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');
        $iouThreshold = (float) $this->option('iou') ?: self::IOU_DEFAULT;

        $faceService = app(FaceRegionService::class);
        if (!$faceService->isAvailable()) {
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

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Processing " . count($files) . " files (IoU threshold: {$iouThreshold})");

        $stats = [
            'files_processed' => 0,
            'files_with_matches' => 0,
            'faces_named' => 0,
            'xmp_read_errors' => 0,
            'no_match' => 0,
            'files_not_found' => 0,
        ];

        foreach ($files as $idx => $file) {
          try {
            $path = $file->current_path;

            if (!file_exists($path)) {
                $stats['files_not_found']++;
                $stats['files_processed']++;
                continue;
            }

            // Read XMP face regions from the physical file
            try {
                $xmpRegions = $faceService->readFaceRegions($path);
            } catch (\Exception $e) {
                $stats['xmp_read_errors']++;
                $stats['files_processed']++;
                continue;
            }

            if (empty($xmpRegions)) {
                $stats['files_processed']++;
                continue;
            }

            // Get AI-detected faces for this file (unnamed only)
            $aiFaces = DB::select("
                SELECT id, region_x, region_y, region_w, region_h, person_name
                FROM file_registry_faces
                WHERE file_registry_id = ? AND source = 'ai_detection' AND person_name = ''
            ", [$file->file_id]);

            if (empty($aiFaces)) {
                $stats['files_processed']++;
                continue;
            }

            // Match XMP regions to AI regions by IoU
            $matched = 0;
            $usedAiFaces = [];

            foreach ($xmpRegions as $xmp) {
                $xmpName = trim($xmp['name'] ?? '');
                if (empty($xmpName) || $xmpName === 'Unknown') continue;

                // Skip comma-concatenated multi-person names (bad XMP data)
                if (str_contains($xmpName, ',')) {
                    // Extract first real name if there's one non-Unknown name
                    $parts = array_map('trim', explode(',', $xmpName));
                    $realNames = array_filter($parts, fn($p) => $p !== 'Unknown' && $p !== '');
                    if (count($realNames) === 1) {
                        $xmpName = reset($realNames);
                    } else {
                        continue; // Ambiguous multi-person region, skip
                    }
                }

                // Safety: skip names exceeding column limit
                if (strlen($xmpName) > 250) continue;

                $bestMatch = null;
                $bestIou = $iouThreshold;

                foreach ($aiFaces as $ai) {
                    if (in_array($ai->id, $usedAiFaces)) continue;

                    $iou = $this->calculateIoU(
                        $xmp['x'], $xmp['y'], $xmp['w'], $xmp['h'],
                        (float) $ai->region_x, (float) $ai->region_y,
                        (float) $ai->region_w, (float) $ai->region_h
                    );

                    if ($iou > $bestIou) {
                        $bestIou = $iou;
                        $bestMatch = $ai;
                    }
                }

                if ($bestMatch) {
                    if ($dryRun) {
                        $this->line("  MATCH: {$xmpName} → face #{$bestMatch->id} (IoU={$bestIou}) in " . basename($path));
                    } else {
                        DB::update("UPDATE file_registry_faces SET person_name = ? WHERE id = ?", [$xmpName, $bestMatch->id]);
                    }
                    $usedAiFaces[] = $bestMatch->id;
                    $matched++;
                }
            }

            if ($matched > 0) {
                $stats['files_with_matches']++;
                $stats['faces_named'] += $matched;

                if (!$dryRun) {
                    // Reset writeback flag so EXIF writeback re-confirms the names
                    DB::update("UPDATE file_registry SET exif_faces_written = 0 WHERE id = ?", [$file->file_id]);
                }
            } else {
                $stats['no_match']++;
            }

            $stats['files_processed']++;

            if (($idx + 1) % 100 === 0) {
                $this->info("  Progress: {$stats['files_processed']}/" . count($files) . " | Named: {$stats['faces_named']}");
            }
          } catch (\Exception $e) {
            $stats['xmp_read_errors']++;
            $stats['files_processed']++;
            Log::debug('faces:backfill-names file error', ['path' => $file->current_path ?? 'unknown', 'error' => $e->getMessage()]);
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
            ]
        );

        if (!$dryRun) {
            Log::info('faces:backfill-names completed', $stats);
        }

        return Command::SUCCESS;
    }

    /**
     * Calculate Intersection over Union between two rectangles.
     * Coordinates are normalized (0-1): x,y = top-left corner, w,h = dimensions.
     */
    private function calculateIoU(
        float $x1, float $y1, float $w1, float $h1,
        float $x2, float $y2, float $w2, float $h2
    ): float {
        $left = max($x1, $x2);
        $top = max($y1, $y2);
        $right = min($x1 + $w1, $x2 + $w2);
        $bottom = min($y1 + $h1, $y2 + $h2);

        if ($right <= $left || $bottom <= $top) {
            return 0.0;
        }

        $intersection = ($right - $left) * ($bottom - $top);
        $area1 = $w1 * $h1;
        $area2 = $w2 * $h2;
        $union = $area1 + $area2 - $intersection;

        return $union > 0 ? $intersection / $union : 0.0;
    }

    private function showStats(): int
    {
        $total = DB::selectOne("SELECT COUNT(*) as cnt FROM file_registry_faces");
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
