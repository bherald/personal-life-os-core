<?php

namespace App\Console\Commands;

use App\Services\NextcloudService;
use Illuminate\Console\Command;

class NextcloudCacheRefresh extends Command
{
    protected $signature = 'nextcloud:cache-refresh
                            {--calendars : Only refresh calendar data}
                            {--contacts : Only refresh contacts data}
                            {--clear : Clear caches before refreshing}';

    protected $description = 'Refresh Nextcloud calendar and contacts cache for faster UI response times';

    public function __construct(
        private NextcloudService $nextcloud
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $onlyCalendars = $this->option('calendars');
        $onlyContacts = $this->option('contacts');
        $clearFirst = $this->option('clear');

        // Default to both if neither specified
        $refreshCalendars = $onlyCalendars || (!$onlyCalendars && !$onlyContacts);
        $refreshContacts = $onlyContacts || (!$onlyCalendars && !$onlyContacts);

        $this->info('Starting Nextcloud cache refresh...');
        $startTime = microtime(true);

        // Clear caches if requested
        if ($clearFirst) {
            $this->info('Clearing existing caches...');
            $cleared = $this->nextcloud->clearAllCaches();
            $this->line('  Cleared: ' . implode(', ', $cleared));
        }

        $results = [];

        // Refresh calendars
        if ($refreshCalendars) {
            $this->info('Refreshing calendar data...');
            try {
                // Calculate date range (current month +/- 2 months)
                $start = date('Y-m-d', strtotime('-2 months'));
                $end = date('Y-m-d', strtotime('+2 months'));

                $result = $this->nextcloud->getAllEventsCached($start, $end, true);
                $results['calendars'] = [
                    'success' => true,
                    'calendars_count' => count($result['calendars'] ?? []),
                    'events_count' => count($result['events'] ?? []),
                ];
                $this->line("  Cached {$results['calendars']['calendars_count']} calendars with {$results['calendars']['events_count']} events");
            } catch (\Exception $e) {
                $results['calendars'] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
                $this->error("  Calendar refresh failed: {$e->getMessage()}");
            }
        }

        // Refresh contacts
        if ($refreshContacts) {
            $this->info('Refreshing contacts data...');
            try {
                $result = $this->nextcloud->getAllContactsCached(true);
                $results['contacts'] = [
                    'success' => true,
                    'addressbooks_count' => count($result['addressBooks'] ?? []),
                    'contacts_count' => $result['count'] ?? 0,
                ];
                $this->line("  Cached {$results['contacts']['addressbooks_count']} address books with {$results['contacts']['contacts_count']} contacts");
            } catch (\Exception $e) {
                $results['contacts'] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
                $this->error("  Contacts refresh failed: {$e->getMessage()}");
            }
        }

        $duration = round(microtime(true) - $startTime, 2);
        $this->newLine();
        $this->info("Cache refresh completed in {$duration}s");

        // Show cache status
        $status = $this->nextcloud->getCacheStatus();
        $this->newLine();
        $this->info('Current Cache Status:');

        $statusTable = [];
        foreach ($status as $key => $info) {
            $statusTable[] = [
                ucfirst($key),
                $info['cached'] ? 'Yes' : 'No',
                $info['lastUpdated'] ?? 'Never',
                isset($info['ttl']) ? "{$info['ttl']}s" : '-',
            ];
        }

        $this->table(
            ['Cache', 'Cached', 'Last Updated', 'TTL'],
            $statusTable
        );

        // Return failure if any refresh failed
        $hasFailure = collect($results)->contains(fn($r) => !$r['success']);
        return $hasFailure ? Command::FAILURE : Command::SUCCESS;
    }
}
