<?php

namespace App\Console\Commands;

use App\Jobs\JoplinAttachmentJob;
use App\Services\JoplinAttachmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * JoplinAttachmentsCommand
 *
 * Unified command for Joplin attachment processing:
 * - reprocess: Queue attachments for v2 extraction
 * - cleanup: Delete old joplin_attachment RAG records
 * - status: Show processing statistics
 *
 * Usage:
 *   php artisan joplin:attachments --action=reprocess --limit=50 --dry-run --force
 *   php artisan joplin:attachments --action=cleanup
 *   php artisan joplin:attachments --action=status
 */
class JoplinAttachmentsCommand extends Command
{
    protected $signature = 'joplin:attachments
                            {--action=status : Action to perform (reprocess, cleanup, status)}
                            {--limit=50 : Maximum attachments to process}
                            {--dry-run : Show what would be done without doing it}
                            {--force : Force reprocess all, ignore version/hash checks}';

    protected $description = 'Manage Joplin attachment extraction with v2 pipeline';

    protected JoplinAttachmentService $service;

    public function __construct(JoplinAttachmentService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function handle(): int
    {
        $action = $this->option('action');
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info("Joplin Attachments Manager");
        $this->info("==========================");
        $this->info("Action: {$action}");

        return match ($action) {
            'reprocess' => $this->handleReprocess($limit, $dryRun, $force),
            'cleanup' => $this->handleCleanup($dryRun),
            'status' => $this->handleStatus(),
            'discover' => $this->handleDiscover($limit, $dryRun),
            default => $this->handleUnknown($action),
        };
    }

    /**
     * Discover attachments from existing Joplin notes and queue for processing
     */
    protected function handleDiscover(int $limit, bool $dryRun): int
    {
        $this->info("Discovering attachments from Joplin notes...");

        // Find all joplin_note documents
        $sql = "SELECT id, source_id, content, title
                FROM rag_documents
                WHERE designation = 'joplin_note'
                ORDER BY updated_at DESC";

        $notes = DB::connection('pgsql_rag')->select($sql);
        $this->info("Found " . count($notes) . " Joplin notes in RAG");

        $attachmentsFound = 0;
        $attachmentsQueued = 0;

        foreach ($notes as $note) {
            // Parse note content for attachment references (both MD links and HTML img tags)
            $attachments = $this->extractAttachmentReferences($note->content);
            $noteId = $note->source_id;

            foreach ($attachments as $att) {
                $filename = $att['filename'];
                $resourceId = $att['resource_id'];

                $attachmentsFound++;

                if ($attachmentsQueued >= $limit) {
                    continue;
                }

                // Check if already in index
                $existing = DB::selectOne(
                    "SELECT id, sync_status FROM joplin_attachment_index WHERE resource_id = ?",
                    [$resourceId]
                );

                if ($existing && $existing->sync_status === 'synced') {
                    continue; // Already processed
                }

                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                // Skip if extension is too long (malformed reference)
                if (strlen($extension) > 20) {
                    continue;
                }

                if ($dryRun) {
                    $this->line("  [DRY-RUN] Would queue: {$filename} (note: {$noteId})");
                } else {
                    // Insert/update index entry
                    $sql = "INSERT INTO joplin_attachment_index
                            (note_id, resource_id, filename, extension, sync_status, created_at, updated_at)
                            VALUES (?, ?, ?, ?, 'queued', NOW(), NOW())
                            ON DUPLICATE KEY UPDATE sync_status = 'queued', updated_at = NOW()";

                    DB::statement($sql, [$noteId, $resourceId, substr($filename, 0, 255), $extension]);

                    // Dispatch job
                    JoplinAttachmentJob::dispatch($resourceId, $filename, $noteId);
                    $this->line("  Queued: {$filename}");
                }

                $attachmentsQueued++;
            }
        }

        $this->newLine();
        $this->info("Discovery complete:");
        $this->table(['Metric', 'Count'], [
            ['Attachments found', $attachmentsFound],
            ['Attachments queued', $attachmentsQueued],
            ['Limit', $limit],
        ]);

        if ($dryRun) {
            $this->warn("Dry run - no jobs were actually queued");
        }

        return Command::SUCCESS;
    }

    /**
     * Reprocess attachments with v2 pipeline
     */
    protected function handleReprocess(int $limit, bool $dryRun, bool $force): int
    {
        $this->info("Reprocessing attachments with v2 pipeline...");
        $this->info("Limit: {$limit}, Dry-run: " . ($dryRun ? 'Yes' : 'No') . ", Force: " . ($force ? 'Yes' : 'No'));
        $this->newLine();

        // First, discover any new attachments from notes
        $this->info("Step 1: Discovering attachments from notes...");

        $sql = "SELECT source_id, content FROM rag_documents WHERE designation = 'joplin_note'";
        $notes = DB::connection('pgsql_rag')->select($sql);

        $discovered = 0;
        foreach ($notes as $note) {
            // Extract attachments from note content (both markdown links and HTML img tags)
            $attachments = $this->extractAttachmentReferences($note->content);

            foreach ($attachments as $att) {
                $filename = $att['filename'];
                $resourceId = $att['resource_id'];
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                // Skip if extension is too long (malformed reference)
                if (strlen($extension) > 20) {
                    continue;
                }

                // Upsert to index
                if (!$dryRun) {
                    $sql = "INSERT INTO joplin_attachment_index
                            (note_id, resource_id, filename, extension, sync_status, created_at, updated_at)
                            VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())
                            ON DUPLICATE KEY UPDATE filename = VALUES(filename), updated_at = NOW()";
                    DB::statement($sql, [$note->source_id, $resourceId, substr($filename, 0, 255), $extension]);
                }
                $discovered++;
            }
        }

        $this->info("Discovered {$discovered} attachments from " . count($notes) . " notes");
        $this->newLine();

        // Step 2: Find attachments needing reprocessing
        $this->info("Step 2: Finding attachments to reprocess...");

        $currentVersion = config('services.joplin_attachments.extraction_version', 'v2');

        if ($force) {
            $sql = "SELECT note_id, resource_id, filename, extraction_version, sync_status
                    FROM joplin_attachment_index
                    ORDER BY updated_at ASC
                    LIMIT ?";
            $attachments = DB::select($sql, [$limit]);
        } else {
            $sql = "SELECT note_id, resource_id, filename, extraction_version, sync_status
                    FROM joplin_attachment_index
                    WHERE extraction_version < ? OR extraction_version IS NULL OR sync_status IN ('pending', 'error')
                    ORDER BY updated_at ASC
                    LIMIT ?";
            $attachments = DB::select($sql, [$currentVersion, $limit]);
        }

        $this->info("Found " . count($attachments) . " attachments to process");
        $this->newLine();

        if (empty($attachments)) {
            $this->info("No attachments need reprocessing.");
            return Command::SUCCESS;
        }

        // Step 3: Queue jobs
        $this->info("Step 3: Queueing jobs for Horizon...");

        $queued = 0;
        foreach ($attachments as $att) {
            if ($dryRun) {
                $this->line("  [DRY-RUN] Would queue: {$att->filename} ({$att->extraction_version} → {$currentVersion})");
            } else {
                // Update status to queued
                DB::statement(
                    "UPDATE joplin_attachment_index SET sync_status = 'queued', updated_at = NOW() WHERE resource_id = ?",
                    [$att->resource_id]
                );

                // Dispatch job
                JoplinAttachmentJob::dispatch(
                    $att->resource_id,
                    $att->filename,
                    $att->note_id,
                    $force
                );

                $this->line("  Queued: {$att->filename}");
            }
            $queued++;
        }

        $this->newLine();
        $this->info("Reprocess queuing complete:");
        $this->table(['Metric', 'Count'], [
            ['Notes scanned', count($notes)],
            ['Attachments discovered', $discovered],
            ['Jobs queued', $queued],
        ]);

        if ($dryRun) {
            $this->warn("Dry run mode - no jobs were actually queued");
        } else {
            $this->info("Jobs are now processing via Horizon. Check status with:");
            $this->line("  php artisan joplin:attachments --action=status");
        }

        Log::channel('single')->info('Joplin attachment reprocess initiated', [
            'notes_scanned' => count($notes),
            'discovered' => $discovered,
            'queued' => $queued,
            'force' => $force,
            'dry_run' => $dryRun,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Cleanup old joplin_attachment RAG records
     */
    protected function handleCleanup(bool $dryRun): int
    {
        $this->info("Cleaning up old joplin_attachment RAG records...");

        // Count existing records
        $countSql = "SELECT COUNT(*) as count FROM rag_documents WHERE designation = 'joplin_attachment'";
        $result = DB::connection('pgsql_rag')->selectOne($countSql);
        $count = $result->count ?? 0;

        $this->info("Found {$count} joplin_attachment records to delete");

        if ($count === 0) {
            $this->info("Nothing to clean up.");
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("[DRY-RUN] Would delete {$count} records");
            return Command::SUCCESS;
        }

        if (!$this->confirm("Are you sure you want to delete {$count} joplin_attachment records?")) {
            $this->info("Cleanup cancelled.");
            return Command::SUCCESS;
        }

        $deleted = $this->service->cleanupOldRagRecords();

        $this->info("Deleted {$deleted} joplin_attachment RAG records");

        Log::channel('single')->info('Joplin attachment cleanup completed', [
            'deleted' => $deleted,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Show processing statistics
     */
    protected function handleStatus(): int
    {
        $this->info("Joplin Attachment Processing Status");
        $this->newLine();

        $stats = $this->service->getStats();

        // Status breakdown
        $this->info("By Status:");
        if (!empty($stats['by_status'])) {
            $statusData = [];
            foreach ($stats['by_status'] as $status => $count) {
                $statusData[] = [$status, $count];
            }
            $this->table(['Status', 'Count'], $statusData);
        } else {
            $this->line("  No attachments in index");
        }

        $this->newLine();

        // Version breakdown
        $this->info("By Extraction Version:");
        if (!empty($stats['by_version'])) {
            $versionData = [];
            foreach ($stats['by_version'] as $version => $count) {
                $versionData[] = [$version ?: 'null', $count];
            }
            $this->table(['Version', 'Count'], $versionData);
        } else {
            $this->line("  No version data");
        }

        $this->newLine();
        $this->info("Current extraction version: " . $stats['current_version']);

        // Queue status
        $this->newLine();
        $this->info("Queue Status:");
        $pendingJobs = $this->getPendingLongRunningJobs();
        $failedJobs = DB::selectOne("SELECT COUNT(*) as count FROM failed_jobs WHERE payload LIKE ?", ['%JoplinAttachmentJob%'])->count ?? 0;

        $this->table(['Queue', 'Count'], [
            ['Pending long-running jobs', $pendingJobs],
            ['Failed jobs (Joplin)', $failedJobs],
        ]);

        // Recent errors
        if (!empty($stats['recent_errors'])) {
            $this->newLine();
            $this->warn("Recent Errors:");
            foreach ($stats['recent_errors'] as $error) {
                $this->line("  [{$error->updated_at}] {$error->filename}: " . substr($error->error_log, 0, 80));
            }
        }

        // Old RAG records
        $this->newLine();
        $oldRagCount = DB::connection('pgsql_rag')
            ->selectOne("SELECT COUNT(*) as count FROM rag_documents WHERE designation = 'joplin_attachment'");
        $this->info("Legacy RAG Records:");
        $this->line("  joplin_attachment records: " . ($oldRagCount->count ?? 0));
        if (($oldRagCount->count ?? 0) > 0) {
            $this->warn("  Run --action=cleanup to remove these legacy records");
        }

        return Command::SUCCESS;
    }

    /**
     * Handle unknown action
     */
    protected function handleUnknown(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->line("Valid actions: reprocess, cleanup, status, discover");
        return Command::FAILURE;
    }

    private function getPendingLongRunningJobs(): int
    {
        if (config('queue.default') === 'redis') {
            try {
                return (int) (Redis::llen('queues:long-running') ?? 0);
            } catch (\Throwable $e) {
                Log::debug('JoplinAttachmentsCommand: Redis queue depth lookup failed', ['error' => $e->getMessage()]);
                return 0;
            }
        }

        return (int) (DB::selectOne("SELECT COUNT(*) as count FROM jobs WHERE queue = ?", ['long-running'])->count ?? 0);
    }

    /**
     * Extract attachment references from note content
     * Handles both markdown links [filename](:/resource_id) and HTML img tags <img src=":/resource_id" alt="filename">
     *
     * @param string $content Note content
     * @return array Array of ['filename' => string, 'resource_id' => string]
     */
    protected function extractAttachmentReferences(string $content): array
    {
        $attachments = [];
        $seen = []; // Track by resource_id to avoid duplicates

        // Pattern 1: Markdown links [filename](:/resource_id)
        preg_match_all('/\[([^\]]+)\]\(:\/([a-f0-9]{32})\)/i', $content, $mdMatches, PREG_SET_ORDER);
        foreach ($mdMatches as $match) {
            $resourceId = $match[2];
            if (!isset($seen[$resourceId])) {
                $attachments[] = [
                    'filename' => $match[1],
                    'resource_id' => $resourceId,
                ];
                $seen[$resourceId] = true;
            }
        }

        // Pattern 2: HTML img tags <img src=":/resource_id" alt="filename" ...>
        preg_match_all('/<img[^>]+src=":\/([a-f0-9]{32})"[^>]*alt="([^"]+)"[^>]*>/i', $content, $imgMatches, PREG_SET_ORDER);
        foreach ($imgMatches as $match) {
            $resourceId = $match[1];
            if (!isset($seen[$resourceId])) {
                $attachments[] = [
                    'filename' => $match[2],
                    'resource_id' => $resourceId,
                ];
                $seen[$resourceId] = true;
            }
        }

        // Pattern 3: HTML img with alt before src <img alt="filename" src=":/resource_id" ...>
        preg_match_all('/<img[^>]+alt="([^"]+)"[^>]+src=":\/([a-f0-9]{32})"[^>]*>/i', $content, $imgAltFirstMatches, PREG_SET_ORDER);
        foreach ($imgAltFirstMatches as $match) {
            $resourceId = $match[2];
            if (!isset($seen[$resourceId])) {
                $attachments[] = [
                    'filename' => $match[1],
                    'resource_id' => $resourceId,
                ];
                $seen[$resourceId] = true;
            }
        }

        return $attachments;
    }
}
