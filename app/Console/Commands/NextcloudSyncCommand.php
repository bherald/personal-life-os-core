<?php

namespace App\Console\Commands;

use App\Services\NextcloudService;
use Illuminate\Console\Command;

class NextcloudSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nextcloud:sync
                            {--calendars : Sync calendar events to MySQL}
                            {--contacts : Sync contacts to MySQL}
                            {--all : Sync all data types}
                            {--months-before=12 : Months of calendar history to sync}
                            {--months-after=6 : Months of future calendar events to sync}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Nextcloud data (calendars, contacts) to MySQL for persistence and RAG indexing';

    /**
     * Execute the console command.
     */
    public function handle(NextcloudService $nextcloudService): int
    {
        $syncCalendars = $this->option('calendars') || $this->option('all');
        $syncContacts = $this->option('contacts') || $this->option('all');

        // Default to all if nothing specified
        if (!$syncCalendars && !$syncContacts) {
            $syncCalendars = true;
            $syncContacts = true;
        }

        $this->info('Starting Nextcloud sync to MySQL...');

        if ($syncCalendars) {
            $this->syncCalendars($nextcloudService);
        }

        if ($syncContacts) {
            $this->syncContacts($nextcloudService);
        }

        $this->info('Nextcloud sync completed.');
        return 0;
    }

    private function syncCalendars(NextcloudService $service): void
    {
        $monthsBefore = (int) $this->option('months-before');
        $monthsAfter = (int) $this->option('months-after');

        $this->info("Syncing calendar events ({$monthsBefore} months back, {$monthsAfter} months forward)...");

        try {
            $result = $service->syncCalendarEventsToDatabase($monthsBefore, $monthsAfter);

            $this->table(
                ['Metric', 'Count'],
                [
                    ['Fetched from Nextcloud', $result['fetched']],
                    ['Inserted (new)', $result['persisted']['inserted'] ?? 0],
                    ['Updated (existing)', $result['persisted']['updated'] ?? 0],
                    ['Errors', $result['persisted']['errors'] ?? 0],
                ]
            );

            $this->info("Date range: {$result['date_range']['start']} to {$result['date_range']['end']}");

        } catch (\Exception $e) {
            $this->error("Calendar sync failed: {$e->getMessage()}");
        }
    }

    private function syncContacts(NextcloudService $service): void
    {
        $this->info('Syncing contacts...');

        try {
            $result = $service->syncContactsToDatabase();

            $this->table(
                ['Metric', 'Count'],
                [
                    ['Fetched from Nextcloud', $result['fetched']],
                    ['Inserted (new)', $result['persisted']['inserted'] ?? 0],
                    ['Updated (existing)', $result['persisted']['updated'] ?? 0],
                    ['Errors', $result['persisted']['errors'] ?? 0],
                ]
            );

        } catch (\Exception $e) {
            $this->error("Contacts sync failed: {$e->getMessage()}");
        }
    }
}
