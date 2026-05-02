<?php

namespace App\Console\Commands;

use App\Services\MboxParserService;
use App\Services\RAGService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DI-6: Index Thunderbird email archives into RAG for semantic search.
 *
 * Reads mbox files from a configured Thunderbird archive folder, parses
 * messages, and indexes to RAG. Dedup by message_id.
 *
 * Usage:
 *   php artisan email:rag-index --scan          # List available mbox files
 *   php artisan email:rag-index --stats          # Show indexing stats
 *   php artisan email:rag-index --limit=100      # Index 100 messages
 *   php artisan email:rag-index --folder=Inbox   # Specific folder
 *   php artisan email:rag-index --dry-run        # Preview without indexing
 */
class EmailRagIndexCommand extends Command
{
    private const DEFAULT_TIMEOUT_MINUTES = 60;

    private const TIMEOUT_SAFETY_BUFFER_SECONDS = 300;

    protected $signature = 'email:rag-index
                            {--scan : Scan and list available mbox files}
                            {--stats : Show indexing statistics}
                            {--folder= : Specific mbox folder name to index}
                            {--limit=100 : Max messages per run}
                            {--max-files=2 : Max mailboxes to scan per run after freshness filtering}
                            {--timeout= : Wall-clock timeout in minutes (stops 5 minutes early)}
                            {--dry-run : Preview without indexing}
                            {--min-length=50 : Minimum body length to index}';

    protected $description = 'DI-6: Index Thunderbird email archives into RAG';

    public function handle(): int
    {
        $nextcloudPath = config('services.nextcloud.data_path');
        if (! $nextcloudPath) {
            $this->error('NEXTCLOUD_DATA_PATH not configured');

            return 1;
        }

        $profilePath = $this->resolveArchiveProfilePath((string) $nextcloudPath);
        $parser = new MboxParserService;

        if ($this->option('scan')) {
            return $this->scanMailboxes($parser, $profilePath);
        }

        if ($this->option('stats')) {
            return $this->showStats();
        }

        return $this->indexMessages($parser, $profilePath);
    }

    private function resolveArchiveProfilePath(string $nextcloudDataPath): string
    {
        $profile = (string) config('services.thunderbird.archive_profile_path', '/Email/Thunderbird');

        return rtrim($nextcloudDataPath, '/').'/'.ltrim($profile, '/');
    }

    private function scanMailboxes(MboxParserService $parser, string $profilePath): int
    {
        $this->info("Scanning: {$profilePath}");

        $mboxFiles = $parser->scanProfile($profilePath);

        if (empty($mboxFiles)) {
            $this->warn('No mbox files found.');

            return 0;
        }

        $this->table(
            ['Folder', 'Size', 'Path'],
            array_map(fn ($f) => [$f['name'], $f['size_human'], $f['path']], $mboxFiles)
        );

        $totalSize = array_sum(array_column($mboxFiles, 'size'));
        $this->info(count($mboxFiles).' mbox files, '.round($totalSize / 1048576).' MB total');

        return 0;
    }

    private function indexMessages(MboxParserService $parser, string $profilePath): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');
        $minLength = (int) $this->option('min-length');
        $folderFilter = $this->option('folder');
        $maxFiles = max(1, (int) $this->option('max-files'));

        $mboxFiles = $parser->scanProfile($profilePath);

        if ($folderFilter) {
            $mboxFiles = array_filter($mboxFiles, fn ($f) => str_contains($f['name'], $folderFilter));
        }

        if (empty($mboxFiles)) {
            $this->warn('No matching mbox files found.');

            return 0;
        }

        $mboxFiles = $this->filterMailboxesNeedingIndex($mboxFiles);
        $mboxFiles = array_slice($mboxFiles, 0, $maxFiles);

        if (empty($mboxFiles)) {
            $this->info('No mailboxes changed since the last index pass.');

            return 0;
        }

        $ragService = app(RAGService::class);
        $indexed = 0;
        $skipped = 0;
        $duped = 0;
        $startTime = microtime(true);
        $deadlineSeconds = $this->resolveDeadlineSeconds();

        foreach ($mboxFiles as $mbox) {
            if ($indexed >= $limit || $this->hasReachedDeadline($startTime, $deadlineSeconds)) {
                break;
            }

            $remaining = $limit - $indexed;
            $this->info("Processing: {$mbox['name']} ({$mbox['size_human']})");

            foreach ($parser->parseFile($mbox['path'], $remaining) as $msg) {
                if ($indexed >= $limit || $this->hasReachedDeadline($startTime, $deadlineSeconds)) {
                    break;
                }

                // Skip too-short messages
                if ($msg['body_length'] < $minLength) {
                    $skipped++;

                    continue;
                }

                // Dedup by message_id
                if ($msg['message_id']) {
                    $hash = hash('sha256', $msg['message_id']);
                    $exists = DB::select(
                        'SELECT id FROM rag_email_index WHERE message_hash = ? LIMIT 1',
                        [$hash]
                    );
                    if (! empty($exists)) {
                        $duped++;

                        continue;
                    }
                } else {
                    // No message-id: hash subject+from+date
                    $hash = hash('sha256', ($msg['subject'] ?? '').($msg['from'] ?? '').($msg['date'] ?? ''));
                }

                if ($dryRun) {
                    $this->line("  Would index: {$msg['subject']} ({$msg['body_length']} chars)");
                    $indexed++;

                    continue;
                }

                try {
                    // Build RAG content
                    $content = $this->buildEmailContent($msg, $mbox['name']);

                    $ragService->indexDocument(
                        documentType: 'email',
                        content: $content,
                        title: $msg['subject'] ?: 'No Subject',
                        metadata: [
                            'from' => $msg['from'],
                            'to' => $msg['to'],
                            'date' => $msg['date'],
                            'folder' => $mbox['name'],
                        ],
                        sourceId: $hash,
                        sourceType: 'email_archive',
                        options: [
                            // Email archive indexing already deduplicates by stable message hash
                            // before this call. Skipping semantic dedup avoids an expensive
                            // recursive near-duplicate check on large HTML bodies.
                            'skip_dedup' => true,
                        ],
                    );

                    // Track in dedup table
                    DB::insert(
                        'INSERT IGNORE INTO rag_email_index (message_hash, subject, sender, message_date, folder, indexed_at)
                         VALUES (?, ?, ?, ?, ?, NOW())',
                        [$hash, mb_substr($msg['subject'] ?? '', 0, 500), mb_substr($msg['from'] ?? '', 0, 255),
                            $this->parseDate($msg['date']), $mbox['name']]
                    );

                    $indexed++;

                    if ($indexed % 25 === 0) {
                        $elapsed = round(microtime(true) - $startTime, 1);
                        $this->line("  Progress: {$indexed} indexed, {$duped} dupes, {$skipped} skipped ({$elapsed}s)");
                    }

                } catch (\Exception $e) {
                    Log::warning('EmailRagIndex: Failed to index message', [
                        'subject' => $msg['subject'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                    $skipped++;
                }
            }
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        if ($this->hasReachedDeadline($startTime, $deadlineSeconds)) {
            $this->warn("Stopped early to stay within runtime budget ({$deadlineSeconds}s).");
        }
        $this->info("Done: {$indexed} indexed, {$duped} duplicates, {$skipped} skipped ({$elapsed}s). [ITEMS_PROCESSED:{$indexed}]");

        return 0;
    }

    private function buildEmailContent(array $msg, string $folder): string
    {
        $parts = [];

        $parts[] = "Email: {$msg['subject']}";
        if ($msg['from']) {
            $parts[] = "From: {$msg['from']}";
        }
        if ($msg['to']) {
            $parts[] = "To: {$msg['to']}";
        }
        if ($msg['date']) {
            $parts[] = "Date: {$msg['date']}";
        }
        $parts[] = "Folder: {$folder}";

        if ($msg['body']) {
            // Limit body to 8000 chars for RAG (avoid huge emails)
            $parts[] = "\n".mb_substr($msg['body'], 0, 8000);
        }

        return implode("\n", $parts);
    }

    private function parseDate(?string $dateStr): ?string
    {
        if (! $dateStr) {
            return null;
        }
        try {
            return date('Y-m-d H:i:s', strtotime($dateStr));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function showStats(): int
    {
        try {
            $stats = DB::selectOne(
                'SELECT COUNT(*) as total, MIN(indexed_at) as oldest, MAX(indexed_at) as newest,
                        COUNT(DISTINCT folder) as folders
                 FROM rag_email_index'
            );

            $this->info('Email RAG Index Stats:');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total indexed', $stats->total ?? 0],
                    ['Folders', $stats->folders ?? 0],
                    ['Oldest', $stats->oldest ?? 'N/A'],
                    ['Newest', $stats->newest ?? 'N/A'],
                ]
            );
        } catch (\Throwable $e) {
            $this->warn("Stats unavailable: {$e->getMessage()}");
            $this->info("Run the migration first if rag_email_index table doesn't exist.");
        }

        return 0;
    }

    private function resolveDeadlineSeconds(): int
    {
        $optionTimeout = (int) ($this->option('timeout') ?? 0);
        if ($optionTimeout > 0) {
            return max(60, ($optionTimeout * 60) - self::TIMEOUT_SAFETY_BUFFER_SECONDS);
        }

        try {
            $job = DB::selectOne(
                "SELECT timeout_minutes FROM scheduled_jobs WHERE name = 'email_rag_index' LIMIT 1"
            );
            $timeoutMinutes = max(1, (int) ($job->timeout_minutes ?? self::DEFAULT_TIMEOUT_MINUTES));
        } catch (\Throwable) {
            $timeoutMinutes = self::DEFAULT_TIMEOUT_MINUTES;
        }

        return max(60, ($timeoutMinutes * 60) - self::TIMEOUT_SAFETY_BUFFER_SECONDS);
    }

    private function hasReachedDeadline(float $startTime, int $deadlineSeconds): bool
    {
        return (microtime(true) - $startTime) >= $deadlineSeconds;
    }

    /**
     * Skip cold mailboxes that have not changed since their last successful index pass.
     *
     * This keeps scheduled runs focused on recently updated folders instead of rescanning
     * large historical archives just to confirm duplicates.
     *
     * @param  array<int, array<string, mixed>>  $mboxFiles
     * @return array<int, array<string, mixed>>
     */
    private function filterMailboxesNeedingIndex(array $mboxFiles): array
    {
        if (empty($mboxFiles)) {
            return [];
        }

        $lastIndexedByFolder = collect(DB::select(
            'SELECT folder, MAX(indexed_at) AS last_indexed_at
             FROM rag_email_index
             GROUP BY folder'
        ))->mapWithKeys(function ($row) {
            return [$row->folder => strtotime($row->last_indexed_at ?? '') ?: 0];
        });

        return array_values(array_filter($mboxFiles, function (array $mbox) use ($lastIndexedByFolder) {
            $folder = $mbox['name'] ?? null;
            $modifiedAt = (int) ($mbox['modified_at'] ?? 0);
            $lastIndexedAt = (int) ($lastIndexedByFolder[$folder] ?? 0);

            return $lastIndexedAt === 0 || $modifiedAt === 0 || $modifiedAt > $lastIndexedAt;
        }));
    }
}
