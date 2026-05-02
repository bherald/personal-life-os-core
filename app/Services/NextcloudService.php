<?php

namespace App\Services;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Nextcloud Service
 *
 * Direct integration with Nextcloud Calendar via CalDAV API.
 * Provides calendar access without relying on broken mcp-nextcloud package.
 *
 * Features:
 * - Intelligent caching with configurable TTLs
 * - Force refresh capability
 * - Cache metadata (lastUpdated timestamps)
 * - Stale-while-revalidate pattern support
 */
class NextcloudService
{
    private string $baseUrl;

    private string $username;

    private string $password;

    private const HTTP_CONNECT_TIMEOUT = 5;

    private const HTTP_TIMEOUT = 120;

    /** Cache TTLs in seconds */
    private const CACHE_TTL_CALENDARS = 3600;        // 1 hour

    private const CACHE_TTL_EVENTS = 300;            // 5 minutes

    private const CACHE_TTL_ADDRESS_BOOKS = 3600;    // 1 hour

    private const CACHE_TTL_CONTACTS = 600;          // 10 minutes

    /** Cache key prefixes */
    private const CACHE_PREFIX = 'nextcloud:';

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.nextcloud.url', ''), '/');
        $this->username = (string) config('services.nextcloud.username', '');
        $this->password = config('services.nextcloud.password') ?? '';
    }

    /**
     * Get cache key with prefix
     */
    private function cacheKey(string $key): string
    {
        return self::CACHE_PREFIX.$key;
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout(self::HTTP_CONNECT_TIMEOUT)
            ->timeout(self::HTTP_TIMEOUT)
            ->withBasicAuth($this->username, $this->password);
    }

    /**
     * Get data with cache metadata wrapper
     */
    private function withCacheMetadata(mixed $data, string $cacheKey, bool $fromCache = true): array
    {
        $metadata = Cache::get($cacheKey.':meta', [
            'lastUpdated' => null,
            'lastRefresh' => null,
        ]);

        return [
            'data' => $data,
            'cache' => [
                'fromCache' => $fromCache,
                'lastUpdated' => $metadata['lastUpdated'] ?? now()->toIso8601String(),
                'lastRefresh' => $metadata['lastRefresh'] ?? null,
            ],
        ];
    }

    /**
     * Store cache metadata
     */
    private function storeCacheMetadata(string $cacheKey): void
    {
        Cache::put($cacheKey.':meta', [
            'lastUpdated' => now()->toIso8601String(),
            'lastRefresh' => now()->toIso8601String(),
        ], self::CACHE_TTL_CALENDARS * 2); // Metadata lives longer than data
    }

    /**
     * Clear all Nextcloud caches
     */
    public function clearAllCaches(): array
    {
        $cleared = [];

        // Clear calendar caches
        Cache::forget($this->cacheKey('calendars'));
        Cache::forget($this->cacheKey('calendars:meta'));
        $cleared[] = 'calendars';

        // Clear address book caches
        Cache::forget($this->cacheKey('addressbooks'));
        Cache::forget($this->cacheKey('addressbooks:meta'));
        $cleared[] = 'addressbooks';

        // Clear contacts caches (pattern-based)
        // Note: For contacts, we use a different approach - clear on refresh
        $cleared[] = 'contacts';

        Log::info('Nextcloud caches cleared', ['cleared' => $cleared]);

        return $cleared;
    }

    /**
     * Get cache status for monitoring
     */
    public function getCacheStatus(): array
    {
        return [
            'calendars' => [
                'cached' => Cache::has($this->cacheKey('calendars')),
                'meta' => Cache::get($this->cacheKey('calendars:meta')),
            ],
            'addressbooks' => [
                'cached' => Cache::has($this->cacheKey('addressbooks')),
                'meta' => Cache::get($this->cacheKey('addressbooks:meta')),
            ],
            'contacts_all' => [
                'cached' => Cache::has($this->cacheKey('contacts:all')),
                'meta' => Cache::get($this->cacheKey('contacts:all:meta')),
            ],
        ];
    }

    /**
     * Get calendar events for a time range
     *
     * @param  string|null  $calendarName  Calendar name (defaults to configured Nextcloud calendar)
     * @param  string|null  $start  Start date (ISO 8601)
     * @param  string|null  $end  End date (ISO 8601)
     * @return array Events
     */
    public function getCalendarEvents(?string $calendarName = null, ?string $start = null, ?string $end = null): array
    {
        // Get available calendars
        $calendars = $this->getCalendars();
        if (empty($calendars)) {
            throw new Exception('No calendars found');
        }

        // Default to configured calendar if not specified
        if (! $calendarName) {
            $calendarName = config('services.nextcloud.default_calendar', config('services.nextcloud.username', 'plos'));
        }

        // Resolve calendar name to calendar ID
        $calendarId = null;

        // Search by displayName, name, or id
        foreach ($calendars as $cal) {
            if ($cal['displayName'] === $calendarName ||
                $cal['name'] === $calendarName ||
                $cal['id'] === $calendarName) {
                $calendarId = $cal['id'];
                break;
            }
        }

        // Fall back to first calendar if specified one doesn't exist
        if (! $calendarId) {
            $calendarId = $calendars[0]['id'];
        }

        // Default to current month if not specified
        if (! $start) {
            $start = date('Y-m-d', strtotime('first day of this month')).'T00:00:00';
        }
        if (! $end) {
            // Get last day of month
            $end = date('Y-m-d', strtotime('last day of this month')).'T23:59:59';
        }

        // Convert to CalDAV format (YYYYMMDDTHHMMSSZ)
        // Parse dates properly to handle various ISO 8601 formats (with/without milliseconds, timezone)
        $startDt = new \DateTime($start);
        $endDt = new \DateTime($end);

        // Convert to UTC before formatting - CalDAV Z suffix means UTC
        $startDt->setTimezone(new \DateTimeZone('UTC'));
        $endDt->setTimezone(new \DateTimeZone('UTC'));

        // Format as CalDAV expects: YYYYMMDDTHHMMSSZ
        $startCaldav = $startDt->format('Ymd\THis').'Z';
        $endCaldav = $endDt->format('Ymd\THis').'Z';

        // CalDAV REPORT request with EXPAND to properly expand recurring events
        $xml = <<<XML
<?xml version="1.0" encoding="utf-8" ?>
<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:prop>
    <D:getetag/>
    <C:calendar-data>
      <C:expand start="{$startCaldav}" end="{$endCaldav}"/>
    </C:calendar-data>
  </D:prop>
  <C:filter>
    <C:comp-filter name="VCALENDAR">
      <C:comp-filter name="VEVENT">
        <C:time-range start="{$startCaldav}" end="{$endCaldav}"/>
      </C:comp-filter>
    </C:comp-filter>
  </C:filter>
</C:calendar-query>
XML;

        $url = "{$this->baseUrl}/remote.php/dav/calendars/{$this->username}/{$calendarId}/";

        try {
            $response = $this->http()
                ->withHeaders([
                    'Content-Type' => 'application/xml; charset=utf-8',
                    'Depth' => '1',
                ])
                ->send('REPORT', $url, ['body' => $xml]);

            if (! $response->successful()) {
                throw new Exception('CalDAV request failed: '.$response->status());
            }

            return $this->parseCalendarResponse($response->body());

        } catch (Exception $e) {
            throw new Exception('Failed to get calendar events: '.$e->getMessage());
        }
    }

    /**
     * Get all calendar events from all calendars (with caching)
     *
     * This is an optimized method that caches the combined result
     * for faster subsequent loads.
     *
     * @param  string|null  $start  Start date (ISO 8601)
     * @param  string|null  $end  End date (ISO 8601)
     * @param  bool  $forceRefresh  Bypass cache and fetch fresh data
     * @return array Combined events with calendar info and cache metadata
     */
    public function getAllEventsCached(?string $start = null, ?string $end = null, bool $forceRefresh = false): array
    {
        // Normalize dates for cache key
        if (! $start) {
            $start = date('Y-m-d', strtotime('first day of this month')).'T00:00:00';
        }
        if (! $end) {
            $end = date('Y-m-d', strtotime('last day of this month')).'T23:59:59';
        }

        // Create a cache key based on the date range
        $dateKey = md5($start.'|'.$end);
        $cacheKey = $this->cacheKey("events:all:{$dateKey}");

        // Return cached data if available and not forcing refresh
        if (! $forceRefresh && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);

            return [
                'events' => $cached['events'],
                'calendars' => $cached['calendars'],
                'cache' => [
                    'fromCache' => true,
                    'lastUpdated' => Cache::get($cacheKey.':meta')['lastUpdated'] ?? null,
                ],
            ];
        }

        // Fetch fresh data
        $calendars = $this->getCalendars($forceRefresh);
        $allEvents = [];

        // Color palette for different calendars
        $colors = [
            '#3498db', // Blue
            '#e74c3c', // Red
            '#27ae60', // Green
            '#9b59b6', // Purple
            '#f39c12', // Orange
            '#1abc9c', // Teal
            '#e67e22', // Dark Orange
            '#34495e', // Dark Gray
        ];

        foreach ($calendars as $index => $calendar) {
            try {
                $events = $this->getCalendarEvents($calendar['id'], $start, $end);
                $color = $colors[$index % count($colors)];

                foreach ($events as $event) {
                    $allEvents[] = [
                        'id' => $event['uid'] ?? uniqid(),
                        'title' => $event['summary'] ?? 'Untitled Event',
                        'start' => $event['start'] ?? null,
                        'end' => $event['end'] ?? null,
                        'allDay' => $this->isAllDayEvent($event),
                        'backgroundColor' => $color,
                        'borderColor' => $color,
                        'extendedProps' => [
                            'uid' => $event['uid'] ?? null,
                            'description' => $event['description'] ?? '',
                            'location' => $event['location'] ?? '',
                            'calendar' => $calendar['displayName'],
                            'calendarId' => $calendar['id'],
                        ],
                    ];
                }
            } catch (Exception $e) {
                Log::warning("Failed to fetch events from calendar {$calendar['displayName']}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Cache the combined result
        $cacheData = [
            'events' => $allEvents,
            'calendars' => $calendars,
        ];
        Cache::put($cacheKey, $cacheData, self::CACHE_TTL_EVENTS);
        $this->storeCacheMetadata($cacheKey);

        Log::debug('Nextcloud all events fetched and cached', [
            'count' => count($allEvents),
            'dateRange' => [$start, $end],
        ]);

        return [
            'events' => $allEvents,
            'calendars' => $calendars,
            'cache' => [
                'fromCache' => false,
                'lastUpdated' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Determine if event is all-day based on time components
     */
    private function isAllDayEvent(array $event): bool
    {
        $start = $event['start'] ?? '';

        // All-day events typically don't have time component or are at midnight
        if (strpos($start, 'T') === false) {
            return true;
        }

        // Check if time is 00:00:00
        if (str_ends_with($start, 'T00:00:00')) {
            return true;
        }

        return false;
    }

    /**
     * Parse CalDAV XML response into events array
     */
    private function parseCalendarResponse(string $xml): array
    {
        $events = [];

        try {
            libxml_use_internal_errors(true);
            $doc = simplexml_load_string($xml);

            if ($doc === false) {
                $errors = libxml_get_errors();
                $errorMsgs = array_map(fn ($e) => trim($e->message), $errors);
                \Log::warning('parseCalendarResponse: simplexml_load_string returned false', ['errors' => $errorMsgs]);
                libxml_clear_errors();

                return [];
            }

            // Register namespaces
            $doc->registerXPathNamespace('d', 'DAV:');
            $doc->registerXPathNamespace('cal', 'urn:ietf:params:xml:ns:caldav');

            $responses = $doc->xpath('//d:response');

            foreach ($responses as $response) {
                $calendarDataArray = $response->xpath('d:propstat/d:prop/cal:calendar-data');

                if (empty($calendarDataArray)) {
                    continue;
                }

                $calendarData = (string) $calendarDataArray[0];

                if (! empty($calendarData)) {
                    // Split by VEVENT blocks (one calendar entry may contain multiple events)
                    $parsedEvents = $this->parseICalEvents($calendarData);
                    $events = array_merge($events, $parsedEvents);
                }
            }

        } catch (Exception $e) {
            // Log error but return empty array
            \Log::warning('Failed to parse calendar XML', ['error' => $e->getMessage()]);
        }

        return $events;
    }

    /**
     * Parse iCalendar data that may contain multiple VEVENT blocks
     */
    private function parseICalEvents(string $icalData): array
    {
        $events = [];

        // Split into individual VEVENT blocks
        preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $icalData, $matches);

        foreach ($matches[1] as $eventBlock) {
            $event = $this->parseICalEvent($eventBlock);
            if ($event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Parse single iCalendar VEVENT block
     */
    private function parseICalEvent(string $eventData): ?array
    {
        $lines = explode("\n", $eventData);
        $event = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'SUMMARY:')) {
                $event['summary'] = substr($line, 8);
            } elseif (str_starts_with($line, 'DTSTART')) {
                preg_match('/DTSTART[^:]*:(.+)/', $line, $matches);
                if (isset($matches[1])) {
                    $event['start'] = $this->parseICalDate($matches[1]);
                }
            } elseif (str_starts_with($line, 'DTEND')) {
                preg_match('/DTEND[^:]*:(.+)/', $line, $matches);
                if (isset($matches[1])) {
                    $event['end'] = $this->parseICalDate($matches[1]);
                }
            } elseif (str_starts_with($line, 'DESCRIPTION:')) {
                $event['description'] = substr($line, 12);
            } elseif (str_starts_with($line, 'LOCATION:')) {
                $event['location'] = substr($line, 9);
            } elseif (str_starts_with($line, 'UID:')) {
                $event['uid'] = substr($line, 4);
            }
        }

        return ! empty($event) ? $event : null;
    }

    /**
     * Parse iCal date format (YYYYMMDDTHHMMSSZ or YYYYMMDD) to ISO 8601
     * Preserves UTC indicator (Z) for proper timezone handling in frontend
     */
    private function parseICalDate(string $date): string
    {
        // Check if date is in UTC (has Z suffix)
        $isUtc = str_ends_with($date, 'Z');
        $date = rtrim($date, 'Z');

        // Parse: YYYYMMDDTHHMMSS (datetime format)
        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})/', $date, $m)) {
            $result = "{$m[1]}-{$m[2]}-{$m[3]}T{$m[4]}:{$m[5]}:{$m[6]}";

            // Preserve UTC indicator for proper timezone conversion in frontend
            return $isUtc ? $result.'Z' : $result;
        }

        // Parse: YYYYMMDD (all-day date format) - no timezone needed
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        return $date;
    }

    /**
     * Get list of available calendars (with caching)
     *
     * @param  bool  $forceRefresh  Bypass cache and fetch fresh data
     * @return array Calendars with cache metadata
     */
    public function getCalendars(bool $forceRefresh = false): array
    {
        $cacheKey = $this->cacheKey('calendars');

        // Return cached data if available and not forcing refresh
        if (! $forceRefresh && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $url = "{$this->baseUrl}/remote.php/dav/calendars/{$this->username}/";

        try {
            $xml = <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<d:propfind xmlns:d="DAV:" xmlns:c="http://calendarserver.org/ns/">
  <d:prop>
    <d:displayname />
    <d:resourcetype />
  </d:prop>
</d:propfind>
XML;

            $response = $this->http()
                ->withHeaders([
                    'Content-Type' => 'application/xml; charset=utf-8',
                    'Depth' => '1',
                ])
                ->send('PROPFIND', $url, ['body' => $xml]);

            if (! $response->successful()) {
                throw new Exception('PROPFIND request failed: '.$response->status());
            }

            $calendars = $this->parseCalendarsResponse($response->body());

            // Cache the result
            Cache::put($cacheKey, $calendars, self::CACHE_TTL_CALENDARS);
            $this->storeCacheMetadata($cacheKey);

            Log::debug('Nextcloud calendars fetched and cached', ['count' => count($calendars)]);

            return $calendars;

        } catch (Exception $e) {
            // If we have stale cache, return it on error
            if (Cache::has($cacheKey)) {
                Log::warning('Nextcloud calendars fetch failed, returning stale cache', ['error' => $e->getMessage()]);

                return Cache::get($cacheKey);
            }
            throw new Exception('Failed to get calendars: '.$e->getMessage());
        }
    }

    /**
     * Parse PROPFIND response for calendars
     */
    private function parseCalendarsResponse(string $xml): array
    {
        $calendars = [];

        try {
            libxml_use_internal_errors(true);

            // Use DOMDocument for better XML handling
            $dom = new \DOMDocument;
            $loaded = $dom->loadXML($xml);

            if (! $loaded) {
                \Log::warning('Failed to parse calendars XML - DOMDocument loadXML failed');

                return [];
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('d', 'DAV:');
            $xpath->registerNamespace('cal', 'urn:ietf:params:xml:ns:caldav');

            $responses = $xpath->query('//d:response');

            foreach ($responses as $response) {
                $hrefNode = $xpath->query('d:href', $response)->item(0);
                if (! $hrefNode) {
                    continue;
                }

                $href = $hrefNode->nodeValue;

                $displaynameNode = $xpath->query('d:propstat/d:prop/d:displayname', $response)->item(0);
                $displayname = $displaynameNode ? $displaynameNode->nodeValue : '';

                // Check if it's a calendar
                $calendarNode = $xpath->query('d:propstat/d:prop/d:resourcetype/cal:calendar', $response)->item(0);
                $isCalendar = $calendarNode !== null;

                if ($isCalendar && ! empty($displayname)) {
                    // Extract calendar ID from href (last path component before trailing slash)
                    // Example: /remote.php/dav/calendars/bill/408f9dce-2d4d-49af-9abe-f732aed65cff/
                    $calendarId = basename(rtrim($href, '/'));

                    $calendars[] = [
                        'id' => $calendarId,  // UUID or internal calendar ID
                        'name' => $displayname,  // Human-readable display name
                        'displayName' => $displayname,
                        'href' => $href,
                    ];
                }
            }

        } catch (Exception $e) {
            \Log::warning('Failed to parse calendars XML', ['error' => $e->getMessage()]);
        }

        return $calendars;
    }

    // ================================
    // CONTACTS (CardDAV) METHODS
    // ================================

    /**
     * Get list of available address books (with caching)
     *
     * @param  bool  $forceRefresh  Bypass cache and fetch fresh data
     * @return array Address books
     */
    public function getAddressBooks(bool $forceRefresh = false): array
    {
        $cacheKey = $this->cacheKey('addressbooks');

        // Return cached data if available and not forcing refresh
        if (! $forceRefresh && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $url = "{$this->baseUrl}/remote.php/dav/addressbooks/users/{$this->username}/";

        try {
            $xml = <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<d:propfind xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
  <d:prop>
    <d:displayname />
    <d:resourcetype />
  </d:prop>
</d:propfind>
XML;

            $response = $this->http()
                ->withHeaders([
                    'Content-Type' => 'application/xml; charset=utf-8',
                    'Depth' => '1',
                ])
                ->send('PROPFIND', $url, ['body' => $xml]);

            if (! $response->successful()) {
                throw new Exception('PROPFIND request failed: '.$response->status());
            }

            $addressBooks = $this->parseAddressBooksResponse($response->body());

            // Cache the result
            Cache::put($cacheKey, $addressBooks, self::CACHE_TTL_ADDRESS_BOOKS);
            $this->storeCacheMetadata($cacheKey);

            Log::debug('Nextcloud address books fetched and cached', ['count' => count($addressBooks)]);

            return $addressBooks;

        } catch (Exception $e) {
            // If we have stale cache, return it on error
            if (Cache::has($cacheKey)) {
                Log::warning('Nextcloud address books fetch failed, returning stale cache', ['error' => $e->getMessage()]);

                return Cache::get($cacheKey);
            }
            throw new Exception('Failed to get address books: '.$e->getMessage());
        }
    }

    /**
     * Parse PROPFIND response for address books
     */
    private function parseAddressBooksResponse(string $xml): array
    {
        $addressBooks = [];

        try {
            libxml_use_internal_errors(true);
            $dom = new \DOMDocument;
            $loaded = $dom->loadXML($xml);

            if (! $loaded) {
                \Log::warning('Failed to parse address books XML');

                return [];
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('d', 'DAV:');
            $xpath->registerNamespace('card', 'urn:ietf:params:xml:ns:carddav');

            $responses = $xpath->query('//d:response');

            foreach ($responses as $response) {
                $hrefNode = $xpath->query('d:href', $response)->item(0);
                if (! $hrefNode) {
                    continue;
                }

                $href = $hrefNode->nodeValue;

                $displaynameNode = $xpath->query('d:propstat/d:prop/d:displayname', $response)->item(0);
                $displayname = $displaynameNode ? $displaynameNode->nodeValue : '';

                // Check if it's an address book
                $addressbookNode = $xpath->query('d:propstat/d:prop/d:resourcetype/card:addressbook', $response)->item(0);
                $isAddressbook = $addressbookNode !== null;

                if ($isAddressbook && ! empty($displayname)) {
                    $addressbookId = basename(rtrim($href, '/'));
                    $addressBooks[] = [
                        'id' => $addressbookId,
                        'name' => $displayname,
                        'displayName' => $displayname,
                        'href' => $href,
                    ];
                }
            }

        } catch (Exception $e) {
            \Log::warning('Failed to parse address books XML', ['error' => $e->getMessage()]);
        }

        return $addressBooks;
    }

    /**
     * Get all contacts from an address book (with caching)
     *
     * @param  string|null  $addressBookId  Address book ID (defaults to 'contacts')
     * @param  bool  $forceRefresh  Bypass cache and fetch fresh data
     * @return array Contacts
     */
    public function getContacts(?string $addressBookId = null, bool $forceRefresh = false): array
    {
        if (! $addressBookId) {
            $addressBookId = 'contacts';  // Default Nextcloud address book
        }

        $cacheKey = $this->cacheKey("contacts:{$addressBookId}");

        // Return cached data if available and not forcing refresh
        if (! $forceRefresh && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $url = "{$this->baseUrl}/remote.php/dav/addressbooks/users/{$this->username}/{$addressBookId}/";

        try {
            $xml = <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<card:addressbook-query xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
  <d:prop>
    <d:getetag/>
    <card:address-data/>
  </d:prop>
</card:addressbook-query>
XML;

            $response = $this->http()
                ->withHeaders([
                    'Content-Type' => 'application/xml; charset=utf-8',
                    'Depth' => '1',
                ])
                ->send('REPORT', $url, ['body' => $xml]);

            if (! $response->successful()) {
                throw new Exception('CardDAV REPORT request failed: '.$response->status());
            }

            $contacts = $this->parseContactsResponse($response->body());

            // Cache the result
            Cache::put($cacheKey, $contacts, self::CACHE_TTL_CONTACTS);
            $this->storeCacheMetadata($cacheKey);

            Log::debug('Nextcloud contacts fetched and cached', [
                'addressBook' => $addressBookId,
                'count' => count($contacts),
            ]);

            return $contacts;

        } catch (Exception $e) {
            // If we have stale cache, return it on error
            if (Cache::has($cacheKey)) {
                Log::warning('Nextcloud contacts fetch failed, returning stale cache', [
                    'addressBook' => $addressBookId,
                    'error' => $e->getMessage(),
                ]);

                return Cache::get($cacheKey);
            }
            throw new Exception('Failed to get contacts: '.$e->getMessage());
        }
    }

    /**
     * Get all contacts from all address books (with caching)
     *
     * This is an optimized method that caches the combined result
     * for faster subsequent loads.
     *
     * @param  bool  $forceRefresh  Bypass cache and fetch fresh data
     * @return array Combined contacts with address book info
     */
    public function getAllContactsCached(bool $forceRefresh = false): array
    {
        $cacheKey = $this->cacheKey('contacts:all');

        // Return cached data if available and not forcing refresh
        if (! $forceRefresh && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);

            return [
                'contacts' => $cached['contacts'],
                'addressBooks' => $cached['addressBooks'],
                'count' => count($cached['contacts']),
                'cache' => [
                    'fromCache' => true,
                    'lastUpdated' => Cache::get($cacheKey.':meta')['lastUpdated'] ?? null,
                ],
            ];
        }

        // Fetch fresh data
        $addressBooks = $this->getAddressBooks($forceRefresh);
        $allContacts = [];

        foreach ($addressBooks as $addressBook) {
            try {
                $contacts = $this->getContacts($addressBook['id'], $forceRefresh);
                foreach ($contacts as $contact) {
                    $contact['addressBook'] = $addressBook['displayName'];
                    $contact['addressBookId'] = $addressBook['id'];
                    $allContacts[] = $contact;
                }
            } catch (Exception $e) {
                Log::warning("Failed to fetch contacts from address book {$addressBook['displayName']}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Sort all contacts by display name
        usort($allContacts, function ($a, $b) {
            return strcasecmp($a['displayName'] ?? '', $b['displayName'] ?? '');
        });

        // Cache the combined result
        $cacheData = [
            'contacts' => $allContacts,
            'addressBooks' => $addressBooks,
        ];
        Cache::put($cacheKey, $cacheData, self::CACHE_TTL_CONTACTS);
        $this->storeCacheMetadata($cacheKey);

        Log::debug('Nextcloud all contacts fetched and cached', ['count' => count($allContacts)]);

        return [
            'contacts' => $allContacts,
            'addressBooks' => $addressBooks,
            'count' => count($allContacts),
            'cache' => [
                'fromCache' => false,
                'lastUpdated' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Parse CardDAV response into contacts array
     */
    private function parseContactsResponse(string $xml): array
    {
        $contacts = [];

        try {
            libxml_use_internal_errors(true);
            $dom = new \DOMDocument;
            $loaded = $dom->loadXML($xml);

            if (! $loaded) {
                \Log::warning('Failed to parse contacts XML');

                return [];
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('d', 'DAV:');
            $xpath->registerNamespace('card', 'urn:ietf:params:xml:ns:carddav');

            $responses = $xpath->query('//d:response');

            foreach ($responses as $response) {
                $addressDataNode = $xpath->query('d:propstat/d:prop/card:address-data', $response)->item(0);
                if (! $addressDataNode) {
                    continue;
                }

                $vcardData = $addressDataNode->nodeValue;
                if (! empty($vcardData)) {
                    $contact = $this->parseVCard($vcardData);
                    if ($contact) {
                        $contacts[] = $contact;
                    }
                }
            }

        } catch (Exception $e) {
            \Log::warning('Failed to parse contacts XML', ['error' => $e->getMessage()]);
        }

        // Sort contacts by display name
        usort($contacts, function ($a, $b) {
            return strcasecmp($a['displayName'] ?? '', $b['displayName'] ?? '');
        });

        return $contacts;
    }

    /**
     * Parse vCard data into contact array
     */
    private function parseVCard(string $vcardData): ?array
    {
        $lines = explode("\n", $vcardData);
        $contact = [
            'uid' => null,
            'displayName' => null,
            'firstName' => null,
            'lastName' => null,
            'email' => null,
            'phone' => null,
            'address' => null,
            'organization' => null,
            'title' => null,
            'note' => null,
            'photo' => null,
        ];

        $currentProperty = null;
        $currentValue = '';

        foreach ($lines as $line) {
            $line = rtrim($line, "\r");

            // Handle line folding (continuation lines start with space or tab)
            if (strlen($line) > 0 && ($line[0] === ' ' || $line[0] === "\t")) {
                $currentValue .= substr($line, 1);

                continue;
            }

            // Process previous property if exists
            if ($currentProperty !== null) {
                $this->processVCardProperty($contact, $currentProperty, $currentValue);
            }

            // Parse new property
            if (strpos($line, ':') !== false) {
                [$propertyPart, $valuePart] = explode(':', $line, 2);
                // Handle parameters (e.g., TEL;TYPE=CELL:+1234567890)
                $propertyName = explode(';', $propertyPart)[0];
                $currentProperty = strtoupper($propertyName);
                $currentValue = $valuePart;
            } else {
                $currentProperty = null;
                $currentValue = '';
            }
        }

        // Process last property
        if ($currentProperty !== null) {
            $this->processVCardProperty($contact, $currentProperty, $currentValue);
        }

        // Skip if no meaningful data
        if (empty($contact['displayName']) && empty($contact['email']) && empty($contact['phone'])) {
            return null;
        }

        // Generate display name from parts if not set
        if (empty($contact['displayName'])) {
            $parts = array_filter([$contact['firstName'], $contact['lastName']]);
            $contact['displayName'] = implode(' ', $parts) ?: $contact['email'] ?: 'Unknown';
        }

        return $contact;
    }

    /**
     * Process a single vCard property
     */
    private function processVCardProperty(array &$contact, string $property, string $value): void
    {
        $value = $this->decodeVCardValue($value);

        switch ($property) {
            case 'UID':
                $contact['uid'] = $value;
                break;
            case 'FN':
                $contact['displayName'] = $value;
                break;
            case 'N':
                // N format: LastName;FirstName;MiddleName;Prefix;Suffix
                $parts = explode(';', $value);
                $contact['lastName'] = $parts[0] ?? null;
                $contact['firstName'] = $parts[1] ?? null;
                break;
            case 'EMAIL':
                if (empty($contact['email'])) {
                    $contact['email'] = $value;
                }
                break;
            case 'TEL':
                if (empty($contact['phone'])) {
                    $contact['phone'] = $value;
                }
                break;
            case 'ADR':
                // ADR format: POBox;ExtAddr;Street;City;State;PostCode;Country
                $parts = explode(';', $value);
                $addressParts = array_filter([
                    $parts[2] ?? '',  // Street
                    $parts[3] ?? '',  // City
                    $parts[4] ?? '',  // State
                    $parts[5] ?? '',  // PostCode
                    $parts[6] ?? '',  // Country
                ]);
                if (! empty($addressParts) && empty($contact['address'])) {
                    $contact['address'] = implode(', ', $addressParts);
                }
                break;
            case 'ORG':
                $contact['organization'] = str_replace(';', ', ', $value);
                break;
            case 'TITLE':
                $contact['title'] = $value;
                break;
            case 'NOTE':
                $contact['note'] = $value;
                break;
            case 'PHOTO':
                // Store photo data URI or URL
                if (str_starts_with($value, 'http')) {
                    $contact['photo'] = $value;
                }
                break;
        }
    }

    /**
     * Decode vCard encoded value (quoted-printable, etc.)
     */
    private function decodeVCardValue(string $value): string
    {
        // Handle escaped characters
        $value = str_replace(['\\n', '\\N', '\\,', '\\;'], ["\n", "\n", ',', ';'], $value);
        // Decode quoted-printable if needed
        if (str_contains($value, '=')) {
            $decoded = quoted_printable_decode($value);
            if ($decoded !== false) {
                $value = $decoded;
            }
        }

        return trim($value);
    }

    // ================================
    // FILE OPERATIONS (WebDAV)
    // ================================

    /**
     * Upload a file to Nextcloud via WebDAV
     *
     * @param  string  $localPath  Local file path to upload
     * @param  string  $remotePath  Remote path (relative to user root, e.g., "/Repository/Official/file.pdf")
     * @return array Result with success status and URL
     */
    public function uploadFile(string $localPath, string $remotePath): array
    {
        try {
            if (! file_exists($localPath)) {
                throw new Exception("Local file not found: {$localPath}");
            }

            // Ensure remote path starts with /
            $remotePath = '/'.ltrim($remotePath, '/');

            // Ensure parent directories exist
            $parentDir = dirname($remotePath);
            if ($parentDir !== '/') {
                $this->ensureDirectory($parentDir);
            }

            // Upload file
            $url = "{$this->baseUrl}/remote.php/dav/files/{$this->username}{$remotePath}";
            $content = file_get_contents($localPath);
            $mimeType = mime_content_type($localPath) ?: 'application/octet-stream';

            $response = $this->http()
                ->withBody($content, $mimeType)
                ->put($url);

            if (! $response->successful()) {
                throw new Exception('WebDAV upload failed: HTTP '.$response->status());
            }

            // Generate shareable URL
            $shareUrl = $this->getFileShareUrl($remotePath);

            Log::info('Uploaded file to Nextcloud', [
                'local' => basename($localPath),
                'remote' => $remotePath,
            ]);

            return [
                'success' => true,
                'remote_path' => $remotePath,
                'webdav_url' => $url,
                'share_url' => $shareUrl,
            ];

        } catch (Exception $e) {
            Log::error('Failed to upload file to Nextcloud', [
                'local' => $localPath,
                'remote' => $remotePath,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Ensure a directory exists in Nextcloud (create if needed)
     *
     * @param  string  $path  Directory path relative to user root
     * @return bool Success
     */
    public function ensureDirectory(string $path): bool
    {
        $path = '/'.ltrim($path, '/');
        $parts = explode('/', trim($path, '/'));
        $currentPath = '';

        foreach ($parts as $part) {
            if (empty($part)) {
                continue;
            }

            $currentPath .= '/'.$part;
            $url = "{$this->baseUrl}/remote.php/dav/files/{$this->username}{$currentPath}";

            // Check if directory exists (PROPFIND)
            $checkResponse = $this->http()
                ->withHeaders(['Depth' => '0'])
                ->send('PROPFIND', $url);

            if ($checkResponse->status() === 404) {
                // Create directory (MKCOL)
                $mkcolResponse = $this->http()
                    ->send('MKCOL', $url);

                if (! $mkcolResponse->successful() && $mkcolResponse->status() !== 405) {
                    Log::warning("Failed to create directory: {$currentPath}", [
                        'status' => $mkcolResponse->status(),
                    ]);

                    return false;
                }

                Log::debug("Created Nextcloud directory: {$currentPath}");
            }
        }

        return true;
    }

    /**
     * Get a shareable/viewable URL for a file
     *
     * @param  string  $remotePath  File path relative to user root
     * @return string URL to access the file
     */
    public function getFileShareUrl(string $remotePath): string
    {
        $remotePath = '/'.ltrim($remotePath, '/');

        // Direct WebDAV download URL (requires auth)
        // For public access, would need to create a share link via OCS API
        return "{$this->baseUrl}/remote.php/dav/files/{$this->username}{$remotePath}";
    }

    /**
     * Get a public share link for a file (creates share if needed)
     *
     * @param  string  $remotePath  File path relative to user root
     * @return array Result with share URL
     */
    public function createPublicShare(string $remotePath): array
    {
        try {
            $remotePath = '/'.ltrim($remotePath, '/');

            // Use OCS Share API to create public link
            $url = "{$this->baseUrl}/ocs/v2.php/apps/files_sharing/api/v1/shares";

            $response = $this->http()
                ->withHeaders([
                    'OCS-APIRequest' => 'true',
                    'Accept' => 'application/json',
                ])
                ->post($url, [
                    'path' => $remotePath,
                    'shareType' => 3, // 3 = public link
                    'permissions' => 1, // 1 = read only
                ]);

            if (! $response->successful()) {
                throw new Exception('Failed to create share: HTTP '.$response->status());
            }

            $data = $response->json();
            $shareUrl = $data['ocs']['data']['url'] ?? null;

            if (! $shareUrl) {
                throw new Exception('No share URL in response');
            }

            return [
                'success' => true,
                'share_url' => $shareUrl,
                'share_id' => $data['ocs']['data']['id'] ?? null,
            ];

        } catch (Exception $e) {
            Log::error('Failed to create public share', [
                'path' => $remotePath,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if a file exists in Nextcloud
     *
     * @param  string  $remotePath  File path relative to user root
     * @return bool File exists
     */
    public function fileExists(string $remotePath): bool
    {
        $remotePath = '/'.ltrim($remotePath, '/');
        $url = "{$this->baseUrl}/remote.php/dav/files/{$this->username}{$remotePath}";

        $response = $this->http()
            ->withHeaders(['Depth' => '0'])
            ->send('PROPFIND', $url);

        return $response->successful();
    }

    /**
     * Get file preview/thumbnail from Nextcloud
     *
     * @param  string  $remotePath  File path relative to user root
     * @param  int  $width  Preview width (default 256)
     * @param  int  $height  Preview height (default 256)
     * @return array Result with base64 preview data or error
     */
    public function getFilePreview(string $remotePath, int $width = 256, int $height = 256): array
    {
        try {
            $remotePath = '/'.ltrim($remotePath, '/');

            // Nextcloud preview API endpoint
            $url = "{$this->baseUrl}/core/preview";
            $response = $this->http()
                ->get($url, [
                    'file' => $remotePath,
                    'x' => $width,
                    'y' => $height,
                    'a' => 1, // Maintain aspect ratio
                ]);

            if (! $response->successful()) {
                // Try alternative preview endpoint
                $fileId = $this->getFileId($remotePath);
                if ($fileId) {
                    $altUrl = "{$this->baseUrl}/index.php/core/preview.png";
                    $response = $this->http()
                        ->get($altUrl, [
                            'fileId' => $fileId,
                            'x' => $width,
                            'y' => $height,
                        ]);
                }
            }

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => 'Preview not available (HTTP '.$response->status().')',
                ];
            }

            $contentType = $response->header('Content-Type') ?? 'image/png';
            $base64 = base64_encode($response->body());

            return [
                'success' => true,
                'preview' => "data:{$contentType};base64,{$base64}",
                'content_type' => $contentType,
            ];

        } catch (Exception $e) {
            Log::error('Failed to get file preview', [
                'path' => $remotePath,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get file ID from Nextcloud via PROPFIND
     *
     * @param  string  $remotePath  File path relative to user root
     * @return int|null File ID
     */
    public function getFileId(string $remotePath): ?int
    {
        try {
            $remotePath = '/'.ltrim($remotePath, '/');
            $url = "{$this->baseUrl}/remote.php/dav/files/{$this->username}{$remotePath}";

            $body = '<?xml version="1.0" encoding="UTF-8"?>
                <d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
                    <d:prop>
                        <oc:fileid/>
                    </d:prop>
                </d:propfind>';

            $response = $this->http()
                ->withHeaders([
                    'Content-Type' => 'application/xml',
                    'Depth' => '0',
                ])
                ->send('PROPFIND', $url, ['body' => $body]);

            if (! $response->successful()) {
                return null;
            }

            // Parse XML response
            $xml = simplexml_load_string($response->body());
            if (! $xml) {
                return null;
            }

            $xml->registerXPathNamespace('d', 'DAV:');
            $xml->registerXPathNamespace('oc', 'http://owncloud.org/ns');

            $fileId = $xml->xpath('//oc:fileid');

            return $fileId ? (int) $fileId[0] : null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Download file content from Nextcloud
     *
     * @param  string  $remotePath  File path relative to user root
     * @param  int  $maxSize  Maximum file size to download (default 10MB)
     * @return array Result with file content or error
     */
    public function downloadFile(string $remotePath, int $maxSize = 10485760): array
    {
        try {
            $remotePath = '/'.ltrim($remotePath, '/');
            $url = "{$this->baseUrl}/remote.php/dav/files/{$this->username}{$remotePath}";

            // First check file size with HEAD request
            $headResponse = $this->http()
                ->head($url);

            if (! $headResponse->successful()) {
                return [
                    'success' => false,
                    'error' => 'File not found (HTTP '.$headResponse->status().')',
                ];
            }

            $contentLength = (int) ($headResponse->header('Content-Length') ?? 0);
            if ($contentLength > $maxSize) {
                return [
                    'success' => false,
                    'error' => 'File too large for preview ('.round($contentLength / 1048576, 1).' MB)',
                    'size' => $contentLength,
                ];
            }

            // Download the file
            $response = $this->http()
                ->timeout(30)
                ->get($url);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => 'Download failed (HTTP '.$response->status().')',
                ];
            }

            $contentType = $response->header('Content-Type') ?? 'application/octet-stream';
            $filename = basename($remotePath);
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            return [
                'success' => true,
                'content' => $response->body(),
                'base64' => base64_encode($response->body()),
                'content_type' => $contentType,
                'filename' => $filename,
                'extension' => $extension,
                'size' => strlen($response->body()),
            ];

        } catch (Exception $e) {
            Log::error('Failed to download file', [
                'path' => $remotePath,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get file info with preview URL for frontend
     *
     * @param  string  $remotePath  File path relative to user root
     * @return array File info with preview capabilities
     */
    public function getFileInfo(string $remotePath): array
    {
        try {
            $remotePath = '/'.ltrim($remotePath, '/');
            $filename = basename($remotePath);
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            // Determine preview type based on extension
            $previewableImages = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
            $previewableDocs = ['pdf'];
            $previewableText = ['txt', 'md', 'json', 'xml', 'csv', 'log', 'yml', 'yaml', 'ini', 'conf'];
            $previewableCode = ['php', 'js', 'ts', 'vue', 'html', 'css', 'scss', 'py', 'rb', 'java', 'c', 'cpp', 'h', 'go', 'rs', 'sql'];

            $previewType = 'none';
            if (in_array($extension, $previewableImages)) {
                $previewType = 'image';
            } elseif (in_array($extension, $previewableDocs)) {
                $previewType = 'pdf';
            } elseif (in_array($extension, $previewableText)) {
                $previewType = 'text';
            } elseif (in_array($extension, $previewableCode)) {
                $previewType = 'code';
            }

            // Get WebDAV URL for direct access
            $webdavUrl = "{$this->baseUrl}/remote.php/dav/files/{$this->username}{$remotePath}";

            return [
                'success' => true,
                'filename' => $filename,
                'extension' => $extension,
                'path' => $remotePath,
                'preview_type' => $previewType,
                'webdav_url' => $webdavUrl,
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // ================================
    // PERSISTENCE METHODS (MySQL)
    // ================================

    /**
     * Persist calendar events to MySQL database
     *
     * @param  array  $events  Events from getAllEventsCached
     * @return array Stats about persisted events
     */
    public function persistCalendarEvents(array $events): array
    {
        $inserted = 0;
        $updated = 0;
        $errors = 0;

        foreach ($events as $event) {
            try {
                $externalId = $event['extendedProps']['uid'] ?? $event['id'] ?? null;
                if (! $externalId) {
                    $errors++;

                    continue;
                }

                // Parse dates
                $startAt = $event['start'] ?? null;
                $endAt = $event['end'] ?? null;

                // Convert to MySQL datetime format
                if ($startAt) {
                    $startAt = date('Y-m-d H:i:s', strtotime($startAt));
                }
                if ($endAt) {
                    $endAt = date('Y-m-d H:i:s', strtotime($endAt));
                }

                // Check if exists
                $existing = DB::select(
                    'SELECT id FROM calendar_events WHERE external_id = ? LIMIT 1',
                    [$externalId]
                );

                if ($existing) {
                    // Update
                    DB::update(
                        'UPDATE calendar_events SET
                            calendar_name = ?,
                            title = ?,
                            description = ?,
                            location = ?,
                            start_at = ?,
                            end_at = ?,
                            all_day = ?,
                            updated_at = NOW()
                        WHERE external_id = ?',
                        [
                            $event['extendedProps']['calendar'] ?? null,
                            $event['title'] ?? 'Untitled',
                            $event['extendedProps']['description'] ?? null,
                            $event['extendedProps']['location'] ?? null,
                            $startAt,
                            $endAt,
                            $event['allDay'] ?? false,
                            $externalId,
                        ]
                    );
                    $updated++;
                } else {
                    // Insert
                    DB::insert(
                        'INSERT INTO calendar_events
                            (external_id, calendar_name, title, description, location, start_at, end_at, all_day, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                        [
                            $externalId,
                            $event['extendedProps']['calendar'] ?? null,
                            $event['title'] ?? 'Untitled',
                            $event['extendedProps']['description'] ?? null,
                            $event['extendedProps']['location'] ?? null,
                            $startAt,
                            $endAt,
                            $event['allDay'] ?? false,
                        ]
                    );
                    $inserted++;
                }
            } catch (Exception $e) {
                Log::warning('Failed to persist calendar event', [
                    'event_id' => $event['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
                $errors++;
            }
        }

        Log::info('Calendar events persisted to MySQL', [
            'inserted' => $inserted,
            'updated' => $updated,
            'errors' => $errors,
        ]);

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'errors' => $errors,
            'total' => count($events),
        ];
    }

    /**
     * Delete a calendar event from Nextcloud via CalDAV DELETE
     *
     * @param  string  $href  The CalDAV href path (e.g. /remote.php/dav/calendars/bill/personal/uuid.ics)
     * @return array Success status
     */
    public function deleteCalendarEventByHref(string $href): array
    {
        try {
            $url = "{$this->baseUrl}{$href}";

            $response = $this->http()
                ->timeout(10)
                ->delete($url);

            if ($response->status() === 204 || $response->status() === 200) {
                return ['success' => true, 'href' => $href];
            }

            return [
                'success' => false,
                'href' => $href,
                'error' => 'HTTP '.$response->status(),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'href' => $href,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * List calendar events with their CalDAV hrefs and UIDs
     *
     * @param  string  $calendarName  Calendar display name
     * @param  string  $start  CalDAV start date (YYYYMMDDTHHMMSSZ)
     * @param  string  $end  CalDAV end date (YYYYMMDDTHHMMSSZ)
     * @return array Events with href, uid, and summary
     */
    public function listCalendarEventsWithHrefs(string $calendarName, string $start, string $end): array
    {
        $calendars = $this->getCalendars(true);
        $calendarId = null;

        foreach ($calendars as $cal) {
            if ($cal['displayName'] === $calendarName || $cal['name'] === $calendarName || $cal['id'] === $calendarName) {
                $calendarId = $cal['id'];
                break;
            }
        }

        if (! $calendarId) {
            throw new Exception("Calendar '{$calendarName}' not found");
        }

        $url = "{$this->baseUrl}/remote.php/dav/calendars/{$this->username}/{$calendarId}/";

        $xml = <<<XML
<?xml version="1.0" encoding="utf-8" ?>
<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:prop>
    <D:getetag/>
    <C:calendar-data/>
  </D:prop>
  <C:filter>
    <C:comp-filter name="VCALENDAR">
      <C:comp-filter name="VEVENT">
        <C:time-range start="{$start}" end="{$end}"/>
      </C:comp-filter>
    </C:comp-filter>
  </C:filter>
</C:calendar-query>
XML;

        $response = $this->http()
            ->withHeaders([
                'Content-Type' => 'application/xml; charset=utf-8',
                'Depth' => '1',
            ])
            ->send('REPORT', $url, ['body' => $xml]);

        if (! $response->successful()) {
            throw new Exception('CalDAV REPORT failed: HTTP '.$response->status());
        }

        return $this->parseEventsWithHrefs($response->body());
    }

    /**
     * Parse CalDAV REPORT response extracting hrefs, UIDs, and summaries
     */
    private function parseEventsWithHrefs(string $xml): array
    {
        $events = [];

        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadXML($xml);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('cal', 'urn:ietf:params:xml:ns:caldav');

        $responses = $xpath->query('//d:response');

        foreach ($responses as $response) {
            $hrefNode = $xpath->query('d:href', $response)->item(0);
            $calDataNode = $xpath->query('d:propstat/d:prop/cal:calendar-data', $response)->item(0);

            if (! $hrefNode || ! $calDataNode) {
                continue;
            }

            $href = trim($hrefNode->nodeValue);
            $icalData = $calDataNode->nodeValue;

            // Extract UID and SUMMARY from iCal data
            preg_match('/UID:(.+)/', $icalData, $uidMatch);
            preg_match('/SUMMARY:(.+)/', $icalData, $summaryMatch);

            $events[] = [
                'href' => $href,
                'uid' => isset($uidMatch[1]) ? trim($uidMatch[1]) : null,
                'summary' => isset($summaryMatch[1]) ? trim($summaryMatch[1]) : null,
            ];
        }

        return $events;
    }

    /**
     * Persist contacts to MySQL database
     *
     * @param  array  $contacts  Contacts from getAllContactsCached
     * @return array Stats about persisted contacts
     */
    public function persistContacts(array $contacts): array
    {
        $inserted = 0;
        $updated = 0;
        $errors = 0;

        foreach ($contacts as $contact) {
            try {
                $externalId = $contact['uid'] ?? null;
                if (! $externalId) {
                    $errors++;

                    continue;
                }

                // Parse name parts
                $fullName = $contact['displayName'] ?? null;
                $firstName = $contact['firstName'] ?? null;
                $lastName = $contact['lastName'] ?? null;

                // Build emails/phones JSON arrays
                $emails = [];
                if (! empty($contact['email'])) {
                    $emails[] = ['type' => 'primary', 'email' => $contact['email']];
                }
                $phones = [];
                if (! empty($contact['phone'])) {
                    $phones[] = ['type' => 'primary', 'number' => $contact['phone']];
                }

                // Check if exists
                $existing = DB::select(
                    'SELECT id FROM contacts WHERE external_id = ? LIMIT 1',
                    [$externalId]
                );

                if ($existing) {
                    // Update
                    DB::update(
                        'UPDATE contacts SET
                            full_name = ?,
                            first_name = ?,
                            last_name = ?,
                            emails = ?,
                            phones = ?,
                            organization = ?,
                            title = ?,
                            notes = ?,
                            photo_url = ?,
                            updated_at = NOW()
                        WHERE external_id = ?',
                        [
                            $fullName,
                            $firstName,
                            $lastName,
                            json_encode($emails),
                            json_encode($phones),
                            $contact['organization'] ?? null,
                            $contact['title'] ?? null,
                            $contact['note'] ?? null,
                            $contact['photo'] ?? null,
                            $externalId,
                        ]
                    );
                    $updated++;
                } else {
                    // Insert
                    DB::insert(
                        'INSERT INTO contacts
                            (external_id, full_name, first_name, last_name, emails, phones, organization, title, notes, photo_url, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                        [
                            $externalId,
                            $fullName,
                            $firstName,
                            $lastName,
                            json_encode($emails),
                            json_encode($phones),
                            $contact['organization'] ?? null,
                            $contact['title'] ?? null,
                            $contact['note'] ?? null,
                            $contact['photo'] ?? null,
                        ]
                    );
                    $inserted++;
                }
            } catch (Exception $e) {
                Log::warning('Failed to persist contact', [
                    'contact' => $contact['displayName'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
                $errors++;
            }
        }

        Log::info('Contacts persisted to MySQL', [
            'inserted' => $inserted,
            'updated' => $updated,
            'errors' => $errors,
        ]);

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'errors' => $errors,
            'total' => count($contacts),
        ];
    }

    /**
     * Sync and persist all calendar events (extended date range for historical data)
     *
     * @param  int  $monthsBefore  Months before current date
     * @param  int  $monthsAfter  Months after current date
     * @return array Sync results
     */
    public function syncCalendarEventsToDatabase(int $monthsBefore = 12, int $monthsAfter = 6): array
    {
        $start = date('Y-m-d', strtotime("-{$monthsBefore} months")).'T00:00:00';
        $end = date('Y-m-d', strtotime("+{$monthsAfter} months")).'T23:59:59';

        Log::info('Syncing calendar events to database', [
            'start' => $start,
            'end' => $end,
        ]);

        // Force refresh from Nextcloud
        $result = $this->getAllEventsCached($start, $end, true);
        $events = $result['events'] ?? [];

        // Persist to database
        $persistResult = $this->persistCalendarEvents($events);

        return [
            'fetched' => count($events),
            'persisted' => $persistResult,
            'date_range' => ['start' => $start, 'end' => $end],
        ];
    }

    /**
     * Sync and persist all contacts
     *
     * @return array Sync results
     */
    public function syncContactsToDatabase(): array
    {
        Log::info('Syncing contacts to database');

        // Force refresh from Nextcloud
        $result = $this->getAllContactsCached(true);
        $contacts = $result['contacts'] ?? [];

        // Persist to database
        $persistResult = $this->persistContacts($contacts);

        return [
            'fetched' => count($contacts),
            'persisted' => $persistResult,
        ];
    }

    /**
     * Get calendar events from database (not cache)
     *
     * @param  string|null  $start  Start date
     * @param  string|null  $end  End date
     * @return array Events from database
     */
    public function getCalendarEventsFromDatabase(?string $start = null, ?string $end = null): array
    {
        $query = 'SELECT * FROM calendar_events WHERE 1=1';
        $params = [];

        if ($start) {
            $query .= ' AND start_at >= ?';
            $params[] = date('Y-m-d H:i:s', strtotime($start));
        }
        if ($end) {
            $query .= ' AND start_at <= ?';
            $params[] = date('Y-m-d H:i:s', strtotime($end));
        }

        $query .= ' ORDER BY start_at ASC';

        return DB::select($query, $params);
    }

    /**
     * Get contacts from database (not cache)
     *
     * @param  string|null  $search  Optional search term
     * @return array Contacts from database
     */
    public function getContactsFromDatabase(?string $search = null): array
    {
        $query = 'SELECT * FROM contacts WHERE 1=1';
        $params = [];

        if ($search) {
            $query .= ' AND (full_name LIKE ? OR organization LIKE ? OR emails LIKE ?)';
            $searchTerm = "%{$search}%";
            $params = [$searchTerm, $searchTerm, $searchTerm];
        }

        $query .= ' ORDER BY full_name ASC';

        return DB::select($query, $params);
    }
}
