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

        $summary = sprintf(
            'Calendar sync: fetched=%d, inserted=%d, updated=%d | RAG: indexed=%d, skipped=%d, reindexed=%d',
            $results['sync']['fetched'],
            $results['sync']['inserted'],
            $results['sync']['updated'],
            $results['rag']['indexed'],
            $results['rag']['skipped'],
            $reindex['marked_for_reindex']
        );

        Log::info("CalendarSyncRAGJob: {$summary}");
    }
}
