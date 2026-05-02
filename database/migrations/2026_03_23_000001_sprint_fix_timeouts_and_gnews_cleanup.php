<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Increase timeouts for jobs hitting wall-clock limits (2026-03-23 sprint)
        // rag_hype_build: 34 → 50 min (67% fail rate, runs take 710s+ on success)
        DB::update("UPDATE scheduled_jobs SET timeout_minutes = 50 WHERE name = 'rag_hype_build'");

        // community_detection: 94 → 120 min (runs take 4700s+, hitting 94min wall-clock)
        DB::update("UPDATE scheduled_jobs SET timeout_minutes = 120 WHERE name = 'community_detection'");

        // workflow_joplin_sync: 57 → 75 min (hitting 57min timeout with large syncs)
        DB::update("UPDATE scheduled_jobs SET timeout_minutes = 75 WHERE name = 'workflow_joplin_sync'");

        // Disable GNews engine in research_engine_health (provider dropped)
        DB::update("UPDATE research_engine_health SET is_active = 0 WHERE engine_name = 'gnews'");

        // JoplinSync node: NULL (300s default) → 900s (15min) to handle large syncs
        DB::update("UPDATE workflow_nodes SET timeout_seconds = 900 WHERE id = 83 AND node_type = 'JoplinSync'");

        // AIFormatter node in joplin_sync workflow: NULL → 600s (10min)
        DB::update("UPDATE workflow_nodes SET timeout_seconds = 600 WHERE id = 84 AND workflow_id = 4 AND node_type = 'AIFormatter'");

        // Accelerate orphan recovery: verify limit 500 → 2000, run twice daily instead of once
        DB::update("UPDATE scheduled_jobs SET command = 'files:registry --verify --limit=2000', cron_expression = '0 8,20 * * *' WHERE name = 'file_registry_verify'");

        // Run maintenance twice weekly (Sun+Wed) instead of weekly to clear orphan backlog faster
        DB::update("UPDATE scheduled_jobs SET cron_expression = '0 2 * * 0,3' WHERE name = 'file_registry_maintenance'");
    }

    public function down(): void
    {
        DB::update("UPDATE scheduled_jobs SET timeout_minutes = 34 WHERE name = 'rag_hype_build'");
        DB::update("UPDATE scheduled_jobs SET timeout_minutes = 94 WHERE name = 'community_detection'");
        DB::update("UPDATE scheduled_jobs SET timeout_minutes = 57 WHERE name = 'workflow_joplin_sync'");
        DB::update("UPDATE research_engine_health SET is_active = 1 WHERE engine_name = 'gnews'");
        DB::update("UPDATE workflow_nodes SET timeout_seconds = NULL WHERE id = 83 AND node_type = 'JoplinSync'");
        DB::update("UPDATE workflow_nodes SET timeout_seconds = NULL WHERE id = 84 AND workflow_id = 4 AND node_type = 'AIFormatter'");
        DB::update("UPDATE scheduled_jobs SET command = 'files:registry --verify --limit=500', cron_expression = '0 20 * * *' WHERE name = 'file_registry_verify'");
        DB::update("UPDATE scheduled_jobs SET cron_expression = '0 2 * * 0' WHERE name = 'file_registry_maintenance'");
    }
};
