<?php

namespace App\Jobs;

use App\Services\ContactsPersistenceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * DI-2: Contacts Sync + RAG Indexing Job
 *
 * Runs every 6 hours via scheduled_jobs table.
 * Pipeline: Nextcloud CardDAV → contacts (MySQL) → rag_documents (PostgreSQL)
 */
class ContactsSyncRAGJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('long-running');
    }

    public function handle(): void
    {
        $service = app(ContactsPersistenceService::class);

        // First, mark updated contacts for re-indexing
        $reindex = $service->reindexUpdatedContacts();

        // Then run full sync + index
        $results = $service->syncAndIndex();

        $summary = sprintf(
            'Contacts sync: fetched=%d, inserted=%d, updated=%d, unchanged=%d | RAG: indexed=%d, skipped=%d, reindexed=%d',
            $results['sync']['fetched'],
            $results['sync']['inserted'],
            $results['sync']['updated'],
            $results['sync']['unchanged'],
            $results['rag']['indexed'],
            $results['rag']['skipped'],
            $reindex['marked_for_reindex']
        );

        Log::info("ContactsSyncRAGJob: {$summary}");

        // Emit items_processed for ScheduledJobService
        $total = ($results['sync']['inserted'] ?? 0) + ($results['sync']['updated'] ?? 0) + ($results['rag']['indexed'] ?? 0);
        echo "[ITEMS_PROCESSED:{$total}]";
    }
}
