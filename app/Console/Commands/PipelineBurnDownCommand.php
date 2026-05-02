<?php

namespace App\Console\Commands;

use App\Services\ScheduledJobService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;

/**
 * PipelineBurnDownCommand - Continuous enrichment worker launcher
 *
 * Launches parallel enrichment workers in rounds until the backlog drops
 * below a threshold. Designed for manual catch-up runs.
 *
 * Usage:
 *   php artisan pipeline:burn-down --type=faces --workers=2 --threshold=100
 *   php artisan pipeline:burn-down --type=ai --workers=2 --max-rounds=10
 *   php artisan pipeline:burn-down --type=rag --workers=2 --threshold=1000
 *   php artisan pipeline:burn-down --type=faces --dry-run
 */
class PipelineBurnDownCommand extends Command
{
    protected $signature = 'pipeline:burn-down
                            {--type=faces : Processing type: faces, ai, rag}
                            {--workers=2 : Number of parallel workers per round}
                            {--threshold=100 : Stop when pending count drops below this}
                            {--max-rounds=50 : Maximum rounds before stopping}
                            {--dry-run : Show what would happen without executing}';

    protected $description = 'Continuously launch enrichment workers until backlog is burned down';

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'tif', 'heic', 'heif'];

    private ScheduledJobService $scheduledJobService;

    public function __construct(ScheduledJobService $scheduledJobService)
    {
        parent::__construct();
        $this->scheduledJobService = $scheduledJobService;
    }

    public function handle(): int
    {
        $type = $this->option('type');
        $workers = (int) $this->option('workers');
        $threshold = (int) $this->option('threshold');
        $maxRounds = (int) $this->option('max-rounds');
        $dryRun = $this->option('dry-run');

        if (!in_array($type, ['faces', 'ai', 'rag'])) {
            $this->error("Unsupported type: {$type}. Use: faces, ai, rag");
            return 1;
        }

        $this->info("=== Pipeline Burn-Down ===");
        $this->info("Type: {$type}, Workers: {$workers}, Threshold: {$threshold}, Max rounds: {$maxRounds}");

        $round = 0;
        $totalProcessed = 0;

        while ($round < $maxRounds) {
            $round++;
            $pending = $this->getPendingCount($type);

            $this->line("\n--- Round {$round}/{$maxRounds} | Pending: {$pending} ---");

            if ($pending <= $threshold) {
                $this->info("Backlog ({$pending}) is at or below threshold ({$threshold}). Done!");
                break;
            }

            if ($dryRun) {
                $this->line("  [DRY-RUN] Would launch {$workers} workers for {$type}");
                continue;
            }

            // Launch workers
            $pids = [];
            for ($w = 0; $w < $workers; $w++) {
                $workerId = "burn-{$type}-r{$round}-w{$w}";
                $pid = $this->launchWorker($type, $workerId);

                if ($pid > 0) {
                    $pids[$workerId] = $pid;
                    $this->line("  Worker {$workerId}: PID {$pid}");
                } else {
                    $this->warn("  Worker {$workerId}: failed to launch");
                }
            }

            if (empty($pids)) {
                $this->error("No workers launched in round {$round}. Stopping.");
                break;
            }

            // Wait for all workers to complete
            $this->line("  Waiting for " . count($pids) . " worker(s)...");
            $this->waitForPids($pids);

            $newPending = $this->getPendingCount($type);
            $roundProcessed = max(0, $pending - $newPending);
            $totalProcessed += $roundProcessed;

            $this->info("  Round {$round} done: processed ~{$roundProcessed}, remaining: {$newPending}");

            // If no progress was made, back off
            if ($roundProcessed === 0) {
                $this->warn("  No progress in round {$round}. Waiting 30s before retry...");
                sleep(30);
            }
        }

        $this->info("\n=== Burn-Down Complete ===");
        $this->info("Rounds: {$round}, Total processed: ~{$totalProcessed}, Remaining: " . $this->getPendingCount($type));

        Log::info('PipelineBurnDown completed', [
            'type' => $type,
            'rounds' => $round,
            'total_processed' => $totalProcessed,
            'remaining' => $this->getPendingCount($type),
        ]);

        return 0;
    }

    private function launchWorker(string $type, string $workerId): int
    {
        $artisan = base_path('artisan');

        $command = match ($type) {
            'faces' => ['php', $artisan, 'files:enrich', '--type=faces', '--limit=auto', "--worker-id={$workerId}"],
            'ai' => ['php', $artisan, 'files:enrich', '--type=ai', '--limit=auto', "--worker-id={$workerId}"],
            'rag' => ['php', $artisan, 'file-catalog:sync', '--rag-sync', '--limit=500', "--worker-id={$workerId}"],
        };

        $process = Process::path(base_path())
            ->forever()
            ->quietly()
            ->start($command);

        return $process->id();
    }

    private function waitForPids(array $pids): void
    {
        $startWait = time();
        $maxWait = 7200; // 2 hour max per round

        while (!empty($pids) && (time() - $startWait) < $maxWait) {
            foreach ($pids as $workerId => $pid) {
                if (!$this->scheduledJobService->isProcessAlive($pid)) {
                    unset($pids[$workerId]);
                }
            }

            if (!empty($pids)) {
                sleep(5); // Poll every 5 seconds
            }
        }

        // Kill any still-alive workers after max wait
        foreach ($pids as $workerId => $pid) {
            $this->warn("  Worker {$workerId} (PID {$pid}) exceeded max wait, killing...");
            $this->scheduledJobService->killProcess($pid);
        }
    }

    private function getPendingCount(string $type): int
    {
        $imageExts = "'" . implode("','", self::IMAGE_EXTENSIONS) . "'";

        try {
            return match ($type) {
                'faces' => (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND face_scan_at IS NULL AND extension IN ({$imageExts})")->c ?? 0),
                'ai' => (int) (DB::selectOne("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND ai_analyzed_at IS NULL AND extension IN ({$imageExts})")->c ?? 0),
                'rag' => (int) (DB::selectOne(
                    "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND rag_indexed_at IS NULL
                     AND (extension IN ('pdf','doc','docx','txt','rtf','odt','md','csv','html','htm','xls','xlsx','ppt','pptx')
                          OR (extension IN ({$imageExts}) AND ai_description IS NOT NULL))"
                )->c ?? 0),
                default => 0,
            };
        } catch (\Exception $e) {
            return 0;
        }
    }
}
