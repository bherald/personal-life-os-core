<?php

namespace App\Console\Commands;

use App\Services\AIAutoTagService;
use App\Services\AIService;
use App\Services\ExifWritebackService;
use App\Services\PerceptualHashService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * FileEnrichmentCommand - Unified file processing pipeline
 *
 * Consolidates all file enrichment tasks into a single command with proper
 * AIService integration (Ollama + Claude slots) for scheduled execution.
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
            }
        }

        $results['completed_at'] = now()->toDateTimeString();

        // Release any stale claims from this worker
        if ($workerId) {
            DB::update('UPDATE file_registry SET claim_worker = NULL, claim_expires_at = NULL WHERE claim_worker = ?', [$workerId]);
        }

        // Emit structured items_processed marker for ScheduledJobService to parse
        $totalProcessed = 0;
        foreach ($results['tasks'] as $type => $task) {
            $totalProcessed += ($task['processed'] ?? 0) + ($task['geocoded'] ?? 0) + ($task['backfill_extracted'] ?? 0);
        }
        $this->line("[ITEMS_PROCESSED:{$totalProcessed}]");

        Log::info('FileEnrichmentCommand completed', $results);

        return Command::SUCCESS;
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
                self::$cpuCores = (int) (trim(\Illuminate\Support\Facades\Process::timeout(5)->run(['nproc'])->output() ?: '4'));
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
        $allAiExts = "'".implode("','", array_merge(config('file_types.image'), config('file_types.document')))."'";
        try {
            return match ($type) {
                'faces' => (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND face_scan_at IS NULL AND extension IN ({$imageExts})")->c ?? 0),
                'ai' => (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND ai_analyzed_at IS NULL AND (ai_analysis_version IS NULL OR (ai_analysis_version NOT IN ('skipped', 'processing') AND ai_analysis_version NOT LIKE 'fail:%')) AND extension IN ({$allAiExts})")->c ?? 0),
                'exif' => (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND (exif_checked IS NULL OR exif_checked = 0) AND extension IN ({$imageExts})")->c ?? 0),
                'writeback' => (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND (exif_written IS NULL OR exif_written = 0) AND extension IN ({$imageExts}) AND date_taken IS NOT NULL AND date_taken_source NOT LIKE 'exif_%'")->c ?? 0),
                'phash' => (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry fr WHERE status = 'active' AND extension IN ({$imageExts}) AND NOT EXISTS (SELECT 1 FROM file_registry_perceptual_hashes ph WHERE ph.file_registry_id = fr.id)")->c ?? 0),
                default => 1000, // Unknown — assume moderate backlog
            };
        } catch (\Exception $e) {
            Log::warning('FileEnrichment: getPendingCount failed', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return -1;
        }
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
            } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
                    SUM(CASE WHEN instance_type != 'ollama' AND instance_type != 'claude_cli' THEN 1 ELSE 0 END) as healthy_external
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
        } catch (\Exception $e) {
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
            } catch (\Exception $e) {
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

        // AI Analysis
        $aiDone = DB::selectOne("SELECT COUNT(*) as count FROM file_registry WHERE status = 'active' AND ai_analyzed_at IS NOT NULL")->count ?? 0;
        $aiSupported = $images + DB::selectOne("SELECT COUNT(*) as count FROM file_registry WHERE status = 'active' AND extension IN ('".implode("','", config('file_types.document'))."')")->count ?? 0;
        $aiPending = $aiSupported - $aiDone;
        $this->line("\nAI Analysis: ".number_format($aiDone).' done, '.number_format(max(0, $aiPending)).' pending');

        // Metadata Writeback (physical file = source of truth)
        $this->line("\n--- Metadata Writeback Status ---");

        // Date writeback pending
        $dateWritebackPending = DB::selectOne("
            SELECT COUNT(*) as count
            FROM file_registry
            WHERE date_taken IS NOT NULL
            AND date_taken_source NOT LIKE 'exif_%'
            AND (exif_written IS NULL OR exif_written = 0)
            AND extension IN ({$imageExts})
            AND date_taken_confidence >= 0.3
        ")->count ?? 0;
        $dateWritebackDone = DB::selectOne('SELECT COUNT(*) as count FROM file_registry WHERE exif_written = 1')->count ?? 0;
        $this->line('Date Writeback: '.number_format($dateWritebackDone).' done, '.number_format($dateWritebackPending).' pending');

        // Face writeback pending
        try {
            $faceWritebackPending = DB::selectOne("
                SELECT COUNT(DISTINCT fr.id) as count
                FROM file_registry fr
                INNER JOIN file_registry_faces ff ON ff.file_registry_id = fr.id
                WHERE (fr.exif_faces_written IS NULL OR fr.exif_faces_written = 0)
                AND fr.extension IN ({$imageExts})
            ")->count ?? 0;
            $faceWritebackDone = DB::selectOne('SELECT COUNT(*) as count FROM file_registry WHERE exif_faces_written = 1')->count ?? 0;
            $this->line('Face Writeback: '.number_format($faceWritebackDone).' done, '.number_format($faceWritebackPending).' pending');
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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

        if ($workerId) {
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

        // Filter to files that exist, build path-to-file map
        $validFiles = [];
        foreach ($files as $file) {
            $localPath = $file->current_path;
            if (! file_exists($localPath)) {
                // Mark as scanned with 0 faces so we don't retry missing files
                DB::update('UPDATE file_registry SET face_scan_at = NOW(), face_count = 0 WHERE id = ?', [$file->id]);
                $errors++;

                continue;
            }
            $validFiles[$localPath] = $file;
        }

        if (empty($validFiles)) {
            return ['processed' => $processed, 'errors' => $errors];
        }

        // Dedup guard: skip files whose content was already face-scanned via a duplicate copy.
        // Uses perceptual hash to find twins. Copies face data from the already-scanned twin.
        $deduped = 0;
        $toRemove = [];
        $validFileIds = array_values(array_map(fn ($f) => $f->id, $validFiles));
        if (! empty($validFileIds)) {
            $ph = implode(',', array_fill(0, count($validFileIds), '?'));
            // Find files that have a phash twin already scanned
            $dupeMatches = DB::select("
                SELECT f.id as new_id, f.current_path as new_path,
                       twin.id as twin_id
                FROM file_registry f
                INNER JOIN file_registry_perceptual_hashes h1 ON h1.file_registry_id = f.id
                INNER JOIN file_registry_perceptual_hashes h2
                    ON h2.dhash_hex = h1.dhash_hex AND h2.file_registry_id != f.id
                INNER JOIN file_registry twin
                    ON twin.id = h2.file_registry_id AND twin.face_scan_at IS NOT NULL AND twin.status = 'active'
                WHERE f.id IN ({$ph})
                GROUP BY f.id, f.current_path, twin.id
            ", $validFileIds);

            foreach ($dupeMatches as $dm) {
                $twinId = $dm->twin_id;
                $newId = $dm->new_id;
                $newPath = $dm->new_path;

                // Copy face_embeddings from twin's file_registry_id, pointing to new file
                $twinEmbeddings = DB::connection('pgsql_rag')->select('
                    SELECT embedding::text as emb_str, person_cluster_id, match_confidence,
                           region_x, region_y, region_w, region_h, crop_path, embedding_model
                    FROM face_embeddings WHERE file_registry_id = ?
                ', [$twinId]);

                // Copy file_registry_faces from twin
                $twinFaces = DB::select('
                    SELECT person_name, genealogy_person_id, face_index, region_x, region_y,
                           region_w, region_h, confidence, source, cluster_id, embedding
                    FROM file_registry_faces WHERE file_registry_id = ?
                ', [$twinId]);

                $twinFaceCount = DB::selectOne('SELECT face_count FROM file_registry WHERE id = ?', [$twinId]);

                // Insert face records for new file (MySQL)
                foreach ($twinFaces as $tf) {
                    DB::insert('
                        INSERT IGNORE INTO file_registry_faces
                        (file_registry_id, person_name, genealogy_person_id, face_index,
                         region_x, region_y, region_w, region_h, confidence, source, cluster_id,
                         embedding, detected_at, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                    ', [
                        $newId, $tf->person_name, $tf->genealogy_person_id, $tf->face_index,
                        $tf->region_x, $tf->region_y, $tf->region_w, $tf->region_h,
                        $tf->confidence, $tf->source, $tf->cluster_id, $tf->embedding,
                    ]);
                }

                // For pgvector: link new file's face records to existing clusters WITHOUT creating new embeddings.
                // The twin's embeddings already represent this face content — no need to duplicate vectors.
                // Just link the MySQL faces to the same clusters.
                $newFaces = DB::select('SELECT id FROM file_registry_faces WHERE file_registry_id = ?', [$newId]);
                foreach ($newFaces as $nf) {
                    // Check if pgvector row already exists for this face record
                    $existing = DB::connection('pgsql_rag')->selectOne('
                        SELECT id FROM face_embeddings WHERE file_registry_face_id = ?
                    ', [$nf->id]);
                    if (! $existing && ! empty($twinEmbeddings)) {
                        // Don't insert duplicate pgvector rows — the twin's embeddings are sufficient.
                        // The cluster already has the face represented.
                    }
                }

                // Mark as scanned with twin's face count
                DB::update('UPDATE file_registry SET face_scan_at = NOW(), face_count = ? WHERE id = ?',
                    [$twinFaceCount->face_count ?? 0, $newId]);

                $toRemove[] = $newPath;
                $deduped++;
            }
        }

        // Remove deduped files from processing queue
        foreach ($toRemove as $path) {
            unset($validFiles[$path]);
        }

        if ($deduped > 0) {
            $this->line("  Dedup: copied face data for {$deduped} duplicate files (skipped re-detection)");
        }

        if (empty($validFiles)) {
            return ['processed' => $processed + $deduped, 'errors' => $errors];
        }

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
                    foreach ($batchResult['results'] as $imgResult) {
                        $imgPath = $imgResult['image_path'] ?? $imgResult['image'] ?? '';
                        $file = $validFiles[$imgPath] ?? null;
                        if (! $file) {
                            continue;
                        }

                        if ($imgResult['success'] && isset($imgResult['faces'])) {
                            foreach ($imgResult['faces'] as $idx => $face) {
                                $norm = $face['normalized'] ?? [];
                                DB::insert("
                                    INSERT IGNORE INTO file_registry_faces
                                    (file_registry_id, face_index, person_name, region_x, region_y, region_w, region_h,
                                     confidence, source, embedding, detected_at, created_at, updated_at)
                                    VALUES (?, ?, '', ?, ?, ?, ?, ?, 'ai_detection', ?, NOW(), NOW(), NOW())
                                ", [
                                    $file->id, $idx,
                                    $norm['x'] ?? 0, $norm['y'] ?? 0, $norm['w'] ?? 0, $norm['h'] ?? 0,
                                    $face['confidence'] ?? 0.9,
                                    json_encode($face['embedding'] ?? []),
                                ]);
                                // Inline pgvector insert + cluster assignment
                                $this->insertPgvectorAndCluster($file->id, $face);
                            }
                            DB::update('UPDATE file_registry SET face_scan_at = NOW(), face_count = ? WHERE id = ?',
                                [count($imgResult['faces']), $file->id]);
                            // Apply XMP names to newly detected faces
                            $this->applyXmpNamesToAiFaces($file->id, $imgPath);
                            $processed++;
                        } else {
                            // Mark as scanned (0 faces) so we don't retry errors forever
                            DB::update('UPDATE file_registry SET face_scan_at = NOW(), face_count = 0 WHERE id = ?', [$file->id]);
                            $errors++;
                        }
                    }
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
                            if ($result && $result['success'] !== false && isset($result['faces'])) {
                                foreach ($result['faces'] as $idx => $face) {
                                    $norm = $face['normalized'] ?? [];
                                    DB::insert("
                                        INSERT IGNORE INTO file_registry_faces
                                        (file_registry_id, face_index, person_name, region_x, region_y, region_w, region_h,
                                         confidence, source, embedding, detected_at, created_at, updated_at)
                                        VALUES (?, ?, '', ?, ?, ?, ?, ?, 'ai_detection', ?, NOW(), NOW(), NOW())
                                    ", [
                                        $file->id, $idx,
                                        $norm['x'] ?? 0, $norm['y'] ?? 0, $norm['w'] ?? 0, $norm['h'] ?? 0,
                                        $face['confidence'] ?? 0.9,
                                        json_encode($face['embedding'] ?? []),
                                    ]);
                                    // Inline pgvector insert + cluster assignment
                                    $this->insertPgvectorAndCluster($file->id, $face);
                                }
                                DB::update('UPDATE file_registry SET face_scan_at = NOW(), face_count = ? WHERE id = ?',
                                    [count($result['faces']), $file->id]);
                                // Apply XMP names to newly detected faces
                                $this->applyXmpNamesToAiFaces($file->id, $path);
                                $processed++;
                            } else {
                                DB::update('UPDATE file_registry SET face_scan_at = NOW(), face_count = 0 WHERE id = ?', [$file->id]);
                                $errors++;
                            }
                        } catch (\Exception $e) {
                            DB::update('UPDATE file_registry SET face_scan_at = NOW(), face_count = 0 WHERE id = ?', [$file->id]);
                            $errors++;
                        }
                    }
                }

                // Cleanup temp files
                @unlink($listFile);
                @array_map('unlink', glob("{$batchOutputDir}/*.json"));
                @rmdir($batchOutputDir);

            } catch (\Exception $e) {
                // Mark all files in failed chunk as scanned to prevent infinite retry
                foreach ($paths as $path) {
                    $file = $validFiles[$path] ?? null;
                    if ($file) {
                        DB::update('UPDATE file_registry SET face_scan_at = NOW(), face_count = 0 WHERE id = ?', [$file->id]);
                    }
                }
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

    /**
     * Insert a face embedding into pgvector and attempt cluster assignment.
     * Called inline during face detection for incremental clustering.
     */
    private function insertPgvectorAndCluster(int $fileRegistryId, array $face): void
    {
        $embedding = $face['embedding'] ?? [];
        if (empty($embedding) || count($embedding) < 64) {
            return;
        }

        try {
            $embeddingService = app(\App\Services\FaceEmbeddingService::class);
            if (! $embeddingService->isAvailable()) {
                return;
            }

            // Get the face record we just inserted (latest for this file+index)
            $faceRecord = DB::selectOne("
                SELECT id FROM file_registry_faces
                WHERE file_registry_id = ? AND source = 'ai_detection'
                ORDER BY id DESC LIMIT 1
            ", [$fileRegistryId]);

            if (! $faceRecord) {
                return;
            }

            $pgConn = \Illuminate\Support\Facades\DB::connection('pgsql_rag');

            // Insert into pgvector (idempotent via ON CONFLICT)
            $vectorStr = '['.implode(',', $embedding).']';
            $pgConn->statement('
                INSERT INTO face_embeddings (file_registry_id, file_registry_face_id, embedding, created_at, updated_at)
                VALUES (?, ?, ?::vector, NOW(), NOW())
                ON CONFLICT (file_registry_face_id) DO NOTHING
            ', [$fileRegistryId, $faceRecord->id, $vectorStr]);

            // Get the pgvector row ID for cluster assignment
            $pgRow = $pgConn->selectOne('
                SELECT id FROM face_embeddings WHERE file_registry_face_id = ?
            ', [$faceRecord->id]);

            if ($pgRow) {
                $result = $embeddingService->assignToCluster($pgRow->id, $embedding);
                if (isset($result['cluster_id'])) {
                    DB::update('UPDATE file_registry_faces SET cluster_id = ? WHERE id = ?',
                        [$result['cluster_id'], $faceRecord->id]);
                }
            }
        } catch (\Exception $e) {
            // Non-fatal — face is saved in MySQL, clustering can be done later via backfill
            Log::debug('Inline pgvector/cluster failed', [
                'file_id' => $fileRegistryId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * After AI face detection, read XMP metadata from the same file and apply
     * any embedded face names to the matching AI-detected regions by IoU overlap.
     * Never overwrites a face that already has a name.
     */
    private function applyXmpNamesToAiFaces(int $fileId, string $filePath): int
    {
        if (! file_exists($filePath)) {
            return 0;
        }

        try {
            $faceService = app(\App\Services\FaceRegionService::class);
            if (! $faceService->isAvailable()) {
                return 0;
            }

            $xmpRegions = $faceService->readFaceRegions($filePath);
            if (empty($xmpRegions)) {
                return 0;
            }

            // Get unnamed AI-detected faces for this file
            $aiFaces = DB::select("
                SELECT id, region_x, region_y, region_w, region_h
                FROM file_registry_faces
                WHERE file_registry_id = ? AND person_name = ''
            ", [$fileId]);

            if (empty($aiFaces)) {
                return 0;
            }

            $named = 0;
            $usedIds = [];

            foreach ($xmpRegions as $xmp) {
                $xmpName = trim($xmp['name'] ?? '');
                if (empty($xmpName) || $xmpName === 'Unknown') {
                    continue;
                }

                // Handle comma-concatenated names (bad XMP data)
                if (str_contains($xmpName, ',')) {
                    $parts = array_map('trim', explode(',', $xmpName));
                    $realNames = array_filter($parts, fn ($p) => $p !== 'Unknown' && $p !== '');
                    if (count($realNames) === 1) {
                        $xmpName = reset($realNames);
                    } else {
                        continue;
                    }
                }
                if (strlen($xmpName) > 250) {
                    continue;
                }

                $bestMatch = null;
                $bestIou = 0.15; // minimum threshold

                foreach ($aiFaces as $ai) {
                    if (in_array($ai->id, $usedIds)) {
                        continue;
                    }

                    // IoU calculation
                    $left = max($xmp['x'], (float) $ai->region_x);
                    $top = max($xmp['y'], (float) $ai->region_y);
                    $right = min($xmp['x'] + $xmp['w'], (float) $ai->region_x + (float) $ai->region_w);
                    $bottom = min($xmp['y'] + $xmp['h'], (float) $ai->region_y + (float) $ai->region_h);

                    if ($right <= $left || $bottom <= $top) {
                        continue;
                    }

                    $intersection = ($right - $left) * ($bottom - $top);
                    $area1 = $xmp['w'] * $xmp['h'];
                    $area2 = (float) $ai->region_w * (float) $ai->region_h;
                    $union = $area1 + $area2 - $intersection;
                    $iou = $union > 0 ? $intersection / $union : 0;

                    if ($iou > $bestIou) {
                        $bestIou = $iou;
                        $bestMatch = $ai;
                    }
                }

                if ($bestMatch) {
                    DB::update("UPDATE file_registry_faces SET person_name = ? WHERE id = ? AND person_name = ''",
                        [$xmpName, $bestMatch->id]);
                    $usedIds[] = $bestMatch->id;
                    $named++;
                }
            }

            if ($named > 0) {
                DB::update('UPDATE file_registry SET exif_faces_written = 0 WHERE id = ?', [$fileId]);
            }

            return $named;
        } catch (\Exception $e) {
            Log::debug('XMP face name apply failed', ['file_id' => $fileId, 'error' => $e->getMessage()]);

            return 0;
        }
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
        $imageExts = "'".implode("','", config('file_types.image'))."'";

        $files = DB::select("
            SELECT fr.id, fr.asset_uuid, fr.current_path, fr.filename
            FROM file_registry fr
            WHERE fr.status = 'active'
            AND fr.extension IN ({$imageExts})
            AND fr.date_taken IS NULL
            AND (fr.exif_checked IS NULL OR fr.exif_checked = 0)
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
                $localPath = $file->current_path;

                if (! file_exists($localPath)) {
                    DB::update('UPDATE file_registry SET exif_checked = 1, updated_at = NOW() WHERE id = ?', [$file->id]);
                    $errors++;
                    Log::debug('FileEnrichment exif: file not found', ['path' => $localPath]);

                    continue;
                }

                $exif = @exif_read_data($localPath, 'EXIF', true);
                $dateTaken = null;
                $gpsLat = null;
                $gpsLon = null;
                $cameraMake = null;
                $cameraModel = null;
                $rating = null;

                if ($exif) {
                    // Date
                    foreach ([$exif['EXIF']['DateTimeOriginal'] ?? null, $exif['EXIF']['DateTimeDigitized'] ?? null, $exif['IFD0']['DateTime'] ?? null] as $dateStr) {
                        if ($dateStr) {
                            $candidate = str_replace(':', '-', substr($dateStr, 0, 10)).substr($dateStr, 10);
                            if (strtotime($candidate) !== false) {
                                $dateTaken = $candidate;
                                break;
                            }
                        }
                    }

                    // Camera
                    $cameraMake = isset($exif['IFD0']['Make']) ? trim($exif['IFD0']['Make']) : null;
                    $cameraModel = isset($exif['IFD0']['Model']) ? trim($exif['IFD0']['Model']) : null;

                    // GPS
                    if (! empty($exif['GPS']['GPSLatitude']) && ! empty($exif['GPS']['GPSLongitude'])) {
                        $gpsLat = $this->gpsToDecimal($exif['GPS']['GPSLatitude'], $exif['GPS']['GPSLatitudeRef'] ?? 'N');
                        $gpsLon = $this->gpsToDecimal($exif['GPS']['GPSLongitude'], $exif['GPS']['GPSLongitudeRef'] ?? 'E');
                    }

                    // Rating (stored in IFD0 or EXIF as RatingPercent or Rating)
                    $ratingRaw = $exif['IFD0']['Rating'] ?? $exif['EXIF']['Rating'] ?? null;
                    if ($ratingRaw !== null && $ratingRaw >= 1 && $ratingRaw <= 5) {
                        $rating = (int) $ratingRaw;
                    }
                }

                // ExifTool pass for keywords and caption (broader IPTC/XMP support)
                $exifKeywords = null;
                $exifCaption = null;
                $exiftool = '/usr/bin/exiftool';
                if (file_exists($exiftool)) {
                    $json = Process::timeout(30)->run([
                        $exiftool,
                        '-json',
                        '-IPTC:Keywords',
                        '-XMP:Subject',
                        '-IPTC:Caption-Abstract',
                        '-XMP:Description',
                        '-Rating',
                        $localPath,
                    ])->output();
                    if ($json) {
                        $et = json_decode($json, true)[0] ?? [];
                        // Keywords: merge IPTC and XMP, deduplicate
                        $kw = array_unique(array_filter(array_merge(
                            (array) ($et['Keywords'] ?? []),
                            (array) ($et['Subject'] ?? [])
                        )));
                        $exifKeywords = $kw ? implode(', ', $kw) : null;
                        // Caption
                        $exifCaption = $et['Caption-Abstract'] ?? $et['Description'] ?? null;
                        // Rating fallback from ExifTool if PHP EXIF missed it
                        if ($rating === null && isset($et['Rating']) && $et['Rating'] >= 1 && $et['Rating'] <= 5) {
                            $rating = (int) $et['Rating'];
                        }
                    }
                }

                DB::update("
                    UPDATE file_registry
                    SET date_taken        = COALESCE(date_taken, ?),
                        date_taken_source = CASE WHEN date_taken IS NULL AND ? IS NOT NULL THEN 'exif_original' ELSE date_taken_source END,
                        gps_latitude      = COALESCE(gps_latitude, ?),
                        gps_longitude     = COALESCE(gps_longitude, ?),
                        camera_make       = COALESCE(camera_make, ?),
                        camera_model      = COALESCE(camera_model, ?),
                        exif_rating       = COALESCE(exif_rating, ?),
                        exif_keywords     = COALESCE(exif_keywords, ?),
                        exif_caption      = COALESCE(exif_caption, ?),
                        exif_checked      = 1,
                        updated_at        = NOW()
                    WHERE id = ?
                ", [$dateTaken, $dateTaken, $gpsLat, $gpsLon, $cameraMake, $cameraModel, $rating, $exifKeywords, $exifCaption, $file->id]);

                if ($dateTaken) {
                    $processed++;
                }

            } catch (Exception $e) {
                $errors++;
                DB::update('UPDATE file_registry SET exif_checked = 1, updated_at = NOW() WHERE id = ?', [$file->id]);
                Log::debug('FileEnrichment exif error', ['file_id' => $file->id, 'error' => $e->getMessage()]);
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
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
            $photoAnalysis = app(\App\Services\PhotoAnalysisService::class);
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
     * Process AI analysis using AIService (Ollama + Claude)
     * Uses claim-based selection when running with --worker-id for parallel safety.
     */
    private function processAIAnalysis(int $limit, bool $dryRun): array
    {
        $workerId = $this->option('worker-id');
        $aiTagService = app(AIAutoTagService::class);

        if ($workerId) {
            // Claim-based: atomically claim unclaimed files for AI analysis
            $imageExts = "'".implode("','", config('file_types.image'))."'";
            $docExts = "'".implode("','", config('file_types.document'))."'";
            $claimExpiry = now()->addMinutes(30)->format('Y-m-d H:i:s');

            DB::update("
                UPDATE file_registry
                SET claim_worker = ?, claim_expires_at = ?
                WHERE status = 'active'
                AND ai_analyzed_at IS NULL
                AND (ai_analysis_version IS NULL OR ai_analysis_version NOT IN ('skipped', 'processing'))
                AND ai_analysis_version NOT LIKE 'fail:%'
                AND extension IN ({$imageExts},{$docExts})
                AND (claim_worker IS NULL OR claim_expires_at < NOW())
                ORDER BY created_at DESC
                LIMIT ?
            ", [$workerId, $claimExpiry, $limit]);

            $files = DB::select('
                SELECT id FROM file_registry WHERE claim_worker = ? AND ai_analyzed_at IS NULL
            ', [$workerId]);
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
        $results = $aiTagService->batchAnalyze($fileIds, 5, false);

        // Release claims
        if ($workerId) {
            DB::update('UPDATE file_registry SET claim_worker = NULL, claim_expires_at = NULL WHERE claim_worker = ?', [$workerId]);
        }

        return [
            'processed' => $results['processed'] ?? 0,
            'errors' => $results['failed'] ?? 0,
            'skipped' => $results['skipped'] ?? 0,
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
        $basePath = rtrim($dataPath, '/').$libraryRoot;
        $imageExts = "'".implode("','", config('file_types.image'))."'";

        // Get files needing writeback: derived dates OR pending location writeback
        $files = DB::select("
            SELECT fr.id, fr.current_path, fr.filename,
                   fr.date_taken, fr.date_taken_source, fr.date_taken_confidence
            FROM file_registry fr
            WHERE fr.status = 'active'
            AND fr.extension IN ({$imageExts})
            AND (
                (fr.date_taken IS NOT NULL AND fr.date_taken_source NOT LIKE 'exif_%'
                 AND (fr.exif_written IS NULL OR fr.exif_written = 0)
                 AND fr.date_taken_confidence >= 0.3)
                OR
                (fr.gps_location IS NOT NULL AND fr.gps_location != ''
                 AND (fr.exif_location_written IS NULL OR fr.exif_location_written = 0))
            )
            ORDER BY fr.created_at DESC
            LIMIT ?
        ", [$limit]);

        if ($dryRun) {
            return ['pending' => count($files), 'processed' => 0, 'errors' => 0];
        }

        $processed = 0;
        $errors = 0;

        $writebackService = app(ExifWritebackService::class);

        foreach ($files as $file) {
            try {
                // Build local path
                $ncPath = '/'.ltrim((string) $file->current_path, '/');
                if (str_starts_with($ncPath, $libraryRoot)) {
                    $localPath = $basePath.substr($ncPath, strlen($libraryRoot));
                } else {
                    $localPath = $basePath.$ncPath;
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
}
