<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Comprehensive scheduled jobs audit:
 * 1. Add missing maintenance flags to existing commands
 * 2. Set categories on uncategorized jobs
 * 3. Re-enable disabled agents (cost sprint ended)
 * 4. Register missing maintenance/health jobs
 * 5. Optimize scheduling to avoid GPU contention
 */
return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────
        // 1. Fix missing maintenance flags on existing commands
        // ──────────────────────────────────────────────

        // rss:self-heal — add --notify for alerts on corrections
        DB::update(
            "UPDATE scheduled_jobs SET command = ? WHERE name = ?",
            ['rss:self-heal --check-all --notify', 'rss_self_heal']
        );

        // faces:cluster backfill — add --dedup to remove duplicate embeddings
        DB::update(
            "UPDATE scheduled_jobs SET command = ? WHERE name = ?",
            ['faces:cluster --backfill --optimize --dedup', 'face_recluster']
        );

        // faces:cluster full — add --purge-bloat to fix oversized clusters
        DB::update(
            "UPDATE scheduled_jobs SET command = ? WHERE name = ?",
            ['faces:cluster --recluster-singletons --optimize --purge-bloat', 'face_recluster_full']
        );

        // genealogy:media-validate — add --purge for orphaned row cleanup
        DB::update(
            "UPDATE scheduled_jobs SET command = ? WHERE name = ?",
            ['genealogy:media-validate --tree-id=4 --batch=500 --purge', 'genealogy_media_validate']
        );

        // ──────────────────────────────────────────────
        // 2. Set categories on uncategorized jobs
        // ──────────────────────────────────────────────

        DB::update("UPDATE scheduled_jobs SET category = 'ops' WHERE name = 'daily_report' AND category IS NULL");
        DB::update("UPDATE scheduled_jobs SET category = 'Research' WHERE name = 'factcheck_run' AND category IS NULL");
        DB::update("UPDATE scheduled_jobs SET category = 'Files' WHERE name = 'file_enrich_gps' AND category IS NULL");
        DB::update("UPDATE scheduled_jobs SET category = 'Research' WHERE name = 'research_run_missions' AND category IS NULL");

        // ──────────────────────────────────────────────
        // 3. Re-enable disabled agents
        // ──────────────────────────────────────────────

        $agentsToEnable = [
            'email_ops_agent',
            'factcheck_ops_agent',
            'data_removal_ops_agent',
            'file_curator_agent',
            'knowledge_curator_agent',
            'research_analyst_agent',
            'email_rag_index',
        ];

        foreach ($agentsToEnable as $name) {
            DB::update("UPDATE scheduled_jobs SET enabled = 1 WHERE name = ?", [$name]);
        }

        // ──────────────────────────────────────────────
        // 4. Register missing maintenance/health jobs
        // ──────────────────────────────────────────────

        $newJobs = [
            [
                'name' => 'workflow_health_check',
                'description' => 'Detect and auto-cleanup stuck workflow runs',
                'command' => 'workflow:health-check --auto-cleanup --alert',
                'cron_expression' => '0 */6 * * *',
                'timeout_minutes' => 15,
                'category' => 'Maintenance',
            ],
            [
                'name' => 'rss_health',
                'description' => 'Proactive RSS feed health monitoring',
                'command' => 'rss:health --report',
                'cron_expression' => '0 6 * * *',
                'timeout_minutes' => 15,
                'category' => 'Maintenance',
            ],
            [
                'name' => 'ops_smoke_test',
                'description' => 'Quick daily smoke test — registry + jobs + services',
                'command' => 'ops:smoke-test --quick',
                'cron_expression' => '30 5 * * *',
                'timeout_minutes' => 15,
                'category' => 'ops',
            ],
            [
                'name' => 'ops_nightly',
                'description' => 'Evening ops health summary via Pushover',
                'command' => 'ops:nightly',
                'cron_expression' => '0 22 * * *',
                'timeout_minutes' => 10,
                'category' => 'ops',
            ],
            [
                'name' => 'ops_schema_sync',
                'description' => 'Weekly schema-reference.md sync from live DB',
                'command' => 'ops:sync-schema-reference',
                'cron_expression' => '0 5 * * 1',
                'timeout_minutes' => 10,
                'category' => 'ops',
            ],
            [
                'name' => 'joplin_cache_refresh',
                'description' => 'Refresh Joplin note cache and prune deleted entries',
                'command' => 'joplin:cache-refresh --prune',
                'cron_expression' => '0 */4 * * *',
                'timeout_minutes' => 15,
                'category' => 'Sync',
            ],
            [
                'name' => 'joplin_cleanup_queue',
                'description' => 'Clean stale Joplin queue entries (7d normal, 30d failed)',
                'command' => 'joplin:cleanup-queue',
                'cron_expression' => '0 5 * * 0',
                'timeout_minutes' => 10,
                'category' => 'Maintenance',
            ],
            [
                'name' => 'genealogy_embed_persons',
                'description' => 'Build/update person embeddings for semantic genealogy search',
                'command' => 'genealogy:embed-persons --limit=200',
                'cron_expression' => '30 */8 * * *',
                'timeout_minutes' => 30,
                'category' => 'Genealogy',
            ],
            [
                'name' => 'genealogy_backfill_photos',
                'description' => 'Weekly backfill missing primary photos on person records',
                'command' => 'genealogy:backfill-primary-photos --tree=4',
                'cron_expression' => '0 4 * * 0',
                'timeout_minutes' => 30,
                'category' => 'Genealogy',
            ],
            [
                'name' => 'youtube_transcript_health',
                'description' => 'Weekly check of YouTube transcript method health',
                'command' => 'youtube:transcript-health',
                'cron_expression' => '0 7 * * 0',
                'timeout_minutes' => 10,
                'category' => 'youtube',
            ],
            [
                'name' => 'email_scheduled_process',
                'description' => 'Process due scheduled emails',
                'command' => 'email:scheduled --process',
                'cron_expression' => '0 */2 * * *',
                'timeout_minutes' => 15,
                'category' => 'Email',
            ],
        ];

        foreach ($newJobs as $job) {
            DB::insert(
                "INSERT IGNORE INTO scheduled_jobs
                    (name, description, job_type, command, cron_expression, enabled, timeout_minutes, category, created_at, updated_at)
                 VALUES (?, ?, 'command', ?, ?, 1, ?, ?, NOW(), NOW())",
                [
                    $job['name'],
                    $job['description'],
                    $job['command'],
                    $job['cron_expression'],
                    $job['timeout_minutes'],
                    $job['category'],
                ]
            );
        }

        // ──────────────────────────────────────────────
        // 5. Schedule optimizations — reduce GPU contention
        // ──────────────────────────────────────────────

        // Stagger re-enabled agents to avoid :00 pile-up
        // email-ops: offset to :10
        DB::update(
            "UPDATE scheduled_jobs SET cron_expression = ? WHERE name = ?",
            ['10 */2 * * *', 'email_ops_agent']
        );

        // factcheck-ops: offset to :20 past
        DB::update(
            "UPDATE scheduled_jobs SET cron_expression = ? WHERE name = ?",
            ['20 */6 * * *', 'factcheck_ops_agent']
        );

        // file-curator: offset to :40 past
        DB::update(
            "UPDATE scheduled_jobs SET cron_expression = ? WHERE name = ?",
            ['40 */4 * * *', 'file_curator_agent']
        );

        // knowledge-curator: offset to :30 past
        DB::update(
            "UPDATE scheduled_jobs SET cron_expression = ? WHERE name = ?",
            ['30 */6 * * *', 'knowledge_curator_agent']
        );

        // research-analyst: offset to :45 past
        DB::update(
            "UPDATE scheduled_jobs SET cron_expression = ? WHERE name = ?",
            ['45 */6 * * *', 'research_analyst_agent']
        );

        // data-removal-ops: offset to :50 past
        DB::update(
            "UPDATE scheduled_jobs SET cron_expression = ? WHERE name = ?",
            ['50 */4 * * *', 'data_removal_ops_agent']
        );
    }

    public function down(): void
    {
        // Revert command flag changes
        DB::update("UPDATE scheduled_jobs SET command = 'rss:self-heal --check-all' WHERE name = 'rss_self_heal'");
        DB::update("UPDATE scheduled_jobs SET command = 'faces:cluster --backfill --optimize' WHERE name = 'face_recluster'");
        DB::update("UPDATE scheduled_jobs SET command = 'faces:cluster --recluster-singletons --optimize' WHERE name = 'face_recluster_full'");
        DB::update("UPDATE scheduled_jobs SET command = 'genealogy:media-validate --tree-id=4 --batch=500' WHERE name = 'genealogy_media_validate'");

        // Re-disable agents
        $agents = ['email_ops_agent', 'factcheck_ops_agent', 'data_removal_ops_agent',
                    'file_curator_agent', 'knowledge_curator_agent', 'research_analyst_agent', 'email_rag_index'];
        foreach ($agents as $name) {
            DB::update("UPDATE scheduled_jobs SET enabled = 0 WHERE name = ?", [$name]);
        }

        // Revert schedules
        DB::update("UPDATE scheduled_jobs SET cron_expression = '*/30 * * * *' WHERE name = 'email_ops_agent'");
        DB::update("UPDATE scheduled_jobs SET cron_expression = '0 */6 * * *' WHERE name = 'factcheck_ops_agent'");
        DB::update("UPDATE scheduled_jobs SET cron_expression = '0 */4 * * *' WHERE name = 'file_curator_agent'");
        DB::update("UPDATE scheduled_jobs SET cron_expression = '0 */6 * * *' WHERE name = 'knowledge_curator_agent'");
        DB::update("UPDATE scheduled_jobs SET cron_expression = '0 */6 * * *' WHERE name = 'research_analyst_agent'");
        DB::update("UPDATE scheduled_jobs SET cron_expression = '0 */4 * * *' WHERE name = 'data_removal_ops_agent'");

        // Remove new jobs
        $newNames = [
            'workflow_health_check', 'rss_health', 'ops_smoke_test', 'ops_nightly',
            'ops_schema_sync', 'joplin_cache_refresh', 'joplin_cleanup_queue',
            'genealogy_embed_persons', 'genealogy_backfill_photos', 'youtube_transcript_health',
            'email_scheduled_process',
        ];
        foreach ($newNames as $name) {
            DB::delete("DELETE FROM scheduled_jobs WHERE name = ?", [$name]);
        }
    }
};
