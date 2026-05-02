<?php

namespace App\Console\Commands;

use App\Services\JoplinQueueService;
use App\Services\JoplinWriteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Joplin Queue Worker Command
 *
 * Processes queued Joplin operations that failed to acquire locks.
 * Runs periodically via Laravel scheduler to retry pending operations.
 *
 * Usage:
 *   php artisan joplin:process-queue
 *   php artisan joplin:process-queue --limit=50
 *   php artisan joplin:process-queue --limit=50 -v (verbose output)
 */
class JoplinQueueWorker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'joplin:process-queue
                          {--limit=50 : Maximum number of jobs to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process queued Joplin operations with lock retry logic';

    protected JoplinQueueService $queueService;
    protected JoplinWriteService $writeService;

    public function __construct(
        JoplinQueueService $queueService,
        JoplinWriteService $writeService
    ) {
        parent::__construct();
        $this->queueService = $queueService;
        $this->writeService = $writeService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $verbose = $this->getOutput()->isVerbose();

        $this->info('Joplin Queue Worker started');

        // Get statistics before processing
        $statsBefore = $this->queueService->getStatistics();

        if ($verbose) {
            $this->line('Queue statistics (before):');
            $this->table(
                ['Status', 'Count'],
                [
                    ['Pending', $statsBefore['pending']],
                    ['Processing', $statsBefore['processing']],
                    ['Completed', $statsBefore['completed']],
                    ['Failed', $statsBefore['failed']],
                ]
            );

            if ($statsBefore['oldest_pending']) {
                $this->line("Oldest pending job: {$statsBefore['oldest_pending']}");
            }
        }

        // Get pending jobs ready for processing
        $jobs = $this->queueService->getPendingJobs($limit);

        if ($jobs->isEmpty()) {
            $this->info('No jobs ready for processing');
            return Command::SUCCESS;
        }

        $this->info("Processing {$jobs->count()} job(s)...");

        $processed = 0;
        $succeeded = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            $processed++;

            try {
                if ($verbose) {
                    $this->line("Processing job #{$job->id} ({$job->operation_type})...");
                }

                $success = $this->queueService->processJob($job, $this->writeService);

                if ($success) {
                    $succeeded++;
                    $this->info("✓ Job #{$job->id} completed successfully");
                } else {
                    $failed++;
                    $this->warn("✗ Job #{$job->id} failed (will retry)");

                    if ($verbose && $job->error_message) {
                        $this->line("  Error: {$job->error_message}");
                    }
                }

            } catch (\Exception $e) {
                $failed++;
                $this->error("✗ Job #{$job->id} encountered error: {$e->getMessage()}");
                Log::error('JoplinQueueWorker: Unexpected error', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Final statistics
        $this->newLine();
        $this->info("Processing complete:");
        $this->line("  Processed: $processed");
        $this->line("  Succeeded: $succeeded");
        $this->line("  Failed: $failed");

        // Get updated statistics
        $statsAfter = $this->queueService->getStatistics();

        if ($verbose) {
            $this->newLine();
            $this->line('Queue statistics (after):');
            $this->table(
                ['Status', 'Count'],
                [
                    ['Pending', $statsAfter['pending']],
                    ['Processing', $statsAfter['processing']],
                    ['Completed', $statsAfter['completed']],
                    ['Failed', $statsAfter['failed']],
                ]
            );
        }

        return Command::SUCCESS;
    }
}
