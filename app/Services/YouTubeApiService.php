<?php

namespace App\Services;

use Exception;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use MrMySQL\YoutubeTranscript\Exception\NoTranscriptFoundException;
use MrMySQL\YoutubeTranscript\Exception\TooManyRequestsException;
use MrMySQL\YoutubeTranscript\Exception\TranscriptsDisabledException;
use MrMySQL\YoutubeTranscript\TranscriptListFetcher;

/**
 * YouTube API Service
 *
 * Handles YouTube Data API v3 requests with OAuth 2.0 authentication.
 * Provides methods for fetching subscriptions, playlists, and video metadata.
 */
class YouTubeApiService
{
    private ?string $clientId = null;

    private ?string $clientSecret = null;

    private ?string $redirectUri = null; // Nullable - dynamically generated in controller

    private string $apiBaseUrl = 'https://www.googleapis.com/youtube/v3';

    /**
     * Cache TTL for API responses (in minutes)
     */
    private int $cacheTtl;

    private ?YouTubeTranscriptLanguagePolicy $transcriptLanguagePolicy = null;

    public function __construct()
    {
        // Get credentials from config (works when config is cached, unlike env())
        $clientId = config('youtube.client_id');
        $clientSecret = config('youtube.client_secret');

        // Validate credentials before assignment to avoid TypeError
        if (empty($clientId) || empty($clientSecret)) {
            Log::warning('YouTubeApiService: API credentials not configured in .env');
            throw new Exception('YouTube API credentials not configured in .env');
        }

        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = config('youtube.redirect_uri');
        $this->cacheTtl = (int) config('youtube.cache_ttl', 60); // 1 hour default
    }

    /**
     * Get access token from cache or refresh if expired
     *
     * @return string Access token
     *
     * @throws Exception If token refresh fails
     */
    private function getAccessToken(): string
    {
        $cacheKey = 'youtube_access_token';

        // Check cache first
        $token = Cache::get($cacheKey);
        if ($token) {
            return $token;
        }

        // Get refresh token from config/database
        $refreshToken = $this->getRefreshToken();
        if (! $refreshToken) {
            throw new Exception('YouTube refresh token not found. Please authenticate.');
        }

        // Refresh access token
        $response = Http::asForm()->connectTimeout(5)->timeout(30)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            throw new Exception('Failed to refresh YouTube access token: '.$response->body());
        }

        $data = $response->json();
        $accessToken = $data['access_token'];
        $expiresIn = $data['expires_in'] ?? 3600;

        // Cache the token for fast access
        Cache::put($cacheKey, $accessToken, now()->addSeconds($expiresIn - 60));

        // Also update database with new access token and expiration
        DB::update(
            'UPDATE oauth_tokens SET access_token = ?, access_token_expires_at = ?, updated_at = ? WHERE provider = ?',
            [$accessToken, now()->addSeconds($expiresIn), now(), 'youtube']
        );

        Log::info('YouTube access token refreshed', [
            'expires_in' => $expiresIn,
        ]);

        return $accessToken;
    }

    /**
     * Get refresh token from database storage
     * Falls back to cache for seamless migration
     */
    private function getRefreshToken(): ?string
    {
        // Try database first
        $result = DB::selectOne(
            'SELECT refresh_token FROM oauth_tokens WHERE provider = ? LIMIT 1',
            ['youtube']
        );
        $token = $result->refresh_token ?? null;

        if ($token) {
            return $token;
        }

        // Fallback to cache for seamless migration
        $cachedToken = Cache::get('youtube_refresh_token');
        if ($cachedToken) {
            // Migrate from cache to database automatically
            $this->storeRefreshToken($cachedToken);
            Log::info('Auto-migrated YouTube refresh token from cache to database');

            return $cachedToken;
        }

        return null;
    }

    /**
     * Store refresh token in database
     */
    public function storeRefreshToken(string $refreshToken): void
    {
        // Check if record exists
        $exists = DB::selectOne('SELECT id FROM oauth_tokens WHERE provider = ? LIMIT 1', ['youtube']);

        if ($exists) {
            DB::update(
                'UPDATE oauth_tokens SET refresh_token = ?, updated_at = ? WHERE provider = ?',
                [$refreshToken, now(), 'youtube']
            );
        } else {
            DB::insert(
                'INSERT INTO oauth_tokens (provider, refresh_token, created_at, updated_at) VALUES (?, ?, ?, ?)',
                ['youtube', $refreshToken, now(), now()]
            );
        }

        Log::info('YouTube refresh token stored in database');
    }

    /**
     * Store both access and refresh tokens (used during OAuth callback)
     *
     * @param  int  $expiresIn  Seconds until access token expires
     */
    public function storeTokens(string $accessToken, string $refreshToken, int $expiresIn = 3600): void
    {
        // Check if record exists
        $exists = DB::selectOne('SELECT id FROM oauth_tokens WHERE provider = ? LIMIT 1', ['youtube']);

        if ($exists) {
            DB::update(
                'UPDATE oauth_tokens SET access_token = ?, refresh_token = ?, access_token_expires_at = ?, updated_at = ? WHERE provider = ?',
                [$accessToken, $refreshToken, now()->addSeconds($expiresIn), now(), 'youtube']
            );
        } else {
            DB::insert(
                'INSERT INTO oauth_tokens (provider, access_token, refresh_token, access_token_expires_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                ['youtube', $accessToken, $refreshToken, now()->addSeconds($expiresIn), now(), now()]
            );
        }

        // Also cache the access token for faster access
        Cache::put('youtube_access_token', $accessToken, now()->addSeconds($expiresIn - 60));

        Log::info('YouTube OAuth tokens stored', [
            'access_expires_in' => $expiresIn,
        ]);
    }

    /**
     * Get token status for debugging/monitoring
     */
    public function getTokenStatus(): ?array
    {
        $token = DB::selectOne(
            'SELECT * FROM oauth_tokens WHERE provider = ? LIMIT 1',
            ['youtube']
        );

        if (! $token) {
            return null;
        }

        return [
            'provider' => $token->provider,
            'has_refresh_token' => ! empty($token->refresh_token),
            'has_access_token' => ! empty($token->access_token),
            'access_token_expires_at' => $token->access_token_expires_at,
            'is_expired' => $token->access_token_expires_at ? now()->isAfter($token->access_token_expires_at) : null,
            'created_at' => $token->created_at,
            'updated_at' => $token->updated_at,
        ];
    }

    /**
     * Make authenticated API request
     *
     * @param  string  $endpoint  API endpoint (e.g., 'subscriptions')
     * @param  array  $params  Query parameters
     * @param  bool  $useCache  Whether to use cached response
     * @return array API response
     *
     * @throws Exception
     */
    private function apiRequest(string $endpoint, array $params = [], bool $useCache = true): array
    {
        $cacheKey = 'youtube_api:'.$endpoint.':'.md5(json_encode($params));

        // Check cache
        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                Log::debug('YouTube API cache hit', ['endpoint' => $endpoint]);

                return $cached;
            }
        }

        // Get access token
        $accessToken = $this->getAccessToken();

        // Make API request
        $url = $this->apiBaseUrl.'/'.$endpoint;
        $response = Http::withToken($accessToken)->connectTimeout(5)->timeout(30)->get($url, $params);

        if (! $response->successful()) {
            Log::error('YouTube API request failed', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new Exception('YouTube API request failed: '.$response->body());
        }

        $data = $response->json();

        // Cache response
        if ($useCache) {
            Cache::put($cacheKey, $data, now()->addMinutes($this->cacheTtl));
        }

        return $data;
    }

    /**
     * Get user's subscriptions
     *
     * @param  int  $maxResults  Maximum results to fetch (1-50)
     * @param  string|null  $pageToken  Page token for pagination
     * @param  bool  $useCache  Whether to use cache
     * @return array Subscriptions data
     *
     * @throws Exception
     */
    public function getSubscriptions(int $maxResults = 50, ?string $pageToken = null, bool $useCache = true): array
    {
        $params = [
            'part' => 'snippet,contentDetails',
            'mine' => 'true',
            'maxResults' => min($maxResults, 50),
        ];

        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }

        $response = $this->apiRequest('subscriptions', $params, $useCache);

        Log::info('YouTube subscriptions fetched', [
            'count' => count($response['items'] ?? []),
            'total_results' => $response['pageInfo']['totalResults'] ?? 0,
        ]);

        return $response;
    }

    /**
     * Get videos from a playlist
     *
     * @param  string  $playlistId  Playlist ID ('WL' for Watch Later)
     * @param  int  $maxResults  Maximum results (1-50)
     * @param  string|null  $pageToken  Page token for pagination
     * @param  bool  $useCache  Whether to use cache
     * @return array Playlist items
     *
     * @throws Exception
     */
    public function getPlaylistItems(string $playlistId, int $maxResults = 50, ?string $pageToken = null, bool $useCache = true): array
    {
        $params = [
            'part' => 'snippet,contentDetails,status',
            'playlistId' => $playlistId,
            'maxResults' => min($maxResults, 50),
        ];

        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }

        $response = $this->apiRequest('playlistItems', $params, $useCache);

        Log::info('YouTube playlist items fetched', [
            'playlist_id' => $playlistId,
            'count' => count($response['items'] ?? []),
        ]);

        return $response;
    }

    /**
     * Get video details
     *
     * @param  array  $videoIds  Array of video IDs
     * @param  bool  $useCache  Whether to use cache
     * @return array Video details
     *
     * @throws Exception
     */
    public function getVideoDetails(array $videoIds, bool $useCache = true): array
    {
        if (empty($videoIds)) {
            return ['items' => []];
        }

        $params = [
            'part' => 'snippet,contentDetails,statistics,status',
            'id' => implode(',', array_slice($videoIds, 0, 50)), // API limit: 50 IDs
        ];

        $response = $this->apiRequest('videos', $params, $useCache);

        Log::info('YouTube video details fetched', [
            'requested' => count($videoIds),
            'returned' => count($response['items'] ?? []),
        ]);

        return $response;
    }

    /**
     * Get channel's latest uploads
     *
     * @param  string  $channelId  Channel ID
     * @param  int  $maxResults  Maximum results (1-50)
     * @param  \DateTime|null  $publishedAfter  Only videos published after this date
     * @param  bool  $useCache  Whether to use cache
     * @return array Channel videos
     *
     * @throws Exception
     */
    public function getChannelUploads(
        string $channelId,
        int $maxResults = 10,
        ?\DateTime $publishedAfter = null,
        bool $useCache = true
    ): array {
        $params = [
            'part' => 'snippet',
            'channelId' => $channelId,
            'type' => 'video',
            'order' => 'date',
            'maxResults' => min($maxResults, 50),
        ];

        if ($publishedAfter) {
            $params['publishedAfter'] = $publishedAfter->format('Y-m-d\TH:i:s\Z');
        }

        $response = $this->apiRequest('search', $params, $useCache);

        Log::info('YouTube channel uploads fetched', [
            'channel_id' => $channelId,
            'count' => count($response['items'] ?? []),
        ]);

        return $response;
    }

    /**
     * Convert ISO 8601 duration to seconds
     *
     * @param  string  $duration  ISO 8601 duration (e.g., PT15M33S)
     * @return int Duration in seconds
     */
    public static function parseDuration(string $duration): int
    {
        preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $duration, $matches);

        $hours = (int) ($matches[1] ?? 0);
        $minutes = (int) ($matches[2] ?? 0);
        $seconds = (int) ($matches[3] ?? 0);

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    /**
     * Get available captions for a video (OAuth - lists only)
     *
     * This method can list caption tracks for any video, but downloading
     * the actual caption content (via downloadCaption) requires video ownership.
     *
     * Use case: Can identify available languages/tracks for any public video,
     * but you can only download captions from videos you own.
     *
     * @param  string  $videoId  YouTube video ID
     * @param  bool  $useCache  Whether to use cached response
     * @return array Caption tracks available for the video
     *
     * @throws Exception
     */
    public function getCaptions(string $videoId, bool $useCache = true): array
    {
        $params = [
            'part' => 'snippet',
            'videoId' => $videoId,
        ];

        try {
            $response = $this->apiRequest('captions', $params, $useCache);

            Log::info('YouTube captions list fetched', [
                'video_id' => $videoId,
                'caption_count' => count($response['items'] ?? []),
            ]);

            return $response;
        } catch (Exception $e) {
            // Captions API might not be accessible for all videos
            Log::warning('YouTube captions list failed', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return ['items' => []];
        }
    }

    /**
     * Download caption track content (OAuth - requires video ownership)
     *
     * IMPORTANT LIMITATION: This method ONLY works for videos you own or have
     * editing permissions for. It will return 403 Forbidden errors for other
     * creators' videos, even if the captions are publicly visible on YouTube.
     *
     * Per YouTube API documentation: "This method requires the user to have
     * permission to edit the video."
     *
     * Use case: Only use for videos uploaded to your own YouTube channel.
     * For Watch Later playlists or other creators' videos, use timedtext API instead.
     *
     * @param  string  $captionId  Caption track ID from getCaptions()
     * @param  string  $format  Format (srt, vtt, sbv, ttml, or transcript)
     * @return string Caption content
     *
     * @throws Exception When video ownership is not established (403 Forbidden)
     */
    public function downloadCaption(string $captionId, string $format = 'srt'): string
    {
        // Get access token
        $accessToken = $this->getAccessToken();

        // Download caption - cannot use apiRequest() as it returns binary data
        $url = $this->apiBaseUrl.'/captions/'.$captionId;
        $response = Http::withToken($accessToken)
            ->connectTimeout(5)
            ->timeout(30)
            ->get($url, ['tfmt' => $format]);

        if (! $response->successful()) {
            Log::error('YouTube caption download failed', [
                'caption_id' => $captionId,
                'format' => $format,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new Exception('Caption download failed: '.$response->body());
        }

        $content = $response->body();

        Log::info('YouTube caption downloaded', [
            'caption_id' => $captionId,
            'format' => $format,
            'size_bytes' => strlen($content),
        ]);

        return $content;
    }

    /**
     * Parse WebVTT format to plain text
     *
     * WebVTT (Web Video Text Tracks) format includes timestamps and cue settings.
     * This method extracts only the text content.
     *
     * @param  string  $vttContent  WebVTT formatted caption content
     * @return string Plain text transcript
     */
    private function parseWebVTTToText(string $vttContent): string
    {
        $lines = explode("\n", $vttContent);
        $textLines = [];

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip WEBVTT header
            if (strpos($line, 'WEBVTT') === 0) {
                continue;
            }

            // Skip empty lines
            if (empty($line)) {
                continue;
            }

            // Skip metadata lines (Kind:, Language:, etc.)
            if (preg_match('/^(Kind|Language):/i', $line)) {
                continue;
            }

            // Skip cue identifiers (lines that are just numbers) and timestamp lines
            if (preg_match('/^\d+$/', $line) || preg_match('/^\d{2}:\d{2}/', $line)) {
                continue;
            }

            // Remove HTML/XML tags and cue settings
            $line = strip_tags($line);
            $line = preg_replace('/<[^>]+>/', '', $line);

            // Add non-empty text lines
            if (! empty($line)) {
                $textLines[] = $line;
            }
        }

        return implode(' ', $textLines);
    }

    /**
     * Parse WebVTT format to structured transcript array
     *
     * Converts WebVTT captions into timestamped transcript entries.
     *
     * @param  string  $vttContent  WebVTT formatted caption content
     * @return array Array of transcript entries with text, start, and duration
     */
    private function parseWebVTTToArray(string $vttContent): array
    {
        $lines = explode("\n", $vttContent);
        $transcript = [];
        $currentStart = 0;
        $currentDuration = 0;
        $currentText = '';

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip WEBVTT header and empty lines
            if (strpos($line, 'WEBVTT') === 0 || empty($line)) {
                continue;
            }

            // Parse timestamp line (e.g., "00:00:00.000 --> 00:00:02.500")
            if (preg_match('/(\d{2}):(\d{2}):(\d{2})\.(\d{3})\s+-->\s+(\d{2}):(\d{2}):(\d{2})\.(\d{3})/', $line, $matches)) {
                // Save previous entry if exists
                if (! empty($currentText)) {
                    $transcript[] = [
                        'text' => $currentText,
                        'start' => $currentStart,
                        'duration' => $currentDuration,
                    ];
                }

                // Parse start time
                $startHours = (int) $matches[1];
                $startMinutes = (int) $matches[2];
                $startSeconds = (int) $matches[3];
                $startMs = (int) $matches[4];
                $currentStart = ($startHours * 3600) + ($startMinutes * 60) + $startSeconds + ($startMs / 1000);

                // Parse end time
                $endHours = (int) $matches[5];
                $endMinutes = (int) $matches[6];
                $endSeconds = (int) $matches[7];
                $endMs = (int) $matches[8];
                $endTime = ($endHours * 3600) + ($endMinutes * 60) + $endSeconds + ($endMs / 1000);

                $currentDuration = $endTime - $currentStart;
                $currentText = '';
            }
            // Skip cue identifiers (pure numbers)
            elseif (preg_match('/^\d+$/', $line)) {
                continue;
            }
            // Text content
            else {
                $text = strip_tags($line);
                if (! empty($text)) {
                    $currentText .= ($currentText ? ' ' : '').$text;
                }
            }
        }

        // Save final entry
        if (! empty($currentText)) {
            $transcript[] = [
                'text' => $currentText,
                'start' => $currentStart,
                'duration' => $currentDuration,
            ];
        }

        return $transcript;
    }

    /**
     * Get transcript using Invidious API (privacy-focused YouTube frontend)
     *
     * Invidious provides access to YouTube captions without IP blocking issues.
     * Uses multiple public instances for fault tolerance.
     *
     * @param  string  $videoId  YouTube video ID
     * @param  string  $language  Preferred language code (e.g., 'en')
     * @param  bool  $useCache  Whether to use cache
     * @return array Transcript data with success status
     */
    public function getTranscriptViaInvidious(string $videoId, string $language = 'en', bool $useCache = true): array
    {
        try {
            // Check cache first
            $cacheKey = "youtube_invidious:{$videoId}:{$language}";
            if ($useCache) {
                $cached = Cache::get($cacheKey);
                if ($cached) {
                    Log::info('Invidious transcript cache hit', ['video_id' => $videoId]);

                    return $cached;
                }
            }

            // Get Invidious instances from config (comma-separated in .env)
            // Configurable via INVIDIOUS_INSTANCES env variable for easier maintenance
            $instances = $this->getInvidiousInstances();

            $lastError = null;

            // Try each instance until one succeeds
            foreach ($instances as $instance) {
                try {
                    Log::debug('Trying Invidious instance', [
                        'instance' => $instance,
                        'video_id' => $videoId,
                    ]);

                    // Get captions list
                    $response = Http::connectTimeout(5)->timeout(10)->get("{$instance}/api/v1/captions/{$videoId}");

                    if (! $response->successful()) {
                        $lastError = "HTTP {$response->status()} from {$instance}";

                        continue;
                    }

                    $data = $response->json();
                    $captions = $data['captions'] ?? [];

                    if (empty($captions)) {
                        $lastError = 'No captions available';

                        continue;
                    }

                    // Find best matching caption track
                    $selectedCaption = null;

                    // 1. Try exact language match (manual captions preferred)
                    foreach ($captions as $caption) {
                        if (($caption['languageCode'] ?? '') === $language) {
                            $selectedCaption = $caption;
                            break;
                        }
                    }

                    // 2. Try language prefix match (e.g., 'en' matches 'en-US')
                    if (! $selectedCaption) {
                        foreach ($captions as $caption) {
                            $captionLang = $caption['languageCode'] ?? '';
                            if (strpos($captionLang, $language) === 0) {
                                $selectedCaption = $caption;
                                break;
                            }
                        }
                    }

                    if (! $selectedCaption) {
                        return [
                            'success' => false,
                            'video_id' => $videoId,
                            'error' => 'No transcript found for requested language',
                            'error_type' => 'NoTranscriptForRequestedLanguage',
                            'requested_language' => $language,
                            'available_languages' => array_values(array_unique(array_filter(array_map(
                                fn ($caption) => $caption['languageCode'] ?? null,
                                $captions
                            )))),
                            'method' => 'invidious',
                        ];
                    }

                    // Download caption content in WebVTT format
                    $captionResponse = Http::connectTimeout(5)->timeout(15)->get("{$instance}/api/v1/captions/{$videoId}", [
                        'label' => $selectedCaption['label'] ?? $selectedCaption['name'] ?? 'English',
                    ]);

                    if (! $captionResponse->successful()) {
                        $lastError = "Failed to download caption from {$instance}: HTTP ".$captionResponse->status();

                        continue;
                    }

                    $vttContent = $captionResponse->body();

                    // FAULT TOLERANCE: Check for empty response (YouTube API sometimes returns 200 with empty body)
                    if (empty($vttContent) || strlen(trim($vttContent)) < 50) {
                        $lastError = "Empty or invalid caption response from {$instance}";
                        Log::debug('Invidious returned empty caption content', [
                            'instance' => $instance,
                            'video_id' => $videoId,
                            'content_length' => strlen($vttContent),
                        ]);

                        continue; // Try next instance
                    }

                    $fullText = $this->parseWebVTTToText($vttContent);
                    $transcript = $this->parseWebVTTToArray($vttContent);
                    $wordCount = str_word_count($fullText);

                    // FAULT TOLERANCE: If parsed content is empty, try next instance
                    if ($wordCount === 0) {
                        $lastError = "Parsed transcript has zero words from {$instance}";
                        Log::debug('Invidious transcript parsed to zero words', [
                            'instance' => $instance,
                            'video_id' => $videoId,
                            'raw_length' => strlen($vttContent),
                        ]);

                        continue; // Try next instance
                    }

                    $result = [
                        'success' => true,
                        'video_id' => $videoId,
                        'language' => $selectedCaption['languageCode'] ?? $language,
                        'caption_type' => isset($selectedCaption['label']) &&
                                         strpos(strtolower($selectedCaption['label']), 'auto') !== false
                                         ? 'auto-generated' : 'manual',
                        'transcript' => $transcript,
                        'full_text' => $fullText,
                        'word_count' => $wordCount,
                        'instance' => $instance,
                        'method' => 'invidious',
                    ];

                    // Cache the successful result (only if we have actual content)
                    if ($useCache) {
                        Cache::put($cacheKey, $result, now()->addHours(24));
                    }

                    Log::info('Invidious transcript fetched successfully', [
                        'video_id' => $videoId,
                        'instance' => $instance,
                        'language' => $result['language'],
                        'caption_type' => $result['caption_type'],
                        'word_count' => $wordCount,
                    ]);

                    return $result;

                } catch (\Exception $e) {
                    $lastError = $e->getMessage();
                    Log::debug('Invidious instance failed', [
                        'instance' => $instance,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }
            }

            // All instances failed
            Log::error('All Invidious instances failed', [
                'video_id' => $videoId,
                'last_error' => $lastError,
            ]);

            return [
                'success' => false,
                'video_id' => $videoId,
                'error' => 'All Invidious instances failed: '.$lastError,
                'error_type' => 'AllInvidiousInstancesFailed',
            ];

        } catch (\Exception $e) {
            Log::error('Invidious transcript fetch error', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'video_id' => $videoId,
                'error' => $e->getMessage(),
                'error_type' => 'InvidiousError',
            ];
        }
    }

    /**
     * Get transcript for a public YouTube video
     *
     * Uses multiple transcript sources with automatic fallback:
     * 1. Invidious API (privacy-focused YouTube frontend)
     * 2. Piped API (another privacy-focused alternative)
     * 3. PHP mrmysql/youtube-transcript library (direct YouTube access)
     *
     * If a source returns empty transcript (word_count=0) or fails, tries next source.
     * The PHP library fallback has built-in rate limit detection (TooManyRequestsException).
     *
     * @param  string  $videoId  YouTube video ID
     * @param  string  $language  Preferred language code (e.g., 'en')
     * @param  bool  $useCache  Whether to use cache
     * @return array Transcript data with success status
     */
    public function getTranscript(string $videoId, string $language = 'en', bool $useCache = true): array
    {
        // INF-9: Delegate to the full 6-method chain with health-based ordering.
        // Previously used only 3 methods (phplib, piped, invidious) → 57% success rate.
        // getTranscriptWithBackoff uses all 6 methods (phplib, piped, direct, invidious,
        // yt-dlp, whisper) with dynamic health scoring and cooldown tracking.
        // attempt=0 means no backoff delay on first call.
        return $this->getTranscriptWithBackoff($videoId, $language, $useCache, 0);
    }

    /**
     * Get transcript using Piped API (privacy-focused YouTube alternative)
     *
     * Piped provides access to YouTube captions through the /streams endpoint.
     * Uses multiple public instances for fault tolerance.
     *
     * @param  string  $videoId  YouTube video ID
     * @param  string  $language  Preferred language code (e.g., 'en')
     * @param  bool  $useCache  Whether to use cache
     * @return array Transcript data with success status
     */
    public function getTranscriptViaPiped(string $videoId, string $language = 'en', bool $useCache = true): array
    {
        try {
            // Check cache first
            $cacheKey = "youtube_piped:{$videoId}:{$language}";
            if ($useCache) {
                $cached = Cache::get($cacheKey);
                if ($cached) {
                    Log::info('Piped transcript cache hit', ['video_id' => $videoId]);

                    return $cached;
                }
            }

            $instances = $this->getPipedInstances();
            $lastError = null;

            foreach ($instances as $instance) {
                try {
                    Log::debug('Trying Piped instance', [
                        'instance' => $instance,
                        'video_id' => $videoId,
                    ]);

                    // Get stream info which includes subtitles
                    $response = Http::connectTimeout(5)->timeout(15)->get("{$instance}/streams/{$videoId}");

                    if (! $response->successful()) {
                        $lastError = "HTTP {$response->status()} from {$instance}";

                        continue;
                    }

                    $data = $response->json();
                    $subtitles = $data['subtitles'] ?? [];

                    if (empty($subtitles)) {
                        $lastError = 'No subtitles available from Piped';

                        continue;
                    }

                    // Find best matching subtitle track
                    $selectedSubtitle = null;

                    // 1. Try exact language match (prefer non-auto-generated)
                    foreach ($subtitles as $subtitle) {
                        $code = $subtitle['code'] ?? '';
                        $autoGen = $subtitle['autoGenerated'] ?? false;
                        if ($code === $language && ! $autoGen) {
                            $selectedSubtitle = $subtitle;
                            break;
                        }
                    }

                    // 2. Try exact language match (including auto-generated)
                    if (! $selectedSubtitle) {
                        foreach ($subtitles as $subtitle) {
                            if (($subtitle['code'] ?? '') === $language) {
                                $selectedSubtitle = $subtitle;
                                break;
                            }
                        }
                    }

                    // 3. Try language prefix match
                    if (! $selectedSubtitle) {
                        foreach ($subtitles as $subtitle) {
                            $code = $subtitle['code'] ?? '';
                            if (str_starts_with($code, $language)) {
                                $selectedSubtitle = $subtitle;
                                break;
                            }
                        }
                    }

                    if (! $selectedSubtitle) {
                        return [
                            'success' => false,
                            'video_id' => $videoId,
                            'error' => 'No transcript found for requested language',
                            'error_type' => 'NoTranscriptForRequestedLanguage',
                            'requested_language' => $language,
                            'available_languages' => array_values(array_unique(array_filter(array_map(
                                fn ($subtitle) => $subtitle['code'] ?? null,
                                $subtitles
                            )))),
                            'method' => 'piped',
                        ];
                    }

                    // Download subtitle content from the proxy URL
                    $subtitleUrl = $selectedSubtitle['url'] ?? '';
                    if (empty($subtitleUrl)) {
                        $lastError = 'No subtitle URL in response';

                        continue;
                    }

                    $subtitleResponse = Http::connectTimeout(5)->timeout(15)->get($subtitleUrl);

                    if (! $subtitleResponse->successful()) {
                        $lastError = 'Failed to download subtitle from Piped proxy';

                        continue;
                    }

                    $content = $subtitleResponse->body();
                    $mimeType = $selectedSubtitle['mimeType'] ?? '';

                    // Parse based on format
                    if (str_contains($mimeType, 'ttml') || str_contains($content, '<?xml')) {
                        $transcript = $this->parseTTMLToArray($content);
                        $fullText = $this->parseTTMLToText($content);
                    } else {
                        // Assume WebVTT
                        $transcript = $this->parseWebVTTToArray($content);
                        $fullText = $this->parseWebVTTToText($content);
                    }

                    $wordCount = str_word_count($fullText);

                    $result = [
                        'success' => true,
                        'video_id' => $videoId,
                        'language' => $selectedSubtitle['code'] ?? $language,
                        'caption_type' => ($selectedSubtitle['autoGenerated'] ?? false) ? 'auto-generated' : 'manual',
                        'transcript' => $transcript,
                        'full_text' => $fullText,
                        'word_count' => $wordCount,
                        'instance' => $instance,
                        'method' => 'piped',
                    ];

                    // Cache the successful result
                    if ($useCache && $wordCount > 0) {
                        Cache::put($cacheKey, $result, now()->addHours(24));
                    }

                    Log::info('Piped transcript fetched successfully', [
                        'video_id' => $videoId,
                        'instance' => $instance,
                        'language' => $result['language'],
                        'caption_type' => $result['caption_type'],
                        'word_count' => $wordCount,
                    ]);

                    return $result;

                } catch (Exception $e) {
                    $lastError = $e->getMessage();
                    Log::debug('Piped instance failed', [
                        'instance' => $instance,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }
            }

            // All instances failed
            Log::error('All Piped instances failed', [
                'video_id' => $videoId,
                'last_error' => $lastError,
            ]);

            return [
                'success' => false,
                'video_id' => $videoId,
                'error' => 'All Piped instances failed: '.$lastError,
                'error_type' => 'AllPipedInstancesFailed',
            ];

        } catch (Exception $e) {
            Log::error('Piped transcript fetch error', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'video_id' => $videoId,
                'error' => $e->getMessage(),
                'error_type' => 'PipedError',
            ];
        }
    }

    /**
     * Parse TTML (Timed Text Markup Language) to plain text
     *
     * @param  string  $ttmlContent  TTML/XML formatted caption content
     * @return string Plain text transcript
     */
    private function parseTTMLToText(string $ttmlContent): string
    {
        try {
            $xml = simplexml_load_string($ttmlContent);
            if ($xml === false) {
                return '';
            }

            $textParts = [];

            // Register namespaces
            $namespaces = $xml->getNamespaces(true);

            // Try to find body/div/p structure (standard TTML)
            $body = $xml->body ?? null;
            if ($body) {
                foreach ($body->children() as $div) {
                    foreach ($div->children() as $p) {
                        $text = strip_tags($p->asXML());
                        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
                        $text = trim($text);
                        if (! empty($text)) {
                            $textParts[] = $text;
                        }
                    }
                }
            }

            // Fallback: extract all text nodes
            if (empty($textParts)) {
                $textParts[] = strip_tags($ttmlContent);
            }

            return implode(' ', $textParts);
        } catch (Exception $e) {
            Log::warning('TTML parsing failed', ['error' => $e->getMessage()]);

            return strip_tags($ttmlContent);
        }
    }

    /**
     * Parse TTML to structured transcript array
     *
     * @param  string  $ttmlContent  TTML/XML formatted caption content
     * @return array Array of transcript entries with text, start, and duration
     */
    private function parseTTMLToArray(string $ttmlContent): array
    {
        try {
            $xml = simplexml_load_string($ttmlContent);
            if ($xml === false) {
                return [];
            }

            $transcript = [];
            $body = $xml->body ?? null;

            if ($body) {
                foreach ($body->children() as $div) {
                    foreach ($div->children() as $p) {
                        $attrs = $p->attributes();
                        $begin = (string) ($attrs['begin'] ?? '0');
                        $end = (string) ($attrs['end'] ?? '0');

                        $startSeconds = $this->parseTTMLTime($begin);
                        $endSeconds = $this->parseTTMLTime($end);

                        $text = strip_tags($p->asXML());
                        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
                        $text = trim($text);

                        if (! empty($text)) {
                            $transcript[] = [
                                'text' => $text,
                                'start' => $startSeconds,
                                'duration' => max(0, $endSeconds - $startSeconds),
                            ];
                        }
                    }
                }
            }

            return $transcript;
        } catch (Exception $e) {
            Log::warning('TTML array parsing failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Parse TTML time format to seconds
     *
     * Handles formats like: "00:00:01.000", "1.5s", "1500ms"
     *
     * @param  string  $time  TTML time string
     * @return float Time in seconds
     */
    private function parseTTMLTime(string $time): float
    {
        // Handle HH:MM:SS.mmm format
        if (preg_match('/^(\d+):(\d+):(\d+)(?:\.(\d+))?$/', $time, $matches)) {
            $hours = (int) $matches[1];
            $minutes = (int) $matches[2];
            $seconds = (int) $matches[3];
            $ms = isset($matches[4]) ? (int) str_pad($matches[4], 3, '0') / 1000 : 0;

            return ($hours * 3600) + ($minutes * 60) + $seconds + $ms;
        }

        // Handle MM:SS.mmm format
        if (preg_match('/^(\d+):(\d+)(?:\.(\d+))?$/', $time, $matches)) {
            $minutes = (int) $matches[1];
            $seconds = (int) $matches[2];
            $ms = isset($matches[3]) ? (int) str_pad($matches[3], 3, '0') / 1000 : 0;

            return ($minutes * 60) + $seconds + $ms;
        }

        // Handle Xs format (seconds)
        if (preg_match('/^([\d.]+)s$/i', $time, $matches)) {
            return (float) $matches[1];
        }

        // Handle Xms format (milliseconds)
        if (preg_match('/^(\d+)ms$/i', $time, $matches)) {
            return (int) $matches[1] / 1000;
        }

        return 0;
    }

    /**
     * Get Piped instances dynamically from official API
     *
     * Fetches current healthy instances from piped-instances.kavin.rocks
     * Caches results for configured TTL (default 6 hours).
     *
     * @return array List of Piped API URLs sorted by uptime
     */
    private function getPipedInstances(): array
    {
        $cacheKey = 'piped_instances_list';
        $cacheTtl = (int) ($this->resolveRuntimeEnvValue('PIPED_CACHE_TTL') ?? 21600); // 6 hours default
        $minUptime = (float) ($this->resolveRuntimeEnvValue('PIPED_MIN_UPTIME') ?? 90.0);

        // Check cache first
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::debug('Using cached Piped instances', [
                'count' => count($cached),
                'primary' => $cached[0] ?? 'none',
            ]);

            return $cached;
        }

        // Fetch from official API
        try {
            $response = Http::connectTimeout(5)->timeout(10)->get('https://piped-instances.kavin.rocks/');

            if (! $response->successful()) {
                throw new Exception('API returned status '.$response->status());
            }

            $data = $response->json();
            $instances = [];

            foreach ($data as $item) {
                $apiUrl = $item['api_url'] ?? '';
                $uptime = $item['uptime_24h'] ?? 0;

                // Filter by uptime and valid API URL
                if (! empty($apiUrl) && $uptime >= $minUptime) {
                    $instances[] = [
                        'api_url' => rtrim($apiUrl, '/'),
                        'uptime' => $uptime,
                        'name' => $item['name'] ?? 'unknown',
                    ];
                }
            }

            // Sort by uptime descending
            usort($instances, fn ($a, $b) => $b['uptime'] <=> $a['uptime']);

            // Extract just the API URLs
            $urls = array_map(fn ($i) => $i['api_url'], $instances);

            if (! empty($urls)) {
                Cache::put($cacheKey, $urls, $cacheTtl);

                Log::info('Fetched Piped instances from API', [
                    'count' => count($urls),
                    'primary' => $urls[0] ?? 'none',
                    'cache_ttl' => $cacheTtl,
                ]);

                return $urls;
            }

        } catch (Exception $e) {
            Log::warning('Failed to fetch Piped instances from API', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback instances
        $fallback = $this->resolveRuntimeEnvValue('PIPED_FALLBACK_INSTANCES');
        if ($fallback) {
            return array_map('trim', explode(',', $fallback));
        }

        Log::warning('No Piped instances configured, using hardcoded defaults');

        return [
            'https://api.piped.private.coffee',  // Working as of Dec 2025
            'https://pipedapi.adminforge.de',     // Alternative instance
        ];
    }

    /**
     * Get transcript using mrmysql/youtube-transcript PHP library
     *
     * This is a fallback method when Invidious and Piped APIs fail.
     * Uses the mrmysql/youtube-transcript library which directly
     * accesses YouTube's internal caption API.
     *
     * Features:
     * - Built-in rate limit detection (TooManyRequestsException)
     * - Multi-language support with translation
     * - Auto-generated caption support
     *
     * @param  string  $videoId  YouTube video ID
     * @param  string  $language  Preferred language code (e.g., 'en')
     * @return array Transcript data with success status
     */
    public function getTranscriptViaPhpLib(string $videoId, string $language = 'en'): array
    {
        try {
            Log::debug('Fetching transcript via PHP library', [
                'video_id' => $videoId,
                'language' => $language,
            ]);

            // Create PSR-18/17 compliant HTTP client
            $httpClient = new GuzzleClient([
                'timeout' => 15,
                'connect_timeout' => 10,
            ]);
            $httpFactory = new HttpFactory;

            // Create the transcript fetcher
            $fetcher = new TranscriptListFetcher($httpClient, $httpFactory, $httpFactory);

            // Fetch transcript list
            $transcriptList = $fetcher->fetch($videoId);

            // Get available languages
            $availableLanguages = $transcriptList->getAvailableLanguageCodes();

            if (empty($availableLanguages)) {
                Log::warning('PHP lib: No transcripts available', [
                    'video_id' => $videoId,
                ]);

                return [
                    'success' => false,
                    'video_id' => $videoId,
                    'error' => 'No transcripts available',
                    'error_type' => 'NoTranscriptsAvailable',
                ];
            }

            $actualLanguage = $this->findRequestedLanguageVariant($availableLanguages, $language);
            if ($actualLanguage === null) {
                Log::warning('PHP lib: Requested transcript language not available', [
                    'video_id' => $videoId,
                    'language' => $language,
                    'available_languages' => $availableLanguages,
                ]);

                return [
                    'success' => false,
                    'video_id' => $videoId,
                    'error' => 'No transcript found for requested language',
                    'error_type' => 'NoTranscriptForRequestedLanguage',
                    'requested_language' => $language,
                    'available_languages' => $availableLanguages,
                    'method' => 'phplib',
                ];
            }
            $selectedLang = [$actualLanguage];

            // Find and fetch the transcript
            $transcript = $transcriptList->findTranscript($selectedLang);
            $transcriptData = $transcript->fetch();

            // Build the transcript text from segments
            // Note: Library returns arrays with keys: text, start, duration
            $segments = [];
            $fullText = '';
            foreach ($transcriptData as $segment) {
                // Decode HTML entities (library returns encoded text like &#39; for ')
                $text = html_entity_decode($segment['text'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $segments[] = [
                    'text' => $text,
                    'start' => $segment['start'] ?? 0,
                    'duration' => $segment['duration'] ?? 0,
                ];
                $fullText .= $text.' ';
            }

            $fullText = trim($fullText);
            $wordCount = str_word_count($fullText);

            // Determine caption type (manual vs auto-generated)
            $captionType = $transcript->isGenerated() ? 'auto' : 'manual';

            Log::info('PHP lib transcript fetched successfully', [
                'video_id' => $videoId,
                'language' => $actualLanguage,
                'word_count' => $wordCount,
                'segment_count' => count($segments),
                'caption_type' => $captionType,
            ]);

            return [
                'success' => true,
                'video_id' => $videoId,
                'language' => $actualLanguage,
                'full_text' => $fullText,
                'transcript' => $segments,
                'word_count' => $wordCount,
                'caption_type' => $captionType,
                'method' => 'phplib',
            ];

        } catch (TooManyRequestsException $e) {
            Log::error('PHP lib: YouTube rate limit (TooManyRequests)', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'video_id' => $videoId,
                'error' => 'YouTube is rate limiting requests. '.$e->getMessage(),
                'error_type' => 'TooManyRequests',
            ];

        } catch (TranscriptsDisabledException $e) {
            Log::warning('PHP lib: Transcripts disabled for video', [
                'video_id' => $videoId,
            ]);

            return [
                'success' => false,
                'video_id' => $videoId,
                'error' => 'Transcripts are disabled for this video',
                'error_type' => 'TranscriptsDisabled',
            ];

        } catch (NoTranscriptFoundException $e) {
            Log::warning('PHP lib: No transcript found for language', [
                'video_id' => $videoId,
                'language' => $language,
            ]);

            return [
                'success' => false,
                'video_id' => $videoId,
                'error' => 'No transcript found for requested language',
                'error_type' => 'NoTranscriptFound',
            ];

        } catch (Exception $e) {
            Log::error('PHP lib transcript exception', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'video_id' => $videoId,
                'error' => $e->getMessage(),
                'error_type' => 'PhpLibError',
            ];
        }
    }

    /**
     * Get Invidious instances dynamically from official API
     *
     * Fetches current healthy instances from api.invidious.io/instances.json
     * Caches results for configured TTL (default 6 hours).
     * Falls back to INVIDIOUS_FALLBACK_INSTANCES from .env if API unreachable.
     *
     * @return array List of Invidious instance URLs sorted by health
     */
    private function getInvidiousInstances(): array
    {
        $cacheKey = 'invidious_instances_list';
        $cacheTtl = (int) ($this->resolveRuntimeEnvValue('INVIDIOUS_CACHE_TTL') ?? 21600); // 6 hours default
        $minHealth = (int) ($this->resolveRuntimeEnvValue('INVIDIOUS_MIN_HEALTH') ?? 90);

        // Check cache first
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::debug('Using cached Invidious instances', [
                'count' => count($cached),
                'primary' => $cached[0] ?? 'none',
            ]);

            return $cached;
        }

        // Fetch from official API
        try {
            $instances = $this->fetchInvidiousInstancesFromApi($minHealth);

            if (! empty($instances)) {
                // Cache the result
                Cache::put($cacheKey, $instances, $cacheTtl);

                Log::info('Fetched Invidious instances from API', [
                    'count' => count($instances),
                    'primary' => $instances[0] ?? 'none',
                    'cache_ttl' => $cacheTtl,
                ]);

                return $instances;
            }
        } catch (Exception $e) {
            Log::warning('Failed to fetch Invidious instances from API', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback to .env configuration
        return $this->getFallbackInvidiousInstances();
    }

    /**
     * Fetch Invidious instances from official API
     *
     * @param  int  $minHealth  Minimum health percentage (0-100)
     * @return array List of instance URLs sorted by health descending
     */
    private function fetchInvidiousInstancesFromApi(int $minHealth = 90): array
    {
        $response = Http::connectTimeout(5)->timeout(10)->get('https://api.invidious.io/instances.json');

        if (! $response->successful()) {
            throw new Exception('API returned status '.$response->status());
        }

        $data = $response->json();
        $instances = [];

        foreach ($data as $item) {
            // Each item is [name, details]
            if (! is_array($item) || count($item) < 2) {
                continue;
            }

            [$name, $details] = $item;

            // Filter criteria:
            // - Must be HTTPS (type = 'https')
            // - Must meet minimum health threshold
            // Note: 'api' field means public API access, but captions work regardless
            $type = $details['type'] ?? '';
            $uri = $details['uri'] ?? '';
            $health = $details['monitor']['uptime'] ?? 0;

            if ($type === 'https' && $health >= $minHealth && ! empty($uri)) {
                $instances[] = [
                    'uri' => rtrim($uri, '/'),
                    'health' => $health,
                    'name' => $name,
                ];
            }
        }

        // Sort by health descending (best first)
        usort($instances, fn ($a, $b) => $b['health'] <=> $a['health']);

        // Extract just the URIs
        return array_map(fn ($i) => $i['uri'], $instances);
    }

    /**
     * Get fallback Invidious instances from .env
     *
     * @return array List of fallback instance URLs
     */
    private function getFallbackInvidiousInstances(): array
    {
        $envInstances = $this->resolveRuntimeEnvValue('INVIDIOUS_FALLBACK_INSTANCES');

        if ($envInstances) {
            $instances = array_map(function ($instance) {
                $instance = trim($instance);
                if (! str_starts_with($instance, 'http')) {
                    $instance = 'https://'.$instance;
                }

                return $instance;
            }, explode(',', $envInstances));

            Log::info('Using fallback Invidious instances from .env', [
                'count' => count($instances),
            ]);

            return $instances;
        }

        // Ultimate fallback - hardcoded known-good instances
        Log::warning('No Invidious instances configured, using hardcoded defaults');

        return [
            'https://inv.nadeko.net',
            'https://yewtu.be',
        ];
    }

    /**
     * Clear API cache
     *
     * @param  string|null  $endpoint  Specific endpoint to clear (null for all)
     */
    public function clearCache(?string $endpoint = null): void
    {
        if ($endpoint) {
            // Clear specific endpoint cache
            // Note: This is a simplified version, in production use Redis SCAN
            Cache::forget('youtube_api:'.$endpoint);
        } else {
            // Clear all YouTube API cache
            Cache::flush();
        }

        Log::info('YouTube API cache cleared', ['endpoint' => $endpoint ?? 'all']);
    }

    /**
     * Get transcript using yt-dlp command line tool
     *
     * Most reliable method for extracting YouTube transcripts.
     * Uses cookies from Firefox browser if available.
     * Requires yt-dlp to be installed: pip install yt-dlp
     *
     * @param  string  $videoId  YouTube video ID
     * @param  string  $language  Preferred language code (e.g., 'en')
     * @return array Transcript data with success status
     */
    public function getTranscriptViaYtDlp(string $videoId, string $language = 'en'): array
    {
        $outputPath = null;

        try {
            Log::debug('Fetching transcript via yt-dlp', [
                'video_id' => $videoId,
                'language' => $language,
            ]);

            $homeDir = $this->resolveRuntimeEnvValue('HOME') ?? '';
            $ytdlpPath = $this->resolveRuntimeEnvValue('YTDLP_PATH') ?? ($homeDir !== '' ? $homeDir.'/.local/bin/yt-dlp' : 'yt-dlp');
            $denoPath = $this->resolveDenoBinPath($homeDir);
            $runtimePath = $this->resolveRuntimeEnvValue('PATH') ?? '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

            if (! file_exists($ytdlpPath) || ! is_executable($ytdlpPath)) {
                return [
                    'success' => false,
                    'video_id' => $videoId,
                    'error' => 'yt-dlp not executable at: '.$ytdlpPath,
                    'error_type' => 'YtDlpNotFound',
                ];
            }

            // Create temp directory for output
            $tempDir = sys_get_temp_dir().'/yt_transcripts';
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $outputPath = $tempDir.'/'.$videoId;
            $videoUrl = "https://www.youtube.com/watch?v={$videoId}";

            // Build yt-dlp command with optimized settings
            Log::debug('yt-dlp transcript command', ['video_id' => $videoId, 'language' => $language]);

            $process = Process::timeout(180)
                ->path(base_path())
                ->env([
                    'PATH' => implode(':', array_filter([$denoPath, dirname($ytdlpPath), $runtimePath])),
                ])
                ->run([
                    $ytdlpPath,
                    '--remote-components', 'ejs:github',
                    '--cookies-from-browser', 'firefox',
                    '--write-auto-sub',
                    '--sub-lang', $language,
                    '--skip-download',
                    '--sub-format', 'json3',
                    '-o', $outputPath,
                    $videoUrl,
                ]);

            $output = $process->output().$process->errorOutput();
            $subtitleFile = $outputPath.'.'.$language.'.json3';

            // Check for alternative file names
            if (! file_exists($subtitleFile)) {
                // Try with country variant
                $files = glob($outputPath.'.'.$language.'*.json3');
                if (! empty($files)) {
                    $subtitleFile = $files[0];
                }
            }

            if (! file_exists($subtitleFile)) {
                // Check for rate limit error
                if (strpos($output, '429') !== false || strpos($output, 'Too Many Requests') !== false) {
                    return [
                        'success' => false,
                        'video_id' => $videoId,
                        'error' => 'YouTube rate limit (429 Too Many Requests)',
                        'error_type' => 'TooManyRequests',
                        'output' => $output,
                    ];
                }

                // Check for no subtitles
                if (strpos($output, 'no subtitles') !== false || strpos($output, 'There are no subtitles') !== false) {
                    return [
                        'success' => false,
                        'video_id' => $videoId,
                        'error' => 'No subtitles available for this video',
                        'error_type' => 'NoSubtitlesAvailable',
                        'output' => $output,
                    ];
                }

                return [
                    'success' => false,
                    'video_id' => $videoId,
                    'error' => 'yt-dlp failed to download subtitles',
                    'error_type' => 'YtDlpError',
                    'output' => $output,
                ];
            }

            // Parse JSON3 format
            $jsonContent = file_get_contents($subtitleFile);
            $data = json_decode($jsonContent, true);

            if (! $data || ! isset($data['events'])) {
                return [
                    'success' => false,
                    'video_id' => $videoId,
                    'error' => 'Failed to parse yt-dlp subtitle output',
                    'error_type' => 'ParseError',
                ];
            }

            // Extract text from JSON3 format
            $segments = [];
            $fullText = '';

            foreach ($data['events'] as $event) {
                if (! isset($event['segs'])) {
                    continue;
                }

                $text = '';
                foreach ($event['segs'] as $seg) {
                    $text .= $seg['utf8'] ?? '';
                }

                $text = trim($text);
                if (empty($text) || $text === "\n") {
                    continue;
                }

                $start = ($event['tStartMs'] ?? 0) / 1000;
                $duration = (($event['dDurationMs'] ?? 0)) / 1000;

                $segments[] = [
                    'text' => $text,
                    'start' => $start,
                    'duration' => $duration,
                ];
                $fullText .= $text.' ';
            }

            $fullText = trim(preg_replace('/\s+/', ' ', $fullText));
            $wordCount = str_word_count($fullText);

            Log::info('yt-dlp transcript fetched successfully', [
                'video_id' => $videoId,
                'language' => $language,
                'word_count' => $wordCount,
                'segment_count' => count($segments),
            ]);

            return [
                'success' => true,
                'video_id' => $videoId,
                'language' => $language,
                'full_text' => $fullText,
                'transcript' => $segments,
                'word_count' => $wordCount,
                'caption_type' => 'auto', // yt-dlp primarily gets auto captions
                'method' => 'ytdlp',
            ];

        } catch (Exception $e) {
            Log::error('yt-dlp transcript exception', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'video_id' => $videoId,
                'error' => $e->getMessage(),
                'error_type' => 'YtDlpException',
            ];
        } finally {
            $this->cleanupYtDlpTranscriptArtifacts($outputPath);
        }
    }

    private function cleanupYtDlpTranscriptArtifacts(?string $outputPath): void
    {
        if (! $outputPath) {
            return;
        }

        foreach (glob($outputPath.'.*') ?: [] as $artifact) {
            @unlink($artifact);
        }
    }

    /**
     * Get transcript via direct YouTube timedtext API
     *
     * Fetches the video page, extracts the timedtext URL from player response,
     * and downloads the transcript directly. This bypasses some rate limiting
     * by using realistic browser headers.
     *
     * @param  string  $videoId  YouTube video ID
     * @param  string  $language  Preferred language code
     * @return array Transcript data with success status
     */
    public function getTranscriptViaDirect(string $videoId, string $language = 'en'): array
    {
        try {
            Log::debug('Fetching transcript via direct timedtext API', [
                'video_id' => $videoId,
                'language' => $language,
            ]);

            // Realistic browser headers
            $headers = [
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Sec-Fetch-User' => '?1',
                'Cache-Control' => 'max-age=0',
            ];

            // Fetch video page
            $videoUrl = "https://www.youtube.com/watch?v={$videoId}";
            $response = Http::withHeaders($headers)
                ->connectTimeout(5)
                ->timeout(30)
                ->get($videoUrl);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'video_id' => $videoId,
                    'error' => 'Failed to fetch video page: HTTP '.$response->status(),
                    'error_type' => 'HttpError',
                ];
            }

            $html = $response->body();

            // Extract ytInitialPlayerResponse from page
            if (! preg_match('/ytInitialPlayerResponse\s*=\s*({.+?});/', $html, $matches)) {
                return [
                    'success' => false,
                    'video_id' => $videoId,
                    'error' => 'Could not find player response in page',
                    'error_type' => 'ParseError',
                ];
            }

            $playerResponse = json_decode($matches[1], true);
            if (! $playerResponse) {
                return [
                    'success' => false,
                    'video_id' => $videoId,
                    'error' => 'Failed to parse player response JSON',
                    'error_type' => 'JsonError',
                ];
            }

            // Extract caption tracks
            $captions = $playerResponse['captions']['playerCaptionsTracklistRenderer']['captionTracks'] ?? [];

            if (empty($captions)) {
                return [
                    'success' => false,
                    'video_id' => $videoId,
                    'error' => 'No caption tracks found',
                    'error_type' => 'NoCaptions',
                ];
            }

            // Find matching language track
            $selectedTrack = null;
            foreach ($captions as $track) {
                $langCode = $track['languageCode'] ?? '';
                if ($langCode === $language || str_starts_with($langCode, $language)) {
                    $selectedTrack = $track;
                    break;
                }
            }

            if (! $selectedTrack) {
                return [
                    'success' => false,
                    'video_id' => $videoId,
                    'error' => 'No transcript found for requested language',
                    'error_type' => 'NoTranscriptForRequestedLanguage',
                    'requested_language' => $language,
                    'available_languages' => array_values(array_unique(array_filter(array_map(
                        fn ($track) => $track['languageCode'] ?? null,
                        $captions
                    )))),
                    'method' => 'direct',
                ];
            }

            $baseUrl = $selectedTrack['baseUrl'] ?? '';
            if (empty($baseUrl)) {
                return [
                    'success' => false,
                    'video_id' => $videoId,
                    'error' => 'No baseUrl in caption track',
                    'error_type' => 'NoBaseUrl',
                ];
            }

            // Modify URL to get JSON3 format
            $captionUrl = $baseUrl.'&fmt=json3';

            // Small delay before fetching captions
            usleep(500000); // 0.5 second

            // Fetch captions
            $captionResponse = Http::withHeaders($headers)
                ->connectTimeout(5)
                ->timeout(30)
                ->get($captionUrl);

            if (! $captionResponse->successful()) {
                $status = $captionResponse->status();
                $errorType = $status === 429 ? 'TooManyRequests' : 'HttpError';

                return [
                    'success' => false,
                    'video_id' => $videoId,
                    'error' => 'Failed to fetch captions: HTTP '.$status,
                    'error_type' => $errorType,
                ];
            }

            // FAULT TOLERANCE: Check for empty response (YouTube often returns 200 with empty body)
            $responseBody = $captionResponse->body();
            if (empty($responseBody) || strlen(trim($responseBody)) < 20) {
                Log::debug('Direct timedtext returned empty body', [
                    'video_id' => $videoId,
                    'body_length' => strlen($responseBody),
                ]);

                return [
                    'success' => false,
                    'video_id' => $videoId,
                    'error' => 'YouTube returned empty caption response (likely rate limited or changed API)',
                    'error_type' => 'EmptyResponse',
                ];
            }

            $captionData = $captionResponse->json();

            if (! $captionData || ! isset($captionData['events'])) {
                Log::debug('Direct timedtext invalid JSON format', [
                    'video_id' => $videoId,
                    'body_preview' => substr($responseBody, 0, 200),
                ]);

                return [
                    'success' => false,
                    'video_id' => $videoId,
                    'error' => 'Invalid caption data format',
                    'error_type' => 'ParseError',
                ];
            }

            // Parse events to extract text
            $segments = [];
            $fullText = '';

            foreach ($captionData['events'] as $event) {
                if (! isset($event['segs'])) {
                    continue;
                }

                $text = '';
                foreach ($event['segs'] as $seg) {
                    $text .= $seg['utf8'] ?? '';
                }

                $text = trim($text);
                if (empty($text) || $text === "\n") {
                    continue;
                }

                $start = ($event['tStartMs'] ?? 0) / 1000;
                $duration = (($event['dDurationMs'] ?? 0)) / 1000;

                $segments[] = [
                    'text' => $text,
                    'start' => $start,
                    'duration' => $duration,
                ];
                $fullText .= $text.' ';
            }

            $fullText = trim(preg_replace('/\s+/', ' ', $fullText));
            $wordCount = str_word_count($fullText);

            $captionType = (stripos($selectedTrack['name']['simpleText'] ?? '', 'auto') !== false)
                ? 'auto-generated'
                : 'manual';

            Log::info('Direct timedtext transcript fetched successfully', [
                'video_id' => $videoId,
                'language' => $selectedTrack['languageCode'] ?? $language,
                'word_count' => $wordCount,
                'segment_count' => count($segments),
                'caption_type' => $captionType,
            ]);

            return [
                'success' => true,
                'video_id' => $videoId,
                'language' => $selectedTrack['languageCode'] ?? $language,
                'full_text' => $fullText,
                'transcript' => $segments,
                'word_count' => $wordCount,
                'caption_type' => $captionType,
                'method' => 'direct',
            ];

        } catch (Exception $e) {
            Log::error('Direct timedtext transcript exception', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'video_id' => $videoId,
                'error' => $e->getMessage(),
                'error_type' => 'DirectError',
            ];
        }
    }

    /**
     * Get transcript via Puppeteer browser automation
     *
     * Ultimate fallback that uses a real browser to extract transcripts.
     * This bypasses most rate limiting by appearing as a real user.
     * Requires Puppeteer MCP server to be available.
     *
     * @param  string  $videoId  YouTube video ID
     * @param  string  $language  Preferred language code
     * @return array Transcript data with success status
     */
    public function getTranscriptViaPuppeteer(string $videoId, string $language = 'en'): array
    {
        // This method is called externally via the Puppeteer MCP
        // Return a marker that the caller should use Puppeteer MCP
        return [
            'success' => false,
            'video_id' => $videoId,
            'error' => 'Use Puppeteer MCP directly for browser-based extraction',
            'error_type' => 'UsePuppeteerMCP',
            'use_puppeteer' => true,
        ];
    }

    /**
     * Get transcript with exponential backoff and multiple fallbacks
     *
     * Enhanced version that tries multiple methods with proper delays:
     * 1. Direct timedtext API (fastest, may hit rate limits)
     * 2. Invidious (privacy proxy)
     * 3. Piped (another privacy proxy)
     * 4. PHP library (direct YouTube access)
     * 5. yt-dlp (command line tool)
     *
     * @param  string  $videoId  YouTube video ID
     * @param  string  $language  Preferred language code
     * @param  bool  $useCache  Whether to use cache
     * @param  int  $attempt  Current retry attempt (for exponential backoff)
     * @return array Transcript data with success status
     */
    public function getTranscriptWithBackoff(string $videoId, string $language = 'en', bool $useCache = true, int $attempt = 0): array
    {
        $validatedLanguage = $this->transcriptLanguagePolicy()->validateRequestedLanguage($language);
        if (! ($validatedLanguage['success'] ?? false)) {
            return array_merge($validatedLanguage, [
                'success' => false,
                'video_id' => $videoId,
            ]);
        }

        $language = $validatedLanguage['language'];

        // Check cache first
        $cacheKey = "youtube_transcript_v2:{$videoId}:{$language}";
        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if ($cached && ($cached['word_count'] ?? 0) > 0) {
                $cached = $this->transcriptLanguagePolicy()->guardResult($cached, $language);
                if (! ($cached['success'] ?? false)) {
                    Cache::forget($cacheKey);
                } else {
                    Log::info('Transcript cache hit', ['video_id' => $videoId]);

                    return $cached;
                }
            }
        }

        // Calculate delay with exponential backoff
        $baseDelay = (int) config('youtube.transcript.base_delay', 10);
        $maxDelay = (int) config('youtube.transcript.max_delay', 300);
        $delay = min($baseDelay * pow(2, $attempt), $maxDelay);

        if ($attempt > 0) {
            Log::info('Exponential backoff delay', [
                'video_id' => $videoId,
                'attempt' => $attempt,
                'delay_seconds' => $delay,
            ]);
            sleep($delay);
        }

        // Base methods with their default priority (lower = higher priority)
        $baseMethods = [
            'phplib' => ['fn' => fn () => $this->getTranscriptViaPhpLib($videoId, $language), 'priority' => 1],
            'piped' => ['fn' => fn () => $this->getTranscriptViaPiped($videoId, $language, false), 'priority' => 2],
            'direct' => ['fn' => fn () => $this->getTranscriptViaDirect($videoId, $language), 'priority' => 3],
            'invidious' => ['fn' => fn () => $this->getTranscriptViaInvidious($videoId, $language, false), 'priority' => 4],
            'ytdlp' => ['fn' => fn () => $this->getTranscriptViaYtDlp($videoId, $language), 'priority' => 5],
            'whisper' => ['fn' => fn () => $this->getTranscriptViaWhisper($videoId, $language), 'priority' => 6],
        ];

        // Get dynamically ordered methods based on health tracking
        $methods = $this->getHealthOrderedMethods($baseMethods);

        $lastResult = null;
        $attempts = [];

        foreach ($methods as $methodName => $methodData) {
            // Skip methods that are in cooldown
            if ($this->isMethodInCooldown($methodName)) {
                Log::debug("Skipping method {$methodName} - in cooldown", ['video_id' => $videoId]);

                continue;
            }

            try {
                Log::debug("Trying transcript method: {$methodName}", ['video_id' => $videoId]);

                $result = $methodData['fn']();
                $result = $this->transcriptLanguagePolicy()->guardResult($result, $language);

                $attempts[] = [
                    'method' => $methodName,
                    'success' => $result['success'],
                    'word_count' => $result['word_count'] ?? 0,
                    'error_type' => $result['error_type'] ?? null,
                ];

                // Success with content
                if ($result['success'] && ($result['word_count'] ?? 0) > 0) {
                    // Record success for health tracking
                    $this->recordMethodHealth($methodName, true);

                    // Cache successful result
                    if ($useCache) {
                        Cache::put($cacheKey, $result, now()->addHours(48));
                    }

                    Log::info('Transcript fetched successfully', [
                        'video_id' => $videoId,
                        'method' => $methodName,
                        'word_count' => $result['word_count'],
                        'attempts' => $attempts,
                    ]);

                    return $result;
                }

                // Record failure for health tracking
                $this->recordMethodHealth($methodName, false, $result['error_type'] ?? 'Unknown');

                // Rate limited on direct methods - continue with proxy methods
                if (($result['error_type'] ?? '') === 'TooManyRequests') {
                    Log::warning('Rate limited on direct method, trying proxy methods', [
                        'video_id' => $videoId,
                        'method' => $methodName,
                    ]);
                    $lastResult = $result;

                    // Continue to try proxy methods (invidious, piped) which use different IPs
                    continue;
                }

                $lastResult = $result;

                // Small delay between methods
                usleep(1000000); // 1 second

            } catch (Exception $e) {
                // Record exception as failure
                $this->recordMethodHealth($methodName, false, 'Exception');

                Log::warning("Method {$methodName} threw exception", [
                    'video_id' => $videoId,
                    'error' => $e->getMessage(),
                ]);
                $attempts[] = [
                    'method' => $methodName,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // All methods failed
        Log::error('All transcript methods failed', [
            'video_id' => $videoId,
            'attempts' => $attempts,
        ]);

        return $lastResult ?? [
            'success' => false,
            'video_id' => $videoId,
            'error' => 'All transcript methods failed',
            'error_type' => 'AllMethodsFailed',
            'attempts' => $attempts,
        ];
    }

    /**
     * Get methods ordered by health score (success rate)
     * Methods with better recent success rates get higher priority
     *
     * @param  array  $baseMethods  Base methods with default priorities
     * @return array Methods ordered by health-adjusted priority
     */
    private function getHealthOrderedMethods(array $baseMethods): array
    {
        $methodScores = [];

        foreach ($baseMethods as $methodName => $methodData) {
            $health = $this->getMethodHealth($methodName);

            // Calculate health score: success_rate * 100, default to 50 if no data
            $successRate = $health['success_rate'] ?? 0.5;
            $totalCalls = $health['total_calls'] ?? 0;

            // If method has been called, use actual success rate
            // Weight by number of calls (more calls = more confidence)
            $confidence = min($totalCalls / 10, 1.0); // Full confidence after 10 calls
            $healthScore = ($successRate * $confidence) + (0.5 * (1 - $confidence));

            // Adjust base priority by health score
            // Lower adjusted priority = higher in order
            // A healthy method (score=1.0) keeps its priority
            // An unhealthy method (score=0.0) gets demoted by 10 positions
            $basePriority = $methodData['priority'];
            $adjustedPriority = $basePriority + ((1 - $healthScore) * 10);

            $methodScores[$methodName] = [
                'fn' => $methodData['fn'],
                'priority' => $methodData['priority'],
                'adjusted_priority' => $adjustedPriority,
                'health_score' => $healthScore,
                'success_rate' => $successRate,
                'total_calls' => $totalCalls,
            ];
        }

        // Sort by adjusted priority (lower = higher priority)
        uasort($methodScores, fn ($a, $b) => $a['adjusted_priority'] <=> $b['adjusted_priority']);

        // Log the ordering for debugging
        $orderInfo = array_map(fn ($m) => [
            'adjusted_priority' => round($m['adjusted_priority'], 2),
            'health_score' => round($m['health_score'], 2),
            'success_rate' => round($m['success_rate'], 2),
        ], $methodScores);

        Log::debug('Transcript method order (health-adjusted)', ['order' => $orderInfo]);

        return $methodScores;
    }

    private function transcriptLanguagePolicy(): YouTubeTranscriptLanguagePolicy
    {
        return $this->transcriptLanguagePolicy ??= app(YouTubeTranscriptLanguagePolicy::class);
    }

    private function findRequestedLanguageVariant(array $availableLanguages, string $requestedLanguage): ?string
    {
        foreach ($availableLanguages as $availableLanguage) {
            if (strcasecmp((string) $availableLanguage, $requestedLanguage) === 0) {
                return (string) $availableLanguage;
            }
        }

        $normalizedRequested = $this->transcriptLanguagePolicy()->normalize($requestedLanguage);
        if ($normalizedRequested === null) {
            return null;
        }

        foreach ($availableLanguages as $availableLanguage) {
            $availableLanguage = (string) $availableLanguage;
            $normalizedAvailable = $this->transcriptLanguagePolicy()->normalize($availableLanguage);
            if ($normalizedAvailable === $normalizedRequested) {
                return $availableLanguage;
            }
        }

        return null;
    }

    /**
     * Record success or failure for a transcript method
     * Uses sliding window of last 20 calls
     *
     * @param  string  $method  Method name
     * @param  bool  $success  Whether the call succeeded
     * @param  string|null  $errorType  Error type if failed
     */
    private function recordMethodHealth(string $method, bool $success, ?string $errorType = null): void
    {
        $cacheKey = "youtube_transcript_health:{$method}";
        $health = Cache::get($cacheKey, [
            'successes' => 0,
            'failures' => 0,
            'total_calls' => 0,
            'recent_results' => [], // Sliding window of last 20 results
            'consecutive_failures' => 0,
            'last_failure_time' => null,
            'last_success_time' => null,
            'error_types' => [],
        ]);

        // Update counters
        $health['total_calls']++;

        if ($success) {
            $health['successes']++;
            $health['consecutive_failures'] = 0;
            $health['last_success_time'] = now()->toIso8601String();
        } else {
            $health['failures']++;
            $health['consecutive_failures']++;
            $health['last_failure_time'] = now()->toIso8601String();

            // Track error types
            if ($errorType) {
                $health['error_types'][$errorType] = ($health['error_types'][$errorType] ?? 0) + 1;
            }
        }

        // Sliding window of recent results (last 20)
        $health['recent_results'][] = $success ? 1 : 0;
        if (count($health['recent_results']) > 20) {
            array_shift($health['recent_results']);
        }

        // Calculate success rate from recent results
        $recentCount = count($health['recent_results']);
        $recentSuccesses = array_sum($health['recent_results']);
        $health['success_rate'] = $recentCount > 0 ? $recentSuccesses / $recentCount : 0.5;

        // Check if method should enter cooldown (3+ consecutive failures)
        if ($health['consecutive_failures'] >= 3) {
            $cooldownMinutes = min(60, $health['consecutive_failures'] * 10); // 30min, 40min, 50min, max 60min
            $health['cooldown_until'] = now()->addMinutes($cooldownMinutes)->toIso8601String();

            Log::warning('Transcript method entering cooldown', [
                'method' => $method,
                'consecutive_failures' => $health['consecutive_failures'],
                'cooldown_minutes' => $cooldownMinutes,
                'last_error_type' => $errorType,
            ]);
        }

        // Store health data (TTL: 24 hours to allow recovery)
        Cache::put($cacheKey, $health, now()->addHours(24));

        Log::debug('Recorded method health', [
            'method' => $method,
            'success' => $success,
            'success_rate' => round($health['success_rate'], 2),
            'consecutive_failures' => $health['consecutive_failures'],
            'total_calls' => $health['total_calls'],
        ]);
    }

    /**
     * Get health data for a transcript method
     *
     * @param  string  $method  Method name
     * @return array Health data
     */
    private function getMethodHealth(string $method): array
    {
        $cacheKey = "youtube_transcript_health:{$method}";

        return Cache::get($cacheKey, [
            'successes' => 0,
            'failures' => 0,
            'total_calls' => 0,
            'success_rate' => 0.5, // Default to 50% if no data
            'consecutive_failures' => 0,
            'recent_results' => [],
        ]);
    }

    /**
     * Check if a method is in cooldown due to consecutive failures
     *
     * @param  string  $method  Method name
     * @return bool True if method is in cooldown
     */
    private function isMethodInCooldown(string $method): bool
    {
        $health = $this->getMethodHealth($method);

        if (! isset($health['cooldown_until'])) {
            return false;
        }

        $cooldownUntil = \Carbon\Carbon::parse($health['cooldown_until']);

        if (now()->lt($cooldownUntil)) {
            return true;
        }

        // Cooldown expired - reset consecutive failures to give method another chance
        $this->resetMethodCooldown($method);

        return false;
    }

    /**
     * Reset cooldown for a method (called when cooldown expires)
     *
     * @param  string  $method  Method name
     */
    private function resetMethodCooldown(string $method): void
    {
        $cacheKey = "youtube_transcript_health:{$method}";
        $health = Cache::get($cacheKey);

        if ($health) {
            // Reset consecutive failures but keep history
            $health['consecutive_failures'] = 0;
            unset($health['cooldown_until']);

            Cache::put($cacheKey, $health, now()->addHours(24));

            Log::info('Method cooldown reset', [
                'method' => $method,
                'success_rate' => round($health['success_rate'] ?? 0.5, 2),
            ]);
        }
    }

    /**
     * Get health status for all transcript methods (for debugging/monitoring)
     *
     * @return array Health status for all methods
     */
    public function getTranscriptMethodsHealth(): array
    {
        $methods = ['phplib', 'piped', 'direct', 'invidious', 'ytdlp'];
        $health = [];

        foreach ($methods as $method) {
            $methodHealth = $this->getMethodHealth($method);
            $health[$method] = [
                'success_rate' => round(($methodHealth['success_rate'] ?? 0.5) * 100, 1).'%',
                'total_calls' => $methodHealth['total_calls'] ?? 0,
                'consecutive_failures' => $methodHealth['consecutive_failures'] ?? 0,
                'in_cooldown' => $this->isMethodInCooldown($method),
                'last_success' => $methodHealth['last_success_time'] ?? null,
                'last_failure' => $methodHealth['last_failure_time'] ?? null,
                'top_errors' => array_slice($methodHealth['error_types'] ?? [], 0, 3, true),
            ];
        }

        return $health;
    }

    /**
     * Get transcript via Whisper audio transcription (6th fallback)
     *
     * Downloads audio via yt-dlp, transcribes with Whisper CLI.
     * Only used when all text-based methods fail.
     * Uses GPU lock to prevent concurrent Ollama + Whisper usage.
     */
    public function getTranscriptViaWhisper(string $videoId, string $language = 'en'): array
    {
        $lockKey = 'whisper_gpu_lock';
        $ollamaBusyKey = 'ollama_busy_lock';
        $lockTtl = (int) config('services.whisper.gpu_lock_ttl', 900);

        try {
            // Check GPU availability - can't run if Ollama is busy
            if (Cache::has($ollamaBusyKey)) {
                Log::info('Whisper: Ollama is using GPU, skipping', ['video_id' => $videoId]);

                return [
                    'success' => false,
                    'video_id' => $videoId,
                    'error' => 'GPU busy (Ollama)',
                    'error_type' => 'GpuBusy',
                    'method' => 'whisper',
                ];
            }

            // Try to acquire GPU lock
            if (! Cache::add($lockKey, [
                'video_id' => $videoId,
                'started_at' => time(),
                'pid' => getmypid(),
            ], $lockTtl)) {
                Log::info('Whisper: GPU lock already held', ['video_id' => $videoId]);

                return [
                    'success' => false,
                    'video_id' => $videoId,
                    'error' => 'Whisper GPU lock unavailable',
                    'error_type' => 'GpuBusy',
                    'method' => 'whisper',
                ];
            }

            Log::info('Whisper: Starting audio transcription', ['video_id' => $videoId]);

            // Download audio via yt-dlp
            $audioFile = $this->downloadAudioViaYtDlp($videoId);
            if (! $audioFile) {
                Cache::forget($lockKey);

                return [
                    'success' => false,
                    'video_id' => $videoId,
                    'error' => 'Audio download failed',
                    'error_type' => 'DownloadFailed',
                    'method' => 'whisper',
                ];
            }

            // Transcribe with Whisper
            $transcription = $this->transcribeWithWhisper($audioFile, $language);

            // Cleanup audio file
            if (file_exists($audioFile)) {
                @unlink($audioFile);
            }

            // Release GPU lock
            Cache::forget($lockKey);

            if (empty($transcription)) {
                return [
                    'success' => false,
                    'video_id' => $videoId,
                    'error' => 'Whisper transcription returned empty',
                    'error_type' => 'EmptyTranscription',
                    'method' => 'whisper',
                ];
            }

            $wordCount = str_word_count($transcription);

            return [
                'success' => true,
                'video_id' => $videoId,
                'full_text' => $transcription,
                'word_count' => $wordCount,
                'language' => $language,
                'method' => 'whisper',
                'caption_type' => 'whisper-transcribed',
                'transcript' => $this->textToTranscriptEntries($transcription),
            ];

        } catch (Exception $e) {
            // Always release lock on failure
            Cache::forget($lockKey);

            Log::error('Whisper transcription failed', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'video_id' => $videoId,
                'error' => $e->getMessage(),
                'error_type' => 'WhisperError',
                'method' => 'whisper',
            ];
        }
    }

    /**
     * Download audio from YouTube video via yt-dlp
     */
    private function downloadAudioViaYtDlp(string $videoId): ?string
    {
        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }

        $outputFile = $tempDir.'/whisper_'.$videoId.'_'.uniqid().'.wav';
        $timeout = (int) config('services.whisper.youtube_download_timeout', 120);
        $homeDir = $this->resolveRuntimeEnvValue('HOME') ?? '';
        $runtimePath = $this->resolveRuntimeEnvValue('PATH') ?? '/usr/bin:/bin:/usr/local/bin';
        $ytdlpPath = $this->resolveRuntimeEnvValue('YTDLP_PATH') ?? ($homeDir !== '' ? $homeDir.'/.local/bin/yt-dlp' : 'yt-dlp');
        $denoPath = $this->resolveDenoBinPath($homeDir);

        if (! file_exists($ytdlpPath) || ! is_executable($ytdlpPath)) {
            Log::warning('Whisper: yt-dlp executable not found for audio download', [
                'video_id' => $videoId,
                'path' => $ytdlpPath,
            ]);

            return null;
        }

        $url = "https://www.youtube.com/watch?v={$videoId}";

        $result = \Illuminate\Support\Facades\Process::timeout($timeout)
            ->env([
                'PATH' => implode(':', array_filter([$denoPath, dirname($ytdlpPath), $runtimePath])),
            ])
            ->run([
                $ytdlpPath,
                '-x',
                '--audio-format', 'wav',
                '--postprocessor-args', 'ffmpeg:-ar 16000 -ac 1',
                '--no-playlist',
                '--cookies-from-browser', 'firefox',
                '-o', $outputFile,
                $url,
            ]);

        if (! $result->successful() || ! file_exists($outputFile)) {
            Log::warning('Whisper: Audio download failed', [
                'video_id' => $videoId,
                'exit_code' => $result->exitCode(),
                'error' => substr($result->errorOutput(), -500),
            ]);
            // Clean up partial file
            if (file_exists($outputFile)) {
                @unlink($outputFile);
            }

            return null;
        }

        Log::debug('Whisper: Audio downloaded', [
            'video_id' => $videoId,
            'file_size' => filesize($outputFile),
        ]);

        return $outputFile;
    }

    /**
     * Transcribe audio file using Whisper CLI
     */
    private function transcribeWithWhisper(string $audioFile, string $language = 'en'): ?string
    {
        // Find Whisper executable (reuse AIService pattern)
        $whisperPath = $this->findWhisperPath();
        if (! $whisperPath) {
            Log::error('Whisper: Executable not found');

            return null;
        }

        $outputDir = storage_path('app/temp/whisper_out_'.uniqid());
        @mkdir($outputDir, 0755, true);

        $model = config('services.whisper.model', 'base');
        $timeout = (int) config('services.whisper.timeout', 300);

        $cmd = [
            $whisperPath, $audioFile,
            '--model', $model,
            '--output_dir', $outputDir,
            '--output_format', 'txt',
        ];

        if (! empty($language)) {
            $cmd[] = '--language';
            $cmd[] = $language;
        }

        $result = \Illuminate\Support\Facades\Process::timeout($timeout)->run($cmd);

        $transcription = '';
        if ($result->successful()) {
            $txtFiles = glob($outputDir.'/*.txt');
            if (! empty($txtFiles)) {
                $transcription = trim(file_get_contents($txtFiles[0]));
            }
        } else {
            Log::warning('Whisper: Transcription process failed', [
                'exit_code' => $result->exitCode(),
                'error' => substr($result->errorOutput(), -500),
            ]);
        }

        // Cleanup
        array_map('unlink', glob($outputDir.'/*'));
        @rmdir($outputDir);

        return $transcription ?: null;
    }

    /**
     * Find Whisper executable path
     */
    private function findWhisperPath(): ?string
    {
        $paths = [
            config('services.whisper.path'),
            '/usr/local/bin/whisper',
            '/usr/bin/whisper',
            ($this->resolveRuntimeEnvValue('HOME') ?? '').'/.local/bin/whisper',
        ];

        foreach ($paths as $path) {
            if ($path && file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Try 'which'
        $result = \Illuminate\Support\Facades\Process::timeout(5)->run(['which', 'whisper']);
        if ($result->successful()) {
            $path = trim($result->output());
            if (! empty($path) && file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Convert plain text to transcript entry array format
     */
    private function textToTranscriptEntries(string $text): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $entries = [];
        $offset = 0.0;
        $avgDuration = 3.0; // Estimated seconds per sentence

        foreach ($sentences as $sentence) {
            $entries[] = [
                'text' => trim($sentence),
                'start' => $offset,
                'duration' => $avgDuration,
            ];
            $offset += $avgDuration;
        }

        return $entries;
    }

    private function resolveRuntimeEnvValue(?string $key): ?string
    {
        if (! $key) {
            return null;
        }

        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }

        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function resolveDenoBinPath(string $homeDir = ''): string
    {
        return $this->resolveRuntimeEnvValue('DENO_BIN_DIR')
            ?? $this->resolveRuntimeEnvValue('DENO_PATH')
            ?? ($homeDir !== '' ? $homeDir.'/.deno/bin' : '/usr/local/bin');
    }
}
