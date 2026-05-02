<?php

/**
 * Domain Configuration - Personal Life OS
 *
 * Registry of all data domains in the system.
 * Used for domain discovery, stats aggregation, and maintenance.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Domain Definitions
    |--------------------------------------------------------------------------
    |
    | Each domain represents a category of life data.
    | Required fields:
    | - table: MySQL table name (or pattern for multi-table domains)
    | - service: Fully qualified service class name
    | - rag_type: Document type identifier for RAG indexing
    | - enabled: Whether domain is currently active
    |
    | Optional fields:
    | - description: Human-readable description
    | - sync_schedule: Cron expression for sync (if applicable)
    | - retention_days: Days to retain non-RAG data (0 = forever)
    |
    */

    'domains' => [

        // ==============================
        // ACTIVE DOMAINS (Phase 1)
        // ==============================

        'calendar' => [
            'name' => 'Calendar',
            'table' => 'calendar_events',
            'service' => \App\Services\NextcloudService::class,
            'rag_type' => 'calendar_event',
            'enabled' => true,
            'description' => 'Nextcloud calendar events',
            'sync_method' => 'syncCalendarEventsToDatabase',
            'retention_days' => 0, // Keep forever
        ],

        'contacts' => [
            'name' => 'Contacts',
            'table' => 'contacts',
            'service' => \App\Services\NextcloudService::class,
            'rag_type' => 'contact',
            'enabled' => true,
            'description' => 'Nextcloud contacts',
            'sync_method' => 'syncContactsToDatabase',
            'retention_days' => 0,
        ],

        'news' => [
            'name' => 'News Articles',
            'table' => 'news_articles',
            'service' => \App\Services\NewsArticleService::class,
            'rag_type' => 'news_article',
            'enabled' => true,
            'description' => 'RSS feed articles',
            'retention_days' => 90, // Cleanup after 90 days if not in RAG
        ],

        'genealogy' => [
            'name' => 'Genealogy',
            'table' => 'genealogy_persons', // Primary table
            'tables' => ['genealogy_*'], // All genealogy tables
            'service' => \App\Services\GenealogyService::class,
            'rag_type' => 'genealogy_person',
            'enabled' => true,
            'description' => 'Family tree and genealogy records',
            'retention_days' => 0,
        ],

        'email' => [
            'name' => 'Email',
            'table' => 'email_threads',
            'service' => \App\Services\EmailService::class,
            'rag_type' => 'email',
            'enabled' => true,
            'description' => 'Email threads and classifications',
            'retention_days' => 0,
        ],

        'youtube' => [
            'name' => 'YouTube',
            'table' => 'youtube_transcripts',
            'service' => \App\Services\YouTubeTranscriptStorageService::class,
            'rag_type' => 'youtube_video',
            'enabled' => true,
            'description' => 'YouTube video transcripts',
            'retention_days' => 0,
        ],

        'files' => [
            'name' => 'File Registry',
            'table' => 'file_registry',
            'service' => \App\Services\FileRegistryService::class,
            'rag_type' => 'file_catalog',
            'enabled' => true,
            'description' => 'File catalog and metadata',
            'retention_days' => 0,
        ],

        'media' => [
            'name' => 'Media Manager',
            'table' => 'file_registry_faces',
            'tables' => ['file_registry_faces', 'genealogy_face_match_queue'],
            'service' => \App\Services\FaceMatcherService::class,
            'rag_type' => null, // Faces don't go to RAG
            'enabled' => true,
            'description' => 'Media face detection and genealogy linking',
            'maintenance' => [
                'scan_faces' => 'media:scan --faces --new-only --limit=100',
                'sync_faces' => 'media:sync-faces --refresh-genealogy --limit=100',
            ],
            'retention_days' => 0,
        ],

        'joplin' => [
            'name' => 'Joplin Notes',
            'table' => 'joplin_notes', // Virtual - actually in RAG
            'service' => \App\Services\JoplinFilesService::class,
            'rag_type' => 'joplin_note',
            'enabled' => true,
            'description' => 'Joplin markdown notes',
            'retention_days' => 0,
        ],

        'research' => [
            'name' => 'Research',
            'table' => 'research_results', // PostgreSQL
            'service' => \App\Services\ResearchService::class,
            'rag_type' => 'research',
            'enabled' => true,
            'description' => 'Research findings and sources',
            'connection' => 'pgsql_rag',
            'retention_days' => 0,
        ],

        // ==============================
        // PLANNED DOMAINS (Phase 2+)
        // ==============================

        'health' => [
            'name' => 'Health',
            'table' => 'health_records',
            'service' => null, // Not yet implemented
            'rag_type' => 'health',
            'enabled' => false,
            'description' => 'Health records, medications, appointments',
            'retention_days' => 0,
            'planned_fields' => [
                'record_type', // medication, appointment, test_result, symptom, measurement
                'title',
                'description',
                'provider',
                'data', // JSON for flexible measurements
                'attachments', // JSON array of file UUIDs
            ],
        ],

        'financial' => [
            'name' => 'Financial',
            'table' => 'financial_records',
            'service' => null,
            'rag_type' => 'financial',
            'enabled' => false,
            'description' => 'Financial records, accounts, transactions',
            'retention_days' => 0,
            'planned_fields' => [
                'record_type', // account, transaction, asset, liability, document
                'title',
                'amount',
                'currency',
                'institution',
                'data',
            ],
        ],

        'career' => [
            'name' => 'Career Archive',
            'table' => 'career_records',
            'service' => null,
            'rag_type' => 'career',
            'enabled' => false,
            'description' => '40 years of projects, code, professional history',
            'retention_days' => 0,
            'planned_fields' => [
                'record_type', // project, position, skill, achievement, code_sample
                'title',
                'description',
                'organization',
                'technologies', // JSON array
                'date_range', // JSON {start, end}
                'data',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Domain Groups
    |--------------------------------------------------------------------------
    |
    | Logical groupings of domains for UI and reporting.
    |
    */

    'groups' => [
        'personal' => [
            'name' => 'Personal Data',
            'domains' => ['calendar', 'contacts', 'health', 'financial'],
        ],
        'knowledge' => [
            'name' => 'Knowledge',
            'domains' => ['news', 'research', 'joplin', 'youtube'],
        ],
        'heritage' => [
            'name' => 'Heritage',
            'domains' => ['genealogy', 'career'],
        ],
        'system' => [
            'name' => 'System',
            'domains' => ['files', 'email', 'media'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Settings
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'retention_days' => 0, // Keep forever by default
        'rag_batch_size' => 50, // Records to index per maintenance run
        'sync_on_maintenance' => true, // Sync during nightly maintenance
    ],

];
