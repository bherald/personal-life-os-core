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
                            {--stats : Show current sync statistics}
                            {--json : Emit machine-readable JSON}';

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

            if ($this->option('json')) {
                $this->emitJson([
                    'mode' => 'stats',
                    'status' => ($stats['stats_available'] ?? true) === false ? 'degraded' : 'ok',
                    'stats' => $stats,
                ]);

                return self::SUCCESS;
            }

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

        if (! $this->option('json')) {
            if ($limit) {
                $this->info("Starting Joplin sync (limited to {$limit} notes)...");
            } else {
                $this->info('Starting full Joplin sync...');
            }

            $this->line('');
        }

        try {
            $progressBar = null;

            // Start sync
            $stats = $syncService->syncAll($limit);

            if ($this->option('json')) {
                $this->emitJson($this->syncPayload($stats));

                return ! empty($stats['deferred']) || ($stats['errors'] ?? 0) > 0
                    ? self::FAILURE
                    : self::SUCCESS;
            }

            $this->newLine();
            $deferred = ! empty($stats['deferred']);
            $errorCount = (int) ($stats['errors'] ?? 0);

            if ($deferred) {
                $this->warn('Sync deferred: '.($stats['defer_reason'] ?? 'unknown reason'));
            } elseif ($errorCount > 0) {
                $this->warn('Sync completed with errors.');
            } else {
                $this->info('✓ Sync completed successfully!');
            }
            $this->newLine();

            // Display results
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Files Found', $stats['total_files']],
                    ['Notes Indexed', $stats['notes_indexed']],
                    ['Notes Skipped', $stats['notes_skipped'].' (folders/resources)'],
                    ['Errors', $stats['errors']],
                    ['Duration', $stats['duration_seconds'].' seconds'],
                    ['Started', $stats['start_time']->format('Y-m-d H:i:s')],
                    ['Completed', $stats['end_time']->format('Y-m-d H:i:s')],
                ]
            );

            if ($deferred || $errorCount > 0) {
                if ($errorCount > 0) {
                    $this->warn('⚠ Some files failed to process. Check logs for details.');
                }
                $errorSamples = $stats['error_samples'] ?? [];
                if (! empty($errorSamples)) {
                    $this->warn('Recent error samples:');
                    foreach ($errorSamples as $sample) {
                        $note = $sample['note_hash'] ?? 'unknown';
                        $error = $sample['error'] ?? 'unknown error';
                        $this->line("  - note {$note}: {$error}");
                    }
                }

                return self::FAILURE;
            }

            $this->newLine();
            $this->comment('Notes are now searchable via RAG:');
            $this->line('  php artisan rag:search "your search query"');
            $apiBase = rtrim(config('app.url', 'http://localhost'), '/');
            $this->line("  curl -X POST {$apiBase}/api/rag/search -d '{\"query\":\"your search\"}'");

            return self::SUCCESS;
        } catch (\Exception $e) {
            if ($this->option('json')) {
                $this->emitJson([
                    'mode' => 'sync',
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ]);

                return self::FAILURE;
            }

            $this->error('✗ Sync failed: '.$e->getMessage());
            $this->newLine();
            $this->comment('Check logs for more details:');
            $this->line('  tail -f storage/logs/laravel.log');

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitJson(array $payload): void
    {
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    private function syncPayload(array $stats): array
    {
        $errors = (int) ($stats['errors'] ?? 0);
        $status = ! empty($stats['deferred'])
            ? 'deferred'
            : ($errors > 0 ? 'partial' : 'success');

        return [
            'mode' => 'sync',
            'status' => $status,
            'deferred' => (bool) ($stats['deferred'] ?? false),
            'defer_reason' => $stats['defer_reason'] ?? null,
            'total_files' => (int) ($stats['total_files'] ?? 0),
            'notes_indexed' => (int) ($stats['notes_indexed'] ?? 0),
            'notes_skipped' => (int) ($stats['notes_skipped'] ?? 0),
            'errors' => $errors,
            'error_samples' => array_values(array_filter(
                (array) ($stats['error_samples'] ?? []),
                'is_array'
            )),
            'duration_seconds' => (float) ($stats['duration_seconds'] ?? 0),
            'started_at' => $this->formatTimestamp($stats['start_time'] ?? null),
            'completed_at' => $this->formatTimestamp($stats['end_time'] ?? null),
        ];
    }

    private function formatTimestamp(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
