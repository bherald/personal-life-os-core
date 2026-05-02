<?php

namespace App\Nodes;

use App\Services\NextcloudService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CalendarSyncNode - External Calendar Synchronization
 *
 * Syncs calendar events between multiple sources:
 * - Google Calendar (via API)
 * - Apple iCloud Calendar (via CalDAV)
 * - Nextcloud Calendar (via CalDAV)
 *
 * Supports pull, push, and bidirectional sync modes.
 *
 * Config options:
 * - source_type: 'google', 'icloud', 'nextcloud'
 * - credentials_key: Key in oauth_tokens table or config
 * - sync_direction: 'pull', 'push', 'bidirectional'
 * - calendar_ids: Array of calendar IDs to sync
 * - sync_window_days: Number of days ahead to sync (default: 30)
 * - conflict_resolution: 'source_wins', 'target_wins', 'newest_wins'
 */
class CalendarSyncNode extends BaseNode
{
    /** Cache TTL for sync state (24 hours) */
    private const SYNC_STATE_CACHE_TTL = 86400;

    /** Supported source types */
    private const SOURCE_TYPES = ['google', 'icloud', 'nextcloud'];

    /** Supported sync directions */
    private const SYNC_DIRECTIONS = ['pull', 'push', 'bidirectional'];

    public function execute(array $input): array
    {
        $startTime = microtime(true);

        try {
            // Get configuration
            $sourceType = $this->getConfigValue('source_type', 'nextcloud');
            $credentialsKey = $this->getConfigValue('credentials_key', 'default');
            $syncDirection = $this->getConfigValue('sync_direction', 'pull');
            $calendarIds = $this->getConfigValue('calendar_ids', []);
            $syncWindowDays = (int) $this->getConfigValue('sync_window_days', 30);
            $conflictResolution = $this->getConfigValue('conflict_resolution', 'source_wins');
            $dryRun = (bool) $this->getConfigValue('dry_run', false);

            // Validate configuration
            $validationError = $this->validateConfig($sourceType, $syncDirection);
            if ($validationError) {
                return $this->standardOutput(null, [], $validationError);
            }

            Log::info('CalendarSyncNode starting', [
                'source_type' => $sourceType,
                'sync_direction' => $syncDirection,
                'calendar_ids' => $calendarIds,
                'sync_window_days' => $syncWindowDays,
                'dry_run' => $dryRun,
            ]);

            // Get credentials for the source
            $credentials = $this->getCredentials($sourceType, $credentialsKey);
            if (!$credentials) {
                return $this->standardOutput(null, [], "No credentials found for source: {$sourceType} (key: {$credentialsKey})");
            }

            // Calculate sync window
            $syncStart = Carbon::now()->startOfDay();
            $syncEnd = Carbon::now()->addDays($syncWindowDays)->endOfDay();

            // Execute sync based on direction
            $stats = [
                'pulled' => 0,
                'pushed' => 0,
                'conflicts' => 0,
                'errors' => 0,
                'skipped' => 0,
            ];
            $syncResults = [];

            switch ($syncDirection) {
                case 'pull':
                    $syncResults = $this->executePull(
                        $sourceType,
                        $credentials,
                        $calendarIds,
                        $syncStart,
                        $syncEnd,
                        $dryRun
                    );
                    $stats['pulled'] = $syncResults['count'] ?? 0;
                    break;

                case 'push':
                    $syncResults = $this->executePush(
                        $sourceType,
                        $credentials,
                        $calendarIds,
                        $syncStart,
                        $syncEnd,
                        $dryRun
                    );
                    $stats['pushed'] = $syncResults['count'] ?? 0;
                    break;

                case 'bidirectional':
                    $syncResults = $this->executeBidirectional(
                        $sourceType,
                        $credentials,
                        $calendarIds,
                        $syncStart,
                        $syncEnd,
                        $conflictResolution,
                        $dryRun
                    );
                    $stats['pulled'] = $syncResults['pulled'] ?? 0;
                    $stats['pushed'] = $syncResults['pushed'] ?? 0;
                    $stats['conflicts'] = $syncResults['conflicts'] ?? 0;
                    break;
            }

            $stats['errors'] = $syncResults['errors'] ?? 0;
            $stats['skipped'] = $syncResults['skipped'] ?? 0;

            // Log sync completion
            $this->logSyncRun($sourceType, $syncDirection, $stats, $dryRun);

            $durationMs = round((microtime(true) - $startTime) * 1000);

            $summary = sprintf(
                "Calendar sync %s: pulled=%d, pushed=%d, conflicts=%d, errors=%d, skipped=%d (%dms)%s",
                $sourceType,
                $stats['pulled'],
                $stats['pushed'],
                $stats['conflicts'],
                $stats['errors'],
                $stats['skipped'],
                $durationMs,
                $dryRun ? ' [DRY RUN]' : ''
            );

            Log::info('CalendarSyncNode completed', [
                'summary' => $summary,
                'stats' => $stats,
                'duration_ms' => $durationMs,
            ]);

            return $this->standardOutput([
                'summary' => $summary,
                'stats' => $stats,
                'source_type' => $sourceType,
                'sync_direction' => $syncDirection,
                'sync_window' => [
                    'start' => $syncStart->toIso8601String(),
                    'end' => $syncEnd->toIso8601String(),
                ],
                'calendars_synced' => $syncResults['calendars'] ?? [],
                'events' => $syncResults['events'] ?? [],
                'dry_run' => $dryRun,
            ], [
                'source_type' => $sourceType,
                'sync_direction' => $syncDirection,
                'duration_ms' => $durationMs,
                'pulled' => $stats['pulled'],
                'pushed' => $stats['pushed'],
                'conflicts' => $stats['conflicts'],
                'errors' => $stats['errors'],
            ]);

        } catch (Exception $e) {
            Log::error('CalendarSyncNode failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->standardOutput(null, [], 'Calendar sync failed: ' . $e->getMessage());
        }
    }

    /**
     * Validate configuration parameters
     */
    private function validateConfig(string $sourceType, string $syncDirection): ?string
    {
        if (!in_array($sourceType, self::SOURCE_TYPES)) {
            return "Invalid source_type: {$sourceType}. Must be one of: " . implode(', ', self::SOURCE_TYPES);
        }

        if (!in_array($syncDirection, self::SYNC_DIRECTIONS)) {
            return "Invalid sync_direction: {$syncDirection}. Must be one of: " . implode(', ', self::SYNC_DIRECTIONS);
        }

        return null;
    }

    /**
     * Get credentials from oauth_tokens table or config
     */
    private function getCredentials(string $sourceType, string $credentialsKey): ?array
    {
        // First try oauth_tokens table (raw SQL per project rules)
        $tokens = DB::select(
            'SELECT * FROM oauth_tokens WHERE provider = ? ORDER BY updated_at DESC LIMIT 1',
            [$sourceType]
        );

        if (!empty($tokens)) {
            $token = $tokens[0];
            return [
                'access_token' => $token->access_token,
                'refresh_token' => $token->refresh_token ?? null,
                'expires_at' => $token->access_token_expires_at ?? null,
                'extra' => json_decode($token->extra ?? '{}', true),
            ];
        }

        // Fallback to config
        $configKey = "services.{$sourceType}.{$credentialsKey}";
        $configCredentials = config($configKey);

        if ($configCredentials) {
            return is_array($configCredentials) ? $configCredentials : ['key' => $configCredentials];
        }

        // For Nextcloud, use default service config
        if ($sourceType === 'nextcloud') {
            return [
                'url' => config('services.nextcloud.url'),
                'username' => config('services.nextcloud.username'),
                'password' => config('services.nextcloud.password'),
            ];
        }

        return null;
    }

    /**
     * Execute pull sync (source -> local)
     */
    private function executePull(
        string $sourceType,
        array $credentials,
        array $calendarIds,
        Carbon $syncStart,
        Carbon $syncEnd,
        bool $dryRun
    ): array {
        $results = [
            'count' => 0,
            'errors' => 0,
            'skipped' => 0,
            'calendars' => [],
            'events' => [],
        ];

        try {
            $events = $this->fetchEventsFromSource(
                $sourceType,
                $credentials,
                $calendarIds,
                $syncStart,
                $syncEnd
            );

            foreach ($events as $event) {
                try {
                    if ($dryRun) {
                        $results['events'][] = [
                            'action' => 'would_pull',
                            'uid' => $event['uid'] ?? 'unknown',
                            'title' => $event['title'] ?? $event['summary'] ?? 'Untitled',
                        ];
                        $results['count']++;
                        continue;
                    }

                    // Store event locally (upsert)
                    $stored = $this->storeEventLocally($sourceType, $event);
                    if ($stored) {
                        $results['count']++;
                        $results['events'][] = [
                            'action' => 'pulled',
                            'uid' => $event['uid'] ?? 'unknown',
                            'title' => $event['title'] ?? $event['summary'] ?? 'Untitled',
                        ];
                    } else {
                        $results['skipped']++;
                    }
                } catch (Exception $e) {
                    Log::warning('Failed to pull event', [
                        'event' => $event['uid'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                    $results['errors']++;
                }
            }

            // Track which calendars were synced
            $results['calendars'] = array_unique(array_column($events, 'calendar_id'));

        } catch (Exception $e) {
            Log::error('Pull sync failed', ['error' => $e->getMessage()]);
            $results['errors']++;
        }

        return $results;
    }

    /**
     * Execute push sync (local -> source)
     */
    private function executePush(
        string $sourceType,
        array $credentials,
        array $calendarIds,
        Carbon $syncStart,
        Carbon $syncEnd,
        bool $dryRun
    ): array {
        $results = [
            'count' => 0,
            'errors' => 0,
            'skipped' => 0,
            'calendars' => [],
            'events' => [],
        ];

        try {
            // Get local events that need to be pushed
            $localEvents = $this->getLocalEventsForPush($sourceType, $calendarIds, $syncStart, $syncEnd);

            foreach ($localEvents as $event) {
                try {
                    if ($dryRun) {
                        $results['events'][] = [
                            'action' => 'would_push',
                            'uid' => $event['uid'] ?? 'unknown',
                            'title' => $event['title'] ?? 'Untitled',
                        ];
                        $results['count']++;
                        continue;
                    }

                    // Push event to source
                    $pushed = $this->pushEventToSource($sourceType, $credentials, $event);
                    if ($pushed) {
                        $results['count']++;
                        $results['events'][] = [
                            'action' => 'pushed',
                            'uid' => $event['uid'] ?? 'unknown',
                            'title' => $event['title'] ?? 'Untitled',
                        ];
                    } else {
                        $results['skipped']++;
                    }
                } catch (Exception $e) {
                    Log::warning('Failed to push event', [
                        'event' => $event['uid'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                    $results['errors']++;
                }
            }

        } catch (Exception $e) {
            Log::error('Push sync failed', ['error' => $e->getMessage()]);
            $results['errors']++;
        }

        return $results;
    }

    /**
     * Execute bidirectional sync
     */
    private function executeBidirectional(
        string $sourceType,
        array $credentials,
        array $calendarIds,
        Carbon $syncStart,
        Carbon $syncEnd,
        string $conflictResolution,
        bool $dryRun
    ): array {
        $results = [
            'pulled' => 0,
            'pushed' => 0,
            'conflicts' => 0,
            'errors' => 0,
            'skipped' => 0,
            'calendars' => [],
            'events' => [],
        ];

        try {
            // Fetch events from both sides
            $remoteEvents = $this->fetchEventsFromSource($sourceType, $credentials, $calendarIds, $syncStart, $syncEnd);
            $localEvents = $this->getLocalEventsForPush($sourceType, $calendarIds, $syncStart, $syncEnd);

            // Index by UID for comparison
            $remoteByUid = [];
            foreach ($remoteEvents as $event) {
                $uid = $event['uid'] ?? null;
                if ($uid) {
                    $remoteByUid[$uid] = $event;
                }
            }

            $localByUid = [];
            foreach ($localEvents as $event) {
                $uid = $event['uid'] ?? null;
                if ($uid) {
                    $localByUid[$uid] = $event;
                }
            }

            // Process remote events (pull new/updated)
            foreach ($remoteByUid as $uid => $remoteEvent) {
                try {
                    $localEvent = $localByUid[$uid] ?? null;

                    if (!$localEvent) {
                        // New event from remote - pull it
                        if ($dryRun) {
                            $results['events'][] = ['action' => 'would_pull_new', 'uid' => $uid];
                        } else {
                            $this->storeEventLocally($sourceType, $remoteEvent);
                        }
                        $results['pulled']++;
                    } else {
                        // Event exists in both - check for conflict
                        $conflict = $this->detectConflict($localEvent, $remoteEvent);
                        if ($conflict) {
                            $results['conflicts']++;
                            $winner = $this->resolveConflict($localEvent, $remoteEvent, $conflictResolution);
                            $results['events'][] = [
                                'action' => $dryRun ? 'would_resolve_conflict' : 'resolved_conflict',
                                'uid' => $uid,
                                'resolution' => $conflictResolution,
                                'winner' => $winner === $remoteEvent ? 'remote' : 'local',
                            ];
                        }
                    }
                } catch (Exception $e) {
                    Log::warning('Bidirectional sync error for event', ['uid' => $uid, 'error' => $e->getMessage()]);
                    $results['errors']++;
                }
            }

            // Process local events (push new to remote)
            foreach ($localByUid as $uid => $localEvent) {
                if (!isset($remoteByUid[$uid])) {
                    // New local event - push it
                    try {
                        if ($dryRun) {
                            $results['events'][] = ['action' => 'would_push_new', 'uid' => $uid];
                        } else {
                            $this->pushEventToSource($sourceType, $credentials, $localEvent);
                        }
                        $results['pushed']++;
                    } catch (Exception $e) {
                        Log::warning('Failed to push new local event', ['uid' => $uid, 'error' => $e->getMessage()]);
                        $results['errors']++;
                    }
                }
            }

            $results['calendars'] = array_unique(array_merge(
                array_column($remoteEvents, 'calendar_id'),
                array_column($localEvents, 'calendar_id')
            ));

        } catch (Exception $e) {
            Log::error('Bidirectional sync failed', ['error' => $e->getMessage()]);
            $results['errors']++;
        }

        return $results;
    }

    /**
     * Fetch events from external calendar source
     */
    private function fetchEventsFromSource(
        string $sourceType,
        array $credentials,
        array $calendarIds,
        Carbon $syncStart,
        Carbon $syncEnd
    ): array {
        switch ($sourceType) {
            case 'nextcloud':
                return $this->fetchNextcloudEvents($credentials, $calendarIds, $syncStart, $syncEnd);

            case 'google':
                return $this->fetchGoogleEvents($credentials, $calendarIds, $syncStart, $syncEnd);

            case 'icloud':
                return $this->fetchICloudEvents($credentials, $calendarIds, $syncStart, $syncEnd);

            default:
                throw new Exception("Unsupported source type: {$sourceType}");
        }
    }

    /**
     * Fetch events from Nextcloud CalDAV
     */
    private function fetchNextcloudEvents(
        array $credentials,
        array $calendarIds,
        Carbon $syncStart,
        Carbon $syncEnd
    ): array {
        $nextcloud = new NextcloudService();

        // Get all calendars if none specified
        if (empty($calendarIds)) {
            $calendars = $nextcloud->getCalendars(true);
            $calendarIds = array_column($calendars, 'id');
        }

        $allEvents = [];

        foreach ($calendarIds as $calendarId) {
            try {
                $events = $nextcloud->getCalendarEvents(
                    $calendarId,
                    $syncStart->toIso8601String(),
                    $syncEnd->toIso8601String()
                );

                foreach ($events as $event) {
                    $event['calendar_id'] = $calendarId;
                    $event['source_type'] = 'nextcloud';
                    $event['title'] = $event['summary'] ?? 'Untitled';
                    $allEvents[] = $event;
                }
            } catch (Exception $e) {
                Log::warning("Failed to fetch Nextcloud calendar: {$calendarId}", ['error' => $e->getMessage()]);
            }
        }

        return $allEvents;
    }

    /**
     * Fetch events from Google Calendar API
     */
    private function fetchGoogleEvents(
        array $credentials,
        array $calendarIds,
        Carbon $syncStart,
        Carbon $syncEnd
    ): array {
        $accessToken = $credentials['access_token'] ?? null;
        if (!$accessToken) {
            throw new Exception('Google Calendar requires access_token in credentials');
        }

        // Check if token needs refresh
        $accessToken = $this->refreshGoogleTokenIfNeeded($credentials);

        $allEvents = [];

        // Default to primary calendar if none specified
        if (empty($calendarIds)) {
            $calendarIds = ['primary'];
        }

        foreach ($calendarIds as $calendarId) {
            try {
                $response = Http::withToken($accessToken)
                    ->connectTimeout(5)
                    ->timeout(30)
                    ->get("https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events", [
                        'timeMin' => $syncStart->toRfc3339String(),
                        'timeMax' => $syncEnd->toRfc3339String(),
                        'singleEvents' => true,
                        'orderBy' => 'startTime',
                        'maxResults' => 500,
                    ]);

                if (!$response->successful()) {
                    Log::warning("Google Calendar API error for calendar: {$calendarId}", [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    continue;
                }

                $data = $response->json();
                $events = $data['items'] ?? [];

                foreach ($events as $event) {
                    $allEvents[] = [
                        'uid' => $event['id'],
                        'title' => $event['summary'] ?? 'Untitled',
                        'summary' => $event['summary'] ?? '',
                        'description' => $event['description'] ?? '',
                        'location' => $event['location'] ?? '',
                        'start' => $event['start']['dateTime'] ?? $event['start']['date'] ?? null,
                        'end' => $event['end']['dateTime'] ?? $event['end']['date'] ?? null,
                        'calendar_id' => $calendarId,
                        'source_type' => 'google',
                        'updated' => $event['updated'] ?? null,
                        'raw' => $event,
                    ];
                }
            } catch (Exception $e) {
                Log::warning("Failed to fetch Google calendar: {$calendarId}", ['error' => $e->getMessage()]);
            }
        }

        return $allEvents;
    }

    /**
     * Refresh Google OAuth token if expired
     */
    private function refreshGoogleTokenIfNeeded(array $credentials): string
    {
        $expiresAt = $credentials['expires_at'] ?? null;
        $refreshToken = $credentials['refresh_token'] ?? null;

        // If no expiry or not expired, return current token
        if (!$expiresAt || Carbon::parse($expiresAt)->isFuture()) {
            return $credentials['access_token'];
        }

        // Need to refresh
        if (!$refreshToken) {
            throw new Exception('Google token expired and no refresh_token available');
        }

        $clientId = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');

        if (!$clientId || !$clientSecret) {
            throw new Exception('Google client_id and client_secret required for token refresh');
        }

        $response = Http::asForm()->connectTimeout(5)->timeout(30)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (!$response->successful()) {
            throw new Exception('Failed to refresh Google token: ' . $response->body());
        }

        $data = $response->json();
        $newAccessToken = $data['access_token'];
        $expiresIn = $data['expires_in'] ?? 3600;
        $newExpiresAt = Carbon::now()->addSeconds($expiresIn)->toDateTimeString();

        // Update token in database (raw SQL per project rules)
        DB::update(
            'UPDATE oauth_tokens SET access_token = ?, access_token_expires_at = ?, updated_at = NOW() WHERE provider = ? AND refresh_token = ?',
            [$newAccessToken, $newExpiresAt, 'google', $refreshToken]
        );

        Log::info('Google OAuth token refreshed');

        return $newAccessToken;
    }

    /**
     * Fetch events from iCloud CalDAV
     */
    private function fetchICloudEvents(
        array $credentials,
        array $calendarIds,
        Carbon $syncStart,
        Carbon $syncEnd
    ): array {
        $username = $credentials['username'] ?? $credentials['apple_id'] ?? null;
        $password = $credentials['password'] ?? $credentials['app_password'] ?? null;

        if (!$username || !$password) {
            throw new Exception('iCloud requires username/apple_id and password/app_password in credentials');
        }

        // iCloud CalDAV endpoint
        $baseUrl = 'https://caldav.icloud.com';

        $allEvents = [];

        // First, discover calendars if none specified
        if (empty($calendarIds)) {
            $calendarIds = $this->discoverICloudCalendars($baseUrl, $username, $password);
        }

        // Format dates for CalDAV
        $startCaldav = $syncStart->format('Ymd\THis') . 'Z';
        $endCaldav = $syncEnd->format('Ymd\THis') . 'Z';

        foreach ($calendarIds as $calendarId) {
            try {
                $calendarUrl = "{$baseUrl}/{$username}/calendars/{$calendarId}/";

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

                $response = Http::withBasicAuth($username, $password)
                    ->connectTimeout(5)
                    ->timeout(30)
                    ->withHeaders([
                        'Content-Type' => 'application/xml; charset=utf-8',
                        'Depth' => '1',
                    ])
                    ->send('REPORT', $calendarUrl, ['body' => $xml]);

                if (!$response->successful()) {
                    Log::warning("iCloud CalDAV error for calendar: {$calendarId}", [
                        'status' => $response->status(),
                    ]);
                    continue;
                }

                $events = $this->parseICalResponse($response->body(), $calendarId, 'icloud');
                $allEvents = array_merge($allEvents, $events);

            } catch (Exception $e) {
                Log::warning("Failed to fetch iCloud calendar: {$calendarId}", ['error' => $e->getMessage()]);
            }
        }

        return $allEvents;
    }

    /**
     * Discover available iCloud calendars
     */
    private function discoverICloudCalendars(string $baseUrl, string $username, string $password): array
    {
        $url = "{$baseUrl}/{$username}/calendars/";

        $xml = <<<XML
<?xml version="1.0" encoding="utf-8" ?>
<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:displayname />
    <d:resourcetype />
  </d:prop>
</d:propfind>
XML;

        $response = Http::withBasicAuth($username, $password)
            ->connectTimeout(5)
            ->timeout(30)
            ->withHeaders([
                'Content-Type' => 'application/xml; charset=utf-8',
                'Depth' => '1',
            ])
            ->send('PROPFIND', $url, ['body' => $xml]);

        if (!$response->successful()) {
            Log::warning('Failed to discover iCloud calendars', ['status' => $response->status()]);
            return [];
        }

        // Parse XML to extract calendar IDs
        $calendarIds = [];
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($response->body());
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('d', 'DAV:');
            $xpath->registerNamespace('cal', 'urn:ietf:params:xml:ns:caldav');

            $responses = $xpath->query('//d:response');
            foreach ($responses as $resp) {
                $hrefNode = $xpath->query('d:href', $resp)->item(0);
                $calendarNode = $xpath->query('d:propstat/d:prop/d:resourcetype/cal:calendar', $resp)->item(0);

                if ($hrefNode && $calendarNode) {
                    $href = $hrefNode->nodeValue;
                    $calendarId = basename(rtrim($href, '/'));
                    if (!empty($calendarId)) {
                        $calendarIds[] = $calendarId;
                    }
                }
            }
        } catch (Exception $e) {
            Log::warning('Error parsing iCloud calendar discovery', ['error' => $e->getMessage()]);
        }

        return $calendarIds;
    }

    /**
     * Parse iCalendar response XML into events array
     */
    private function parseICalResponse(string $xml, string $calendarId, string $sourceType): array
    {
        $events = [];

        try {
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadXML($xml);

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('d', 'DAV:');
            $xpath->registerNamespace('cal', 'urn:ietf:params:xml:ns:caldav');

            $responses = $xpath->query('//d:response');

            foreach ($responses as $response) {
                $calendarDataArray = $xpath->query('d:propstat/d:prop/cal:calendar-data', $response);

                if ($calendarDataArray->length === 0) {
                    continue;
                }

                $calendarData = $calendarDataArray->item(0)->nodeValue;

                if (!empty($calendarData)) {
                    $parsedEvents = $this->parseICalEvents($calendarData, $calendarId, $sourceType);
                    $events = array_merge($events, $parsedEvents);
                }
            }
        } catch (Exception $e) {
            Log::warning('Failed to parse iCal response', ['error' => $e->getMessage()]);
        }

        return $events;
    }

    /**
     * Parse iCalendar VEVENT blocks
     */
    private function parseICalEvents(string $icalData, string $calendarId, string $sourceType): array
    {
        $events = [];

        preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $icalData, $matches);

        foreach ($matches[1] as $eventBlock) {
            $event = $this->parseICalEvent($eventBlock);
            if ($event) {
                $event['calendar_id'] = $calendarId;
                $event['source_type'] = $sourceType;
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
                $event['summary'] = $event['title'] = substr($line, 8);
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
            } elseif (str_starts_with($line, 'LAST-MODIFIED:')) {
                $event['updated'] = $this->parseICalDate(substr($line, 14));
            }
        }

        return !empty($event) ? $event : null;
    }

    /**
     * Parse iCal date format to ISO 8601
     */
    private function parseICalDate(string $date): string
    {
        $isUtc = str_ends_with($date, 'Z');
        $date = rtrim($date, 'Z');

        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})/', $date, $m)) {
            $result = "{$m[1]}-{$m[2]}-{$m[3]}T{$m[4]}:{$m[5]}:{$m[6]}";
            return $isUtc ? $result . 'Z' : $result;
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        return $date;
    }

    /**
     * Store event locally in database
     */
    private function storeEventLocally(string $sourceType, array $event): bool
    {
        $uid = $event['uid'] ?? null;
        if (!$uid) {
            return false;
        }

        $calendarName = $event['calendar_id'] ?? $sourceType;

        // Check if event exists (raw SQL per project rules)
        $existing = DB::select(
            'SELECT id, updated_at FROM calendar_events WHERE external_id = ?',
            [$uid]
        );

        $eventData = [
            'external_id' => $uid,
            'calendar_name' => $calendarName,
            'title' => $event['title'] ?? $event['summary'] ?? 'Untitled',
            'description' => $event['description'] ?? null,
            'location' => $event['location'] ?? null,
            'start_at' => $this->normalizeDateTime($event['start'] ?? null),
            'end_at' => $this->normalizeDateTime($event['end'] ?? null),
            'all_day' => $this->isAllDayEvent($event),
        ];

        if (empty($existing)) {
            // Insert new event
            DB::insert(
                'INSERT INTO calendar_events
                (external_id, calendar_name, title, description, location, start_at, end_at, all_day, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $eventData['external_id'],
                    $eventData['calendar_name'],
                    $eventData['title'],
                    $eventData['description'],
                    $eventData['location'],
                    $eventData['start_at'],
                    $eventData['end_at'],
                    $eventData['all_day'] ? 1 : 0,
                ]
            );
            return true;
        } else {
            // Update existing event
            DB::update(
                'UPDATE calendar_events
                SET title = ?, description = ?, location = ?, start_at = ?, end_at = ?, all_day = ?, updated_at = NOW()
                WHERE external_id = ?',
                [
                    $eventData['title'],
                    $eventData['description'],
                    $eventData['location'],
                    $eventData['start_at'],
                    $eventData['end_at'],
                    $eventData['all_day'] ? 1 : 0,
                    $uid,
                ]
            );
            return true;
        }
    }

    /**
     * Normalize datetime string to MySQL format
     */
    private function normalizeDateTime(?string $dateTime): ?string
    {
        if (!$dateTime) {
            return null;
        }

        try {
            return Carbon::parse($dateTime)->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Check if event is all-day
     */
    private function isAllDayEvent(array $event): bool
    {
        $start = $event['start'] ?? '';

        // All-day events typically don't have time component
        if (is_string($start) && strpos($start, 'T') === false && strlen($start) === 10) {
            return true;
        }

        return false;
    }

    /**
     * Get local events that need to be pushed to external source
     */
    private function getLocalEventsForPush(
        string $sourceType,
        array $calendarIds,
        Carbon $syncStart,
        Carbon $syncEnd
    ): array {
        $calendarFilter = '';
        $params = [$syncStart->format('Y-m-d H:i:s'), $syncEnd->format('Y-m-d H:i:s')];

        if (!empty($calendarIds)) {
            $placeholders = implode(',', array_fill(0, count($calendarIds), '?'));
            $calendarFilter = "AND calendar_name IN ({$placeholders})";
            $params = array_merge($params, $calendarIds);
        }

        // Raw SQL per project rules
        $events = DB::select(
            "SELECT * FROM calendar_events
            WHERE start_at >= ? AND start_at <= ? {$calendarFilter}
            ORDER BY start_at ASC",
            $params
        );

        // Convert to array format
        return array_map(function ($row) {
            return [
                'id' => $row->id,
                'uid' => $row->external_id,
                'calendar_name' => $row->calendar_name,
                'title' => $row->title,
                'description' => $row->description,
                'location' => $row->location,
                'start' => $row->start_at,
                'end' => $row->end_at,
                'is_all_day' => (bool) $row->all_day,
                'updated' => $row->updated_at,
            ];
        }, $events);
    }

    /**
     * Push event to external source (placeholder - implement per source)
     */
    private function pushEventToSource(string $sourceType, array $credentials, array $event): bool
    {
        // Push implementation depends on source type
        switch ($sourceType) {
            case 'nextcloud':
                return $this->pushToNextcloud($credentials, $event);

            case 'google':
                return $this->pushToGoogle($credentials, $event);

            case 'icloud':
                return $this->pushToICloud($credentials, $event);

            default:
                throw new Exception("Push not implemented for source: {$sourceType}");
        }
    }

    /**
     * Push event to Nextcloud CalDAV
     */
    private function pushToNextcloud(array $credentials, array $event): bool
    {
        $baseUrl = $credentials['url'] ?? config('services.nextcloud.url');
        $username = $credentials['username'] ?? config('services.nextcloud.username');
        $password = $credentials['password'] ?? config('services.nextcloud.password');

        $calendarId = $event['calendar_id'] ?? 'personal';
        $uid = $event['uid'] ?? 'event-' . uniqid();

        $icalEvent = $this->eventToICal($event, $uid);

        $url = "{$baseUrl}/remote.php/dav/calendars/{$username}/{$calendarId}/{$uid}.ics";

        $response = Http::withBasicAuth($username, $password)
            ->connectTimeout(5)
            ->timeout(30)
            ->withHeaders(['Content-Type' => 'text/calendar; charset=utf-8'])
            ->put($url, $icalEvent);

        return $response->successful();
    }

    /**
     * Push event to Google Calendar
     */
    private function pushToGoogle(array $credentials, array $event): bool
    {
        $accessToken = $this->refreshGoogleTokenIfNeeded($credentials);
        $calendarId = $event['calendar_id'] ?? 'primary';

        $googleEvent = [
            'summary' => $event['title'] ?? 'Untitled',
            'description' => $event['description'] ?? '',
            'location' => $event['location'] ?? '',
            'start' => $this->formatGoogleDateTime($event['start'], $event['is_all_day'] ?? false),
            'end' => $this->formatGoogleDateTime($event['end'], $event['is_all_day'] ?? false),
        ];

        $url = "https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events";

        // Check if updating existing event
        if (!empty($event['uid']) && strpos($event['uid'], '@google.com') !== false) {
            $eventId = str_replace('@google.com', '', $event['uid']);
            $url .= "/{$eventId}";
            $response = Http::withToken($accessToken)->connectTimeout(5)->timeout(30)->put($url, $googleEvent);
        } else {
            $response = Http::withToken($accessToken)->connectTimeout(5)->timeout(30)->post($url, $googleEvent);
        }

        return $response->successful();
    }

    /**
     * Format datetime for Google Calendar API
     */
    private function formatGoogleDateTime(?string $dateTime, bool $isAllDay): array
    {
        if (!$dateTime) {
            $dateTime = Carbon::now()->toIso8601String();
        }

        $carbon = Carbon::parse($dateTime);

        if ($isAllDay) {
            return ['date' => $carbon->format('Y-m-d')];
        }

        return ['dateTime' => $carbon->toRfc3339String()];
    }

    /**
     * Push event to iCloud CalDAV
     */
    private function pushToICloud(array $credentials, array $event): bool
    {
        $username = $credentials['username'] ?? $credentials['apple_id'] ?? null;
        $password = $credentials['password'] ?? $credentials['app_password'] ?? null;

        if (!$username || !$password) {
            throw new Exception('iCloud requires username and password');
        }

        $calendarId = $event['calendar_id'] ?? 'calendar';
        $uid = $event['uid'] ?? 'event-' . uniqid();

        $icalEvent = $this->eventToICal($event, $uid);

        $url = "https://caldav.icloud.com/{$username}/calendars/{$calendarId}/{$uid}.ics";

        $response = Http::withBasicAuth($username, $password)
            ->connectTimeout(5)
            ->timeout(30)
            ->withHeaders(['Content-Type' => 'text/calendar; charset=utf-8'])
            ->put($url, $icalEvent);

        return $response->successful();
    }

    /**
     * Convert event array to iCalendar format
     */
    private function eventToICal(array $event, string $uid): string
    {
        $now = gmdate('Ymd\THis\Z');
        $title = $this->escapeICalText($event['title'] ?? 'Untitled');
        $description = $this->escapeICalText($event['description'] ?? '');
        $location = $this->escapeICalText($event['location'] ?? '');

        $dtstart = $this->formatICalDateTime($event['start'] ?? null, $event['is_all_day'] ?? false);
        $dtend = $this->formatICalDateTime($event['end'] ?? null, $event['is_all_day'] ?? false);

        return "BEGIN:VCALENDAR\r\n" .
            "VERSION:2.0\r\n" .
            "PRODID:-//PLOS//CalendarSyncNode//EN\r\n" .
            "BEGIN:VEVENT\r\n" .
            "UID:{$uid}\r\n" .
            "DTSTAMP:{$now}\r\n" .
            "DTSTART:{$dtstart}\r\n" .
            "DTEND:{$dtend}\r\n" .
            "SUMMARY:{$title}\r\n" .
            ($description ? "DESCRIPTION:{$description}\r\n" : '') .
            ($location ? "LOCATION:{$location}\r\n" : '') .
            "END:VEVENT\r\n" .
            "END:VCALENDAR\r\n";
    }

    /**
     * Escape text for iCalendar format
     */
    private function escapeICalText(string $text): string
    {
        return str_replace(
            ["\n", "\r", ',', ';', '\\'],
            ['\\n', '', '\\,', '\\;', '\\\\'],
            $text
        );
    }

    /**
     * Format datetime for iCalendar
     */
    private function formatICalDateTime(?string $dateTime, bool $isAllDay): string
    {
        if (!$dateTime) {
            return gmdate('Ymd\THis\Z');
        }

        $carbon = Carbon::parse($dateTime);

        if ($isAllDay) {
            return $carbon->format('Ymd');
        }

        return $carbon->utc()->format('Ymd\THis\Z');
    }

    /**
     * Detect if there's a conflict between local and remote events
     */
    private function detectConflict(array $localEvent, array $remoteEvent): bool
    {
        $localUpdated = $localEvent['updated'] ?? null;
        $remoteUpdated = $remoteEvent['updated'] ?? null;

        // If either has no timestamp, assume no conflict
        if (!$localUpdated || !$remoteUpdated) {
            return false;
        }

        // Check if both were modified after sync
        $localTime = Carbon::parse($localUpdated);
        $remoteTime = Carbon::parse($remoteUpdated);

        // Consider conflict if updates are within 5 minutes of each other
        return abs($localTime->diffInMinutes($remoteTime)) < 5;
    }

    /**
     * Resolve conflict between local and remote events
     */
    private function resolveConflict(array $localEvent, array $remoteEvent, string $resolution): array
    {
        switch ($resolution) {
            case 'source_wins':
                return $remoteEvent;

            case 'target_wins':
                return $localEvent;

            case 'newest_wins':
                $localUpdated = Carbon::parse($localEvent['updated'] ?? '1970-01-01');
                $remoteUpdated = Carbon::parse($remoteEvent['updated'] ?? '1970-01-01');
                return $localUpdated->gt($remoteUpdated) ? $localEvent : $remoteEvent;

            default:
                return $remoteEvent;
        }
    }

    /**
     * Log sync run to database
     */
    private function logSyncRun(string $sourceType, string $direction, array $stats, bool $dryRun): void
    {
        try {
            // Raw SQL per project rules
            DB::insert(
                'INSERT INTO calendar_sync_runs
                (source_type, sync_direction, pulled, pushed, conflicts, errors, skipped, is_dry_run, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $sourceType,
                    $direction,
                    $stats['pulled'],
                    $stats['pushed'],
                    $stats['conflicts'],
                    $stats['errors'],
                    $stats['skipped'],
                    $dryRun ? 1 : 0,
                ]
            );
        } catch (Exception $e) {
            // calendar_sync_runs table does not exist yet — log but don't fail
            Log::warning('CalendarSyncNode: Failed to log sync run (calendar_sync_runs table may not exist)', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get node definition for workflow builder
     */
    public static function getDefinition(): array
    {
        return [
            'type' => 'calendar_sync',
            'name' => 'Calendar Sync',
            'description' => 'Sync calendars between Google, iCloud, and Nextcloud',
            'category' => 'Calendar',
            'icon' => '&#128197;',
            'config' => [
                'source_type' => [
                    'type' => 'select',
                    'label' => 'Source Type',
                    'description' => 'Calendar source to sync with',
                    'required' => true,
                    'default' => 'nextcloud',
                    'options' => [
                        'nextcloud' => 'Nextcloud (CalDAV)',
                        'google' => 'Google Calendar',
                        'icloud' => 'Apple iCloud',
                    ],
                ],
                'credentials_key' => [
                    'type' => 'text',
                    'label' => 'Credentials Key',
                    'description' => 'Key to look up credentials (in oauth_tokens or config)',
                    'required' => false,
                    'default' => 'default',
                ],
                'sync_direction' => [
                    'type' => 'select',
                    'label' => 'Sync Direction',
                    'description' => 'How to sync events',
                    'required' => true,
                    'default' => 'pull',
                    'options' => [
                        'pull' => 'Pull (source to local)',
                        'push' => 'Push (local to source)',
                        'bidirectional' => 'Bidirectional',
                    ],
                ],
                'calendar_ids' => [
                    'type' => 'array',
                    'label' => 'Calendar IDs',
                    'description' => 'Specific calendars to sync (empty = all)',
                    'required' => false,
                    'default' => [],
                ],
                'sync_window_days' => [
                    'type' => 'number',
                    'label' => 'Sync Window (days)',
                    'description' => 'Number of days ahead to sync',
                    'required' => false,
                    'default' => 30,
                ],
                'conflict_resolution' => [
                    'type' => 'select',
                    'label' => 'Conflict Resolution',
                    'description' => 'How to resolve conflicts in bidirectional sync',
                    'required' => false,
                    'default' => 'source_wins',
                    'options' => [
                        'source_wins' => 'Source Wins',
                        'target_wins' => 'Target Wins',
                        'newest_wins' => 'Newest Wins',
                    ],
                ],
                'dry_run' => [
                    'type' => 'boolean',
                    'label' => 'Dry Run',
                    'description' => 'Preview changes without applying them',
                    'required' => false,
                    'default' => false,
                ],
            ],
            'outputs' => [
                'summary' => 'Human-readable sync summary',
                'stats' => 'Sync statistics (pulled, pushed, conflicts, errors)',
                'source_type' => 'Source type used',
                'sync_direction' => 'Sync direction used',
                'sync_window' => 'Date range synced',
                'calendars_synced' => 'List of calendar IDs synced',
                'events' => 'List of synced events with actions',
                'dry_run' => 'Whether this was a dry run',
            ],
        ];
    }
}
