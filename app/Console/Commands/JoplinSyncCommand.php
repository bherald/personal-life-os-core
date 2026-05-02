<?php

namespace App\Console\Commands;

use App\Services\JoplinSyncService;
use Illuminate\Console\Command;

class JoplinSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'joplin:sync
                            {--limit= : Limit number of notes to process (useful for testing)}
                            {--stats : Show current sync statistics}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Joplin notes from Nextcloud to RAG for semantic search';

    /**
     * Execute the console command.
     */
    public function handle(JoplinSyncService $syncService): int
    {
        // Show stats only
        if ($this->option('stats')) {
            $stats = $syncService->getStats();

            $this->info('Joplin RAG Sync Statistics');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Joplin Notes in RAG', $stats['total_joplin_notes']],
                    ['Nextcloud URL', $stats['nextcloud_url']],
                    ['Joplin Path', $stats['joplin_path']],
                ]
            );

            return self::SUCCESS;
        }

        // Run sync
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        if ($limit) {
            $this->info("Starting Joplin sync (limited to {$limit} notes)...");
        } else {
            $this->info('Starting full Joplin sync...');
        }

        $this->line('');

        try {
            $progressBar = null;

            // Start sync
            $stats = $syncService->syncAll($limit);

            $this->newLine();
            if (!empty($stats['deferred'])) {
                $this->warn('Sync deferred: ' . ($stats['defer_reason'] ?? 'unknown reason'));
            }
            $this->info('✓ Sync completed successfully!');
            $this->newLine();

            // Display results
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Files Found', $stats['total_files']],
                    ['Notes Indexed', $stats['notes_indexed']],
                    ['Notes Skipped', $stats['notes_skipped'] . ' (folders/resources)'],
                    ['Errors', $stats['errors']],
                    ['Duration', $stats['duration_seconds'] . ' seconds'],
                    ['Started', $stats['start_time']->format('Y-m-d H:i:s')],
                    ['Completed', $stats['end_time']->format('Y-m-d H:i:s')],
                ]
            );

            if ($stats['errors'] > 0) {
                $this->warn('⚠ Some files failed to process. Check logs for details.');
                return self::FAILURE;
            }

            $this->newLine();
            $this->comment('Notes are now searchable via RAG:');
            $this->line('  php artisan rag:search "your search query"');
            $apiBase = rtrim(config('app.url', 'http://localhost'), '/');
            $this->line("  curl -X POST {$apiBase}/api/rag/search -d '{\"query\":\"your search\"}'");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('✗ Sync failed: ' . $e->getMessage());
            $this->newLine();
            $this->comment('Check logs for more details:');
            $this->line('  tail -f storage/logs/laravel.log');

            return self::FAILURE;
        }
    }
}
