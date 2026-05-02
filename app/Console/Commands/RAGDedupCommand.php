<?php

namespace App\Console\Commands;

use App\Services\SemDeDupService;
use Illuminate\Console\Command;

/**
 * RAG Deduplication Command
 *
 * Manages semantic deduplication of RAG documents.
 *
 * Usage:
 *   php artisan rag:dedup --stats
 *   php artisan rag:dedup --backfill-hashes
 *   php artisan rag:dedup --scan --dry-run --threshold=0.90
 *   php artisan rag:dedup --find-duplicates --type=joplin_note
 *   php artisan rag:dedup --remove-duplicates --dry-run
 */
class RAGDedupCommand extends Command
{
    protected $signature = 'rag:dedup
        {--stats : Show deduplication statistics}
        {--scan : Scan existing documents for duplicates}
        {--backfill-hashes : Compute content hashes for documents missing them}
        {--find-duplicates : Find duplicate documents by content hash}
        {--remove-duplicates : Remove duplicate documents (keeps oldest)}
        {--dry-run : Preview actions without executing}
        {--type= : Filter by document type}
        {--threshold=0.95 : Similarity threshold for embedding dedup}
        {--batch=100 : Batch size for processing}';

    protected $description = 'Manage semantic deduplication of RAG documents';

    public function handle(): int
    {
        $service = app(SemDeDupService::class);

        if ($this->option('stats')) {
            return $this->showStats($service);
        }

        if ($this->option('backfill-hashes')) {
            return $this->backfillHashes($service);
        }

        if ($this->option('scan')) {
            return $this->scanDocuments($service);
        }

        if ($this->option('find-duplicates')) {
            return $this->findDuplicates($service);
        }

        if ($this->option('remove-duplicates')) {
            return $this->removeDuplicates($service);
        }

        $this->info('Usage: rag:dedup --stats|--scan|--backfill-hashes|--find-duplicates|--remove-duplicates');
        return 0;
    }

    private function showStats(SemDeDupService $service): int
    {
        $stats = $service->getStats();

        $this->info("RAG Dedup Statistics");
        $this->line("Total documents: {$stats['total_documents']}");
        $this->line("With content hash: {$stats['hash_coverage']}");
        $this->line("Missing hash: " . ($stats['total_documents'] - $stats['hash_coverage']));

        if (!empty($stats['by_dedup_status'])) {
            $this->newLine();
            $this->info("By Dedup Status:");
            foreach ($stats['by_dedup_status'] as $status => $count) {
                $this->line("  {$status}: {$count}");
            }
        }

        if (!empty($stats['log_by_strategy'])) {
            $this->newLine();
            $this->info("Log by Strategy:");
            foreach ($stats['log_by_strategy'] as $strategy => $count) {
                $this->line("  {$strategy}: {$count}");
            }
        }

        if (!empty($stats['log_by_action'])) {
            $this->newLine();
            $this->info("Log by Action:");
            foreach ($stats['log_by_action'] as $action => $count) {
                $this->line("  {$action}: {$count}");
            }
        }

        return 0;
    }

    private function backfillHashes(SemDeDupService $service): int
    {
        $batchSize = (int) $this->option('batch');
        $type = $this->option('type');
        $totalProcessed = 0;
        $totalErrors = 0;

        $this->info("Backfilling content hashes" . ($type ? " for type: {$type}" : ""));

        do {
            $result = $service->backfillHashes($batchSize, $type);
            $totalProcessed += $result['processed'];
            $totalErrors += $result['errors'];

            if ($result['processed'] > 0) {
                $this->line("  Processed: {$totalProcessed} (errors: {$totalErrors})");
            }
        } while ($result['processed'] >= $batchSize);

        $this->info("Done. Total processed: {$totalProcessed}, errors: {$totalErrors}");
        return 0;
    }

    private function scanDocuments(SemDeDupService $service): int
    {
        $batchSize = (int) $this->option('batch');
        $type = $this->option('type');
        $dryRun = $this->option('dry-run');
        $totalScanned = 0;
        $totalDuplicates = 0;

        $this->info("Scanning for duplicates" . ($type ? " (type: {$type})" : "") . ($dryRun ? " [DRY RUN]" : ""));

        do {
            $result = $service->scanExistingDocuments($batchSize, $type);
            $totalScanned += $result['scanned'];
            $totalDuplicates += $result['duplicates_found'];

            if ($result['scanned'] > 0) {
                $this->line("  Scanned: {$totalScanned}, duplicates: {$totalDuplicates}");
            }
        } while ($result['scanned'] >= $batchSize);

        $this->info("Done. Scanned: {$totalScanned}, duplicates found: {$totalDuplicates}");
        return 0;
    }

    private function findDuplicates(SemDeDupService $service): int
    {
        $type = $this->option('type');
        $this->info("Finding duplicate groups" . ($type ? " for type: {$type}" : ""));

        $duplicates = $service->findDuplicates($type);

        if (empty($duplicates)) {
            $this->info("No duplicates found.");
            return 0;
        }

        $headers = ['Hash (first 12)', 'Count', 'First ID', 'All IDs', 'Sample Title'];
        $rows = [];

        foreach ($duplicates as $group) {
            $rows[] = [
                substr($group['content_hash'], 0, 12) . '...',
                $group['count'],
                $group['first_id'],
                is_string($group['all_ids']) ? substr($group['all_ids'], 0, 40) : implode(',', $group['all_ids']),
                substr($group['sample_title'] ?? '(no title)', 0, 40),
            ];
        }

        $this->table($headers, $rows);
        $this->info("Found " . count($duplicates) . " duplicate groups.");
        return 0;
    }

    private function removeDuplicates(SemDeDupService $service): int
    {
        $dryRun = $this->option('dry-run');
        $type = $this->option('type');

        $label = $dryRun ? "[DRY RUN] " : "";
        $this->info("{$label}Removing duplicate documents" . ($type ? " (type: {$type})" : ""));

        $removed = $service->removeDuplicates($dryRun, $type);

        if ($dryRun) {
            $this->info("Would remove {$removed} duplicate documents.");
        } else {
            $this->info("Removed {$removed} duplicate documents.");
        }

        return 0;
    }
}
