<?php

namespace App\Console\Commands;

use App\Services\AIAutoTagService;
use App\Services\AIService;
use App\Services\ExifWritebackService;
use App\Services\FaceEmbeddingService;
use App\Services\FileRegistryService;
use App\Services\NextcloudFileApiService;
use App\Services\PerceptualHashService;
use App\Services\PhotoAnalysisService;
use App\Services\PhotoDateExtractionService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * FileEnrichmentCommand - Unified file processing pipeline
 *
 * Consolidates all file enrichment tasks into a single command with proper
 * AIService integration for scheduled execution.
 *
 * Processing Types:
 * - phash: Perceptual hashing for duplicate detection (no AI)
 * - faces: Face detection using Python face_recognition (no AI)
 * - video: Video fingerprinting with FFmpeg (no AI)
 * - exif: EXIF date extraction (no AI)
 * - ai: AI-powered image/document analysis (uses AIService)
 * - writeback: Write metadata back to physical files (source of truth)
 * - all: Run all processing types
 *
 * Usage:
 *   php artisan files:enrich --type=phash --limit=100
 *   php artisan files:enrich --type=ai --limit=50
 *   php artisan files:enrich --type=writeback --limit=100
 *   php artisan files:enrich --type=all --limit=200
 *   php artisan files:enrich --status
 */
class FileEnrichmentCommand extends Command
{
    protected $signature = 'files:enrich
                            {--type=all : Processing type: phash, faces, video, exif, gps, ai, writeback, all}
                            {--limit=auto : Max files to process (number or "auto" for dynamic scaling)}
                            {--status : Show enrichment status only}
                            {--dry-run : Show what would be processed without doing it}
                            {--face-preview : Prepare anonymous private detector evidence for explicit selectors only}
                            {--asset-uuid=* : Explicit active asset UUIDs for face preview}
                            {--face-review-id=* : Explicit pending face review rows for face preview}
                            {--media-id=* : Explicit genealogy media rows for face preview}
                            {--tree-id= : Required exact tree scope for review or media selectors}
                            {--json : Emit face preview receipt JSON}
                            {--worker-id= : Worker ID for parallel execution (set by scheduler)}';

    protected $description = 'Unified file enrichment pipeline with AI integration';

    /** @see config/file_types.php */

    /**
     * Default limits per type when --limit=auto (used as base, scaled by system load)
     * These are the comfortable baseline for a 12-core, 32GB, single-GPU system
     */
    private const AUTO_LIMITS = [
        'phash' => ['base' => 1000, 'max' => 5000,  'cpu_bound' => true],
        'faces' => ['base' => 2000, 'max' => 10000, 'cpu_bound' => true],
        'video' => ['base' => 50,   'max' => 200,   'cpu_bound' => true],
        'exif' => ['base' => 2000, 'max' => 5000,  'cpu_bound' => false],
        'ai' => ['base' => 300,  'max' => 2000,  'cpu_bound' => false, 'llm_bound' => true],
        'writeback' => ['base' => 2000, 'max' => 5000,  'cpu_bound' => false],
    ];

    /** Type-specific default seconds per item (bootstrap only — superseded by live data) */
    private const LLM_DEFAULT_SECONDS = [
        'ai' => 25,
        'rag' => 4,
    ];

    /** Map enrichment type to scheduled_jobs.name for DB lookups */
    private const JOB_NAME_MAP = [
        'ai' => 'file_enrich_ai',
        'rag' => 'rag_file_bulk_index',
    ];

    public function handle(): int
    {
        if ($this->option('face-preview')) {
            return $this->handleFacePreview();
        }
        if ($this->option('asset-uuid') || $this->option('face-review-id') || $this->option('media-id')) {
            $this->error('Explicit face selectors require --face-preview --type=faces.');

            return self::FAILURE;
        }
        if ($this->option('status')) {
            return $this->showStatus();
        }

        $type = $this->option('type');
        $limitOption = $this->option('limit');
        $dryRun = $this->option('dry-run');
        $workerId = $this->option('worker-id');

        // Dynamic limit calculation
        $limit = $this->resolveLimit($limitOption, $type);

        $isAutoLimit = ($limitOption === 'auto' || $limitOption === null);

        $this->info('=== File Enrichment Pipeline ===');
        $this->info("Type: {$type}, Limit: {$limit}".($isAutoLimit ? ' (auto-scaled)' : '').($dryRun ? ' (DRY RUN)' : '').($workerId ? " (worker: {$workerId})" : ''));

        $results = [
            'started_at' => now()->toDateTimeString(),
            'type' => $type,
            'tasks' => [],
        ];
        $failedStage = false;

        $types = $type === 'all'
            ? ['phash', 'faces', 'video', 'exif', 'gps', 'ai', 'writeback']
            : [$type];

        foreach ($types as $taskType) {
            // When running --type=all with auto limits, each type gets its own optimal limit
            $taskLimit = ($type === 'all' && $isAutoLimit) ? $this->resolveLimit('auto', $taskType) : $limit;

            if ($taskLimit <= 0) {
                $this->warn("\n--- Skipping: {$taskType} (no healthy providers) ---");
                $results['tasks'][$taskType] = ['processed' => 0, 'errors' => 0, 'skipped' => 'no_providers'];

                continue;
            }

            $this->line("\n--- Processing: {$taskType} (limit: {$taskLimit}) ---");

            $taskResult = match ($taskType) {
                'phash' => $this->processPerceptualHashes($taskLimit, $dryRun),
                'faces' => $this->processFaceDetection($taskLimit, $dryRun),
                'video' => $this->processVideoHashes($taskLimit, $dryRun),
                'exif' => $this->processExifDates($taskLimit, $dryRun),
                'gps' => $this->processGpsGeocoding($taskLimit, $dryRun),
                'ai' => $this->processAIAnalysis($taskLimit, $dryRun),
                'writeback' => $this->processMetadataWriteback($taskLimit, $dryRun),
                default => ['error' => "Unknown type: {$taskType}"],
            };

            $results['tasks'][$taskType] = $taskResult;

            if ($taskType === 'gps') {
                $this->info('  Extracted: '.($taskResult['backfill_extracted'] ?? 0).' GPS coords');
                $this->info('  Geocoded: '.($taskResult['geocoded'] ?? 0).' locations');
            } else {
                $this->info('  Processed: '.($taskResult['processed'] ?? 0));
                $this->info('  Errors: '.($taskResult['errors'] ?? 0));
                if ($taskType === 'exif') {
                    $this->info('  Checked: '.($taskResult['checked'] ?? 0).'; no date found: '.($taskResult['no_date'] ?? 0));
                    foreach ($taskResult['error_reasons'] ?? [] as $reason => $count) {
                        $this->warn("  Held: {$reason} ({$count})");
                    }
                }
            }
            if (! empty($taskResult['failure_details'])) {
                $this->line('[ENRICHMENT_FAILURES:'.json_encode(['type' => $taskType,
                    'failures' => $taskResult['failure_details'],
                    'omitted' => max(0, ($taskResult['errors'] ?? 0) - count($taskResult['failure_details'])),
                ], JSON_THROW_ON_ERROR).']');
            }
            $progress = ($taskType === 'exif' ? ($taskResult['checked'] ?? 0) : ($taskResult['processed'] ?? 0))
                + ($taskResult['geocoded'] ?? 0) + ($taskResult['backfill_extracted'] ?? 0);
            if ($progress === 0 && (($taskResult['errors'] ?? 0) > 0 || isset($taskResult['error']))) {
                $failedStage = true;
                break; // A failed prerequisite must not be hidden by a later optimization.
            }
        }

        $results['completed_at'] = now()->toDateTimeString();

        // Release claims only after an executing run; previews must not change them.
        if ($workerId && ! $dryRun) {
            DB::update('UPDATE file_registry SET claim_worker = NULL, claim_expires_at = NULL WHERE claim_worker = ?', [$workerId]);
        }

        // Emit structured items_processed marker for ScheduledJobService to parse
        $totalProcessed = 0;
        foreach ($results['tasks'] as $type => $task) {
            $totalProcessed += ($type === 'exif' ? ($task['checked'] ?? $task['processed'] ?? 0) : ($task['processed'] ?? 0))
                + ($task['geocoded'] ?? 0) + ($task['backfill_extracted'] ?? 0);
        }
        $this->line("[ITEMS_PROCESSED:{$totalProcessed}]");

        Log::info('FileEnrichmentCommand completed', $results);

        return $failedStage ? Command::FAILURE : Command::SUCCESS;
    }

    private function handleFacePreview(): int
    {
        $assets = array_values(array_unique((array) $this->option('asset-uuid')));
        $reviews = array_values(array_unique((array) $this->option('face-review-id')));
        $media = array_values(array_unique((array) $this->option('media-id')));
        $maximum = min(5, max(1, (int) config('file_registry.face_preview.max_assets', 5)));
        $tree = $this->option('tree-id');
        if ($this->option('type') !== 'faces' || $this->option('worker-id') || $this->option('status')
            || count($assets) + count($reviews) + count($media) < 1
            || count($assets) + count($reviews) + count($media) > $maximum
            || (($reviews !== [] || $media !== []) && (! ctype_digit((string) $tree) || (int) $tree < 1))) {
            $this->error('Face preview requires --type=faces, 1 to '.$maximum.' explicit selectors, and --tree-id for review/media selectors.');

            return self::FAILURE;
        }
        foreach ($assets as $uuid) {
            if (! Str::isUuid($uuid)) {
                $this->error('Invalid asset UUID.');

                return self::FAILURE;
            }
        }
        foreach (array_merge($reviews, $media) as $id) {
            if (! ctype_digit((string) $id) || (int) $id < 1) {
                $this->error('Review and media selectors must be positive integers.');

                return self::FAILURE;
            }
        }
        $selectors = array_merge(
            array_map(fn ($uuid) => ['asset_uuid' => $uuid], $assets),
            array_map(fn ($id) => ['face_review_id' => (int) $id, 'tree_id' => (int) $tree], $reviews),
            array_map(fn ($id) => ['media_id' => (int) $id, 'tree_id' => (int) $tree], $media),
        );
        $service = app(FaceEmbeddingService::class);
        $receipts = [];
        foreach ($selectors as $selector) {
            $receipts[] = $service->previewFaces($selector, (bool) $this->option('dry-run'));
        }
        // Only receipts reach CLI output; boxes and crops live in the private packet.
        $this->line(json_encode([
            'mode' => $this->option('dry-run') ? 'dry_run' : 'anonymous_face_preview',
            'database_writes' => ! $this->option('dry-run')
                && count(array_filter($receipts, fn ($receipt) => ($receipt['suggestions_published'] ?? false) === true)) > 0,
            'database_write_scope' => 'anonymous_face_preview_indexes',
            'identity_writes' => false, 'operator_identity_decision_required' => true,
            'receipts' => $receipts,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return count(array_filter($receipts, fn ($receipt) => ! ($receipt['success'] ?? false))) > 0
            ? self::FAILURE : self::SUCCESS;
    }

    private static ?int $cpuCores = null;

    /**
     * Resolve the processing limit — either explicit number or auto-scaled based on system state
     *
     * Auto-scaling uses additive adjustments (not multiplicative) to prevent compounding:
     * - System load average (backs off when load > 80% of cores)
     * - Available memory (backs off below 4GB free)
     * - Backlog size (scales up when large backlog, down when nearly caught up)
     * - Time of day (scales up during off-hours 1AM-6AM)
     */
    private function resolveLimit(string $limitOption, string $type): int
    {
        // Explicit numeric limit — use as-is
        if (is_numeric($limitOption)) {
            return (int) $limitOption;
        }

        $config = self::AUTO_LIMITS[$type] ?? ['base' => 200, 'max' => 1000, 'cpu_bound' => true];
        $base = $config['base'];
        $max = $config['max'];

        // Additive adjustment as percentage of base (-50% to +150%)
        $adjustment = 0.0;

        // 1. System load check (CPU-bound tasks back off under high load)
        if ($config['cpu_bound']) {
            $loadAvg = sys_getloadavg();
            $load1min = $loadAvg[0] ?? 0;
            if (self::$cpuCores === null) {
                self::$cpuCores = (int) (trim(Process::timeout(5)->run(['nproc'])->output() ?: '4'));
            }
            $loadRatio = $load1min / self::$cpuCores;

            if ($loadRatio > 0.8) {
                $adjustment -= 0.40; // High load — reduce 40%
            } elseif ($loadRatio < 0.3) {
                $adjustment += 0.30; // Low load — increase 30%
            }
        }

        // 2. Available memory check
        $memInfo = @file_get_contents('/proc/meminfo');
        if ($memInfo && preg_match('/MemAvailable:\s+(\d+)\s+kB/', $memInfo, $m)) {
            $availableGB = (int) $m[1] / 1024 / 1024;
            if ($availableGB < 4) {
                $adjustment -= 0.30; // Low memory — reduce 30%
            } elseif ($availableGB > 16) {
                $adjustment += 0.20; // Plenty of memory — increase 20%
            }
        }

        // 3. Off-hours boost (9 PM - 6 AM local time)
        $hour = (int) now()->format('H');
        if ($hour >= 21 || $hour <= 6) {
            $adjustment += 0.50; // Off-hours — increase 50%
        }

        // 4. Backlog-aware scaling: scale up when far behind, scale down when nearly done
        $pending = $this->getPendingCount($type);
        if ($pending >= 0) {
            if ($pending > 10000) {
                $adjustment += 0.50; // Large backlog — push harder
            } elseif ($pending < 100) {
                $adjustment -= 0.40; // Nearly caught up — gentle
            }
        }
        // pending < 0 = error sentinel — skip adjustment, don't scale on broken data

        // Clamp total adjustment to -50% to +150% of base
        $adjustment = max(-0.50, min(1.50, $adjustment));
        $limit = (int) round($base * (1.0 + $adjustment));
        $limit = max(10, min($limit, $max));

        // 5. LLM-bound cap: ensure batch fits within dynamic target wall-clock time
        if ($config['llm_bound'] ?? false) {
            $avgSecondsPerItem = $this->getLlmSecondsPerItem($type);
            $targetMinutes = $this->getBatchTargetMinutes($type);
            $capacityFactor = $this->getLlmCapacityFactor();

            if ($capacityFactor <= 0.0) {
                Log::warning("FileEnrichment: No healthy LLM providers — skipping {$type} batch");

                return 0;
            }

            $llmCap = (int) floor(($targetMinutes * 60) / $avgSecondsPerItem);
            $llmCap = (int) round($llmCap * $capacityFactor);
            $llmCap = max(10, min($llmCap, $max));

            if ($limit > $llmCap) {
                Log::info("FileEnrichment: LLM-aware cap applied for {$type}", [
                    'original' => $limit, 'capped' => $llmCap,
                    'avg_s_per_item' => $avgSecondsPerItem,
                    'target_min' => $targetMinutes,
                    'capacity_factor' => $capacityFactor,
                ]);
                $limit = $llmCap;
            }
        }

        // Parallel-aware: when running as a parallel worker, reduce limit
        // so multiple workers don't each grab a full batch
        if ($this->option('worker-id')) {
            $limit = (int) round($limit * 0.6);
            $limit = max(10, $limit);
        }

        return $limit;
    }

    /**
     * Get approximate pending count for a pipeline type (used for auto-scaling)
     */
    private function getPendingCount(string $type): int
    {
        $imageExts = "'".implode("','", config('file_types.image'))."'";
        $pending = AIAutoTagService::pendingAnalysisPredicate();
        $aiPending = $pending['sql'];
        $allAiExts = "'".implode("','", array_merge(config('file_types.image'), config('file_types.document')))."'";
        try {
            return match ($type) {
                'faces' => (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND face_scan_at IS NULL AND extension IN ({$imageExts})")->c ?? 0),
                'ai' => (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND {$aiPending} AND extension IN ({$allAiExts})", $pending['bindings'])->c ?? 0),
                'exif' => (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND (exif_checked IS NULL OR exif_checked = 0) AND extension IN ({$imageExts})")->c ?? 0),
                'writeback' => $this->getMetadataWritebackPendingCount($imageExts),
                'phash' => (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry fr WHERE status = 'active' AND extension IN ({$imageExts}) AND NOT EXISTS (SELECT 1 FROM file_registry_perceptual_hashes ph WHERE ph.file_registry_id = fr.id)")->c ?? 0),
                default => 1000, // Unknown — assume moderate backlog
            };
        } catch (Exception $e) {
            Log::warning('FileEnrichment: getPendingCount failed', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return -1;
        }
    }

    private function getMetadataWritebackPendingCount(string $imageExts): int
    {
        $writebackService = app(ExifWritebackService::class);
        $trustedFaceWritebackExists = $writebackService->faceWritebackExistsSql('fr', 'frf', 'fip');

        return (int) (DB::selectOne("
            SELECT COUNT(*) as c
            FROM file_registry fr
            WHERE fr.status = 'active'
            AND fr.extension IN ({$imageExts})
            AND (
                (fr.date_taken IS NOT NULL
                 AND (COALESCE(fr.date_taken_precision, 'exact') = 'exact' OR (fr.date_taken_precision = 'year' AND fr.date_taken_source = 'user_manual'))
                 AND fr.date_taken_source NOT LIKE 'exif_%'
                 AND (fr.exif_written IS NULL OR fr.exif_written = 0)
                 AND fr.date_taken_confidence >= 0.3)
                OR
                (fr.gps_location IS NOT NULL AND fr.gps_location != ''
                 AND (fr.exif_location_written IS NULL OR fr.exif_location_written = 0))
                OR
                ((fr.exif_faces_written IS NULL OR fr.exif_faces_written = 0)
                 AND {$trustedFaceWritebackExists})
            )
        ")->c ?? 0);
    }

    /**
     * Get actual LLM processing time per item using 3-tier fallback:
     *
     * Tier 1: scheduled_job_runs (most accurate — includes all overhead)
     *         AVG(duration_seconds / items_processed) from last 7 days, ≥3 data points
     * Tier 2: llm_instances.avg_response_ms (live LLM performance × 1.5 overhead)
     * Tier 3: Type-specific defaults (bootstrap only)
     */
    private function getLlmSecondsPerItem(string $type): float
    {
        $defaultSeconds = self::LLM_DEFAULT_SECONDS[$type] ?? 25;
        $jobName = self::JOB_NAME_MAP[$type] ?? null;

        // Tier 1: Real job history with structured items_processed column
        if ($jobName) {
            try {
                $recent = DB::selectOne("
                    SELECT AVG(duration_seconds / items_processed) as avg_seconds,
                           COUNT(*) as sample_count
                    FROM scheduled_job_runs sjr
                    JOIN scheduled_jobs sj ON sj.id = sjr.scheduled_job_id
                    WHERE sj.name = ?
                      AND sjr.status = 'success'
                      AND sjr.items_processed > 0
                      AND sjr.duration_seconds > 0
                      AND sjr.completed_at > NOW() - INTERVAL 7 DAY
                    ORDER BY sjr.completed_at DESC
                    LIMIT 10
                ", [$jobName]);

                if ($recent && $recent->sample_count >= 3 && $recent->avg_seconds > 0) {
                    Log::debug('FileEnrichment: getLlmSecondsPerItem tier=job_history', [
                        'type' => $type, 'avg_seconds' => round($recent->avg_seconds, 1),
                        'samples' => $recent->sample_count,
                    ]);

                    return (float) $recent->avg_seconds;
                }
            } catch (Exception $e) {
                // Fall through to tier 2
            }
        }

        // Tier 2: Live LLM avg_response_ms from primary Ollama instance
        try {
            $instance = DB::selectOne("
                SELECT avg_response_ms FROM llm_instances
                WHERE instance_type = 'ollama' AND is_active = 1 AND avg_response_ms > 0
                ORDER BY priority ASC
                LIMIT 1
            ");

            if ($instance && $instance->avg_response_ms > 0) {
                // Convert ms to seconds with 1.5x overhead (file I/O, prompt building, parsing)
                $llmSeconds = ($instance->avg_response_ms / 1000) * 1.5;
                Log::debug('FileEnrichment: getLlmSecondsPerItem tier=llm_avg_response', [
                    'type' => $type, 'avg_response_ms' => $instance->avg_response_ms,
                    'estimated_seconds' => round($llmSeconds, 1),
                ]);

                return max(2.0, $llmSeconds); // floor at 2s sanity
            }
        } catch (Exception $e) {
            // Fall through to tier 3
        }

        // Tier 3: Type-specific defaults (bootstrap)
        Log::debug('FileEnrichment: getLlmSecondsPerItem tier=default', [
            'type' => $type, 'default_seconds' => $defaultSeconds,
        ]);

        return $defaultSeconds;
    }

    /**
     * Get LLM capacity factor based on healthy provider availability.
     *
     * Multiple providers + skip_if_busy → 1.3 (external APIs absorb overflow)
     * Only external APIs (Ollama circuits open) → 0.6 (rate-limited)
     * Zero healthy providers → 0.0 (skip batch)
     */
    private function getLlmCapacityFactor(): float
    {
        try {
            $stats = DB::selectOne("
                SELECT
                    COUNT(*) as total_healthy,
                    SUM(CASE WHEN instance_type = 'ollama' AND circuit_state != 'open' THEN 1 ELSE 0 END) as healthy_ollama,
                    SUM(CASE WHEN instance_type NOT IN ('ollama', 'claude_cli', 'anthropic_api') THEN 1 ELSE 0 END) as healthy_external
                FROM llm_instances
                WHERE is_active = 1 AND is_healthy = 1 AND circuit_state != 'open'
            ");

            if (! $stats || $stats->total_healthy == 0) {
                return 0.0;
            }

            // Ollama available + external APIs available → boost
            if ($stats->healthy_ollama > 0 && $stats->healthy_external > 0) {
                return 1.3;
            }

            // Only external APIs (all Ollama circuits open) → reduce for rate limits
            if ($stats->healthy_ollama == 0) {
                return 0.6;
            }

            // Ollama only, no external — normal throughput
            return 1.0;
        } catch (Exception $e) {
            return 1.0; // Safe default
        }
    }

    /**
     * Derive batch target minutes from the job's timeout_minutes.
     * Target = 66% of timeout (complete in 2/3, 1/3 buffer). Clamped [20, 120].
     */
    private function getBatchTargetMinutes(string $type): int
    {
        $jobName = self::JOB_NAME_MAP[$type] ?? null;

        if ($jobName) {
            try {
                $job = DB::selectOne('SELECT timeout_minutes FROM scheduled_jobs WHERE name = ? LIMIT 1', [$jobName]);
                if ($job && $job->timeout_minutes > 0) {
                    $target = (int) round($job->timeout_minutes * 0.66);

                    return max(20, min(120, $target));
                }
            } catch (Exception $e) {
                // Fall through to default
            }
        }

        return 60; // default
    }

    /**
     * Show current enrichment status
     */
    private function showStatus(): int
    {
        $this->info("=== File Enrichment Status ===\n");

        // Total files
        $total = DB::selectOne("SELECT COUNT(*) as count FROM file_registry WHERE status = 'active'")->count ?? 0;
        $this->line('Total Active Files: '.number_format($total));

        // Image count
        $imageExts = "'".implode("','", config('file_types.image'))."'";
        $images = DB::selectOne("SELECT COUNT(*) as count FROM file_registry WHERE status = 'active' AND extension IN ({$imageExts})")->count ?? 0;
        $this->line('Images: '.number_format($images));

        // Perceptual hashes
        $phashDone = DB::selectOne('SELECT COUNT(*) as count FROM file_registry_perceptual_hashes')->count ?? 0;
        $phashPending = $images - $phashDone;
        $this->line('  Perceptual Hashes: '.number_format($phashDone).' done, '.number_format(max(0, $phashPending)).' pending');

        // Face detection
        $facesDone = DB::selectOne('SELECT COUNT(DISTINCT file_registry_id) as count FROM file_registry_faces')->count ?? 0;
        $facesPending = $images - $facesDone;
        $this->line('  Face Detection: '.number_format($facesDone).' done, '.number_format(max(0, $facesPending)).' pending');

        // EXIF dates
        $exifDone = DB::selectOne("SELECT COUNT(*) as count FROM file_registry WHERE status = 'active' AND date_taken IS NOT NULL AND extension IN ({$imageExts})")->count ?? 0;
        $exifPending = $images - $exifDone;
        $this->line('  EXIF Dates: '.number_format($exifDone).' done, '.number_format(max(0, $exifPending)).' pending');

        // Videos
        $videoExts = "'".implode("','", config('file_types.video'))."'";
        $videos = DB::selectOne("SELECT COUNT(*) as count FROM file_registry WHERE status = 'active' AND extension IN ({$videoExts})")->count ?? 0;
        $this->line("\nVideos: ".number_format($videos));

        $videoHashDone = DB::selectOne("SELECT COUNT(*) as count FROM file_registry WHERE status = 'active' AND video_hash IS NOT NULL AND extension IN ({$videoExts})")->count ?? 0;
        $videoHashPending = $videos - $videoHashDone;
        $this->line('  Video Hashes: '.number_format($videoHashDone).' done, '.number_format(max(0, $videoHashPending)).' pending');

        // These categories describe recorded state; only images have source-hash proof.
        $aiCounts = AIAutoTagService::getAnalysisCounts();
        $this->line("\nAI Analysis:");
        $this->line('  Recorded History: '.number_format($aiCounts['analyzed_count']).' (includes legacy skip timestamps)');
        $this->line('  Source-bound Images: '.number_format($aiCounts['source_bound_image_count']).' (stored catalog/provenance binding)');
        $this->line('  Current-version Documents: '.number_format($aiCounts['current_version_document_count']).' (no source-byte proof)');
        $this->line('  Pending Supported Work: '.number_format($aiCounts['pending_count']));
        $this->line('  Pending includes eligible retries; processing, skipped and held failures are excluded. Unqualified records are not source-bound.');

        // Metadata Writeback (physical file = source of truth)
        $this->line("\n--- Metadata Writeback Status ---");

        // Date writeback pending
        $dateWritebackPending = DB::selectOne("
            SELECT COUNT(*) as count
            FROM file_registry
            WHERE date_taken IS NOT NULL
            AND (COALESCE(date_taken_precision, 'exact') = 'exact' OR (date_taken_precision = 'year' AND date_taken_source = 'user_manual'))
            AND date_taken_source NOT LIKE 'exif_%'
            AND (exif_written IS NULL OR exif_written = 0)
            AND extension IN ({$imageExts})
            AND date_taken_confidence >= 0.3
        ")->count ?? 0;
        $dateWritebackDone = DB::selectOne('SELECT COUNT(*) as count FROM file_registry WHERE exif_written = 1')->count ?? 0;
        $this->line('Date Writeback: '.number_format($dateWritebackDone).' done, '.number_format($dateWritebackPending).' pending');

        // Face writeback pending
        try {
            $writebackService = app(ExifWritebackService::class);
            $trustedFaceWritebackExists = $writebackService->faceWritebackExistsSql('fr', 'ff', 'fip');
            $faceWritebackPending = DB::selectOne("
                SELECT COUNT(*) as count
                FROM file_registry fr
                WHERE (fr.exif_faces_written IS NULL OR fr.exif_faces_written = 0)
                AND fr.status = 'active'
                AND fr.extension IN ({$imageExts})
                AND {$trustedFaceWritebackExists}
            ")->count ?? 0;
            $faceWritebackDone = DB::selectOne('SELECT COUNT(*) as count FROM file_registry WHERE exif_faces_written = 1')->count ?? 0;
            $this->line('Face Writeback: '.number_format($faceWritebackDone).' done, '.number_format($faceWritebackPending).' pending');
        } catch (Exception $e) {
            $this->line('Face Writeback: (column not yet added)');
        }

        // Tag writeback pending
        try {
            $tagWritebackPending = DB::selectOne("
                SELECT COUNT(*) as count
                FROM file_registry
                WHERE ai_tags IS NOT NULL AND ai_tags != '[]' AND ai_tags != 'null'
                AND (exif_tags_written IS NULL OR exif_tags_written = 0)
                AND extension IN ({$imageExts})
            ")->count ?? 0;
            $tagWritebackDone = DB::selectOne('SELECT COUNT(*) as count FROM file_registry WHERE exif_tags_written = 1')->count ?? 0;
            $this->line('Tag Writeback: '.number_format($tagWritebackDone).' done, '.number_format($tagWritebackPending).' pending');
        } catch (Exception $e) {
            $this->line('Tag Writeback: (table/column not yet added)');
        }

        return Command::SUCCESS;
    }

    /**
     * Process perceptual hashes for images
     */
    private function processPerceptualHashes(int $limit, bool $dryRun): array
    {
        $imageExts = "'".implode("','", config('file_types.image'))."'";

        $files = DB::select("
            SELECT fr.id, fr.asset_uuid, fr.current_path, fr.filename
            FROM file_registry fr
            WHERE fr.status = 'active'
            AND fr.extension IN ({$imageExts})
            AND fr.phash_error IS NULL
            AND NOT EXISTS (
                SELECT 1 FROM file_registry_perceptual_hashes ph
                WHERE ph.file_registry_id = fr.id
            )
            ORDER BY fr.created_at DESC
            LIMIT ?
        ", [$limit]);

        if ($dryRun) {
            return ['pending' => count($files), 'processed' => 0, 'errors' => 0];
        }

        $processed = 0;
        $errors = 0;

        $phash = app(PerceptualHashService::class);

        // Extensions that can crash Imagick at C level — process in isolated subprocess
        $riskyExtensions = ['tif', 'tiff', 'gif'];

        foreach ($files as $file) {
            try {
                $localPath = $file->current_path;

                if (! file_exists($localPath)) {
                    $errors++;
                    Log::debug('FileEnrichment phash: file not found', ['path' => $localPath]);
                    $this->markPhashError($file->id, 'file_missing: File not found on disk');

                    continue;
                }

                $ext = strtolower(pathinfo($file->filename, PATHINFO_EXTENSION));
                if (in_array($ext, $riskyExtensions)) {
                    // Subprocess isolation: Imagick can corrupt memory on certain TIFFs/GIFs
                    $result = $this->computePhashInSubprocess($localPath);
                    if ($result === null) {
                        $errors++;
                        $this->markPhashError($file->id, 'subprocess_crash: Imagick aborted on this file');

                        continue;
                    }
                    $hashResult = $result;
                } else {
                    $hashResult = $phash->computeHash($localPath);
                }

                DB::insert('
                    INSERT INTO file_registry_perceptual_hashes
                    (file_registry_id, dhash_hex, dhash_int_hi, dhash_int_lo, phash_hex, phash_int, algorithm_version, computed_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ', [
                    $file->id,
                    $hashResult['dhash_hex'],
                    $hashResult['dhash_int_hi'],
                    $hashResult['dhash_int_lo'],
                    $hashResult['phash_hex'],
                    $hashResult['phash_int'],
                    '1.0',
                ]);

                $processed++;

            } catch (Exception $e) {
                $errors++;
                $errorMsg = $e->getMessage();
                $actualMime = file_exists($file->current_path) ? (mime_content_type($file->current_path) ?: 'unknown') : 'file_missing';

                Log::warning('FileEnrichment phash failed', [
                    'file_id' => $file->id,
                    'uuid' => $file->asset_uuid,
                    'extension' => pathinfo($file->filename, PATHINFO_EXTENSION),
                    'actual_mime' => $actualMime,
                    'error' => $errorMsg,
                    'path' => $file->current_path,
                ]);

                $this->markPhashError($file->id, substr($actualMime.': '.$errorMsg, 0, 255));
            }
        }

        return ['processed' => $processed, 'errors' => $errors, 'pending' => count($files) - $processed - $errors];
    }

    /**
     * Run phash computation in an isolated subprocess to protect against Imagick C-level crashes.
     * Returns hash result array on success, null if subprocess crashed.
     */
    private function computePhashInSubprocess(string $filePath): ?array
    {
        $php = PHP_BINARY;

        // Inline PHP script that boots the app, computes hash, outputs JSON
        $script = <<<'PHPCMD'
require $argv[1] . '/vendor/autoload.php';
$app = require_once $argv[1] . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
try {
    $svc = app('App\Services\PerceptualHashService');
    $result = $svc->computeHash($argv[2]);
    echo json_encode(['ok' => true, 'data' => $result]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
PHPCMD;

        $result = Process::timeout(120)->run([
            $php,
            '-r',
            $script,
            base_path(),
            $filePath,
        ]);
        $output = preg_split('/\r?\n/', trim($result->output())) ?: [];
        $exitCode = $result->exitCode();

        if ($exitCode !== 0 || empty($output)) {
            return null; // Process crashed (SIGABRT, segfault, etc.)
        }

        $result = json_decode(implode('', $output), true);
        if (! $result || ! ($result['ok'] ?? false)) {
            // PHP-level error in subprocess — treat as regular error
            $errorMsg = $result['error'] ?? 'Unknown subprocess error';
            throw new Exception($errorMsg);
        }

        return $result['data'];
    }

    /**
     * Mark a file with phash_error and insert a skip marker.
     */
    private function markPhashError(int $fileId, string $error): void
    {
        try {
            DB::update('UPDATE file_registry SET phash_error = ? WHERE id = ?', [
                substr($error, 0, 255),
                $fileId,
            ]);
        } catch (Exception $e) {
            // best effort
        }

        try {
            DB::insert('
                INSERT IGNORE INTO file_registry_perceptual_hashes
                (file_registry_id, dhash_hex, dhash_int_hi, dhash_int_lo, phash_hex, phash_int, algorithm_version, computed_at)
                VALUES (?, ?, 0, 0, NULL, NULL, ?, NOW())
            ', [$fileId, str_repeat('0', 32), 'skipped']);
        } catch (Exception $e) {
            // best effort
        }
    }

    /**
     * Process face detection for images.
     * Uses claim-based file selection when running with --worker-id for parallel safety.
     */
    private function processFaceDetection(int $limit, bool $dryRun): array
    {
        $imageExts = "'".implode("','", config('file_types.image'))."'";
        $workerId = $this->option('worker-id');

        if ($workerId && ! $dryRun) {
            // Claim-based: atomically claim unclaimed files
            $claimExpiry = now()->addMinutes(30)->format('Y-m-d H:i:s');
            DB::update("
                UPDATE file_registry
                SET claim_worker = ?, claim_expires_at = ?
                WHERE status = 'active'
                AND extension IN ({$imageExts})
                AND face_scan_at IS NULL
                AND (claim_worker IS NULL OR claim_expires_at < NOW())
                ORDER BY created_at DESC
                LIMIT ?
            ", [$workerId, $claimExpiry, $limit]);

            $files = DB::select('
                SELECT fr.id, fr.asset_uuid, fr.current_path, fr.filename
                FROM file_registry fr
                WHERE fr.claim_worker = ?
                AND fr.face_scan_at IS NULL
            ', [$workerId]);
        } else {
            $files = DB::select("
                SELECT fr.id, fr.asset_uuid, fr.current_path, fr.filename
                FROM file_registry fr
                WHERE fr.status = 'active'
                AND fr.extension IN ({$imageExts})
                AND fr.face_scan_at IS NULL
                ORDER BY fr.created_at DESC
                LIMIT ?
            ", [$limit]);
        }

        if ($dryRun) {
            return ['pending' => count($files), 'processed' => 0, 'errors' => 0];
        }

        $processed = 0;
        $errors = 0;

        $pythonScript = base_path('scripts/face_detector.py');
        $outputDir = storage_path('app/face_batch');
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Resolve virtual Nextcloud paths and admit only readable files within its configured root.
        $nextcloud = app(NextcloudFileApiService::class);
        $dataPath = trim((string) config('services.nextcloud.data_path', ''));
        $dataRoot = $dataPath === '' ? false : realpath($dataPath);
        $validFiles = [];
        foreach ($files as $file) {
            $sourcePath = (string) $file->current_path;
            if ($sourcePath === '' || str_contains($sourcePath, "\0") || str_contains($sourcePath, '\\')
                || in_array('..', explode('/', $sourcePath), true)) {
                $errors++;

                continue;
            }
            $localPath = realpath($sourcePath) ?: $nextcloud->localPath($sourcePath);
            $physical = is_string($localPath) ? realpath($localPath) : false;
            if ($dataRoot === false || $physical === false
                || ! str_starts_with($physical, rtrim($dataRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
                || ! is_file($physical) || ! is_readable($physical)) {
                // Keep unavailable evidence pending; absence is not a successful zero-face scan.
                $errors++;

                continue;
            }
            $validFiles[$physical] = $file;
        }

        if (empty($validFiles)) {
            if ($workerId) {
                DB::update('UPDATE file_registry SET claim_worker = NULL, claim_expires_at = NULL WHERE claim_worker = ?', [$workerId]);
            }

            return ['processed' => $processed, 'errors' => $errors];
        }

        // Perceptual similarity is review evidence; every source needs its own detection.
        // Process in batches using Python batch mode (avoids ~2.5s startup per image)
        $batchSize = config('compute.face_detection_batch_size', 50);
        $chunks = array_chunk(array_keys($validFiles), $batchSize);

        foreach ($chunks as $chunkIndex => $paths) {
            try {
                $listFile = $outputDir."/batch_{$chunkIndex}.txt";
                file_put_contents($listFile, implode("\n", $paths));

                $batchOutputDir = $outputDir."/results_{$chunkIndex}";
                if (! is_dir($batchOutputDir)) {
                    mkdir($batchOutputDir, 0755, true);
                }

                $output = Process::timeout(300)->run([
                    'python3',
                    $pythonScript,
                    '--batch',
                    $listFile,
                    '--output-dir',
                    $batchOutputDir,
                ])->output();
                $batchResult = json_decode($output, true);

                if ($batchResult && $batchResult['success'] && isset($batchResult['results'])) {
                    $reportedPaths = [];
                    foreach ($batchResult['results'] as $imgResult) {
                        $imgPath = $imgResult['image_path'] ?? $imgResult['image'] ?? '';
                        $file = $validFiles[$imgPath] ?? null;
                        if (! $file || isset($reportedPaths[$imgPath])) {
                            continue;
                        }
                        $reportedPaths[$imgPath] = true;

                        if ($this->publishDetectedFaces($file, $imgPath, $imgResult)) {
                            $this->applyXmpNamesToAiFaces($file->id, $imgPath);
                            $processed++;
                        } else {
                            // Detector failures leave the evidence pending for review.
                            $errors++;
                        }
                    }
                    $errors += count(array_diff($paths, array_keys($reportedPaths)));
                } else {
                    // Batch failed entirely — fall back to single-image mode for this chunk
                    foreach ($paths as $path) {
                        $file = $validFiles[$path] ?? null;
                        if (! $file) {
                            continue;
                        }
                        try {
                            $singleOutput = Process::timeout(120)->run([
                                'python3',
                                $pythonScript,
                                '--image',
                                $path,
                            ])->output();
                            $result = json_decode($singleOutput, true);
                            if (is_array($result) && $this->publishDetectedFaces($file, $path, $result)) {
                                $this->applyXmpNamesToAiFaces($file->id, $path);
                                $processed++;
                            } else {
                                $errors++;
                            }
                        } catch (Exception $e) {
                            $errors++;
                        }
                    }
                }

                // Cleanup temp files
                @unlink($listFile);
                @array_map('unlink', glob("{$batchOutputDir}/*.json"));
                @rmdir($batchOutputDir);

            } catch (Exception $e) {
                // Preserve pending source evidence when the batch cannot complete.
                $errors += count($paths);
                Log::error('FileEnrichment face batch error', ['error' => $e->getMessage()]);
            }
        }

        // Cleanup batch dir
        @array_map('unlink', glob("{$outputDir}/batch_*.txt"));

        // Release claims
        if ($workerId) {
            DB::update('UPDATE file_registry SET claim_worker = NULL, claim_expires_at = NULL WHERE claim_worker = ?', [$workerId]);
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    /** Bind one detector result to its source before publishing any derived rows. */
    private function publishDetectedFaces(object $file, string $path, array $result): bool
    {
        $sourceHash = $result['source_sha256'] ?? null;
        if (($result['success'] ?? false) !== true || ! is_array($result['faces'] ?? null)
            || ! is_string($sourceHash) || ! preg_match('/^[a-f0-9]{64}$/D', $sourceHash)
            || (isset($result['image_path']) && $result['image_path'] !== $path)) {
            return false;
        }
        foreach ($result['faces'] as $face) {
            if (($face['source_sha256'] ?? null) !== $sourceHash || ($face['face_region_convention'] ?? null) !== 'normalized_top_left') {
                return false;
            }
            foreach (['x', 'y', 'w', 'h'] as $axis) {
                $value = $face['normalized'][$axis] ?? null;
                if (! is_numeric($value) || ! is_finite((float) $value)) {
                    return false;
                }
            }
            $box = $face['normalized'];
            if ($box['x'] < 0 || $box['y'] < 0 || $box['w'] <= 0 || $box['h'] <= 0
                || $box['x'] + $box['w'] > 1 || $box['y'] + $box['h'] > 1) {
                return false;
            }
        }
        try {
            $published = DB::transaction(function () use ($file, $path, $result, $sourceHash): bool {
                $current = DB::selectOne('SELECT current_path, status FROM file_registry WHERE id = ? FOR UPDATE', [$file->id]);
                $hash = @hash_file('sha256', $path);
                if (! $current || $current->status !== 'active' || $current->current_path !== $file->current_path
                    || ! is_string($hash) || ! hash_equals($sourceHash, $hash)) {
                    return false;
                }
                $driftHold = app(FaceEmbeddingService::class)->legacySourceDriftHoldReason((int) $file->id, $sourceHash, true);
                if ($driftHold !== null) {
                    Log::warning('Face detection publication held', ['file_id' => $file->id, 'reason' => $driftHold]);

                    return false;
                }
                DB::update('UPDATE file_registry_faces SET source_current = 0, superseded_at = COALESCE(superseded_at, CURRENT_TIMESTAMP)
                    WHERE file_registry_id = ? AND source_current = 1 AND (source_sha256 IS NULL OR source_sha256 <> ?)', [$file->id, $sourceHash]);
                foreach ($result['faces'] as $index => $face) {
                    $box = $face['normalized'];
                    $stored = DB::selectOne("SELECT source_sha256, face_region_convention, source_current, superseded_at, region_x, region_y, region_w, region_h
                        FROM file_registry_faces WHERE file_registry_id = ? AND face_index = ? AND source = 'ai_detection' AND source_sha256 = ?", [$file->id, $index, $sourceHash]);
                    if (! $stored) {
                        DB::insert("INSERT IGNORE INTO file_registry_faces
                        (file_registry_id, face_index, person_name, region_x, region_y, region_w, region_h,
                        confidence, source, embedding, source_sha256, face_region_convention, source_generation_key, source_current, detected_at, created_at, updated_at)
                        VALUES (?, ?, '', ?, ?, ?, ?, ?, 'ai_detection', ?, ?, ?, ?, 1, NOW(), NOW(), NOW())", [
                            $file->id, $index, $box['x'], $box['y'], $box['w'], $box['h'], $face['confidence'] ?? 0.9,
                            json_encode($face['embedding'] ?? []), $sourceHash, 'normalized_top_left', $sourceHash,
                        ]);
                        $stored = DB::selectOne("SELECT source_sha256, face_region_convention, source_current, superseded_at, region_x, region_y, region_w, region_h
                        FROM file_registry_faces WHERE file_registry_id = ? AND face_index = ? AND source = 'ai_detection' AND source_sha256 = ?", [$file->id, $index, $sourceHash]);
                    }
                    if (! $stored || empty($stored->source_current) || ! empty($stored->superseded_at) || ($stored->source_sha256 ?? null) !== $sourceHash || ($stored->face_region_convention ?? null) !== 'normalized_top_left') {
                        throw new \RuntimeException('Existing source generation requires review');
                    }
                    foreach (['x', 'y', 'w', 'h'] as $axis) {
                        if (round((float) $stored->{'region_'.$axis}, 8) !== round((float) $box[$axis], 8)) {
                            throw new \RuntimeException('Existing detection region requires review');
                        }
                    }
                }
                $hash = @hash_file('sha256', $path);
                if (! is_string($hash) || ! hash_equals($sourceHash, $hash)) {
                    throw new \RuntimeException('Source changed before detection publication');
                }
                DB::update('UPDATE file_registry SET face_scan_at = NOW(), face_count = ? WHERE id = ?', [count($result['faces']), $file->id]);

                return true;
            });
            if ($published) {
                try {
                    DB::connection('pgsql_rag')->update('UPDATE face_embeddings SET source_current = FALSE, superseded_at = COALESCE(superseded_at, CURRENT_TIMESTAMP)
                        WHERE file_registry_id = ? AND source_current = TRUE AND (source_sha256 IS NULL OR source_sha256 <> ?)', [$file->id, $sourceHash]);
                } catch (\Throwable $e) {
                    Log::warning('Older face vectors could not be superseded', ['file_id' => $file->id, 'exception' => $e::class]);
                }
                foreach ($result['faces'] as $index => $face) {
                    if (! $this->insertPgvectorAndCluster($file->id, $face, (int) $index)) {
                        break; // Uncertain grouping stops further publication, never a retry.
                    }
                }
            }

            return $published;
        } catch (\Throwable $e) {
            Log::warning('Face detection publication held', ['file_id' => $file->id, 'exception' => $e::class]);

            return false;
        }
    }

    /**
     * Insert a face embedding into pgvector and attempt cluster assignment.
     * Called inline during face detection for incremental clustering.
     */
    private function insertPgvectorAndCluster(int $fileRegistryId, array $face, int $faceIndex): bool
    {
        $embedding = $face['embedding'] ?? [];
        if (! is_array($embedding) || count($embedding) < 64 || $faceIndex < 0) {
            return true;
        }
        foreach ($embedding as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                return true;
            }
        }

        try {
            $embeddingService = app(FaceEmbeddingService::class);
            if (! $embeddingService->isAvailable()) {
                return true;
            }

            // INSERT IGNORE may retain an older row; bind only the current unnamed region.
            $faceRecord = DB::selectOne("
                SELECT id, region_x, region_y, region_w, region_h, embedding, source_sha256, face_region_convention, source_current, superseded_at
                FROM file_registry_faces
                WHERE file_registry_id = ? AND source = 'ai_detection' AND face_index = ?
                  AND person_name = '' AND genealogy_person_id IS NULL AND COALESCE(verified, 0) = 0
                  AND source_sha256 = ? AND source_current = 1 AND superseded_at IS NULL
            ", [$fileRegistryId, $faceIndex, $face['source_sha256'] ?? null]);

            if (! $faceRecord || ! is_string($face['source_sha256'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/D', $face['source_sha256'])
                || ($faceRecord->source_sha256 ?? null) !== $face['source_sha256']
                || ($face['face_region_convention'] ?? null) !== 'normalized_top_left'
                || ($faceRecord->face_region_convention ?? null) !== 'normalized_top_left') {
                return true;
            }
            foreach (['x', 'y', 'w', 'h'] as $coordinate) {
                $current = $face['normalized'][$coordinate] ?? null;
                $stored = $faceRecord->{'region_'.$coordinate} ?? null;
                if (! is_numeric($current) || ! is_numeric($stored)
                    || ! is_finite((float) $current) || ! is_finite((float) $stored)
                    || round((float) $current, 8) !== round((float) $stored, 8)) {
                    return true;
                }
            }
            $storedEmbedding = json_decode($faceRecord->embedding ?? '', true);
            if (! is_array($storedEmbedding) || count($storedEmbedding) !== count($embedding)) {
                return true;
            }
            foreach ($embedding as $index => $value) {
                if (! isset($storedEmbedding[$index]) || ! is_numeric($storedEmbedding[$index])
                    || (float) $storedEmbedding[$index] !== (float) $value) {
                    return true;
                }
            }

            $publicationEvidence = (object) array_merge((array) $faceRecord, [
                'file_registry_id' => $fileRegistryId, 'file_registry_face_id' => (int) $faceRecord->id,
            ]);
            if (! $embeddingService->embeddingHasCurrentSourceEvidence($publicationEvidence)) {
                return true;
            }

            $pgConn = DB::connection('pgsql_rag');

            // Preserve existing vectors and memberships; cluster only a newly inserted row.
            $vectorStr = '['.implode(',', $embedding).']';
            $inserted = $pgConn->select('
                INSERT INTO face_embeddings (file_registry_id, file_registry_face_id, embedding, region_x, region_y, region_w, region_h, source_sha256, face_region_convention, source_current, superseded_at, created_at, updated_at)
                VALUES (?, ?, ?::vector, ?, ?, ?, ?, ?, ?, TRUE, NULL, NOW(), NOW())
                ON CONFLICT (file_registry_face_id)
                WHERE file_registry_face_id IS NOT NULL AND source_current IS TRUE AND superseded_at IS NULL
                DO NOTHING
                RETURNING id
            ', [$fileRegistryId, $faceRecord->id, $vectorStr, $faceRecord->region_x, $faceRecord->region_y, $faceRecord->region_w, $faceRecord->region_h, $face['source_sha256'], 'normalized_top_left']);
            $pgRow = $inserted[0] ?? null;
            if ($pgRow) {
                // The service owns guarded membership writes; retained IDs may use a different namespace.
                $grouping = $embeddingService->assignToCluster($pgRow->id, $embedding);
                if (($grouping['action'] ?? null) === 'unknown') {
                    Log::warning('Inline automatic grouping outcome is partial or unknown; no replay authorized', [
                        'file_id' => $fileRegistryId, 'face_embedding_id' => (int) $pgRow->id,
                        'reason' => $grouping['reason'] ?? 'matching_publication_outcome_unknown',
                        'automatic_replay_allowed' => false, 'technical_recovery_required' => true,
                    ]);

                    return false;
                }
            }

            return true;
        } catch (Exception $e) {
            // Face persistence is independent; grouping failures grant no replay authority.
            Log::debug('Inline pgvector/cluster failed', [
                'file_id' => $fileRegistryId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Import embedded source regions as unverified evidence without naming detector faces.
     * The legacy return value counts detector faces named, which remains zero.
     */
    private function applyXmpNamesToAiFaces(int $fileId, string $filePath): int
    {
        if (! is_file($filePath)) {
            return 0;
        }

        try {
            $file = DB::selectOne("SELECT asset_uuid FROM file_registry WHERE id = ? AND status = 'active'", [$fileId]);
            if (! $file || ! $file->asset_uuid) {
                return 0;
            }
            $result = app(FileRegistryService::class)->scanFileFaces($file->asset_uuid, $filePath);
            if (! ($result['success'] ?? false)) {
                Log::debug('XMP evidence import requires review', ['file_id' => $fileId]);
            }
        } catch (Exception $e) {
            Log::debug('XMP evidence import failed', ['file_id' => $fileId, 'error' => $e->getMessage()]);
        }

        return 0;
    }

    /**
     * Process video hashes
     */
    private function processVideoHashes(int $limit, bool $dryRun): array
    {
        $videoExts = "'".implode("','", config('file_types.video'))."'";

        $files = DB::select("
            SELECT fr.id, fr.asset_uuid, fr.current_path, fr.filename
            FROM file_registry fr
            WHERE fr.status = 'active'
            AND fr.extension IN ({$videoExts})
            AND fr.video_hash IS NULL
            ORDER BY fr.created_at DESC
            LIMIT ?
        ", [$limit]);

        if ($dryRun) {
            return ['pending' => count($files), 'processed' => 0, 'errors' => 0];
        }

        $processed = 0;
        $errors = 0;

        foreach ($files as $file) {
            try {
                // Use local filesystem path directly when the registry already stores one.
                $localPath = $file->current_path;

                if (! file_exists($localPath)) {
                    $errors++;
                    Log::debug('FileEnrichment video: file not found', ['path' => $localPath]);

                    continue;
                }

                // Generate video hash using ffmpeg directly from local file
                $ffmpegResult = Process::pipe(function ($pipe) use ($localPath) {
                    $pipe->command([
                        'ffmpeg',
                        '-i',
                        $localPath,
                        '-vf',
                        'select=eq(n\,0)+eq(n\,30)+eq(n\,60)+eq(n\,90),scale=8:8,format=gray',
                        '-f',
                        'rawvideo',
                        '-',
                    ]);
                    $pipe->command(['md5sum']);
                });
                $videoHash = trim(strtok($ffmpegResult->output(), ' '));

                if (! empty($videoHash)) {
                    DB::update('
                        UPDATE file_registry
                        SET video_hash = ?, updated_at = NOW()
                        WHERE id = ?
                    ', [$videoHash, $file->id]);
                    $processed++;
                } else {
                    $errors++;
                }

            } catch (Exception $e) {
                $errors++;
                Log::debug('FileEnrichment video error', ['file_id' => $file->id, 'error' => $e->getMessage()]);
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    /**
     * Process EXIF date extraction
     */
    private function processExifDates(int $limit, bool $dryRun): array
    {
        if ($limit <= 0) {
            return ['processed' => 0, 'checked' => 0, 'no_date' => 0, 'errors' => 0];
        }
        $imageExts = "'".implode("','", config('file_types.image'))."'";
        $files = DB::select("
            SELECT fr.id, fr.asset_uuid, fr.current_path, fr.filename, fr.nextcloud_modified_at, fr.file_size, fr.content_hash, fr.lifecycle_generation_key, fr.is_scan, fr.date_needs_review
            FROM file_registry fr
            WHERE fr.status = 'active'
            AND fr.extension IN ({$imageExts})
            AND fr.date_taken IS NULL
            AND (fr.exif_checked IS NULL OR fr.exif_checked = 0)
            ORDER BY fr.created_at DESC
            LIMIT ?
        ", [$limit]);
        if ($dryRun) {
            return ['pending' => count($files), 'processed' => 0, 'checked' => 0, 'no_date' => 0, 'errors' => 0];
        }

        $processed = $errors = $checked = $noDate = 0;
        $errorReasons = [];
        $failureDetails = [];
        $nextcloud = app(NextcloudFileApiService::class);
        $dataPath = trim((string) config('services.nextcloud.data_path', ''));
        $dataRoot = $dataPath === '' ? false : realpath($dataPath);
        foreach ($files as $file) {
            $failureStage = 'source';
            try {
                $sourcePath = (string) $file->current_path;
                if ($sourcePath === '' || str_contains($sourcePath, "\0") || str_contains($sourcePath, '\\')
                    || in_array('..', explode('/', $sourcePath), true)) {
                    throw new \RuntimeException('exif_source_unavailable');
                }
                $localPath = realpath($sourcePath) ?: $nextcloud->localPath($sourcePath);
                $physical = is_string($localPath) ? realpath($localPath) : false;
                if ($dataRoot === false || $physical === false
                    || ! str_starts_with($physical, rtrim($dataRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
                    || ! is_file($physical) || ! is_readable($physical)) {
                    throw new \RuntimeException('exif_source_unavailable');
                }
                $hashPath = $sourcePath;
                if (realpath($sourcePath) !== false) {
                    // Legacy physical paths are admitted only through an exact
                    // contained connector round-trip; the catalog path is unchanged.
                    $hashPath = '/'.ltrim(substr($physical, strlen(rtrim($dataRoot, DIRECTORY_SEPARATOR))), '/');
                    $bound = $nextcloud->localPath($hashPath);
                    if (! is_string($bound) || realpath($bound) !== $physical) {
                        throw new \RuntimeException('exif_source_unproven');
                    }
                }
                clearstatcache(true, $physical);
                $before = stat($physical);
                if ($before === false) {
                    throw new \RuntimeException('exif_source_unavailable');
                }
                $hashTimeout = config('file_registry.photo_review.hash_timeout_seconds');
                $toolTimeout = config('file_registry.exif_read.timeout_seconds');
                $executable = config('file_registry.exif_read.executable');
                if (! is_numeric($hashTimeout) || ! is_finite((float) $hashTimeout) || $hashTimeout <= 0
                    || ! is_numeric($toolTimeout) || ! is_finite((float) $toolTimeout) || $toolTimeout <= 0
                    || ! is_string($executable) || trim($executable) === '' || str_contains($executable, "\0")) {
                    throw new \RuntimeException('exif_read_policy_unavailable');
                }
                $deadline = hrtime(true) / 1e9 + (float) $hashTimeout;
                $fingerprintFields = array_flip(['dev', 'ino', 'mode', 'size', 'mtime', 'ctime']);
                $sourceProof = $nextcloud->computeFileHash($hashPath, $deadline, true);
                if (! ($sourceProof['success'] ?? false) || ! is_string($sourceProof['hash'] ?? null)
                    || ! preg_match('/^[a-f0-9]{64}$/D', $sourceProof['hash'])
                    || ($sourceProof['source_fingerprint'] ?? null) !== array_intersect_key($before, $fingerprintFields)) {
                    throw new \RuntimeException('exif_source_unproven');
                }
                $failureStage = 'php_exif';
                $exif = @exif_read_data($physical, 'ANY_TAG', true) ?: [];
                $gpsLat = $gpsLon = $cameraMake = $cameraModel = $rating = null;
                $cameraMake = isset($exif['IFD0']['Make']) ? trim($exif['IFD0']['Make']) : null;
                $cameraModel = isset($exif['IFD0']['Model']) ? trim($exif['IFD0']['Model']) : null;
                if (! empty($exif['GPS']['GPSLatitude']) && ! empty($exif['GPS']['GPSLongitude'])) {
                    $gpsLat = $this->gpsToDecimal($exif['GPS']['GPSLatitude'], $exif['GPS']['GPSLatitudeRef'] ?? 'N');
                    $gpsLon = $this->gpsToDecimal($exif['GPS']['GPSLongitude'], $exif['GPS']['GPSLongitudeRef'] ?? 'E');
                }
                $ratingRaw = $exif['IFD0']['Rating'] ?? $exif['EXIF']['Rating'] ?? null;
                if ($ratingRaw !== null && $ratingRaw >= 1 && $ratingRaw <= 5) {
                    $rating = (int) $ratingRaw;
                }

                // PHP cannot distinguish absent EXIF from a failed metadata read.
                // Only a successful complete tool response permits terminal checking.
                $failureStage = 'metadata_tool';
                $read = Process::timeout((float) $toolTimeout)->run([
                    $executable, '-json', '-G1', '-a', '-s', '-DateTimeOriginal', '-CreateDate', '-ModifyDate',
                    '-XMP-dc:Source', '-XMP-photoshop:DateCreated', '-IPTC:DateCreated',
                    '-IPTC:Keywords', '-XMP:Subject', '-IPTC:Caption-Abstract', '-XMP:Description', '-Rating', $physical,
                ]);
                if (! $read->successful()) {
                    throw new \RuntimeException('exif_metadata_read_failed');
                }
                $failureStage = 'metadata_decode';
                $decoded = json_decode($read->output(), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || ! array_is_list($decoded) || count($decoded) !== 1
                    || ! is_array($decoded[0]) || ($decoded[0]['SourceFile'] ?? null) !== $physical
                    || ! empty($decoded[0]['Error']) || ! empty($decoded[0]['Warning'])
                    || ! empty($decoded[0]['ExifTool:Error']) || ! empty($decoded[0]['ExifTool:Warning'])) {
                    throw new \RuntimeException('exif_metadata_read_invalid');
                }
                $et = $decoded[0];
                $dateService = app(PhotoDateExtractionService::class);
                $operatorDate = $dateService->operatorDateFromExifMetadata($et);
                $scanContext = $dateService->isScanContext($sourcePath, $file->filename);
                $isScan = (int) $file->is_scan === 1 || $scanContext !== false;
                $reviewHeld = (int) $file->date_needs_review === 1 || $operatorDate !== null || $isScan;
                // Grouped marker tags remain distinct; normalize only the ordinary requested fields.
                foreach ($et as $key => $value) {
                    $field = str_contains($key, ':') ? substr($key, strrpos($key, ':') + 1) : $key;
                    if (in_array($field, ['DateTimeOriginal', 'CreateDate', 'ModifyDate', 'Keywords', 'Subject',
                        'Caption-Abstract', 'Description', 'Rating'], true) && ! array_key_exists($field, $et)) {
                        $et[$field] = $value;
                    }
                }
                $dateTaken = $dateSource = null;
                // Same field/source classification as PhotoDateExtractionService.
                foreach ([
                    'exif_original' => $exif['EXIF']['DateTimeOriginal'] ?? $et['DateTimeOriginal'] ?? null,
                    'exif_digitized' => $exif['EXIF']['DateTimeDigitized'] ?? $et['CreateDate'] ?? null,
                    'exif_modified' => $exif['IFD0']['DateTime'] ?? $et['ModifyDate'] ?? null,
                ] as $source => $dateStr) {
                    $candidate = $dateService->parseExifDate($dateStr);
                    if ($candidate !== null && ! $reviewHeld) {
                        $dateTaken = $candidate;
                        $dateSource = $source;
                        break;
                    }
                }
                $kw = array_unique(array_filter(array_merge((array) ($et['Keywords'] ?? []), (array) ($et['Subject'] ?? []))));
                $exifKeywords = $kw ? implode(', ', $kw) : null;
                $exifCaption = $et['Caption-Abstract'] ?? $et['Description'] ?? null;
                if ($rating === null && isset($et['Rating']) && $et['Rating'] >= 1 && $et['Rating'] <= 5) {
                    $rating = (int) $et['Rating'];
                }

                clearstatcache();
                $after = stat($physical);
                $currentLocal = realpath($sourcePath) ?: $nextcloud->localPath($sourcePath);
                if ($after === false || ! is_string($currentLocal) || realpath($currentLocal) !== $physical) {
                    throw new \RuntimeException('exif_source_changed');
                }
                foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $field) {
                    if ($before[$field] !== $after[$field]) {
                        throw new \RuntimeException('exif_source_changed');
                    }
                }
                $afterProof = $nextcloud->computeFileHash($hashPath, hrtime(true) / 1e9 + (float) $hashTimeout, true);
                if (! ($afterProof['success'] ?? false) || ! is_string($afterProof['hash'] ?? null)
                    || ! hash_equals($sourceProof['hash'], $afterProof['hash'])
                    || ($afterProof['source_fingerprint'] ?? null) !== $sourceProof['source_fingerprint']) {
                    throw new \RuntimeException('exif_source_changed');
                }
                $failureStage = 'registry_publish';
                // MySQL evaluates SET left-to-right: classify before filling date_taken.
                // COALESCE retains dates and metadata supplied by an operator meanwhile.
                DB::transaction(function () use ($dateTaken, $dateSource, $gpsLat, $gpsLon, $cameraMake, $cameraModel,
                    $rating, $exifKeywords, $exifCaption, $file, $sourcePath, $hashPath, $nextcloud, $sourceProof, $hashTimeout, $reviewHeld, $isScan): void {
                    $updated = DB::update("
                    UPDATE file_registry
                    SET date_needs_review = CASE WHEN date_taken IS NULL AND ? = 1 THEN 1 ELSE date_needs_review END,
                        is_scan = CASE WHEN date_taken IS NULL AND ? = 1 THEN 1 ELSE is_scan END,
                        date_taken_source = CASE WHEN date_taken IS NULL AND ? IS NOT NULL THEN ? ELSE date_taken_source END,
                        date_taken        = COALESCE(date_taken, ?),
                        gps_latitude      = COALESCE(gps_latitude, ?),
                        gps_longitude     = COALESCE(gps_longitude, ?),
                        camera_make       = COALESCE(camera_make, ?),
                        camera_model      = COALESCE(camera_model, ?),
                        exif_rating       = COALESCE(exif_rating, ?),
                        exif_keywords     = COALESCE(exif_keywords, ?),
                        exif_caption      = COALESCE(exif_caption, ?),
                        exif_checked      = 1,
                        updated_at        = NOW()
                    WHERE id = ? AND status = 'active' AND BINARY current_path = BINARY ?
                        AND (exif_checked IS NULL OR exif_checked = 0)
                        AND is_scan <=> ? AND date_needs_review <=> ?
                        AND nextcloud_modified_at <=> ? AND file_size <=> ? AND BINARY content_hash <=> BINARY ?
                        AND BINARY lifecycle_generation_key <=> BINARY ?
                ", [(int) $reviewHeld, (int) $isScan, $dateTaken, $dateSource, $dateTaken, $gpsLat, $gpsLon, $cameraMake, $cameraModel,
                        $rating, $exifKeywords, $exifCaption, $file->id, $sourcePath, $file->is_scan, $file->date_needs_review, $file->nextcloud_modified_at,
                        $file->file_size, $file->content_hash, $file->lifecycle_generation_key]);
                    if ($updated !== 1) {
                        throw new \RuntimeException('exif_registry_changed');
                    }
                    $finalProof = $nextcloud->computeFileHash($hashPath, hrtime(true) / 1e9 + (float) $hashTimeout, true);
                    if (! ($finalProof['success'] ?? false) || ! is_string($finalProof['hash'] ?? null)
                        || ! hash_equals($sourceProof['hash'], $finalProof['hash'])
                        || ($finalProof['source_fingerprint'] ?? null) !== $sourceProof['source_fingerprint']) {
                        throw new \RuntimeException('exif_source_changed');
                    }

                });
                $checked++;
                if ($dateTaken !== null) {
                    $processed++;
                } else {
                    $noDate++;
                }
            } catch (\Throwable $e) {
                $errors++;
                // Failed stages retain their pending marker for recovery/retry.
                $knownReasons = ['exif_source_unavailable', 'exif_source_unproven', 'exif_source_changed',
                    'exif_metadata_read_failed', 'exif_metadata_read_invalid', 'exif_registry_changed', 'exif_read_policy_unavailable'];
                $reason = in_array($e->getMessage(), $knownReasons, true) ? $e->getMessage() : 'exif_read_exception';
                $errorReasons[$reason] = ($errorReasons[$reason] ?? 0) + 1;
                if (count($failureDetails) < config('file_registry.ai_analysis.failure_receipt_limit', 50)) {
                    $kind = match (true) {
                        $e instanceof QueryException => 'database',
                        $e instanceof \JsonException => 'json',
                        $e instanceof ProcessTimedOutException => 'timeout',
                        $e instanceof \TypeError => 'type',
                        $e instanceof \ErrorException => 'php_warning',
                        default => 'exception',
                    };
                    $databaseCodes = [];
                    if ($e instanceof QueryException) {
                        $errorInfo = $e->errorInfo ?? null;
                        $sqlState = is_array($errorInfo) ? ($errorInfo[0] ?? null) : null;
                        if (! is_string($sqlState) || ! preg_match('/\A[A-Za-z0-9]{5}\z/D', $sqlState)) {
                            $sqlState = $e->getCode();
                        }
                        if (is_string($sqlState) && preg_match('/\A[A-Za-z0-9]{5}\z/D', $sqlState)) {
                            $databaseCodes['sqlstate'] = strtoupper($sqlState);
                        }
                        $driverCode = is_array($errorInfo) ? ($errorInfo[1] ?? null) : null;
                        if (is_int($driverCode) && $driverCode >= 0 && $driverCode <= 2147483647) {
                            $databaseCodes['driver_error_code'] = $driverCode;
                        } elseif (is_string($driverCode) && preg_match('/\A[0-9]{1,10}\z/D', $driverCode)) {
                            $normalizedDriverCode = ltrim($driverCode, '0');
                            $parsedDriverCode = filter_var($normalizedDriverCode === '' ? '0' : $normalizedDriverCode, FILTER_VALIDATE_INT);
                            if ($parsedDriverCode !== false && $parsedDriverCode >= 0 && $parsedDriverCode <= 2147483647) {
                                $databaseCodes['driver_error_code'] = $parsedDriverCode;
                            }
                        }
                    }
                    $failureDetails[] = ['file_registry_id' => (int) $file->id, 'reason' => $reason,
                        'stage' => $failureStage, 'exception_kind' => $kind] + $databaseCodes;
                }
                Log::debug('FileEnrichment exif read held', ['file_id' => $file->id, 'reason' => $reason]);
            }
        }

        return ['processed' => $processed, 'checked' => $checked, 'no_date' => $noDate, 'errors' => $errors, 'error_reasons' => $errorReasons]
            + ($failureDetails === [] ? [] : ['failure_details' => $failureDetails]);
    }

    /**
     * GPS geocoding pass: two phases.
     * Phase 1 (backfill): re-read EXIF GPS for already-checked files missing gps_latitude.
     * Phase 2 (geocode): reverse-geocode files with coordinates but no location string.
     */
    private function processGpsGeocoding(int $limit, bool $dryRun): array
    {
        $imageExts = "'".implode("','", config('file_types.image'))."'";
        $half = max(1, intdiv($limit, 2));

        // Phase 1: backfill GPS coords from EXIF for already-checked files
        $backfill = DB::select("
            SELECT id, current_path FROM file_registry
            WHERE status = 'active'
              AND exif_checked = 1
              AND gps_latitude IS NULL
              AND extension IN ({$imageExts})
            ORDER BY id ASC
            LIMIT ?
        ", [$half]);

        $extracted = 0;
        if (! $dryRun) {
            $exiftool = '/usr/bin/exiftool';
            foreach ($backfill as $file) {
                if (! file_exists($file->current_path)) {
                    continue;
                }
                $gpsLat = $gpsLon = null;

                // Try PHP exif_read_data first
                $exif = @exif_read_data($file->current_path, 'GPS', true);
                if (! empty($exif['GPS']['GPSLatitude']) && ! empty($exif['GPS']['GPSLongitude'])) {
                    $gpsLat = $this->gpsToDecimal($exif['GPS']['GPSLatitude'], $exif['GPS']['GPSLatitudeRef'] ?? 'N');
                    $gpsLon = $this->gpsToDecimal($exif['GPS']['GPSLongitude'], $exif['GPS']['GPSLongitudeRef'] ?? 'E');
                }

                // Fallback: ExifTool for formats PHP misses (HEIC, etc.)
                if ($gpsLat === null && file_exists($exiftool)) {
                    $json = Process::timeout(30)->run([
                        $exiftool,
                        '-json',
                        '-GPSLatitude',
                        '-GPSLongitude',
                        $file->current_path,
                    ])->output();
                    if ($json) {
                        $et = json_decode($json, true)[0] ?? [];
                        if (! empty($et['GPSLatitude'])) {
                            $gpsLat = $this->parseExiftoolGps($et['GPSLatitude']);
                            $gpsLon = $this->parseExiftoolGps($et['GPSLongitude'] ?? '');
                        }
                    }
                }

                if ($gpsLat !== null && $gpsLon !== null) {
                    DB::update('UPDATE file_registry SET gps_latitude=?, gps_longitude=?, updated_at=NOW() WHERE id=?', [$gpsLat, $gpsLon, $file->id]);
                    $extracted++;
                }
            }
        }

        // Phase 2: reverse-geocode files with coordinates but no location yet
        $toGeocode = DB::select("
            SELECT id, gps_latitude, gps_longitude FROM file_registry
            WHERE status = 'active'
              AND gps_latitude IS NOT NULL
              AND gps_location IS NULL
            ORDER BY id ASC
            LIMIT ?
        ", [$half]);

        $geocoded = 0;
        if (! $dryRun) {
            $photoAnalysis = app(PhotoAnalysisService::class);
            foreach ($toGeocode as $file) {
                $location = $photoAnalysis->reverseGeocode((float) $file->gps_latitude, (float) $file->gps_longitude);
                DB::update(
                    'UPDATE file_registry SET gps_location=?, updated_at=NOW() WHERE id=?',
                    [$location ?? '', $file->id]   // empty string = attempted but no result
                );
                $geocoded++;
                usleep(1100000); // 1.1s — Nominatim 1 req/sec policy
            }
        }

        return ['backfill_extracted' => $extracted, 'geocoded' => $geocoded, 'pending_backfill' => count($backfill), 'pending_geocode' => count($toGeocode)];
    }

    /**
     * Convert GPS EXIF fraction array to decimal degrees.
     * Duplicated here to avoid coupling to PhotoAnalysisService internals.
     */
    private function gpsToDecimal(array $coord, string $ref): ?float
    {
        if (count($coord) !== 3) {
            return null;
        }
        $parts = array_map(function ($v) {
            if (is_numeric($v)) {
                return (float) $v;
            }
            $p = explode('/', $v);

            return (count($p) === 2 && $p[1] != 0) ? (float) $p[0] / (float) $p[1] : null;
        }, $coord);
        if (in_array(null, $parts, true)) {
            return null;
        }
        $decimal = $parts[0] + ($parts[1] / 60) + ($parts[2] / 3600);

        return round(in_array(strtoupper($ref), ['S', 'W']) ? -$decimal : $decimal, 6);
    }

    /**
     * Parse ExifTool GPS string (e.g. "41 deg 24' 32.40\" N") to decimal.
     */
    private function parseExiftoolGps($value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (preg_match('/(-?[\d.]+)\s*deg\s*([\d.]+)\'\s*([\d.]+)"?\s*([NSEW])?/i', $value, $m)) {
            $d = abs((float) $m[1]) + ((float) $m[2] / 60) + ((float) $m[3] / 3600);

            return round(isset($m[4]) && in_array(strtoupper($m[4]), ['S', 'W']) ? -$d : $d, 6);
        }

        return null;
    }

    /**
     * Process AI analysis using AIService.
     * Uses claim-based selection when running with --worker-id for parallel safety.
     */
    private function processAIAnalysis(int $limit, bool $dryRun): array
    {
        $workerId = $this->option('worker-id');
        $aiTagService = app(AIAutoTagService::class);
        $pending = AIAutoTagService::pendingAnalysisPredicate();
        $aiPending = $pending['sql'];
        if (! $dryRun) {
            $aiTagService->recoverOrphanedProcessingFiles();
        }

        if ($workerId && $dryRun) {
            $extensions = "'".implode("','", array_merge(config('file_types.image'), config('file_types.document')))."'";
            $files = DB::select("
                SELECT id FROM file_registry WHERE status = 'active'
                AND {$aiPending}
                AND extension IN ({$extensions})
                AND (claim_worker IS NULL OR claim_expires_at < NOW())
                ORDER BY created_at DESC LIMIT ?
            ", [...$pending['bindings'], $limit]);

            return ['pending' => count($files), 'processed' => 0, 'errors' => 0];
        }

        if ($workerId) {
            // Claim-based: atomically claim unclaimed files for AI analysis
            $imageExts = "'".implode("','", config('file_types.image'))."'";
            $docExts = "'".implode("','", config('file_types.document'))."'";
            $claimExpiry = now()->addMinutes(config('file_registry.ai_analysis.claim_minutes', 30))->format('Y-m-d H:i:s');

            DB::update("
                UPDATE file_registry
                SET claim_worker = ?, claim_expires_at = ?
                WHERE status = 'active'
                AND {$aiPending}
                AND extension IN ({$imageExts},{$docExts})
                AND (claim_worker IS NULL OR claim_expires_at < NOW())
                ORDER BY created_at DESC
                LIMIT ?
            ", [$workerId, $claimExpiry, ...$pending['bindings'], $limit]);

            $files = DB::select("SELECT id FROM file_registry WHERE claim_worker = ? AND status = 'active'
                AND {$aiPending} AND claim_expires_at > NOW()", [$workerId, ...$pending['bindings']]);
        } else {
            $files = $aiTagService->getUnanalyzedFiles($limit);
        }

        if ($dryRun) {
            return ['pending' => count($files), 'processed' => 0, 'errors' => 0];
        }

        if (empty($files)) {
            return ['processed' => 0, 'errors' => 0, 'message' => 'No files pending AI analysis'];
        }

        $fileIds = array_column($files, 'id');
        $results = $aiTagService->batchAnalyze($fileIds, 5, false, $workerId ?: null);

        // Release claims
        if ($workerId) {
            DB::update('UPDATE file_registry SET claim_worker = NULL, claim_expires_at = NULL WHERE claim_worker = ?', [$workerId]);
        }

        return [
            'processed' => $results['processed'] ?? 0,
            'errors' => $results['failed'] ?? 0,
            'skipped' => $results['skipped'] ?? 0,
            'failure_details' => $results['failure_details'] ?? [],
        ];
    }

    /**
     * Write metadata back to physical files (source of truth)
     * Uses ExifWritebackService to embed dates, faces, and tags
     */
    private function processMetadataWriteback(int $limit, bool $dryRun): array
    {
        $dataPath = trim((string) config('services.nextcloud.data_path', ''));
        if ($dataPath === '') {
            return ['processed' => 0, 'errors' => 0, 'message' => 'NEXTCLOUD_DATA_PATH not configured'];
        }

        $libraryRoot = '/'.trim((string) config('services.nextcloud.library_root', '/Library'), '/');
        $imageExts = "'".implode("','", config('file_types.image'))."'";

        $writebackService = app(ExifWritebackService::class);
        $trustedFaceWritebackExists = $writebackService->faceWritebackExistsSql('fr', 'frf', 'fip');

        // Get files needing writeback: derived dates, pending locations, or trusted face labels.
        $files = DB::select("
            SELECT fr.id, fr.current_path, fr.filename,
                   fr.date_taken, fr.date_taken_source, fr.date_taken_confidence
            FROM file_registry fr
            WHERE fr.status = 'active'
            AND fr.extension IN ({$imageExts})
            AND (
                (fr.date_taken IS NOT NULL AND fr.date_taken_source NOT LIKE 'exif_%'
                 AND (COALESCE(fr.date_taken_precision, 'exact') = 'exact' OR (fr.date_taken_precision = 'year' AND fr.date_taken_source = 'user_manual'))
                 AND (fr.exif_written IS NULL OR fr.exif_written = 0)
                 AND fr.date_taken_confidence >= 0.3)
                OR
                (fr.gps_location IS NOT NULL AND fr.gps_location != ''
                 AND (fr.exif_location_written IS NULL OR fr.exif_location_written = 0))
                OR
                ((fr.exif_faces_written IS NULL OR fr.exif_faces_written = 0)
                 AND {$trustedFaceWritebackExists})
            )
            ORDER BY fr.created_at DESC
            LIMIT ?
        ", [$limit]);

        if ($dryRun) {
            return ['pending' => count($files), 'processed' => 0, 'errors' => 0];
        }

        $processed = 0;
        $errors = 0;

        foreach ($files as $file) {
            try {
                $localPath = $this->resolveWritebackLocalPath((string) $file->current_path, $dataPath, $libraryRoot);
                if ($localPath === null) {
                    $errors++;

                    continue;
                }

                if (! file_exists($localPath)) {
                    DB::update('UPDATE file_registry SET exif_written = -1 WHERE id = ?', [$file->id]);
                    $errors++;

                    continue;
                }

                // Write all available metadata
                $result = $writebackService->writeAll($file->id, $localPath);

                if ($result['success']) {
                    if ($result['date']['success'] ?? false) {
                        DB::update('UPDATE file_registry SET exif_written = 1, exif_date_written_at = NOW() WHERE id = ?', [$file->id]);
                    }
                    if ($result['faces']['success'] ?? false) {
                        DB::update('UPDATE file_registry SET exif_faces_written = 1, exif_faces_written_at = NOW() WHERE id = ?', [$file->id]);
                    }
                    if ($result['tags']['success'] ?? false) {
                        DB::update('UPDATE file_registry SET exif_tags_written = 1, exif_tags_written_at = NOW() WHERE id = ?', [$file->id]);
                    }
                    DB::update('
                        UPDATE file_registry
                        SET metadata_synced_at = NOW()
                        WHERE id = ?
                    ', [$file->id]);
                    $processed++;
                } elseif (($result['code'] ?? null) === -2) {
                    Log::warning('FileEnrichment writeback halted - host lacks EXIF writeback permissions', [
                        'file_id' => $file->id,
                        'path' => $localPath,
                    ]);
                    $errors++;
                    break;
                } elseif (($result['code'] ?? null) === -3) {
                    Log::info('FileEnrichment writeback skipped - metadata writeback disabled', [
                        'file_id' => $file->id,
                        'path' => $localPath,
                    ]);
                    break;
                } else {
                    // Mark as error to prevent infinite retry
                    DB::update('UPDATE file_registry SET exif_written = -1 WHERE id = ?', [$file->id]);
                    $errors++;
                }

            } catch (Exception $e) {
                $errors++;
                DB::update('UPDATE file_registry SET exif_written = -1 WHERE id = ?', [$file->id]);
                Log::debug('FileEnrichment writeback error', ['file_id' => $file->id, 'error' => $e->getMessage()]);
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    private function resolveWritebackLocalPath(string $currentPath, string $dataPath, string $libraryRoot): ?string
    {
        $libraryRoot = '/'.trim($libraryRoot, '/');
        $ncPath = '/'.ltrim($currentPath, '/');
        $matchedLibraryRoot = str_starts_with(strtolower($ncPath), strtolower($libraryRoot));
        $diskLibraryRoot = $matchedLibraryRoot ? substr($ncPath, 0, strlen($libraryRoot)) : $libraryRoot;
        $basePath = rtrim($dataPath, '/').$diskLibraryRoot;
        $relativePath = $matchedLibraryRoot
            ? substr($ncPath, strlen($libraryRoot))
            : ltrim($ncPath, '/');

        $segments = [];
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..' || str_contains($segment, "\0")) {
                return null;
            }
            $segments[] = $segment;
        }

        return $basePath.(empty($segments) ? '' : '/'.implode('/', $segments));
    }
}
