<?php

namespace App\Jobs;

use App\Services\CalendarPersistenceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * DI-1: Calendar Sync + RAG Indexing Job
 *
 * Runs every 2 hours via scheduled_jobs table.
 * Pipeline: Nextcloud CalDAV → calendar_events (MySQL) → rag_documents (PostgreSQL)
 */
class CalendarSyncRAGJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('long-running');
    }

    public function handle(): void
    {
        $service = app(CalendarPersistenceService::class);

        // First, mark updated events for re-indexing
        $reindex = $service->reindexUpdatedEvents();

        // Then run full sync + index
        $results = $service->syncAndIndex();

        Log::info('CalendarSyncRAGJob completed', [
            'fetched' => $results['sync']['fetched'],
            'inserted' => $results['sync']['inserted'],
            'updated' => $results['sync']['updated'],
            'rag_indexed' => $results['rag']['indexed'],
            'rag_skipped' => $results['rag']['skipped'],
            'marked_for_reindex' => $reindex['marked_for_reindex'],
        ]);
    }
}
