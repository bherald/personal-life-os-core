<?php

namespace App\Console\Commands;

use App\Services\AIService;
use App\Services\PhotoDateExtractionService;
use App\Services\ScanDateRuntimePolicyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Detect scanned photographs and stage AI-estimated original dates for review.
 *
 * This command deliberately stops at the catalog suggestion boundary. It never
 * writes XMP, IPTC, or EXIF metadata to image files. A human must accept each
 * suggested date in Review Hub before it becomes an approved catalog value.
 */
class FilesScanDateFixCommand extends Command
{
    private const AI_SOURCES = "'ai_visual_high','ai_visual_medium','ai_visual_low'";

    protected $signature = 'files:date-fix-scans
                            {--stats        : Show scan detection statistics}
                            {--execute      : Apply catalog changes; omitted mode is read-only}
                            {--dry-run      : Explicit read-only compatibility alias}
                            {--detect-only  : Flag scan records and preserve scan_date; skip AI}
                            {--use-ai       : Stage AI date suggestions for operator review}
                            {--reprocess    : Include older scan candidates that need re-evaluation}
                            {--folder=      : Filter by path substring, e.g. Slides or Negatives}
                            {--limit=       : Max records selected; required for execution}
                            {--time-budget= : Optional lower run budget in seconds}
                            {--json         : Emit a structured result}
                            {--compact      : Emit compact JSON without whitespace}';

    protected $description = 'Detect scanned photographs and stage original-date suggestions for operator review';

    public function handle(
        PhotoDateExtractionService $dateService,
        ScanDateRuntimePolicyService $runtimePolicy,
    ): int {
        if ((bool) $this->option('stats')) {
            return $this->showStats();
        }

        $execute = (bool) $this->option('execute');
        $dryRun = (bool) $this->option('dry-run');
        $detectOnly = (bool) $this->option('detect-only');
        $useAI = (bool) $this->option('use-ai');

        if ($execute && $dryRun) {
            return $this->inputError('--execute and --dry-run cannot be combined.');
        }
        if ($detectOnly === $useAI) {
            return $this->inputError('Choose exactly one processing mode: --detect-only or --use-ai.');
        }

        try {
            $policy = $runtimePolicy->required();
            $limit = $runtimePolicy->resolveLimit($this->option('limit'), $policy, $execute);
            $timeBudget = $runtimePolicy->resolveTimeBudget($this->option('time-budget'), $policy);
        } catch (RuntimeException $e) {
            return $this->inputError($e->getMessage());
        }

        $folder = $this->nullableOption('folder');
        $reprocess = (bool) $this->option('reprocess');
        $reviewBacklogBefore = $useAI ? $this->pendingReviewCount() : 0;
        $summary = $this->newSummary(
            $execute ? 'execute' : 'preview',
            $limit,
            $timeBudget,
            $reviewBacklogBefore,
        );

        if ($execute && $useAI && $reviewBacklogBefore >= $policy['pending_review_soft_cap']) {
            $summary['status'] = 'deferred';
            $summary['stopped_reason'] = 'review_backlog_soft_cap';
            $summary['review_backlog_after'] = $reviewBacklogBefore;
            $this->emitSummary($summary);

            return self::SUCCESS;
        }

        $imageExtensions = "'".implode("','", config('file_types.image'))."'";
        [$whereClause, $bindings] = $this->buildQuery($imageExtensions, $folder, $reprocess, $useAI);
        $files = DB::select(
            "SELECT fr.id, fr.current_path, fr.filename, fr.extension,
                    fr.date_taken, fr.date_taken_source, fr.scan_date, fr.is_scan, fr.scan_context
             FROM file_registry fr
             WHERE {$whereClause}
             ORDER BY fr.current_path ASC
             LIMIT ?",
            array_merge($bindings, [$limit])
        );
        $summary['selected'] = count($files);

        if (! $execute) {
            $summary['status'] = 'preview';
            $summary['review_backlog_after'] = $reviewBacklogBefore;
            $this->emitPreview($files, $summary);

            return self::SUCCESS;
        }

        $aiService = $useAI ? app(AIService::class) : null;
        $startedAt = hrtime(true);
        $reviewBacklog = $reviewBacklogBefore;

        foreach ($files as $file) {
            $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
            if ($elapsedSeconds + $policy['per_item_reserve_seconds'] >= $timeBudget) {
                $summary['stopped_reason'] = 'runtime_budget';
                break;
            }
            if ($useAI && $reviewBacklog >= $policy['pending_review_soft_cap']) {
                $summary['stopped_reason'] = 'review_backlog_soft_cap';
                break;
            }

            $summary['attempted']++;
            try {
                $result = $this->processOne($file, $dateService, $aiService, $detectOnly);
                if ($result['error']) {
                    $summary['errors']++;

                    continue;
                }
                if ($result['skipped']) {
                    $summary['skipped']++;

                    continue;
                }
                if ($result['flagged']) {
                    $summary['flagged']++;
                }
                if ($result['ai_done']) {
                    $summary['ai_estimated']++;
                    $summary['staged_for_review']++;
                    $reviewBacklog++;
                }

                if (! (bool) $this->option('json')) {
                    $this->line($result['ai_done']
                        ? "  staged {$file->filename}: {$result['estimated_year']} ({$result['source']}, {$result['confidence_pct']}%)"
                        : "  flagged {$file->filename} as a scan");
                }
            } catch (Throwable $e) {
                $summary['errors']++;
                Log::error('FilesScanDateFix: processing failed', [
                    'file_id' => $file->id,
                    'error_class' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Every AI-estimated row is already included in flagged; do not double
        // count it in scheduler throughput and adaptive-timeout training.
        $summary['items_processed'] = $summary['flagged'];
        $summary['review_backlog_after'] = $useAI ? $this->pendingReviewCount() : 0;
        $summary['status'] = $summary['stopped_reason'] === null ? 'completed' : 'checkpointed';
        $this->emitSummary($summary);

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function processOne(
        object $file,
        PhotoDateExtractionService $dateService,
        ?AIService $aiService,
        bool $detectOnly,
    ): array {
        $result = [
            'flagged' => false,
            'ai_done' => false,
            'skipped' => false,
            'error' => false,
            'estimated_year' => null,
            'source' => null,
            'confidence_pct' => null,
        ];

        $localPath = $file->current_path;
        if (! file_exists($localPath)) {
            $result['error'] = true;

            return $result;
        }

        $scanContext = $file->scan_context;
        if (! $scanContext) {
            $scanContext = $dateService->isScanContext($file->current_path, $file->filename);
            if (! $scanContext && $file->date_taken && strtotime($file->date_taken) > mktime(0, 0, 0, 1, 1, 2000)) {
                $signals = $dateService->detectOldPhotoSignals($localPath);
                if ($signals['is_grayscale']) {
                    $scanContext = 'grayscale_recent_exif';
                }
            }
        }

        if (! $scanContext && ! $file->is_scan) {
            $result['skipped'] = true;

            return $result;
        }

        $scanDate = $file->scan_date ?? $file->date_taken;
        if (! $file->is_scan || $file->scan_date === null) {
            DB::update(
                "UPDATE file_registry
                 SET is_scan = 1, scan_context = COALESCE(scan_context, ?),
                     scan_date = COALESCE(scan_date, ?), date_taken = NULL,
                     date_taken_source = 'scan_exif', updated_at = NOW()
                 WHERE id = ?",
                [$scanContext, $scanDate, $file->id]
            );
        } else {
            DB::update(
                'UPDATE file_registry SET is_scan = 1, scan_context = COALESCE(scan_context, ?), updated_at = NOW() WHERE id = ?',
                [$scanContext, $file->id]
            );
        }
        $result['flagged'] = true;

        if ($detectOnly || $aiService === null) {
            return $result;
        }

        $aiResult = $dateService->estimateDateWithAIScanContext(
            $localPath,
            $aiService,
            $scanDate,
            $scanContext,
            $file->filename,
            $file->current_path
        );
        $year = (int) substr((string) ($aiResult['date'] ?? ''), 0, 4);
        if (! ($aiResult['success'] ?? false) || $year < 1800) {
            DB::update('UPDATE file_registry SET date_needs_review = 1 WHERE id = ?', [$file->id]);

            return $result;
        }

        $confidence = (float) $aiResult['confidence'];
        $yearRange = is_array($aiResult['year_range'] ?? null) ? $aiResult['year_range'] : null;
        $precision = $yearRange !== null ? 'year_range' : 'year';
        $source = match (true) {
            $confidence >= 0.65 => 'ai_visual_high',
            $confidence >= 0.45 => 'ai_visual_medium',
            default => 'ai_visual_low',
        };

        DB::update(
            'UPDATE file_registry
             SET date_taken = ?, date_taken_precision = ?, date_taken_source = ?, date_taken_confidence = ?,
                 date_taken_reasoning = ?, date_extracted_at = NOW(),
                 date_needs_review = 1, exif_written = 0, updated_at = NOW()
             WHERE id = ?',
            [$aiResult['date'], $precision, $source, $confidence, $aiResult['reasoning'], $file->id]
        );

        $result['ai_done'] = true;
        $result['source'] = $source;
        $result['precision'] = $precision;
        $result['year_range'] = $yearRange;
        $result['estimated_year'] = substr((string) $aiResult['date'], 0, 4);
        $result['confidence_pct'] = round($confidence * 100);

        return $result;
    }

    /** @return array{0:string,1:list<mixed>} */
    private function buildQuery(string $imageExtensions, ?string $folder, bool $reprocess, bool $useAI): array
    {
        $conditions = ["fr.status = 'active'", "fr.extension IN ({$imageExtensions})"];
        $bindings = [];
        $keywordParts = [];
        foreach (PhotoDateExtractionService::SCAN_PATH_KEYWORDS as $keyword) {
            $keywordParts[] = 'fr.current_path LIKE ?';
            $bindings[] = '%'.$keyword.'%';
        }
        $scanPathWhere = '('.implode(' OR ', $keywordParts).')';

        if ($folder !== null) {
            $conditions[] = 'fr.current_path LIKE ?';
            $bindings[] = '%'.$folder.'%';
        } else {
            $conditions[] = "({$scanPathWhere} OR fr.is_scan = 1)";
        }

        $conditions[] = $reprocess
            ? "fr.date_taken_source IN ('exif_original','exif_digitized','exif_modified','scan_exif')"
            : 'fr.date_taken_source NOT IN ('.self::AI_SOURCES.",'user_manual')";
        if ($useAI) {
            $conditions[] = "fr.date_taken_source NOT IN ('ai_visual_high')";
        }

        return [implode(' AND ', $conditions), $bindings];
    }

    private function pendingReviewCount(): int
    {
        return (int) (DB::selectOne(
            "SELECT COUNT(*) AS total
             FROM file_registry
             WHERE status = 'active' AND is_scan = 1 AND date_needs_review = 1
               AND date_taken IS NOT NULL
               AND date_taken_source IN (".self::AI_SOURCES.')
               AND COALESCE(exif_written, 0) <> 1'
        )->total ?? 0);
    }

    /** @return array<string,mixed> */
    private function newSummary(string $mode, int $limit, int $timeBudget, int $reviewBacklog): array
    {
        return [
            'schema' => 'plos.files_scan_date_run.v1',
            'mode' => $mode,
            'status' => 'ready',
            'selected' => 0,
            'attempted' => 0,
            'flagged' => 0,
            'ai_estimated' => 0,
            'staged_for_review' => 0,
            'skipped' => 0,
            'errors' => 0,
            'stopped_reason' => null,
            'items_processed' => 0,
            'review_backlog_before' => $reviewBacklog,
            'review_backlog_after' => $reviewBacklog,
            'metadata_file_writes' => 0,
            'limit' => $limit,
            'time_budget_seconds' => $timeBudget,
            'policy_source' => ScanDateRuntimePolicyService::CONFIG_KEY,
            'scheduler_marker' => '[ITEMS_PROCESSED:0]',
        ];
    }

    /** @param list<object> $files @param array<string,mixed> $summary */
    private function emitPreview(array $files, array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->emitSummary($summary);

            return;
        }

        $this->info('Read-only preview: '.count($files).' scan candidates; no catalog or file metadata changes.');
        foreach (array_slice($files, 0, 15) as $file) {
            $this->line("  [{$file->date_taken_source}] {$file->date_taken}  {$file->filename}");
        }
        if (count($files) > 15) {
            $this->line('  ... and '.(count($files) - 15).' more');
        }
        $this->line('[ITEMS_PROCESSED:0]');
    }

    /** @param array<string,mixed> $summary */
    private function emitSummary(array $summary): void
    {
        $summary['scheduler_marker'] = '[ITEMS_PROCESSED:'.(int) $summary['items_processed'].']';
        if ((bool) $this->option('json')) {
            $flags = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
            if (! (bool) $this->option('compact')) {
                $flags |= JSON_PRETTY_PRINT;
            }
            $this->line(json_encode($summary, $flags));

            return;
        }

        $this->info(sprintf(
            'Scan-date run %s: selected=%d attempted=%d flagged=%d staged=%d errors=%d stop=%s metadata_file_writes=0',
            $summary['status'],
            $summary['selected'],
            $summary['attempted'],
            $summary['flagged'],
            $summary['staged_for_review'],
            $summary['errors'],
            $summary['stopped_reason'] ?? 'none',
        ));
        $this->line($summary['scheduler_marker']);
    }

    private function showStats(): int
    {
        $stats = DB::selectOne(
            "SELECT SUM(is_scan) AS total_scan_flagged,
                    SUM(CASE WHEN is_scan = 1 AND date_taken IS NULL THEN 1 ELSE 0 END) AS pending_ai,
                    SUM(CASE WHEN date_taken_source = 'ai_visual_high' THEN 1 ELSE 0 END) AS ai_high,
                    SUM(CASE WHEN date_taken_source = 'ai_visual_medium' THEN 1 ELSE 0 END) AS ai_medium,
                    SUM(CASE WHEN date_taken_source = 'ai_visual_low' THEN 1 ELSE 0 END) AS ai_low,
                    SUM(CASE WHEN date_taken_source = 'scan_exif' THEN 1 ELSE 0 END) AS scan_exif_only,
                    SUM(CASE WHEN is_scan = 1 AND date_needs_review = 1 THEN 1 ELSE 0 END) AS needs_review,
                    SUM(CASE WHEN is_scan = 1 AND exif_written = 1 THEN 1 ELSE 0 END) AS legacy_metadata_written
             FROM file_registry WHERE status = 'active'"
        );
        $keywordParts = array_fill(0, count(PhotoDateExtractionService::SCAN_PATH_KEYWORDS), 'current_path LIKE ?');
        $bindings = array_map(static fn (string $keyword): string => '%'.$keyword.'%', PhotoDateExtractionService::SCAN_PATH_KEYWORDS);
        $undetected = (int) (DB::selectOne(
            "SELECT COUNT(*) AS total FROM file_registry
             WHERE status = 'active' AND is_scan = 0
               AND date_taken_source IN ('exif_original','exif_digitized')
               AND (".implode(' OR ', $keywordParts).')',
            $bindings
        )->total ?? 0);

        $payload = [
            'schema' => 'plos.files_scan_date_stats.v1',
            'scan_flagged' => (int) ($stats->total_scan_flagged ?? 0),
            'pending_ai' => (int) ($stats->pending_ai ?? 0),
            'scan_exif_only' => (int) ($stats->scan_exif_only ?? 0),
            'ai_high' => (int) ($stats->ai_high ?? 0),
            'ai_medium' => (int) ($stats->ai_medium ?? 0),
            'ai_low' => (int) ($stats->ai_low ?? 0),
            'needs_review' => (int) ($stats->needs_review ?? 0),
            'review_hub_ready' => $this->pendingReviewCount(),
            'legacy_metadata_written' => (int) ($stats->legacy_metadata_written ?? 0),
            'undetected_reprocess_candidates' => $undetected,
            'metadata_file_writes_enabled' => false,
        ];

        if ((bool) $this->option('json')) {
            $flags = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
            if (! (bool) $this->option('compact')) {
                $flags |= JSON_PRETTY_PRINT;
            }
            $this->line(json_encode($payload, $flags));

            return self::SUCCESS;
        }

        $this->table(['Metric', 'Count'], [
            ['Scan-flagged images total', $payload['scan_flagged']],
            ['Pending AI estimation', $payload['pending_ai']],
            ['Flagged only (no AI yet)', $payload['scan_exif_only']],
            ['AI estimated - high confidence', $payload['ai_high']],
            ['AI estimated - medium confidence', $payload['ai_medium']],
            ['AI estimated - low confidence', $payload['ai_low']],
            ['Needs human review', $payload['needs_review']],
            ['Review Hub ready (file untouched)', $payload['review_hub_ready']],
            ['Legacy metadata already written', $payload['legacy_metadata_written']],
            ['Undetected (--reprocess needed)', $payload['undetected_reprocess_candidates']],
        ]);
        $this->line('Automatic XMP/IPTC/EXIF writes are disabled for this pipeline.');

        return self::SUCCESS;
    }

    private function nullableOption(string $name): ?string
    {
        $value = trim((string) $this->option($name));

        return $value === '' ? null : $value;
    }

    private function inputError(string $message): int
    {
        $this->error($message);

        return self::INVALID;
    }
}
