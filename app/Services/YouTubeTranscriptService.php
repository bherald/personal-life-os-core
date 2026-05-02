<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * YouTube Transcript Service
 *
 * Fetches transcripts from YouTube videos using the Invidious API.
 * This service works for all public videos, including Watch Later playlists
 * containing other creators' videos, without IP blocking issues.
 *
 * Uses YouTubeApiService::getTranscript() which accesses Invidious public instances
 * (privacy-focused YouTube frontend). Provides caching, multi-instance fallback,
 * and error handling for reliable transcript retrieval.
 *
 * Supports persistent MySQL storage via YouTubeTranscriptStorageService for
 * long-term transcript retention and faster retrieval.
 */
class YouTubeTranscriptService
{
    /**
     * Cache TTL in minutes
     */
    private int $cacheTtl;

    /**
     * Storage service for persistent transcripts
     */
    private ?YouTubeTranscriptStorageService $storageService = null;

    /**
     * Storage configuration
     */
    private array $storageConfig;

    private YouTubeTranscriptLanguagePolicy $languagePolicy;

    public function __construct(
        ?YouTubeTranscriptStorageService $storageService = null,
        ?YouTubeTranscriptLanguagePolicy $languagePolicy = null
    ) {
        $this->cacheTtl = (int) config('youtube.cache_ttl', 60);

        $this->storageConfig = config('youtube.transcript.storage', [
            'enabled' => true,
            'prefer_stored' => true,
            'auto_store' => true,
            'stale_days' => 0,
        ]);

        $this->storageService = $storageService;
        $this->languagePolicy = $languagePolicy ?? app(YouTubeTranscriptLanguagePolicy::class);
        if ($this->storageConfig['enabled'] && ! $this->storageService) {
            $this->storageService = app(YouTubeTranscriptStorageService::class);
        }
    }

    /**
     * Get transcript for a YouTube video
     *
     * @param  string  $videoId  YouTube video ID
     * @param  string  $language  Preferred language code (default: 'en')
     * @param  bool  $useCache  Whether to use cached results
     * @param  bool  $forceFresh  Skip storage and cache, fetch fresh
     * @return array Transcript data with metadata
     *
     * @throws \Exception If script execution fails
     */
    public function getTranscript(string $videoId, string $language = 'en', bool $useCache = true, bool $forceFresh = false): array
    {
        $validatedLanguage = $this->validateRequestedLanguage($videoId, $language);
        if (! ($validatedLanguage['success'] ?? false)) {
            return $validatedLanguage;
        }

        $language = $validatedLanguage['language'];

        Log::info('YouTubeTranscriptService: Fetching transcript', [
            'video_id' => $videoId,
            'language' => $language,
            'use_cache' => $useCache,
            'force_fresh' => $forceFresh,
        ]);

        // Check persistent storage first (if enabled and not forcing fresh)
        if (! $forceFresh && $this->storageConfig['enabled'] && $this->storageConfig['prefer_stored'] && $this->storageService) {
            $stored = $this->getFromStorage($videoId, $language);
            if ($stored !== null) {
                $stored = $this->languagePolicy->guardResult($stored, $language);
                if (! ($stored['success'] ?? false)) {
                    Log::warning('YouTubeTranscriptService: Rejected stored transcript due to language policy', [
                        'video_id' => $videoId,
                        'requested_language' => $language,
                        'actual_language' => $stored['actual_language'] ?? null,
                    ]);
                } else {
                    Log::info('YouTubeTranscriptService: Using stored transcript', [
                        'video_id' => $videoId,
                        'source' => 'mysql_storage',
                    ]);

                    return $stored;
                }
            }
        }

        // Check Redis cache second
        if ($useCache && ! $forceFresh) {
            $cached = $this->getCachedTranscript($videoId, $language);
            if ($cached !== null) {
                $cached = $this->languagePolicy->guardResult($cached, $language);
                if (! ($cached['success'] ?? false)) {
                    $this->clearCache($videoId, $language);
                    Log::warning('YouTubeTranscriptService: Cleared cached transcript due to language policy', [
                        'video_id' => $videoId,
                        'requested_language' => $language,
                        'actual_language' => $cached['actual_language'] ?? null,
                    ]);
                } else {
                    Log::info('YouTubeTranscriptService: Using cached transcript', [
                        'video_id' => $videoId,
                    ]);

                    // Auto-store to persistent storage if not already there
                    if ($this->storageConfig['enabled'] && $this->storageConfig['auto_store'] && $this->storageService) {
                        if (! $this->storageService->exists($videoId, $language)) {
                            $this->storageService->store($videoId, $cached);
                        }
                    }

                    return $cached;
                }
            }
        }

        // Fetch using timedtext API (works for all public videos)
        $result = $this->fetchTranscriptFromApiV3($videoId, $language, $useCache);
        $result = $this->languagePolicy->guardResult($result, $language);

        // Cache successful results
        if ($result['success'] && $useCache) {
            $this->cacheTranscript($videoId, $language, $result);
        }

        // Store to persistent storage
        if ($result['success'] && $this->storageConfig['enabled'] && $this->storageConfig['auto_store'] && $this->storageService) {
            $this->storageService->store($videoId, $result);
        }

        return $result;
    }

    /**
     * Get transcript from persistent storage.
     *
     * @param  string  $videoId  YouTube video ID
     * @param  string  $language  Language code
     * @return array|null Transcript data or null
     */
    private function getFromStorage(string $videoId, string $language): ?array
    {
        if (! $this->storageService) {
            return null;
        }

        $stored = $this->storageService->getAsTranscriptResult($videoId, $language);

        if (! $stored) {
            return null;
        }

        // Check if stale (if stale_days > 0)
        if ($this->storageConfig['stale_days'] > 0) {
            $fetchedAt = strtotime($stored['fetched_at']);
            $staleThreshold = time() - ($this->storageConfig['stale_days'] * 86400);
            if ($fetchedAt < $staleThreshold) {
                Log::debug('YouTubeTranscriptService: Stored transcript is stale', [
                    'video_id' => $videoId,
                    'fetched_at' => $stored['fetched_at'],
                    'stale_days' => $this->storageConfig['stale_days'],
                ]);

                return null;
            }
        }

        return $stored;
    }

    /**
     * Get the storage service instance.
     */
    public function getStorageService(): ?YouTubeTranscriptStorageService
    {
        return $this->storageService;
    }

    /**
     * Fetch transcript using Invidious API
     *
     * Uses Invidious public instances (privacy-focused YouTube frontend).
     * Works for all public videos regardless of ownership, without IP blocking.
     * Automatically falls back across multiple instances for reliability.
     *
     * @param  string  $videoId  YouTube video ID
     * @param  string  $language  Language code
     * @param  bool  $useCache  Whether to use cache
     * @return array Transcript result
     */
    private function fetchTranscriptFromApiV3(string $videoId, string $language, bool $useCache = true): array
    {
        try {
            $youtubeApi = $this->makeApiService();
            $result = $youtubeApi->getTranscript($videoId, $language, $useCache);

            if ($result['success']) {
                Log::info('YouTubeTranscriptService: Fetched via Invidious API', [
                    'video_id' => $videoId,
                    'language' => $result['language'],
                    'caption_type' => $result['caption_type'] ?? 'unknown',
                    'word_count' => $result['word_count'],
                    'instance' => $result['instance'] ?? 'unknown',
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            Log::warning('YouTubeTranscriptService: Invidious API fetch failed', [
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
     * Get cached transcript
     *
     * @param  string  $videoId  YouTube video ID
     * @param  string  $language  Language code
     * @return array|null Cached transcript or null
     */
    private function getCachedTranscript(string $videoId, string $language): ?array
    {
        $cacheKey = $this->getCacheKey($videoId, $language);

        return Cache::get($cacheKey);
    }

    /**
     * Seam for tests to inject a stub API service without touching the network.
     */
    protected function makeApiService(): YouTubeApiService
    {
        return new YouTubeApiService;
    }

    /**
     * Cache transcript result
     *
     * @param  string  $videoId  YouTube video ID
     * @param  string  $language  Language code
     * @param  array  $result  Transcript result
     */
    private function cacheTranscript(string $videoId, string $language, array $result): void
    {
        $cacheKey = $this->getCacheKey($videoId, $language);
        Cache::put($cacheKey, $result, now()->addMinutes($this->cacheTtl));

        Log::debug('YouTubeTranscriptService: Cached transcript', [
            'video_id' => $videoId,
            'cache_key' => $cacheKey,
            'ttl_minutes' => $this->cacheTtl,
        ]);
    }

    /**
     * Generate cache key for transcript
     *
     * @param  string  $videoId  YouTube video ID
     * @param  string  $language  Language code
     * @return string Cache key
     */
    private function getCacheKey(string $videoId, string $language): string
    {
        return "youtube_transcript:{$videoId}:{$language}";
    }

    /**
     * Clear cached transcript
     *
     * @param  string  $videoId  YouTube video ID
     * @param  string|null  $language  Language code (null to clear all languages)
     */
    public function clearCache(string $videoId, ?string $language = null): void
    {
        if ($language !== null) {
            $cacheKey = $this->getCacheKey($videoId, $language);
            Cache::forget($cacheKey);
            Log::info('YouTubeTranscriptService: Cleared cache for video', [
                'video_id' => $videoId,
                'language' => $language,
            ]);
        } else {
            // Clear all language variants for this video via Redis SCAN
            $cachePrefix = config('cache.prefix', config('database.redis.options.prefix', ''));
            $dbPrefix = config('database.redis.options.prefix', '');
            $redis = \Illuminate\Support\Facades\Redis::connection(config('cache.stores.redis.connection', 'cache'));
            $keys = $redis->keys($cachePrefix."youtube_transcript:{$videoId}:*");
            foreach ($keys as $key) {
                $cleanKey = str_starts_with($key, $dbPrefix) ? substr($key, strlen($dbPrefix)) : $key;
                $redis->del($cleanKey);
            }
            Log::info('YouTubeTranscriptService: Cleared all cached variants', [
                'video_id' => $videoId,
                'keys_cleared' => count($keys),
            ]);
        }
    }

    /**
     * Extract video ID from YouTube URL
     *
     * @param  string  $url  YouTube URL
     * @return string|null Video ID or null if invalid
     */
    public static function extractVideoId(string $url): ?string
    {
        // Support various YouTube URL formats
        $patterns = [
            '/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/',
            '/youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/',
            '/youtube\.com\/v\/([a-zA-Z0-9_-]{11})/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }

        // If no pattern matches, assume it's already a video ID
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $url)) {
            return $url;
        }

        return null;
    }

    /**
     * Batch fetch transcripts for multiple videos
     *
     * @param  array  $videoIds  Array of video IDs
     * @param  string  $language  Language code
     * @param  bool  $useCache  Whether to use cache
     * @return array Results keyed by video ID
     */
    public function batchGetTranscripts(array $videoIds, string $language = 'en', bool $useCache = true): array
    {
        $results = [];

        foreach ($videoIds as $videoId) {
            try {
                $results[$videoId] = $this->getTranscript($videoId, $language, $useCache);
            } catch (\Exception $e) {
                Log::error('YouTubeTranscriptService: Batch fetch error', [
                    'video_id' => $videoId,
                    'error' => $e->getMessage(),
                ]);
                $results[$videoId] = [
                    'success' => false,
                    'video_id' => $videoId,
                    'error' => $e->getMessage(),
                    'error_type' => 'Exception',
                ];
            }
        }

        return $results;
    }

    /**
     * Get transcript with exponential backoff and multiple fallback methods
     *
     * Enhanced method that tries multiple sources in order:
     * 0. Persistent MySQL storage (if enabled)
     * 1. Direct timedtext API (fastest)
     * 2. Invidious (privacy proxy)
     * 3. Piped (another privacy proxy)
     * 4. PHP library
     * 5. yt-dlp (command line tool)
     *
     * Uses exponential backoff to avoid rate limiting.
     *
     * @param  string  $videoId  YouTube video ID
     * @param  string  $language  Language code
     * @param  bool  $useCache  Whether to use cache
     * @param  int  $attempt  Current retry attempt (for exponential backoff)
     * @param  bool  $forceFresh  Skip storage and cache, fetch fresh
     * @return array Transcript result
     */
    public function getTranscriptWithBackoff(string $videoId, string $language = 'en', bool $useCache = true, int $attempt = 0, bool $forceFresh = false): array
    {
        $validatedLanguage = $this->validateRequestedLanguage($videoId, $language);
        if (! ($validatedLanguage['success'] ?? false)) {
            return $validatedLanguage;
        }

        $language = $validatedLanguage['language'];

        // Check persistent storage first (if enabled and not forcing fresh)
        if (! $forceFresh && $this->storageConfig['enabled'] && $this->storageConfig['prefer_stored'] && $this->storageService) {
            $stored = $this->getFromStorage($videoId, $language);
            if ($stored !== null) {
                $stored = $this->languagePolicy->guardResult($stored, $language);
                if (! ($stored['success'] ?? false)) {
                    Log::warning('YouTubeTranscriptService: Rejected stored transcript during backoff fetch', [
                        'video_id' => $videoId,
                        'requested_language' => $language,
                        'actual_language' => $stored['actual_language'] ?? null,
                    ]);
                } else {
                    Log::info('YouTubeTranscriptService: Using stored transcript (backoff method)', [
                        'video_id' => $videoId,
                        'source' => 'mysql_storage',
                    ]);

                    return $stored;
                }
            }
        }

        try {
            $youtubeApi = $this->makeApiService();
            $result = $youtubeApi->getTranscriptWithBackoff($videoId, $language, $useCache, $attempt);
            $result = $this->languagePolicy->guardResult($result, $language);

            if ($result['success']) {
                Log::info('YouTubeTranscriptService: Fetched with backoff', [
                    'video_id' => $videoId,
                    'method' => $result['method'] ?? 'unknown',
                    'word_count' => $result['word_count'] ?? 0,
                    'attempt' => $attempt,
                ]);

                // Cache the result in our local cache as well. `forceFresh`
                // means "skip READING stale cache", but we still want to WRITE
                // the freshly-fetched transcript so subsequent reads don't
                // return the old entry until it naturally expires.
                if ($useCache || $forceFresh) {
                    $this->cacheTranscript($videoId, $language, $result);
                }

                // Store to persistent storage
                if ($this->storageConfig['enabled'] && $this->storageConfig['auto_store'] && $this->storageService) {
                    $this->storageService->store($videoId, $result);
                }
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('YouTubeTranscriptService: getTranscriptWithBackoff failed', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'video_id' => $videoId,
                'error' => $e->getMessage(),
                'error_type' => 'Exception',
            ];
        }
    }

    /**
     * Refresh a transcript (force fresh fetch and update storage).
     *
     * @param  string  $videoId  YouTube video ID
     * @param  string  $language  Language code
     * @return array Transcript result
     */
    public function refreshTranscript(string $videoId, string $language = 'en'): array
    {
        return $this->getTranscriptWithBackoff($videoId, $language, false, 0, true);
    }

    /**
     * Get storage statistics.
     *
     * @return array|null Stats or null if storage disabled
     */
    public function getStorageStats(): ?array
    {
        if (! $this->storageService) {
            return null;
        }

        return $this->storageService->getStats();
    }

    /**
     * Check if storage is enabled.
     */
    public function isStorageEnabled(): bool
    {
        return $this->storageConfig['enabled'] && $this->storageService !== null;
    }

    private function validateRequestedLanguage(string $videoId, string $language): array
    {
        $validated = $this->languagePolicy->validateRequestedLanguage($language);
        if ($validated['success'] ?? false) {
            return $validated;
        }

        return array_merge($validated, [
            'success' => false,
            'video_id' => $videoId,
        ]);
    }
}
