<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Joplin Queue Cleanup Command
 *
 * Removes old completed and failed jobs from the Joplin queue.
 * Helps maintain database hygiene and prevent table bloat.
 */
class JoplinQueueCleanupCommand extends Command
{
    protected $signature = 'joplin:cleanup-queue
                            {--days=7 : Delete completed jobs older than this many days}
                            {--failed-days=30 : Delete failed jobs older than this many days}
                            {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Clean up old Joplin queue jobs';

    public function handle(): int
    {
        $completedDays = (int) $this->option('days');
        $failedDays = (int) $this->option('failed-days');
        $dryRun = $this->option('dry-run');

        $this->info('Joplin Queue Cleanup');
        $this->info('====================');

        if ($dryRun) {
            $this->warn('DRY RUN - No records will be deleted');
        }

        $completedThreshold = now()->subDays($completedDays);
        $failedThreshold = now()->subDays($failedDays);

        // Count completed jobs to delete
        $completedResult = DB::selectOne("SELECT COUNT(*) as count FROM joplin_queue_jobs WHERE status = 'completed' AND updated_at < ?", [$completedThreshold]);
        $completedCount = $completedResult->count ?? 0;

        // Count failed jobs to delete
        $failedResult = DB::selectOne("SELECT COUNT(*) as count FROM joplin_queue_jobs WHERE status = 'failed' AND updated_at < ?", [$failedThreshold]);
        $failedCount = $failedResult->count ?? 0;

        $this->table(
            ['Type', 'Age Threshold', 'Records to Delete'],
            [
                ['Completed', "{$completedDays} days", $completedCount],
                ['Failed', "{$failedDays} days", $failedCount],
            ]
        );

        $totalCount = $completedCount + $failedCount;

        if ($totalCount === 0) {
            $this->info('No records to clean up.');
            return Command::SUCCESS;
        }

        if (!$dryRun) {
            // Delete completed jobs
            if ($completedCount > 0) {
                DB::delete("DELETE FROM joplin_queue_jobs WHERE status = 'completed' AND updated_at < ?", [$completedThreshold]);

                $this->info("Deleted {$completedCount} completed jobs older than {$completedDays} days");

                Log::info('Joplin queue cleanup: deleted completed jobs', [
                    'count' => $completedCount,
                    'threshold_days' => $completedDays,
                ]);
            }

            // Delete failed jobs
            if ($failedCount > 0) {
                DB::delete("DELETE FROM joplin_queue_jobs WHERE status = 'failed' AND updated_at < ?", [$failedThreshold]);

                $this->info("Deleted {$failedCount} failed jobs older than {$failedDays} days");

                Log::info('Joplin queue cleanup: deleted failed jobs', [
                    'count' => $failedCount,
                    'threshold_days' => $failedDays,
                ]);
            }

            $this->info("Total: {$totalCount} records deleted");
        } else {
            $this->info("Would delete {$totalCount} records (dry run)");
        }

        // Show remaining queue statistics
        $this->newLine();
        $this->info('Remaining Queue Statistics:');

        $pending = DB::selectOne("SELECT COUNT(*) as count FROM joplin_queue_jobs WHERE status = 'pending'");
        $processing = DB::selectOne("SELECT COUNT(*) as count FROM joplin_queue_jobs WHERE status = 'processing'");
        $completed = DB::selectOne("SELECT COUNT(*) as count FROM joplin_queue_jobs WHERE status = 'completed'");
        $failed = DB::selectOne("SELECT COUNT(*) as count FROM joplin_queue_jobs WHERE status = 'failed'");

        $this->table(
            ['Status', 'Count'],
            [
                ['Pending', $pending->count ?? 0],
                ['Processing', $processing->count ?? 0],
                ['Completed', $completed->count ?? 0],
                ['Failed', $failed->count ?? 0],
            ]
        );

        return Command::SUCCESS;
    }
}
