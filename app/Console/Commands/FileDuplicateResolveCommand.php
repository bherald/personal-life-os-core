<?php

namespace App\Console\Commands;

use App\Services\DuplicateResolutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FileDuplicateResolveCommand extends Command
{
    protected $signature = 'files:resolve-duplicates
        {--dry-run : Show what would be resolved without making changes}
        {--audit-only : Run audit sample only, do not resolve}
        {--status : Show duplicate statistics for the filtered scope}
        {--sample-size=30 : Number of pairs to random-sample for audit}
        {--min-accuracy=95 : Minimum audit accuracy percentage to proceed}
        {--folder-filter=Family Tree Maker : Only process pairs where BOTH files match this path substring}';

    protected $description = 'Resolve file duplicates with AI audit safety gate. Audits random sample, then batch-resolves if accuracy passes threshold.';

    public function handle(): int
    {
        $folderFilter = $this->option('folder-filter');
        $sampleSize = (int) $this->option('sample-size');
        $minAccuracy = (int) $this->option('min-accuracy') / 100.0;
        $dryRun = (bool) $this->option('dry-run');
        $auditOnly = (bool) $this->option('audit-only');

        $service = app(DuplicateResolutionService::class);

        // --- Status mode ---
        if ($this->option('status')) {
            return $this->showStatus($service, $folderFilter);
        }

        // --- Fetch scoped pairs ---
        $this->info("Scope: files matching '{$folderFilter}'");
        $pairs = $service->getScopedPendingPairs($folderFilter);

        if (empty($pairs)) {
            $this->info('No pending duplicates in scope.');
            return Command::SUCCESS;
        }

        $this->info(count($pairs) . ' pending duplicate pairs found.');

        // --- Audit phase ---
        $this->newLine();
        $this->info('=== AUDIT PHASE ===');
        $audit = $service->auditSample($pairs, $sampleSize);

        $accuracyPct = round($audit['accuracy'] * 100, 1);
        $this->info("Sampled: {$audit['total_sampled']} | Passed: {$audit['passed']} | Failed: {$audit['failed']} | Accuracy: {$accuracyPct}%");

        if (!empty($audit['failures'])) {
            $this->newLine();
            $this->warn('Audit failures:');
            foreach ($audit['failures'] as $f) {
                $this->line("  Pair #{$f['pair_id']}: {$f['reason']}");
                $this->line("    A: {$f['path_a']}");
                $this->line("    B: {$f['path_b']}");
            }
        }

        // --- Accuracy gate ---
        if ($audit['accuracy'] < $minAccuracy) {
            $this->error("Audit accuracy {$accuracyPct}% is below threshold " . ($minAccuracy * 100) . "%. Aborting.");
            Log::warning('FileDuplicateResolve: Audit failed accuracy gate', [
                'accuracy' => $audit['accuracy'],
                'threshold' => $minAccuracy,
                'failures' => $audit['failures'],
            ]);
            return Command::FAILURE;
        }

        $this->info("Audit PASSED ({$accuracyPct}% >= " . ($minAccuracy * 100) . "% threshold)");

        // --- Audit-only mode ---
        if ($auditOnly) {
            $this->info('Audit-only mode — stopping before resolution.');
            return Command::SUCCESS;
        }

        // --- Resolution phase ---
        $this->newLine();
        $this->info($dryRun ? '=== RESOLUTION (DRY RUN) ===' : '=== RESOLUTION ===');

        $result = $service->resolveAll($pairs, $dryRun);

        $mbReclaimable = round($result['total_bytes_reclaimable'] / 1024 / 1024, 1);
        $prefix = $dryRun ? '[DRY RUN] Would resolve' : 'Resolved';
        $this->info("{$prefix}: {$result['resolved']} pairs | Errors: {$result['errors']} | Reclaimable: {$mbReclaimable} MB");

        if (!$dryRun) {
            Log::info('FileDuplicateResolve: Batch resolution complete', [
                'folder_filter' => $folderFilter,
                'resolved' => $result['resolved'],
                'errors' => $result['errors'],
                'bytes_reclaimable' => $result['total_bytes_reclaimable'],
                'audit_accuracy' => $audit['accuracy'],
                'sample_size' => $audit['total_sampled'],
            ]);
        }

        $this->info("[ITEMS_PROCESSED:{$result['resolved']}]");

        return Command::SUCCESS;
    }

    private function showStatus(DuplicateResolutionService $service, string $folderFilter): int
    {
        $stats = $service->getStatistics($folderFilter);

        $this->info("=== Duplicate Statistics (scope: '{$folderFilter}') ===");
        $this->newLine();
        $this->line("Pending pairs:        {$stats['pending_pairs']}");
        $this->line("Unique content hashes: {$stats['unique_content_hashes']}");
        $this->line("Pairs with phash:     {$stats['pairs_with_phash']}");
        $this->line("Reclaimable space:    " . round($stats['reclaimable_bytes'] / 1024 / 1024, 1) . ' MB');

        if (!empty($stats['by_mime_type'])) {
            $this->newLine();
            $this->info('By MIME type:');
            foreach ($stats['by_mime_type'] as $mt) {
                $this->line("  {$mt['type']}: {$mt['count']}");
            }
        }

        return Command::SUCCESS;
    }
}
