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
        {--json : Emit machine-readable JSON in status mode}
        {--compact : Emit aggregate-only status evidence without paths or row selectors}
        {--sample-size=30 : Number of pairs to random-sample for audit}
        {--min-accuracy=95 : Minimum audit accuracy percentage to proceed}
        {--limit=50 : Maximum pending pairs to inspect or resolve per run, capped at 500}
        {--confirm : Confirm merge-status updates when not using --dry-run or --audit-only}
        {--folder-filter=Family Tree Maker : Only process pairs where BOTH files match this path substring}';

    protected $description = 'Resolve file duplicates with AI audit safety gate. Audits random sample, then batch-resolves if accuracy passes threshold.';

    public function handle(): int
    {
        $folderFilter = $this->option('folder-filter');
        $sampleSize = (int) $this->option('sample-size');
        $minAccuracy = (int) $this->option('min-accuracy') / 100.0;
        $limit = max(1, min(500, (int) $this->option('limit')));
        $dryRun = (bool) $this->option('dry-run');
        $auditOnly = (bool) $this->option('audit-only');

        $service = app(DuplicateResolutionService::class);

        // --- Status mode ---
        if ($this->option('status')) {
            return $this->showStatus(
                service: $service,
                folderFilter: $folderFilter,
                json: (bool) $this->option('json'),
                compact: (bool) $this->option('compact'),
            );
        }

        if (! $dryRun && ! $auditOnly && ! $this->option('confirm')) {
            $this->error('Duplicate resolution writes file_registry_duplicates.status and requires --confirm. Use --dry-run or --audit-only first.');

            return Command::FAILURE;
        }

        // --- Fetch scoped pairs ---
        $this->info("Scope: files matching '{$folderFilter}' (limit {$limit})");
        $pairs = $service->getScopedPendingPairs($folderFilter, $limit);

        if (empty($pairs)) {
            $this->info('No pending duplicates in scope.');

            return Command::SUCCESS;
        }

        $this->info(count($pairs).' pending duplicate pairs found.');

        // --- Audit phase ---
        $this->newLine();
        $this->info('=== AUDIT PHASE ===');
        $audit = $service->auditSample($pairs, $sampleSize);

        $accuracyPct = round($audit['accuracy'] * 100, 1);
        $this->info("Sampled: {$audit['total_sampled']} | Passed: {$audit['passed']} | Failed: {$audit['failed']} | Accuracy: {$accuracyPct}%");

        if (! empty($audit['failures'])) {
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
            $this->error("Audit accuracy {$accuracyPct}% is below threshold ".($minAccuracy * 100).'%. Aborting.');
            Log::warning('FileDuplicateResolve: Audit failed accuracy gate', [
                'accuracy' => $audit['accuracy'],
                'threshold' => $minAccuracy,
                'failures' => $audit['failures'],
            ]);

            return Command::FAILURE;
        }

        $this->info("Audit PASSED ({$accuracyPct}% >= ".($minAccuracy * 100).'% threshold)');

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

        if (! $dryRun) {
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

    private function showStatus(DuplicateResolutionService $service, string $folderFilter, bool $json, bool $compact): int
    {
        $stats = $service->getStatistics($folderFilter);

        if ($json) {
            $payload = $compact
                ? $this->compactStatusPayload($stats, $folderFilter)
                : array_merge([
                    'version' => 1,
                    'mode' => 'status',
                    'folder_filter' => $folderFilter,
                ], $stats);
            $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if ($encoded === false) {
                $this->error('Failed to encode duplicate status JSON.');

                return Command::FAILURE;
            }

            $this->line($encoded);

            return Command::SUCCESS;
        }

        $this->info("=== Duplicate Statistics (scope: '{$folderFilter}') ===");
        $this->newLine();
        $this->line("Pending pairs:        {$stats['pending_pairs']}");
        $this->line("Unique content hashes: {$stats['unique_content_hashes']}");
        $this->line("Pairs with phash:     {$stats['pairs_with_phash']}");
        $this->line('Reclaimable space:    '.round($stats['reclaimable_bytes'] / 1024 / 1024, 1).' MB');

        if (! empty($stats['by_mime_type'])) {
            $this->newLine();
            $this->info('By MIME type:');
            foreach ($stats['by_mime_type'] as $mt) {
                $this->line("  {$mt['type']}: {$mt['count']}");
            }
        }

        return Command::SUCCESS;
    }

    private function compactStatusPayload(array $stats, string $folderFilter): array
    {
        $reclaimableBytes = (int) ($stats['reclaimable_bytes'] ?? 0);
        $mimeTypes = array_values(array_filter(
            array_map(
                static fn ($row): ?array => is_array($row)
                    ? [
                        'type' => is_scalar($row['type'] ?? null) ? (string) $row['type'] : 'unknown',
                        'count' => (int) ($row['count'] ?? 0),
                    ]
                    : null,
                (array) ($stats['by_mime_type'] ?? [])
            )
        ));

        return [
            'version' => 1,
            'mode' => 'status',
            'compact' => true,
            'scope' => [
                'folder_filter_label' => $folderFilter === 'Family Tree Maker' ? 'default_family_tree_maker' : 'custom_filter',
                'custom_filter_included' => false,
            ],
            'pending_pairs' => (int) ($stats['pending_pairs'] ?? 0),
            'unique_content_hashes' => (int) ($stats['unique_content_hashes'] ?? 0),
            'pairs_with_phash' => (int) ($stats['pairs_with_phash'] ?? 0),
            'reclaimable_bytes' => $reclaimableBytes,
            'reclaimable_mb' => round($reclaimableBytes / 1024 / 1024, 1),
            'by_mime_type' => $mimeTypes,
            'posture' => [
                'aggregate_only' => true,
                'paths_included' => false,
                'row_selectors_included' => false,
                'file_mutation_enabled' => false,
                'duplicate_resolution_enabled' => false,
            ],
        ];
    }
}
