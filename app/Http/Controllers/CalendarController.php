<?php

namespace App\Http\Controllers;

use App\Services\NextcloudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;

/**
 * Calendar Controller
 *
 * Provides API endpoints for calendar integration with Nextcloud CalDAV.
 * Supports fetching calendars, events, and formatting for FullCalendar.
 *
 * Features:
 * - Intelligent caching for fast response times
 * - Force refresh capability via ?force=true
 * - Cache metadata in responses (lastUpdated, fromCache)
 */
class CalendarController extends Controller
{
    private NextcloudService $nextcloud;

    public function __construct(NextcloudService $nextcloud)
    {
        $this->nextcloud = $nextcloud;
    }

    /**
     * Get list of available calendars
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getCalendars(Request $request): JsonResponse
    {
        try {
            $forceRefresh = $request->boolean('force', false);
            $calendars = $this->nextcloud->getCalendars($forceRefresh);

            return response()->json([
                'success' => true,
                'data' => [
                    'calendars' => $calendars,
                ],
                'cache' => $this->nextcloud->getCacheStatus()['calendars'] ?? null,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => 'Failed to fetch calendars: ' . $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get calendar events for a date range
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getEvents(Request $request): JsonResponse
    {
        try {
            $calendarName = $request->query('calendar');
            $start = $request->query('start');
            $end = $request->query('end');

            $events = $this->nextcloud->getCalendarEvents($calendarName, $start, $end);

            // Transform to FullCalendar format
            $fullCalendarEvents = array_map(function ($event) {
                return [
                    'id' => $event['uid'] ?? uniqid(),
                    'title' => $event['summary'] ?? 'Untitled Event',
                    'start' => $event['start'] ?? null,
                    'end' => $event['end'] ?? null,
                    'description' => $event['description'] ?? '',
                    'location' => $event['location'] ?? '',
                    'allDay' => $this->isAllDayEvent($event),
                    'extendedProps' => [
                        'uid' => $event['uid'] ?? null,
                        'description' => $event['description'] ?? '',
                        'location' => $event['location'] ?? '',
                    ]
                ];
            }, $events);

            return response()->json([
                'success' => true,
                'data' => [
                    'events' => $fullCalendarEvents
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => 'Failed to fetch events: ' . $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get events from all calendars combined (cached)
     *
     * Uses intelligent caching for fast response times.
     * Pass ?force=true to bypass cache and fetch fresh data.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAllEvents(Request $request): JsonResponse
    {
        try {
            $start = $request->query('start');
            $end = $request->query('end');
            $forceRefresh = $request->boolean('force', false);

            // Use the cached method from NextcloudService
            $result = $this->nextcloud->getAllEventsCached($start, $end, $forceRefresh);

            return response()->json([
                'success' => true,
                'data' => [
                    'events' => $result['events'],
                    'calendars' => $result['calendars'],
                ],
                'cache' => $result['cache'] ?? null,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => 'Failed to fetch events: ' . $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Clear calendar caches and return fresh data
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            // Clear caches
            $this->nextcloud->clearAllCaches();

            // Get the requested date range
            $start = $request->query('start');
            $end = $request->query('end');

            // Fetch fresh data
            $result = $this->nextcloud->getAllEventsCached($start, $end, true);

            return response()->json([
                'success' => true,
                'message' => 'Cache refreshed successfully',
                'data' => [
                    'events' => $result['events'],
                    'calendars' => $result['calendars'],
                ],
                'cache' => $result['cache'] ?? null,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'message' => 'Failed to refresh calendar data: ' . $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Get cache status for calendars
     *
     * @return JsonResponse
     */
    public function cacheStatus(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->nextcloud->getCacheStatus(),
        ]);
    }
}
