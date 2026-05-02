<?php

namespace App\Console\Commands;

use App\Services\MediaUrlService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill media_url for existing RAG documents and Joplin attachments
 *
 * E17/EA1: Adds Nextcloud WebDAV URLs to existing records that were
 * created before the media_url feature was implemented.
 */
class BackfillMediaUrls extends Command
{
    protected $signature = 'media:backfill-urls
                            {--dry-run : Show what would be updated without making changes}
                            {--rag-only : Only update RAG documents}
                            {--joplin-only : Only update Joplin attachments}
                            {--force : Update all records, even those with existing URLs}';

    protected $description = 'Backfill media_url for existing RAG documents and Joplin attachments';

    public function __construct(
        private MediaUrlService $mediaUrlService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $ragOnly = $this->option('rag-only');
        $joplinOnly = $this->option('joplin-only');
        $force = $this->option('force');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        if ($force) {
            $this->warn('FORCE MODE - Updating all records including those with existing URLs');
        }

        $stats = [
            'rag_updated' => 0,
            'rag_skipped' => 0,
            'joplin_updated' => 0,
            'joplin_skipped' => 0,
        ];

        // Update Joplin attachments
        if (!$ragOnly) {
            $this->info('Processing Joplin attachments...');
            $stats = array_merge($stats, $this->backfillJoplinAttachments($dryRun, $force));
        }

        // Update RAG documents
        if (!$joplinOnly) {
            $this->info('Processing RAG documents...');
            $stats = array_merge($stats, $this->backfillRagDocuments($dryRun, $force));
        }

        // Summary
        $this->newLine();
        $this->info('=== Backfill Summary ===');
        $this->table(
            ['Type', 'Updated', 'Skipped'],
            [
                ['Joplin Attachments', $stats['joplin_updated'], $stats['joplin_skipped']],
                ['RAG Documents', $stats['rag_updated'], $stats['rag_skipped']],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * Backfill media_url for Joplin attachments
     */
    private function backfillJoplinAttachments(bool $dryRun, bool $force = false): array
    {
        $updated = 0;
        $skipped = 0;

        $sql = "SELECT * FROM joplin_attachment_index" . (!$force ? " WHERE media_url IS NULL" : "");
        $attachments = collect(DB::select($sql));

        $this->output->progressStart($attachments->count());

        foreach ($attachments as $attachment) {
            $mediaUrl = $this->mediaUrlService->getJoplinAttachmentUrl(
                $attachment->resource_id,
                $attachment->filename
            );

            if ($mediaUrl) {
                if (!$dryRun) {
                    DB::update("UPDATE joplin_attachment_index SET media_url = ? WHERE id = ?", [$mediaUrl, $attachment->id]);
                }
                $updated++;
            } else {
                $skipped++;
            }

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
        $this->info("  Joplin attachments: {$updated} updated, {$skipped} skipped");

        return [
            'joplin_updated' => $updated,
            'joplin_skipped' => $skipped,
        ];
    }

    /**
     * Backfill media_url for RAG documents
     *
     * Only Joplin-sourced documents can have media URLs (for now).
     * Future: Add Windows file URLs when migrated to Nextcloud.
     */
    private function backfillRagDocuments(bool $dryRun, bool $force = false): array
    {
        $updated = 0;
        $skipped = 0;

        // Get RAG documents that are Joplin-sourced
        $whereClause = "(document_type LIKE ? OR source_type LIKE ? OR designation LIKE ?)";
        $params = ['joplin%', '%Joplin%', 'joplin%'];

        if (!$force) {
            $whereClause .= " AND media_url IS NULL";
        }

        $documents = DB::connection('pgsql_rag')->select(
            "SELECT * FROM rag_documents WHERE {$whereClause}",
            $params
        );

        $this->output->progressStart(count($documents));

        foreach ($documents as $doc) {
            $mediaUrl = null;

            // Try to determine the source and generate URL
            if ($this->isJoplinAttachment($doc)) {
                // For Joplin attachments, source_id should be the resource ID
                $resourceId = $this->extractResourceId($doc);
                if ($resourceId) {
                    $mediaUrl = $this->mediaUrlService->getJoplinAttachmentUrl($resourceId);
                }
            }

            if ($mediaUrl) {
                if (!$dryRun) {
                    DB::connection('pgsql_rag')
                        ->table('rag_documents')
                        ->where('id', $doc->id)
                        ->update(['media_url' => $mediaUrl]);
                }
                $updated++;
            } else {
                $skipped++;
            }

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
        $this->info("  RAG documents: {$updated} updated, {$skipped} skipped");

        return [
            'rag_updated' => $updated,
            'rag_skipped' => $skipped,
        ];
    }

    /**
     * Check if document is a Joplin attachment
     */
    private function isJoplinAttachment($doc): bool
    {
        $type = $doc->document_type ?? '';
        $sourceType = $doc->source_type ?? '';
        $designation = $doc->designation ?? '';

        return str_contains($type, 'joplin_attachment')
            || str_contains($sourceType, 'JoplinAttachment')
            || str_contains($designation, 'joplin_attachment');
    }

    /**
     * Extract Joplin resource ID from document metadata or source_id
     */
    private function extractResourceId($doc): ?string
    {
        // Try source_id first (should be the resource ID for attachments)
        if (!empty($doc->source_id) && is_string($doc->source_id) && strlen($doc->source_id) === 32) {
            return $doc->source_id;
        }

        // Try to extract from metadata
        if (!empty($doc->metadata)) {
            $metadata = is_string($doc->metadata) ? json_decode($doc->metadata, true) : $doc->metadata;
            if (isset($metadata['resource_id'])) {
                return $metadata['resource_id'];
            }
            if (isset($metadata['joplin_resource_id'])) {
                return $metadata['joplin_resource_id'];
            }
        }

        return null;
    }
}
