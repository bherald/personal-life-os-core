<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\NextcloudService;
use Exception;

class TestNextcloudCalendar extends Command
{
    protected $signature = 'nextcloud:test';
    protected $description = 'Test Nextcloud CalDAV connection';

    public function handle(): int
    {
        $this->info('Testing Nextcloud CalDAV Connection...');
        $this->newLine();

        $service = app(NextcloudService::class);

        // Test 1: List calendars
        $this->info('Test 1: Listing calendars...');
        try {
            $calendars = $service->getCalendars();
            $this->info('✓ Success! Found ' . count($calendars) . ' calendars:');
            foreach ($calendars as $calendar) {
                $this->line("  - {$calendar['displayName']} (name: {$calendar['name']})");
            }
        } catch (Exception $e) {
            $this->error('✗ Failed to list calendars:');
            $this->error($e->getMessage());
            return 1;
        }

        $this->newLine();

        // Test 2: Get events from default calendar (first available)
        $calendarName = $calendars[0]['name'] ?? null;
        $this->info('Test 2: Getting events from default calendar (' . ($calendarName ?? 'unknown') . ')...');
        try {
            $events = $service->getCalendarEvents();
            $this->info('✓ Success! Found ' . count($events) . ' events:');
            foreach ($events as $event) {
                $summary = $event['summary'] ?? 'No title';
                $start = $event['start'] ?? 'Unknown';
                $this->line("  - {$summary} (starts: {$start})");
            }
        } catch (Exception $e) {
            $this->error('✗ Failed to get events:');
            $this->error($e->getMessage());
            return 1;
        }

        $this->newLine();
        $this->info('All tests passed!');
        return 0;
    }
}
