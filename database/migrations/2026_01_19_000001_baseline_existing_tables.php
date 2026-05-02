<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Baseline Migration - Documents existing database schema
 *
 * This migration doesn't create tables - they already exist.
 * It serves as a baseline marker so future migrations can run properly.
 *
 * All tables listed below were created manually or via raw SQL before
 * Laravel migrations were standardized (January 2026).
 */
return new class extends Migration
{
    /**
     * Tables that exist in the database as of this baseline
     */
    private array $existingTables = [
        // Core Laravel
        'migrations',
        'users',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',

        // OAuth (Passport)
        'oauth_access_tokens',
        'oauth_auth_codes',
        'oauth_clients',
        'oauth_device_codes',
        'oauth_refresh_tokens',
        'oauth_tokens',

        // Workflows
        'workflows',
        'workflow_nodes',
        'workflow_node_configs',
        'workflow_runs',
        'workflow_run_inputs',
        'workflow_run_outputs',
        'workflow_defaults',
        'workflow_backups',
        'workflow_diagnostics',

        // Node Executions
        'node_executions',
        'node_execution_inputs',
        'node_execution_outputs',
        'node_execution_meta',

        // Scheduled Jobs
        'scheduled_jobs',
        'scheduled_job_runs',
        'retry_configs',
        'retry_backoff_intervals',

        // AI/Prompts
        'ai_prompts',
        'conversations',
        'chat_messages',

        // E13: File Registry / Windows File Organizer
        'file_registry',
        'file_registry_duplicates',
        'file_registry_path_history',
        'file_registry_sync_runs',
        'windows_file_index',
        'windows_file_actions',
        'windows_file_config',
        'windows_folder_mappings',
        'windows_folder_rules',
        'windows_bundle_types',
        'windows_document_types',
        'windows_sync_runs',

        // Joplin Integration
        'joplin_attachment_index',
        'joplin_metadata_cache',
        'joplin_queue_jobs',

        // YouTube
        'youtube_playlist_progress',

        // RSS/News
        'rss_feed_health',
        'bias_ratings',
        'polarizing_topics',
        'emotional_language_words',

        // Email Service (EA2)
        'email_classifications',
        'email_notification_settings',
        'email_reply_drafts',
        'email_rules',
        'email_scheduled',
        'email_settings',
        'email_shipments',
        'email_suggested_actions',
        'email_templates',

        // Data Removal (X1)
        'data_brokers',
        'data_subjects',
        'removal_requests',
        'removal_activity_log',
        'broker_discovery_queue',
        'data_removal_captcha_queue',

        // E20: Genealogy
        'genealogy_trees',
        'genealogy_tree_collaborators',
        'genealogy_tree_invitations',
        'genealogy_persons',
        'genealogy_families',
        'genealogy_children',
        'genealogy_events',
        'genealogy_family_events',
        'genealogy_residences',
        'genealogy_sources',
        'genealogy_repositories',
        'genealogy_citations',
        'genealogy_media',
        'genealogy_person_media',
        'genealogy_family_media',
        'genealogy_person_sources',
        'genealogy_family_sources',
        'genealogy_name_variations',
        'genealogy_activity_log',
        'genealogy_external_connections',
        'genealogy_external_records',
        'genealogy_external_syncs',
        'genealogy_provider_logs',
        'genealogy_provider_sync_status',
        'genealogy_provider_tokens',
        'genealogy_research_hints',
        'genealogy_research_searches',
        'genealogy_research_tasks',
        'genealogy_smart_matches',
        'genealogy_duplicate_pairs',
        'genealogy_face_match_queue',
        'genealogy_newspaper_clippings',
        'genealogy_person_clippings',
        'genealogy_person_external_links',

        // System/Ops
        'system_alerts',
        'system_configs',
        'system_errors',
        'system_health_snapshots',
        'system_issues',
        'process_health_flags',
        'devops_commands',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // This is a baseline migration - no schema changes needed
        // All tables listed above already exist in the database
        // Simply records that the baseline migration has run
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot reverse baseline - would need to drop all tables
        // which is destructive and not desired
    }
};
