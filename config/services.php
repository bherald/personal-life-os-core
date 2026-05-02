<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'nextcloud' => [
        'url' => env('NEXTCLOUD_URL'),
        'username' => env('NEXTCLOUD_USERNAME'),
        'password' => env('NEXTCLOUD_PASSWORD'),
        'data_path' => env('NEXTCLOUD_DATA_PATH'),  // Direct filesystem access; blank disables filesystem-first reads
        'joplin_path' => env('NEXTCLOUD_JOPLIN_PATH', '/Joplin-data'),
        'library_root' => env('NEXTCLOUD_LIBRARY_ROOT', env('NEXTCLOUD_WINDOWS_BASE', '/Library')),
        'windows_base' => env('NEXTCLOUD_LIBRARY_ROOT', env('NEXTCLOUD_WINDOWS_BASE', '/Library')),
        'default_calendar' => env('NEXTCLOUD_DEFAULT_CALENDAR', env('NEXTCLOUD_USERNAME', 'plos')),
        'occ_user' => env('NEXTCLOUD_OCC_USER', env('NEXTCLOUD_USERNAME', 'plos')),
    ],

    'joplin' => [
        'youtube_watch_later_folder_id' => env('JOPLIN_WATCH_LATER_FOLDER_ID', ''),
    ],

    'storage' => [
        'root' => env('PLOS_STORAGE_ROOT', '/srv/nextcloud'),
    ],

    'internet_archive' => [
        'user_agent_contact' => env('PLOS_USER_AGENT_CONTACT'),
    ],

    'pushover' => [
        'token' => env('PUSHOVER_API_TOKEN'),
        'user_key' => env('PUSHOVER_USER_KEY'),
        'api_url' => env('PUSHOVER_API_URL', 'https://api.pushover.net/1/messages.json'),
    ],

    'weather' => [
        'api_key' => env('WEATHER_API_KEY'),
        'api_url' => env('WEATHER_API_URL', 'https://api.openweathermap.org/data/2.5'),
    ],

    'ollama' => [
        'api_url' => env('OLLAMA_API_URL', 'http://127.0.0.1:11434'),
        'model' => env('OLLAMA_MODEL'),
        'embedding_model' => env('OLLAMA_EMBEDDING_MODEL'),
        'vision_model' => env('OLLAMA_VISION_MODEL'),
        // Increased default from 30 to 180 to handle large AI formatting operations
        'timeout' => env('OLLAMA_TIMEOUT', 180),
        'embedding_timeout' => env('OLLAMA_EMBEDDING_TIMEOUT', 10),
        'tool_timeout' => env('OLLAMA_TOOL_TIMEOUT', 180),
        'streaming_timeout' => env('OLLAMA_STREAMING_TIMEOUT', 240),
        'chat_timeout' => env('OLLAMA_CHAT_TIMEOUT', 90),
        // Secondary Ollama instances for failover (comma-separated URLs)
        'secondary_urls' => array_filter(explode(',', env('OLLAMA_SECONDARY_URLS', ''))),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('CLAUDE_MODEL', 'claude-3-5-sonnet-20241022'),
        'cli_path' => env('CLAUDE_CLI_PATH', 'claude'),
        'cli_oauth_token' => env('CLAUDE_CODE_OAUTH_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Thunderbird Email Integration (EA2)
    |--------------------------------------------------------------------------
    |
    | Configuration for Thunderbird MCP server connection.
    | Architecture: ThunderbirdService → MCP Server → Thunderbird Extension
    |
    */
    'thunderbird' => [
        'url' => env('THUNDERBIRD_MCP_URL', 'http://127.0.0.1:8766'),
        'timeout' => env('THUNDERBIRD_TIMEOUT', 30),
        'connect_timeout' => env('THUNDERBIRD_CONNECT_TIMEOUT', 5),
        'archive_profile_path' => env('THUNDERBIRD_ARCHIVE_PROFILE_PATH', '/Email/Thunderbird'),
    ],

    'claude' => [
        // Claude Agent SDK HTTP Proxy for MCP tool access
        // Provides fallback when Ollama is unavailable
        'agent_proxy_url' => env('CLAUDE_AGENT_PROXY_URL', 'http://127.0.0.1:8770'),
        'agent_proxy_timeout' => env('CLAUDE_AGENT_PROXY_TIMEOUT', 120),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],

    'ai' => [
        'default_mode' => env('AI_DEFAULT_MODE', 'auto'),
        'use_dynamic_pool' => env('AI_USE_DYNAMIC_POOL', true),
        'cache_enabled' => env('AI_CACHE_ENABLED', true),
        'cache_similarity' => env('AI_CACHE_SIMILARITY', 0.85),
        'cache_ttl' => env('AI_CACHE_TTL', 86400),
        'semantic_cache' => env('AI_SEMANTIC_CACHE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | E17: Whisper Audio/Video Transcription
    |--------------------------------------------------------------------------
    |
    | Configuration for OpenAI Whisper transcription.
    | Install: pip install openai-whisper
    |
    | Models: tiny, base, small, medium, large, large-v2, large-v3
    | - tiny: ~1GB VRAM, fastest, lower accuracy
    | - base: ~1GB VRAM, good balance for short clips
    | - small: ~2GB VRAM, recommended for most use cases
    | - medium: ~5GB VRAM, high accuracy
    | - large: ~10GB VRAM, highest accuracy
    |
    */
    'whisper' => [
        'path' => env('WHISPER_PATH'),  // Auto-detect if null
        'model' => env('WHISPER_MODEL', 'base'),
        'language' => env('WHISPER_LANGUAGE', ''),  // Empty = auto-detect
        'timeout' => env('WHISPER_TIMEOUT', 300),  // 5 min default
        'gpu_lock_ttl' => env('WHISPER_GPU_LOCK_TTL', 900),  // 15 min max lock
        'youtube_download_timeout' => env('WHISPER_YOUTUBE_DOWNLOAD_TIMEOUT', 120),
    ],

    'newsapi' => [
        'api_key' => env('NEWSAPI_KEY'),
    ],

    // GNews removed — provider dropped (2026-03-23) after repeated reliability
    // failures. BannedExternalPatternsTest (tests/Feature/Quality) guards
    // against the GNews API hostname reappearing. Do not re-enable without
    // operator approval — the provider failed in production and was
    // explicitly dropped.
    // 'gnews' => [
    //     'api_key' => env('GNEWS_API_KEY'),
    // ],

    'twocaptcha' => [
        'api_key' => env('TWO_CAPTCHA_API_KEY'),
    ],

    'research' => [
        'throttle_ms' => env('RESEARCH_THROTTLE_MS', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | SearXNG Meta Search Engine
    |--------------------------------------------------------------------------
    |
    | Local SearXNG instance for privacy-respecting federated search.
    | Install: pip install searxng in /opt/searxng/venv
    | Systemd service: searxng.service
    |
    */
    'searxng' => [
        'url' => env('SEARXNG_URL', 'http://127.0.0.1:8888'),
        'timeout' => env('SEARXNG_TIMEOUT', 30),
        'connect_timeout' => env('SEARXNG_CONNECT_TIMEOUT', 5),
        'failure_threshold' => env('SEARXNG_FAILURE_THRESHOLD', 5),
        'recovery_timeout' => env('SEARXNG_RECOVERY_TIMEOUT', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | E13: Windows File Organizer
    |--------------------------------------------------------------------------
    |
    | Configuration for SSH access to Windows machine for file sync
    |
    */
    'windows_file' => [
        'host' => env('WINDOWS_FILE_HOST'),
        'username' => env('WINDOWS_FILE_USERNAME', ''),
        'password' => env('WINDOWS_FILE_PASSWORD', ''),
        'base_path' => env('WINDOWS_FILE_BASE_PATH', ''),
        'timeout' => env('WINDOWS_FILE_TIMEOUT', 120),
        // Folders to exclude from Nextcloud sync (keep on Windows only)
        'exclude_from_nextcloud' => ['projects', 'Projects'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Joplin Attachment Processing
    |--------------------------------------------------------------------------
    |
    | Configuration for Joplin attachment extraction pipeline (v2)
    | Pipeline: ContentExtractionService + AIService provider routing.
    |
    */
    'joplin_attachments' => [
        'max_concurrent_tesseract' => env('JOPLIN_MAX_CONCURRENT_TESSERACT', 3),
        'retry_delay' => env('JOPLIN_RETRY_DELAY', 5),  // seconds between retries

        // Extraction pipeline version
        'extraction_version' => env('JOPLIN_EXTRACTION_VERSION', 'v2'),

        // Supported file types
        'supported_extensions' => [
            'pdf' => 'pdftotext+tesseract+ai',
            'jpg' => 'tesseract+ai',
            'jpeg' => 'tesseract+ai',
            'png' => 'tesseract+ai',
            'gif' => 'tesseract+ai',
            'bmp' => 'tesseract+ai',
            'tiff' => 'tesseract+ai',
            'webp' => 'tesseract+ai',
            'docx' => 'docx2txt+ai',
            'odt' => 'unzip+ai',
            'txt' => 'direct+ai',
            'md' => 'direct+ai',
            'html' => 'strip_tags+ai',
            'htm' => 'strip_tags+ai',
            'xlsx' => 'ssconvert+ai',
            'csv' => 'direct+ai',
        ],

        // Placeholder extensions (future E17/E18)
        'placeholder_extensions' => ['mp3', 'mp4', 'wav', 'webm', 'ogg', 'zip', 'rar', '7z'],

        // Logging
        'log_channel' => 'joplin-attachments',
        'log_file' => storage_path('logs/joplin-attachments.log'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Genealogy External Providers (Phase 9.5)
    |--------------------------------------------------------------------------
    |
    | Configuration for external genealogy data sources.
    | Each provider has its own authentication requirements.
    |
    | Provider Status:
    | - familysearch / ancestry automated API connections: REMOVED.
    |   Keep them as manual source/citation targets only; do not configure
    |   OAuth, login sessions, or unofficial API access for either service.
    | - myheritage: PRIVATE/PERSONAL-GATED (screenshot automation disabled by default)
    | - findagrave: READY (no auth, web scraping)
    | - billiongraves: READY (no auth, web interface)
    | - loc: READY (no auth, FREE)
    | - nara: READY (optional API key, FREE)
    | - europeana: READY (API key required, FREE)
    |
    */

    // WikiTree (public API, no auth required; client_id is still used for identification)
    'wikitree' => [
        'client_id' => env('WIKITREE_CLIENT_ID', 'plos-genealogy'),
    ],

    // MyHeritage is private/personal-gated. The current provider uses
    // screenshot/vision extraction, so public/default installs keep it disabled.
    'myheritage' => [
        'client_id' => env('MYHERITAGE_CLIENT_ID'),
        'client_secret' => env('MYHERITAGE_CLIENT_SECRET'),
        'redirect_uri' => env('MYHERITAGE_REDIRECT_URI'),
        'personal_automation_enabled' => (bool) env('MYHERITAGE_PERSONAL_AUTOMATION_ENABLED', false),
    ],

    // National Archives (NARA) - FREE, optional API key for higher limits
    // Request key: email Catalog_API@nara.gov
    'nara' => [
        'api_key' => env('NARA_API_KEY'),
    ],

    // Europeana - FREE with API key
    // Register at: https://pro.europeana.eu/page/apis
    'europeana' => [
        'api_key' => env('EUROPEANA_API_KEY'),
    ],

    'newspapers' => [
        'barcode' => env('NEWSPAPERS_BARCODE'),
        'personal_automation_enabled' => (bool) env('NEWSPAPERS_PERSONAL_AUTOMATION_ENABLED', (bool) env('NEWSPAPERS_BARCODE')),
    ],

    'transkribus' => [
        'api_key' => env('TRANSKRIBUS_API_KEY'),
    ],

    // Library of Congress - FREE, no key required
    'loc' => [
        'rate_limit_per_second' => env('LOC_RATE_LIMIT', 1),
    ],

    // FindAGrave - FREE, no auth (web scraping)
    'findagrave' => [
        'cache_ttl' => env('FINDAGRAVE_CACHE_TTL', 86400), // 24 hours
    ],

    // BillionGraves - FREE, no auth
    'billiongraves' => [
        'cache_ttl' => env('BILLIONGRAVES_CACHE_TTL', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Apache Tika Document Extraction Service
    |--------------------------------------------------------------------------
    |
    | Tika Server provides content extraction for 1000+ file formats.
    | Runs as local service on port 9998.
    |
    | Systemd service: tika.service
    | Start: sudo systemctl start tika
    | Logs: journalctl -u tika -f
    |
    */
    'tika' => [
        'url' => env('TIKA_URL', 'http://127.0.0.1:9998'),
        'timeout' => env('TIKA_TIMEOUT', 300),  // seconds (increased from 120 for large PDFs)
        'max_file_size' => env('TIKA_MAX_FILE_SIZE', 100 * 1024 * 1024),  // 100MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Guardrails (Enhancement #17)
    |--------------------------------------------------------------------------
    |
    | Pre-tool validation for AI agent operations.
    | Implements OpenAI Agents SDK guardrails pattern.
    |
    | Rule actions:
    | - block: Completely prevent operation
    | - confirm: Require explicit confirmation
    | - log: Allow but log the action
    | - allow: Explicitly allow (whitelist)
    |
    */
    'guardrails' => [
        // Use database rules in addition to config rules
        'use_database' => env('GUARDRAILS_USE_DATABASE', true),

        // Cache TTL for rules (seconds)
        'cache_ttl' => env('GUARDRAILS_CACHE_TTL', 300),

        // Operations to always log (even when allowed)
        // Use '*' to log all operations
        'log_operations' => [
            'file_write',
            'file_delete',
            'database_*',
            'api_call_*',
        ],

        // Additional config-based rules (merged with database rules)
        // Database rules take precedence when operation patterns match
        'rules' => [
            // Example: Block external API calls in development
            // [
            //     'name' => 'Block External APIs in Dev',
            //     'operation_pattern' => 'api_call_external',
            //     'action' => 'block',
            //     'conditions' => [],
            //     'reason' => 'External API calls disabled in development',
            //     'severity' => 'high',
            //     'priority' => 100,
            // ],
        ],
    ],

    'agent_handoffs' => [
        'use_database' => env('AGENT_HANDOFFS_USE_DATABASE', true),
        'agents' => [],
        'routing_rules' => [],
    ],

];
