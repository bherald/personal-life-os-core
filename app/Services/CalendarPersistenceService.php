<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

/**
 * Calendar Persistence Service (DI-1)
 *
 * Syncs Nextcloud calendar events to MySQL and indexes them into RAG.
 * Designed to run as a scheduled job every 2 hours.
 *
 * Pipeline: Nextcloud CalDAV → calendar_events (MySQL) → rag_documents (PostgreSQL)
 */
class CalendarPersistenceService
{
    private NextcloudService $nextcloud;
    private RAGService $ragService;

    public function __construct(NextcloudService $nextcloud, RAGService $ragService)
    {
        $this->nextcloud = $nextcloud;
        $this->ragService = $ragService;
    }

    /**
     * Full sync: pull from Nextcloud, persist to MySQL, index to RAG.
     *
     * @param int $monthsBefore Months of history to sync
     * @param int $monthsAfter Months ahead to sync
     * @return array Summary of sync results
     */
    public function syncAndIndex(int $monthsBefore = 12, int $monthsAfter = 12): array
    {
        $results = [
            'sync' => ['fetched' => 0, 'inserted' => 0, 'updated' => 0, 'errors' => 0],
            'rag' => ['indexed' => 0, 'skipped' => 0, 'errors' => 0],
        ];

        try {
            // Step 1: Sync from Nextcloud → MySQL
            $syncResult = $this->nextcloud->syncCalendarEventsToDatabase($monthsBefore, $monthsAfter);
            $results['sync']['fetched'] = $syncResult['fetched'] ?? 0;
            $results['sync']['inserted'] = $syncResult['persisted']['inserted'] ?? 0;
            $results['sync']['updated'] = $syncResult['persisted']['updated'] ?? 0;

            Log::info('CalendarPersistence: Sync complete', $results['sync']);
        } catch (Exception $e) {
            Log::error('CalendarPersistence: Sync failed', ['error' => $e->getMessage()]);
            $results['sync']['errors']++;
            return $results;
        }

        try {
            // Step 2: Index unindexed events to RAG
            $ragResult = $this->indexUnindexedEvents();
            $results['rag'] = $ragResult;

            Log::info('CalendarPersistence: RAG indexing complete', $results['rag']);
        } catch (Exception $e) {
            Log::error('CalendarPersistence: RAG indexing failed', ['error' => $e->getMessage()]);
            $results['rag']['errors']++;
        }

        return $results;
    }

    /**
     * Index calendar events that haven't been indexed to RAG yet.
     *
     * @param int $limit Max events to index per run
     * @return array Indexing results
     */
    public function indexUnindexedEvents(int $limit = 200): array
    {
        $indexed = 0;
        $skipped = 0;
        $errors = 0;

        $events = DB::select(
            "SELECT id, external_id, calendar_name, title, description, location,
                    start_at, end_at, all_day, attendees
             FROM calendar_events
             WHERE rag_indexed_at IS NULL
             ORDER BY start_at DESC
             LIMIT ?",
            [$limit]
        );

        foreach ($events as $event) {
            try {
                $content = $this->buildRagContent($event);

                if (empty(trim($content)) || strlen(trim($content)) < 20) {
                    // Skip events with no meaningful content (e.g., just a title with no description)
                    DB::update(
                        "UPDATE calendar_events SET rag_indexed_at = NOW() WHERE id = ?",
                        [$event->id]
                    );
                    $skipped++;
                    continue;
                }

                $metadata = [
                    'calendar_name' => $event->calendar_name,
                    'start_at' => $event->start_at,
                    'end_at' => $event->end_at,
                    'all_day' => (bool) $event->all_day,
                    'location' => $event->location,
                ];

                $result = $this->ragService->indexDocument(
                    documentType: 'calendar_event',
                    content: $content,
                    title: $event->title ?? 'Calendar Event',
                    metadata: $metadata,
                    sourceId: $event->id,
                    sourceType: 'calendar',
                );

                if ($result) {
                    DB::update(
                        "UPDATE calendar_events SET rag_indexed_at = NOW() WHERE id = ?",
                        [$event->id]
                    );
                    $indexed++;
                } else {
                    $skipped++;
                    // Mark as indexed anyway to avoid re-processing (dedup blocked it)
                    DB::update(
                        "UPDATE calendar_events SET rag_indexed_at = NOW() WHERE id = ?",
                        [$event->id]
                    );
                }
            } catch (Exception $e) {
                Log::warning('CalendarPersistence: Failed to index event', [
                    'event_id' => $event->id,
                    'error' => $e->getMessage(),
                ]);
                $errors++;
            }
        }

        return ['indexed' => $indexed, 'skipped' => $skipped, 'errors' => $errors, 'total' => count($events)];
    }

    /**
     * Re-index events that have been updated since last RAG indexing.
     *
     * @return array Re-indexing results
     */
    public function reindexUpdatedEvents(): array
    {
        $reindexed = 0;

        $events = DB::select(
            "SELECT id FROM calendar_events
             WHERE rag_indexed_at IS NOT NULL
               AND updated_at > rag_indexed_at
             LIMIT 100"
        );

        foreach ($events as $event) {
            DB::update(
                "UPDATE calendar_events SET rag_indexed_at = NULL WHERE id = ?",
                [$event->id]
            );
            $reindexed++;
        }

        if ($reindexed > 0) {
            Log::info("CalendarPersistence: Marked {$reindexed} updated events for re-indexing");
        }

        return ['marked_for_reindex' => $reindexed];
    }

    /**
     * Build RAG-optimized text content from a calendar event.
     * Includes temporal context so the RAG system can answer time-based queries.
     */
    private function buildRagContent(object $event): string
    {
        $parts = [];

        // Title
        $title = $event->title ?? 'Untitled Event';
        $parts[] = "Calendar Event: {$title}";

        // Temporal context
        if ($event->start_at) {
            $start = Carbon::parse($event->start_at);
            $dayOfWeek = $start->format('l');
            $date = $start->format('F j, Y');

            if ($event->all_day) {
                $parts[] = "Date: {$dayOfWeek}, {$date} (all day)";
            } else {
                $time = $start->format('g:i A');
                $parts[] = "Date: {$dayOfWeek}, {$date} at {$time}";

                if ($event->end_at) {
                    $end = Carbon::parse($event->end_at);
                    $parts[] = "End: {$end->format('g:i A')}";
                    $duration = $start->diffInMinutes($end);
                    if ($duration > 0) {
                        $hours = floor($duration / 60);
                        $mins = $duration % 60;
                        $durationStr = $hours > 0 ? "{$hours}h" : '';
                        $durationStr .= $mins > 0 ? " {$mins}m" : '';
                        $parts[] = "Duration: " . trim($durationStr);
                    }
                }
            }
        }

        // Calendar name
        if ($event->calendar_name) {
            $parts[] = "Calendar: {$event->calendar_name}";
        }

        // Location
        if (!empty($event->location)) {
            $parts[] = "Location: {$event->location}";
        }

        // Description
        if (!empty($event->description)) {
            $parts[] = "";
            $parts[] = trim($event->description);
        }

        // Attendees
        if (!empty($event->attendees)) {
            $attendees = json_decode($event->attendees, true);
            if (!empty($attendees)) {
                $names = array_map(fn($a) => $a['name'] ?? $a['email'] ?? 'Unknown', $attendees);
                $parts[] = "Attendees: " . implode(', ', $names);
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Get sync statistics.
     */
    public function getStats(): array
    {
        $total = DB::selectOne("SELECT COUNT(*) as c FROM calendar_events")->c;
        $indexed = DB::selectOne("SELECT COUNT(*) as c FROM calendar_events WHERE rag_indexed_at IS NOT NULL")->c;
        $unindexed = $total - $indexed;
        $stale = DB::selectOne(
            "SELECT COUNT(*) as c FROM calendar_events WHERE rag_indexed_at IS NOT NULL AND updated_at > rag_indexed_at"
        )->c;

        $ragDocs = 0;
        try {
            $ragDocs = DB::connection('pgsql_rag')
                ->selectOne("SELECT COUNT(*) as c FROM rag_documents WHERE source_type = 'calendar'")->c;
        } catch (\Throwable $e) {
            // PostgreSQL may be unavailable
        }

        return [
            'total_events' => (int) $total,
            'rag_indexed' => (int) $indexed,
            'rag_unindexed' => (int) $unindexed,
            'rag_stale' => (int) $stale,
            'rag_documents' => (int) $ragDocs,
        ];
    }
}
