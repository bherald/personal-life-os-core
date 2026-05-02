<?php

return [
    /*
    |--------------------------------------------------------------------------
    | YouTube API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for YouTube Data API v3 and transcript fetching.
    |
    */

    'enabled' => env('YOUTUBE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | OAuth 2.0 Credentials
    |--------------------------------------------------------------------------
    */

    'client_id' => env('YOUTUBE_CLIENT_ID'),
    'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
    'redirect_uri' => env('YOUTUBE_REDIRECT_URI'),

    /*
    |--------------------------------------------------------------------------
    | API Cache Settings
    |--------------------------------------------------------------------------
    */

    'cache_ttl' => env('YOUTUBE_API_CACHE_TTL', 60), // minutes

    /*
    |--------------------------------------------------------------------------
    | Transcript Rate Limiting / Fault Tolerance
    |--------------------------------------------------------------------------
    |
    | These settings control the rate limiting and retry logic for fetching
    | YouTube transcripts to prevent IP blocks from Google.
    |
    */

    'transcript' => [
        // Restrict transcript processing to approved languages only.
        // English remains the default workflow language. German and Spanish
        // are permitted for explicitly language-specific workflows.
        'allowed_languages' => array_values(array_filter(array_map(
            static fn ($value) => trim((string) $value),
            explode(',', (string) env('YOUTUBE_TRANSCRIPT_ALLOWED_LANGUAGES', 'en,de,es'))
        ))),

        // Delay in seconds between individual video transcript requests
        // INCREASED: YouTube aggressively rate limits - need longer delays
        'delay' => env('YOUTUBE_TRANSCRIPT_DELAY', 30),

        // Delay in seconds between batches of videos
        'batch_delay' => env('YOUTUBE_TRANSCRIPT_BATCH_DELAY', 60),

        // Maximum number of retry attempts for failed transcript fetches
        'max_retries' => env('YOUTUBE_TRANSCRIPT_MAX_RETRIES', 3),

        // Delay in seconds before retrying a failed request
        // Uses exponential backoff: base_delay * 2^attempt
        'retry_delay' => env('YOUTUBE_TRANSCRIPT_RETRY_DELAY', 30),

        // Base delay for exponential backoff (seconds)
        'base_delay' => env('YOUTUBE_TRANSCRIPT_BASE_DELAY', 10),

        // Maximum delay cap for exponential backoff (seconds)
        'max_delay' => env('YOUTUBE_TRANSCRIPT_MAX_DELAY', 300),

        // Process videos in smaller batches to avoid rate limiting
        'batch_size' => env('YOUTUBE_TRANSCRIPT_BATCH_SIZE', 3),

        // Minimum delay between ANY YouTube requests (seconds)
        'min_request_gap' => env('YOUTUBE_MIN_REQUEST_GAP', 15),

        // Persistent storage settings
        'storage' => [
            // Whether to use persistent MySQL storage
            'enabled' => env('YOUTUBE_TRANSCRIPT_STORAGE_ENABLED', true),

            // Prefer stored transcripts over fresh fetch (faster, avoids rate limits)
            'prefer_stored' => env('YOUTUBE_TRANSCRIPT_PREFER_STORED', true),

            // Auto-store transcripts after successful fetch
            'auto_store' => env('YOUTUBE_TRANSCRIPT_AUTO_STORE', true),

            // Days before transcript is considered stale (0 = never stale)
            'stale_days' => env('YOUTUBE_TRANSCRIPT_STALE_DAYS', 0),
        ],
    ],
];
