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

        Log::info('ContactsSyncRAGJob completed', [
            'fetched' => $results['sync']['fetched'],
            'inserted' => $results['sync']['inserted'],
            'updated' => $results['sync']['updated'],
            'unchanged' => $results['sync']['unchanged'],
            'rag_indexed' => $results['rag']['indexed'],
            'rag_skipped' => $results['rag']['skipped'],
            'marked_for_reindex' => $reindex['marked_for_reindex'],
        ]);

        // Emit items_processed for ScheduledJobService
        $total = ($results['sync']['inserted'] ?? 0) + ($results['sync']['updated'] ?? 0) + ($results['rag']['indexed'] ?? 0);
        echo "[ITEMS_PROCESSED:{$total}]";
    }
}
