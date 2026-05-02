<?php

namespace App\Console\Commands;

use App\Services\JoplinMetadataCacheService;
use Illuminate\Console\Command;

class JoplinCacheRefresh extends Command
{
    protected $signature = 'joplin:cache-refresh {--limit=500 : Max notes to process} {--prune : Remove old deleted entries}';
    protected $description = 'Refresh Joplin metadata cache from WebDAV';

    public function __construct(
        private JoplinMetadataCacheService $cacheService
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $limit = (int) $this->option('limit');
        $prune = $this->option('prune');

        $this->info("Starting Joplin metadata cache refresh (limit: {$limit})...");
        $startTime = microtime(true);

        // Refresh metadata
        $stats = $this->cacheService->refreshAllMetadata($limit);

        $duration = round(microtime(true) - $startTime, 2);

        $this->info("Cache refresh completed in {$duration}s");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $stats['processed']],
                ['Created', $stats['created']],
                ['Updated', $stats['updated']],
                ['Deleted', $stats['deleted']],
                ['Errors', $stats['errors']],
            ]
        );

        // Prune old deleted entries if requested
        if ($prune) {
            $this->info('Pruning old deleted entries...');
            $pruned = $this->cacheService->pruneDeleted();
            $this->info("Pruned {$pruned} old deleted entries");
        }

        // Show cache stats
        $cacheStats = $this->cacheService->getStats();
        $this->newLine();
        $this->info('Current Cache Stats:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Notes', $cacheStats['total_notes']],
                ['Total Notebooks', $cacheStats['total_notebooks']],
                ['Deleted Notes', $cacheStats['deleted_notes']],
                ['Stale Entries', $cacheStats['stale_count']],
                ['Last Update', $cacheStats['last_cache_update']],
            ]
        );

        return Command::SUCCESS;
    }
}
