<?php

namespace App\Nodes;

use App\Services\NextcloudService;
use Carbon\Carbon;
use Exception;

/**
 * CalendarDigest Node
 *
 * Fetches calendar events and formats them into a professional digest
 * for notification services like Pushover.
 */
class CalendarDigest extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $nextcloud = new NextcloudService();

            // Get configuration
            $daysAhead = (int) $this->getConfigValue('days_ahead', 3);
            $includeAllDay = $this->getConfigValue('include_all_day', true);
            $timezone = $this->getConfigValue('timezone', 'America/New_York');

            // Set timezone for Carbon
            Carbon::setLocale('en');

            // Calculate date range
            $startDate = Carbon::now($timezone)->startOfDay();
            $endDate = Carbon::now($timezone)->addDays($daysAhead - 1)->endOfDay();

            // Fetch events from all calendars
            $result = $nextcloud->getAllEventsCached(
                $startDate->toIso8601String(),
                $endDate->toIso8601String(),
                true // Force refresh
            );

            $events = $result['events'] ?? [];

            // Group events by day
            $eventsByDay = $this->groupEventsByDay($events, $timezone, $daysAhead);

            // Format the digest
            $formattedDigest = $this->formatDigest($eventsByDay, $timezone);

            // Count totals
            $totalEvents = array_sum(array_map('count', $eventsByDay));

            return $this->standardOutput([
                'formatted_text' => $formattedDigest,
                'digest' => $formattedDigest,
                'events_by_day' => $eventsByDay,
                'total_events' => $totalEvents,
                'days_covered' => $daysAhead,
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ], [
                'source' => 'nextcloud_caldav',
                'timezone' => $timezone,
            ]);

        } catch (Exception $e) {
            return $this->standardOutput(null, [], 'Calendar digest failed: ' . $e->getMessage());
        }
    }

    /**
     * Group events by day, sorted with all-day events first
     */
    private function groupEventsByDay(array $events, string $timezone, int $daysAhead): array
    {
        $grouped = [];
        $now = Carbon::now($timezone);

        // Initialize days
        for ($i = 0; $i < $daysAhead; $i++) {
            $date = $now->copy()->addDays($i);
            $key = $date->format('Y-m-d');
            $grouped[$key] = [
                'label' => $this->getDayLabel($date, $i),
                'date_formatted' => $date->format('m/d'),
                'day_name' => $date->format('l'),
                'all_day' => [],
                'timed' => [],
            ];
        }

        // Sort events into days
        foreach ($events as $event) {
            $eventStart = $this->parseEventDate($event['start'] ?? '', $timezone);
            if (!$eventStart) continue;

            $dateKey = $eventStart->format('Y-m-d');

            // Skip if event is outside our date range
            if (!isset($grouped[$dateKey])) continue;

            $isAllDay = $event['allDay'] ?? false;
            $formattedEvent = $this->formatEvent($event, $eventStart, $timezone);

            if ($isAllDay) {
                $grouped[$dateKey]['all_day'][] = $formattedEvent;
            } else {
                $grouped[$dateKey]['timed'][] = $formattedEvent;
            }
        }

        // Sort timed events by start time
        foreach ($grouped as &$day) {
            usort($day['timed'], function ($a, $b) {
                return strcmp($a['sort_time'], $b['sort_time']);
            });
        }

        return $grouped;
    }

    /**
     * Get day label (Today, Tomorrow, Day After Tomorrow)
     */
    private function getDayLabel(Carbon $date, int $daysFromNow): string
    {
        switch ($daysFromNow) {
            case 0:
                return 'Today';
            case 1:
                return 'Tomorrow';
            case 2:
                return 'Day After Tomorrow';
            default:
                return $date->format('l'); // Full day name for 3+ days out
        }
    }

    /**
     * Parse event date string to Carbon
     */
    private function parseEventDate(string $dateStr, string $timezone): ?Carbon
    {
        if (empty($dateStr)) return null;

        try {
            // Handle UTC dates (with Z suffix)
            if (str_ends_with($dateStr, 'Z')) {
                return Carbon::parse($dateStr)->setTimezone($timezone);
            }
            // Handle dates without time (all-day events)
            if (strlen($dateStr) === 10) {
                return Carbon::parse($dateStr, $timezone)->startOfDay();
            }
            // Handle other formats
            return Carbon::parse($dateStr)->setTimezone($timezone);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Format a single event for display
     */
    private function formatEvent(array $event, Carbon $startTime, string $timezone): array
    {
        $title = $event['title'] ?? 'Untitled Event';
        $isAllDay = $event['allDay'] ?? false;

        $formatted = [
            'title' => $title,
            'is_all_day' => $isAllDay,
            'sort_time' => $startTime->format('H:i'),
        ];

        if (!$isAllDay) {
            $formatted['time'] = $startTime->format('g:i A');

            // Add end time if available
            if (!empty($event['end'])) {
                $endTime = $this->parseEventDate($event['end'], $timezone);
                if ($endTime) {
                    $formatted['time'] .= ' - ' . $endTime->format('g:i A');
                }
            }
        }

        // Add location if available
        $location = $event['extendedProps']['location'] ?? '';
        if (!empty($location)) {
            $formatted['location'] = $location;
        }

        return $formatted;
    }

    /**
     * Format the complete digest for Pushover (HTML format)
     * Uses symbols for visual hierarchy since Pushover collapses whitespace
     */
    private function formatDigest(array $eventsByDay, string $timezone): string
    {
        $lines = [];
        $totalEvents = 0;

        foreach ($eventsByDay as $dateKey => $day) {
            $allDayCount = count($day['all_day']);
            $timedCount = count($day['timed']);
            $dayEventCount = $allDayCount + $timedCount;
            $totalEvents += $dayEventCount;

            // Day header: "📅 Wed 12/17 · TODAY"
            $dayAbbrev = substr($day['day_name'], 0, 3);
            $headerLabel = strtoupper($day['label']);
            $lines[] = "<b><font color=\"#2980b9\">📅 {$dayAbbrev} {$day['date_formatted']}</font></b> · <font color=\"#7f8c8d\">{$headerLabel}</font>";

            // Show "No events" for empty days
            if ($dayEventCount === 0) {
                $lines[] = "<font color=\"#95a5a6\">── <i>No events</i></font>";
                $lines[] = '';
                continue;
            }

            // All-day events first (purple star)
            foreach ($day['all_day'] as $event) {
                $lines[] = "<font color=\"#9b59b6\">★</font> <b>{$event['title']}</b> <font color=\"#9b59b6\">· all day</font>";
            }

            // Timed events with time
            foreach ($day['timed'] as $event) {
                $time = $event['time'] ?? '';
                $title = $event['title'];

                // Format: "● 9:00 AM → Meeting Title"
                $lines[] = "<font color=\"#27ae60\">●</font> <font color=\"#16a085\">{$time}</font> → {$title}";

                // Location on same conceptual level but with arrow prefix
                if (!empty($event['location'])) {
                    $loc = $this->truncateLocation($event['location']);
                    $lines[] = "<font color=\"#7f8c8d\">⤷ {$loc}</font>";
                }
            }

            // Blank line between days
            $lines[] = '';
        }

        // Remove trailing blank line
        while (!empty($lines) && $lines[count($lines) - 1] === '') {
            array_pop($lines);
        }

        // If no events at all across all days
        if ($totalEvents === 0) {
            $dayCount = count($eventsByDay);
            return implode("\n", $lines) . "\n\n<font color=\"#95a5a6\"><i>Calendar clear for {$dayCount} days</i></font>";
        }

        return implode("\n", $lines);
    }

    /**
     * Truncate long location strings
     */
    private function truncateLocation(string $location, int $maxLength = 40): string
    {
        $location = trim($location);
        if (strlen($location) <= $maxLength) {
            return $location;
        }
        return substr($location, 0, $maxLength - 3) . '...';
    }
}
