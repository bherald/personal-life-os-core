<?php

namespace App\Console\Commands;

use App\Services\AIService;
use App\Services\ExifWritebackService;
use App\Services\PhotoDateExtractionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FilesScanDateFixCommand
 *
 * Detects scanned physical photographs (slides, negatives, prints) and estimates
 * their original capture date via AI visual analysis.
 *
 * Problem: Scanners write the digitization date to EXIF:DateTimeOriginal, making
 * a 1965 slide appear to have been taken in 2024. This command:
 *   1. Detects scan candidates via path keywords ("slides", "negatives", etc.)
 *      and/or grayscale image signals (B&W photo with post-2000 EXIF date).
 *   2. Moves the scanner EXIF date to file_registry.scan_date for preservation.
 *   3. Runs AI visual estimation to determine the original photo date.
 *   4. Writes the estimate to XMP-photoshop:DateCreated + IPTC:DateCreated/TimeCreated
 *      WITHOUT touching DateTimeOriginal (scan date preserved there).
 *
 * EXIF field strategy (non-destructive):
 *   DateTimeOriginal        — unchanged (scanner's digitization date)
 *   DateTimeDigitized       — written with scan date (correct EXIF-spec field)
 *   XMP-photoshop:DateCreated — written with estimated original (Lightroom reads this)
 *   IPTC:DateCreated/Time   — written with estimated original (archival standard)
 *   UserComment             — provenance note with confidence %
 *
 * Re-evaluating already-processed files in PROD:
 *   php artisan files:date-fix-scans --reprocess --detect-only   # flag all scan files
 *   php artisan files:date-fix-scans --use-ai --limit=100        # process 100 at a time
 *
 * Usage:
 *   php artisan files:date-fix-scans --stats
 *   php artisan files:date-fix-scans --dry-run --limit=20
 *   php artisan files:date-fix-scans --use-ai --limit=50
 *   php artisan files:date-fix-scans --folder=Slides --use-ai --limit=200
 *   php artisan files:date-fix-scans --detect-only --limit=5000        # fast, no AI
 *   php artisan files:date-fix-scans --reprocess --use-ai --limit=100  # fix already-processed
 */
class FilesScanDateFixCommand extends Command
{
    protected $signature = 'files:date-fix-scans
                            {--stats        : Show scan detection statistics}
                            {--dry-run      : List candidates without processing}
                            {--detect-only  : Flag is_scan and preserve scan_date only — skip AI}
                            {--use-ai       : Run AI visual estimation for original photo date}
                            {--reprocess    : Include files already processed with exif_original in scan folders}
                            {--folder=      : Filter by path substring, e.g. "Slides" or "Negatives"}
                            {--limit=50     : Max files to process per run}';

    protected $description = 'Detect scanned photographs and estimate original capture date via AI visual analysis';

    public function handle(PhotoDateExtractionService $dateService, ExifWritebackService $writeback): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        $limit      = max(1, (int) $this->option('limit'));
        $folder     = $this->option('folder');
        $dryRun     = (bool) $this->option('dry-run');
        $detectOnly = (bool) $this->option('detect-only');
        $useAI      = (bool) $this->option('use-ai');
        $reprocess  = (bool) $this->option('reprocess');

        $imageExts = "'" . implode("','", config('file_types.image')) . "'";

        [$whereClause, $bindings] = $this->buildQuery($imageExts, $folder, $reprocess, $useAI);

        $files = DB::select(
            "SELECT fr.id, fr.current_path, fr.filename, fr.extension,
                    fr.date_taken, fr.date_taken_source, fr.scan_date, fr.is_scan, fr.scan_context
             FROM file_registry fr
             WHERE {$whereClause}
             ORDER BY fr.current_path ASC
             LIMIT ?",
            array_merge($bindings, [$limit])
        );

        if (empty($files)) {
            $this->info('No scan candidates found.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('Found ' . count($files) . ' scan candidates (dry run):');
            foreach (array_slice($files, 0, 15) as $f) {
                $this->line("  [{$f->date_taken_source}] {$f->date_taken}  {$f->current_path}");
            }
            if (count($files) > 15) {
                $this->line('  ... and ' . (count($files) - 15) . ' more');
            }
            return self::SUCCESS;
        }

        $this->info('Processing ' . count($files) . ' scan candidates' . ($useAI ? ' with AI estimation' : ' (detect-only)') . '...');

        $aiService = $useAI ? app(AIService::class) : null;

        $flagged    = 0;
        $aiDone     = 0;
        $exifWritten = 0;
        $skipped    = 0;
        $errors     = 0;

        foreach ($files as $file) {
            try {
                $r = $this->processOne($file, $dateService, $writeback, $aiService, $detectOnly);

                if ($r['error'])       { $errors++;     continue; }
                if ($r['skipped'])     { $skipped++;    continue; }
                if ($r['flagged'])     $flagged++;
                if ($r['ai_done'])     $aiDone++;
                if ($r['exif_written']) $exifWritten++;

                if ($r['ai_done']) {
                    $this->line("  ✓ {$file->filename}: {$r['estimated_year']} ({$r['source']}, {$r['confidence_pct']}%)");
                } elseif ($r['flagged']) {
                    $this->line("  → {$file->filename}: flagged as scan, scan_date={$r['scan_date']}");
                }

            } catch (\Exception $e) {
                $errors++;
                Log::error('FilesScanDateFix: exception', ['file_id' => $file->id, 'error' => $e->getMessage()]);
                $this->line("  ! {$file->filename}: {$e->getMessage()}");
            }
        }

        $this->info("Done — flagged={$flagged}, ai_estimated={$aiDone}, xmp_written={$exifWritten}, skipped={$skipped}, errors={$errors}");

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function processOne(
        object $file,
        PhotoDateExtractionService $dateService,
        ExifWritebackService $writeback,
        ?AIService $aiService,
        bool $detectOnly
    ): array {
        $result = [
            'flagged'       => false,
            'ai_done'       => false,
            'exif_written'  => false,
            'skipped'       => false,
            'error'         => false,
            'scan_date'     => null,
            'estimated_year' => null,
            'source'        => null,
            'confidence_pct' => null,
        ];

        $localPath = $file->current_path;
        if (!file_exists($localPath)) {
            $result['error'] = true;
            return $result;
        }

        // ── 1. Determine scan context ────────────────────────────────────────
        $scanContext = $file->scan_context;

        if (!$scanContext) {
            // Path keyword check (cheap)
            $scanContext = $dateService->isScanContext($file->current_path, $file->filename);

            // Grayscale check for post-2000 EXIF dates with no path keyword
            if (!$scanContext && $file->date_taken && strtotime($file->date_taken) > mktime(0, 0, 0, 1, 1, 2000)) {
                $signals = $dateService->detectOldPhotoSignals($localPath);
                if ($signals['is_grayscale']) {
                    $scanContext = 'grayscale_recent_exif';
                }
            }
        }

        if (!$scanContext && !$file->is_scan) {
            $result['skipped'] = true;
            return $result;
        }

        // ── 2. Preserve the scanner EXIF date as scan_date ──────────────────
        // The EXIF date in date_taken is the digitization date (written by the scanner
        // to DateTimeOriginal). Move it to scan_date — its proper home.
        $scanDate = $file->scan_date ?? $file->date_taken;
        $result['scan_date'] = $scanDate;

        $needsDateClear = !$file->is_scan || $file->scan_date === null;

        if ($needsDateClear) {
            DB::update("
                UPDATE file_registry
                SET is_scan           = 1,
                    scan_context      = COALESCE(scan_context, ?),
                    scan_date         = COALESCE(scan_date, ?),
                    date_taken        = NULL,
                    date_taken_source = 'scan_exif',
                    updated_at        = NOW()
                WHERE id = ?
            ", [$scanContext, $scanDate, $file->id]);
        } else {
            DB::update("
                UPDATE file_registry
                SET is_scan      = 1,
                    scan_context = COALESCE(scan_context, ?),
                    updated_at   = NOW()
                WHERE id = ?
            ", [$scanContext, $file->id]);
        }

        $result['flagged'] = true;

        if ($detectOnly || !$aiService) {
            return $result;
        }

        // ── 3. AI visual estimation ──────────────────────────────────────────
        $aiResult = $dateService->estimateDateWithAIScanContext($localPath, $aiService, $scanDate, $scanContext);

        if (!$aiResult['success']) {
            // AI couldn't estimate — leave date_taken NULL for human review
            DB::update("UPDATE file_registry SET date_needs_review = 1 WHERE id = ?", [$file->id]);
            return $result;
        }

        // Reject invalid dates (year 0000 or pre-1800 for photos)
        $year = (int) substr($aiResult['date'] ?? '', 0, 4);
        if ($year < 1800) {
            DB::update("UPDATE file_registry SET date_needs_review = 1 WHERE id = ?", [$file->id]);
            return $result;
        }

        $confidence = $aiResult['confidence'];
        $source = match (true) {
            $confidence >= 0.65 => 'ai_visual_high',
            $confidence >= 0.45 => 'ai_visual_medium',
            default             => 'ai_visual_low',
        };

        DB::update("
            UPDATE file_registry
            SET date_taken            = ?,
                date_taken_source     = ?,
                date_taken_confidence = ?,
                date_taken_reasoning  = ?,
                date_extracted_at     = NOW(),
                date_needs_review     = 1,
                updated_at            = NOW()
            WHERE id = ?
        ", [
            $aiResult['date'],
            $source,
            $confidence,
            $aiResult['reasoning'],
            $file->id,
        ]);

        $result['ai_done']       = true;
        $result['source']        = $source;
        $result['estimated_year'] = substr($aiResult['date'], 0, 4);
        $result['confidence_pct'] = round($confidence * 100);

        // ── 4. Write to XMP/IPTC (non-destructive — DateTimeOriginal untouched) ──
        $writeResult = $writeback->writeScanEstimatedDate(
            $localPath,
            $aiResult['date'],
            $scanDate,
            $source,
            $confidence
        );

        if ($writeResult['success']) {
            DB::update("UPDATE file_registry SET exif_written = 1 WHERE id = ?", [$file->id]);
            $result['exif_written'] = true;
        } else {
            Log::warning('FilesScanDateFix: XMP write failed', [
                'file_id' => $file->id,
                'error'   => $writeResult['error'] ?? 'unknown',
            ]);
        }

        return $result;
    }

    // -------------------------------------------------------------------------

    private function buildQuery(string $imageExts, ?string $folder, bool $reprocess, bool $useAI): array
    {
        $conditions = [
            "fr.status = 'active'",
            "fr.extension IN ({$imageExts})",
        ];
        $bindings = [];

        // Build LIKE conditions for scan path keywords (parameterized)
        $keywordParts = [];
        foreach (PhotoDateExtractionService::SCAN_PATH_KEYWORDS as $kw) {
            $keywordParts[] = 'fr.current_path LIKE ?';
            $bindings[] = '%' . $kw . '%';
        }
        $scanPathWhere = '(' . implode(' OR ', $keywordParts) . ')';

        if ($folder) {
            // Explicit folder filter overrides keyword auto-detection
            $conditions[] = 'fr.current_path LIKE ?';
            $bindings[] = '%' . $folder . '%';
        } else {
            $conditions[] = "({$scanPathWhere} OR fr.is_scan = 1)";
        }

        if ($reprocess) {
            // Include files previously processed with exif_original (before scan detection existed)
            // that sit in scan folders. These need to be re-flagged and re-estimated.
            $conditions[] = "fr.date_taken_source IN ('exif_original','exif_digitized','exif_modified','scan_exif')";
        } else {
            // Normal mode: only files not yet AI-estimated
            $conditions[] = "fr.date_taken_source NOT IN ('ai_visual_high','ai_visual_medium','ai_visual_low','user_manual')";
        }

        if ($useAI) {
            // When running AI, skip files already estimated at high confidence
            $conditions[] = "fr.date_taken_source != 'ai_visual_high'";
        }

        return [implode(' AND ', $conditions), $bindings];
    }

    // -------------------------------------------------------------------------

    private function showStats(): int
    {
        $stats = DB::selectOne("
            SELECT
                SUM(is_scan)                                                               AS total_scan_flagged,
                SUM(CASE WHEN is_scan = 1 AND date_taken IS NULL THEN 1 ELSE 0 END)       AS pending_ai,
                SUM(CASE WHEN date_taken_source = 'ai_visual_high'   THEN 1 ELSE 0 END)   AS ai_high,
                SUM(CASE WHEN date_taken_source = 'ai_visual_medium' THEN 1 ELSE 0 END)   AS ai_medium,
                SUM(CASE WHEN date_taken_source = 'ai_visual_low'    THEN 1 ELSE 0 END)   AS ai_low,
                SUM(CASE WHEN date_taken_source = 'scan_exif'        THEN 1 ELSE 0 END)   AS scan_exif_only,
                SUM(CASE WHEN is_scan = 1 AND date_needs_review = 1  THEN 1 ELSE 0 END)   AS needs_review,
                SUM(CASE WHEN is_scan = 1 AND exif_written = 1       THEN 1 ELSE 0 END)   AS xmp_written
            FROM file_registry
            WHERE status = 'active'
        ");

        // Count undetected candidates still in exif_original within scan paths
        $keywordParts = array_fill(0, count(PhotoDateExtractionService::SCAN_PATH_KEYWORDS), 'current_path LIKE ?');
        $bindings = array_map(fn($kw) => '%' . $kw . '%', PhotoDateExtractionService::SCAN_PATH_KEYWORDS);

        $undetected = DB::selectOne(
            "SELECT COUNT(*) AS c FROM file_registry
             WHERE status = 'active' AND is_scan = 0
             AND date_taken_source IN ('exif_original','exif_digitized')
             AND (" . implode(' OR ', $keywordParts) . ")",
            $bindings
        )->c ?? 0;

        $this->table(['Metric', 'Count'], [
            ['Scan-flagged images total',        $stats->total_scan_flagged ?? 0],
            ['Pending AI estimation',            $stats->pending_ai ?? 0],
            ['Flagged only (no AI yet)',         $stats->scan_exif_only ?? 0],
            ['AI estimated — high confidence',   $stats->ai_high ?? 0],
            ['AI estimated — medium confidence', $stats->ai_medium ?? 0],
            ['AI estimated — low confidence',    $stats->ai_low ?? 0],
            ['Needs human review',               $stats->needs_review ?? 0],
            ['XMP/IPTC written to file',         $stats->xmp_written ?? 0],
            ['Undetected (--reprocess needed)',   $undetected],
        ]);

        if ($undetected > 0) {
            $this->line('');
            $this->warn("  {$undetected} files in scan folders still have exif_original source.");
            $this->line('  Run: php artisan files:date-fix-scans --reprocess --detect-only --limit=5000');
        }

        return self::SUCCESS;
    }
}
