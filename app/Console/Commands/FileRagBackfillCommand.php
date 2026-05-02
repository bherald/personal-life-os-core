<?php

namespace App\Console\Commands;

use App\Services\FileCategorizationRAGService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfill RAG indexing for files with AI descriptions/OCR text.
 *
 * Indexes file_registry records into rag_documents for semantic search.
 * Runs incrementally — only processes files where rag_indexed_at IS NULL.
 * Designed to run as a scheduled job (hourly) until backlog is cleared.
 */
class FileRagBackfillCommand extends Command
{
    private const FALLBACK_SECONDS_PER_FILE = 45.0;
    private const MIN_TARGET_MINUTES = 10;
    private const MAX_TARGET_MINUTES = 120;

    protected $signature = 'files:rag-backfill
                            {--limit=500 : Max files to process per run}
                            {--dry-run : Show what would be indexed without changing data}
                            {--stats : Show current backfill status and exit}
                            {--priority=described : Priority mode: described, ocr, all}
                            {--batch-size=50 : Files per batch before throttle pause}
                            {--throttle-ms=200 : Milliseconds to pause between batches}';

    protected $description = 'Index files with AI data into RAG for semantic search';

    public function handle(): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        $limit = $this->resolveEffectiveLimit((int) $this->option('limit'));
        $dryRun = $this->option('dry-run');
        $priority = $this->option('priority');
        $batchSize = (int) $this->option('batch-size');
        $throttleMs = (int) $this->option('throttle-ms');
        $maxSeconds = $this->resolveMaxSeconds();
        $startTime = microtime(true);

        $files = $this->getPendingFiles($priority, $limit);

        if (empty($files)) {
            $this->info('No files pending RAG indexing.');
            $this->line('[ITEMS_PROCESSED:0]');
            return Command::SUCCESS;
        }

        $this->info(sprintf(
            'Found %d files to index (priority: %s, limit: %d)%s',
            count($files),
            $priority,
            $limit,
            $dryRun ? ' [DRY RUN]' : ''
        ));

        if ($dryRun) {
            $this->table(
                ['ID', 'UUID', 'Filename', 'Has Description', 'Has OCR'],
                array_map(fn($f) => [
                    $f->id,
                    substr($f->asset_uuid, 0, 8) . '...',
                    substr(basename($f->current_path), 0, 40),
                    $f->ai_description ? 'Yes' : 'No',
                    $f->ai_detected_text ? 'Yes' : 'No',
                ], array_slice($files, 0, 20))
            );
            if (count($files) > 20) {
                $this->line(sprintf('... and %d more', count($files) - 20));
            }
            $this->line('[ITEMS_PROCESSED:0]');
            return Command::SUCCESS;
        }

        $ragService = app(FileCategorizationRAGService::class);
        $indexed = 0;
        $errors = 0;
        $processed = 0;
        $consecutiveFailures = 0;
        $maxConsecutiveFailures = 5;
        $earlyExit = false;
        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        $batches = array_chunk($files, $batchSize);
        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $file) {
                if ($this->shouldStopBeforeStartingFile($startTime, $maxSeconds, $processed)) {
                    $this->warn("  Stopped early to stay within runtime budget ({$maxSeconds}s)");
                    Log::warning('FileRagBackfill: Wall-clock budget reached, stopping early', [
                        'budget_seconds' => $maxSeconds,
                        'processed' => $processed,
                        'indexed' => $indexed,
                        'errors' => $errors,
                    ]);
                    $earlyExit = true;
                    break;
                }

                try {
                    $processed++;
                    $result = $ragService->indexFileForBulkRag($file->asset_uuid);
                    if ($result['success']) {
                        $indexed++;
                        $consecutiveFailures = 0;
                    } else {
                        $errors++;
                        $consecutiveFailures++;
                        Log::warning('FileRagBackfill: index failed', [
                            'uuid' => $file->asset_uuid,
                            'error' => $result['error'] ?? 'unknown',
                        ]);
                    }
                } catch (\Exception $e) {
                    $errors++;
                    $consecutiveFailures++;
                    Log::error('FileRagBackfill: exception', [
                        'uuid' => $file->asset_uuid,
                        'error' => $e->getMessage(),
                    ]);
                }
                $bar->advance();

                if ($consecutiveFailures >= $maxConsecutiveFailures) {
                    Log::error("FileRagBackfill: {$maxConsecutiveFailures} consecutive failures — providers likely down, stopping early", [
                        'processed' => $processed, 'indexed' => $indexed, 'errors' => $errors,
                    ]);
                    $this->error("  Stopped: {$maxConsecutiveFailures} consecutive failures");
                    $earlyExit = true;
                    break;
                }
            }

            if ($earlyExit) break;

            // Throttle between batches (skip after last batch)
            if ($throttleMs > 0 && $batchIndex < count($batches) - 1) {
                usleep($throttleMs * 1000);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Indexed', $indexed],
                ['Errors', $errors],
                ['Total processed', $processed],
            ]
        );

        $this->line("[ITEMS_PROCESSED:{$processed}]");
        return $errors > ($indexed / 2) ? Command::FAILURE : Command::SUCCESS;
    }

    private function resolveEffectiveLimit(int $requestedLimit): int
    {
        if ($requestedLimit <= 0) {
            return $requestedLimit;
        }

        $cap = $this->getDynamicLimitCap();

        if ($requestedLimit > $cap) {
            Log::info('FileRagBackfill: limit capped', [
                'original' => $requestedLimit,
                'capped' => $cap,
            ]);
            return $cap;
        }

        return $requestedLimit;
    }

    private function getDynamicLimitCap(): int
    {
        $avgSecondsPerFile = self::FALLBACK_SECONDS_PER_FILE;
        $targetMinutes = $this->getBatchTargetMinutes('file_rag_backfill', 45);

        try {
            $recent = DB::selectOne("
                SELECT AVG(duration_seconds / items_processed) as avg_seconds,
                       COUNT(*) as sample_count
                FROM scheduled_job_runs sjr
                JOIN scheduled_jobs sj ON sj.id = sjr.scheduled_job_id
                WHERE sj.name = 'file_rag_backfill'
                  AND sjr.status = 'success'
                  AND sjr.items_processed > 0
                  AND sjr.duration_seconds > 0
                  AND sjr.completed_at > NOW() - INTERVAL 14 DAY
                LIMIT 10
            ");

            if ($recent && $recent->sample_count >= 3 && $recent->avg_seconds > 0) {
                $avgSecondsPerFile = max((float) $recent->avg_seconds, 5.0);
            }
        } catch (\Exception $e) {
            // Use fallback if history lookup fails.
        }

        return max(10, (int) floor(($targetMinutes * 60) / $avgSecondsPerFile));
    }

    private function resolveMaxSeconds(): int
    {
        return $this->getBatchTargetMinutes('file_rag_backfill', 45) * 60;
    }

    private function getBatchTargetMinutes(string $jobName, int $defaultMinutes): int
    {
        $targetMinutes = $defaultMinutes;

        try {
            $job = DB::selectOne("
                SELECT cron_expression, timeout_minutes
                FROM scheduled_jobs
                WHERE name = ?
                LIMIT 1
            ", [$jobName]);

            if (!$job) {
                return $targetMinutes;
            }

            $timeoutTarget = !empty($job->timeout_minutes)
                ? max(self::MIN_TARGET_MINUTES, ((int) $job->timeout_minutes) - 5)
                : null;
            $intervalTarget = $this->getIntervalSafeMinutes($job->cron_expression ?? null);

            $candidates = array_values(array_filter([
                $timeoutTarget,
                $intervalTarget,
            ], fn($value) => $value !== null && $value > 0));

            if (!empty($candidates)) {
                $targetMinutes = min($candidates);
            }
        } catch (\Exception $e) {
            // Use default if job metadata lookup fails.
        }

        return max(self::MIN_TARGET_MINUTES, min(self::MAX_TARGET_MINUTES, $targetMinutes));
    }

    private function getIntervalSafeMinutes(?string $cronExpression): ?int
    {
        if (!$cronExpression) {
            return null;
        }

        try {
            $cron = new \Cron\CronExpression($cronExpression);
            $first = $cron->getNextRunDate(now()->toDateTimeImmutable());
            $second = $cron->getNextRunDate($first);
            $intervalMinutes = (int) floor(($second->getTimestamp() - $first->getTimestamp()) / 60);

            if ($intervalMinutes <= 0) {
                return null;
            }

            return max(
                self::MIN_TARGET_MINUTES,
                $intervalMinutes - max(2, (int) floor($intervalMinutes * 0.2))
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getPendingFiles(string $priority, int $limit): array
    {
        $where = "fr.status = 'active' AND fr.rag_indexed_at IS NULL";

        switch ($priority) {
            case 'described':
                $where .= " AND fr.ai_description IS NOT NULL";
                break;
            case 'ocr':
                $where .= " AND fr.ai_detected_text IS NOT NULL";
                break;
            case 'all':
                // No additional filter
                break;
            default:
                $where .= " AND fr.ai_description IS NOT NULL";
        }

        return DB::select("
            SELECT fr.id, fr.asset_uuid, fr.current_path, fr.ai_description, fr.ai_detected_text
            FROM file_registry fr
            WHERE {$where}
            ORDER BY fr.ai_analyzed_at DESC
            LIMIT ?
        ", [$limit]);
    }

    private function shouldStopBeforeStartingFile(float $startedAt, int $maxSeconds, int $processedCount): bool
    {
        $elapsedSeconds = microtime(true) - $startedAt;
        $estimatedNextFileSeconds = $this->estimateNextFileSeconds($startedAt, $processedCount);

        return ($elapsedSeconds + $estimatedNextFileSeconds) >= $maxSeconds;
    }

    private function estimateNextFileSeconds(float $startedAt, int $processedCount): float
    {
        if ($processedCount <= 0) {
            return self::FALLBACK_SECONDS_PER_FILE;
        }

        $avgSecondsPerFile = (microtime(true) - $startedAt) / max(1, $processedCount);

        return max(self::FALLBACK_SECONDS_PER_FILE, min(180.0, $avgSecondsPerFile));
    }

    private function showStats(): int
    {
        $stats = DB::select("
            SELECT
                COUNT(*) as total_active,
                SUM(CASE WHEN rag_indexed_at IS NOT NULL THEN 1 ELSE 0 END) as indexed,
                SUM(CASE WHEN rag_indexed_at IS NULL AND ai_description IS NOT NULL THEN 1 ELSE 0 END) as pending_described,
                SUM(CASE WHEN rag_indexed_at IS NULL AND ai_detected_text IS NOT NULL THEN 1 ELSE 0 END) as pending_ocr,
                SUM(CASE WHEN rag_indexed_at IS NULL THEN 1 ELSE 0 END) as pending_all
            FROM file_registry
            WHERE status = 'active'
        ");

        $s = $stats[0];

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total active files', $s->total_active],
                ['Already RAG-indexed', $s->indexed],
                ['Pending (with description)', $s->pending_described],
                ['Pending (with OCR text)', $s->pending_ocr],
                ['Pending (all)', $s->pending_all],
            ]
        );

        return Command::SUCCESS;
    }
}
