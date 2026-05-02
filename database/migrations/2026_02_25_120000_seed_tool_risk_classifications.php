<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Read tools — monitoring category
        $readMonitoring = [
            'system_health_check', 'system_health_trend', 'system_unhealthy_snapshots',
            'alerts_get_active', 'alerts_statistics', 'workflow_health_summary',
            'workflow_failing', 'queue_metrics', 'ai_health_stats', 'ai_system_load',
            'rss_health_summary', 'rss_feeds_needing_attention', 'agent_health_check',
        ];
        $this->classify($readMonitoring, 'read', 'monitoring');

        // Read tools — pipeline category
        $readPipeline = ['pipeline_status', 'ai_capacity', 'gpu_status', 'enrichment_job_configs', 'stalled_jobs', 'processing_rates'];
        $this->classify($readPipeline, 'read', 'pipeline');

        // Read tools — rag category
        $readRag = ['rag_stats', 'rag_search', 'rag_deep_search', 'raptor_get_pending', 'raptor_get_hierarchy', 'content_extract', 'content_extract_status', 'rag_eval_stats', 'rag_eval_history'];
        $this->classify($readRag, 'read', 'rag');

        // Read tools — agent category
        $readAgent = ['get_pending_reviews', 'get_agent_messages', 'pending_tool_proposals'];
        $this->classify($readAgent, 'read', 'agent');

        // Read tools — genealogy category
        $readGenealogy = [
            'list_persons', 'get_person', 'get_person_events', 'get_person_sources',
            'search_persons', 'get_missing_data_report', 'get_tree_statistics',
            'get_research_hints', 'get_open_research_tasks', 'assess_gps_compliance',
            'resolve_place', 'search_places',
        ];
        $this->classify($readGenealogy, 'read', 'genealogy');

        // Read tools — search category
        $readSearch = ['nara_search', 'loc_newspaper_search', 'internet_archive_search'];
        $this->classify($readSearch, 'read', 'search');

        // Read tools — code category
        $this->classify(['code_quality_check'], 'read', 'code');

        // Write tools — monitoring category
        $this->classify(['alerts_run_checks', 'system_health_snapshot'], 'write', 'monitoring');

        // Write tools — pipeline category
        $writesPipeline = ['adjust_job_config', 'fix_stalled_job'];
        $this->classify($writesPipeline, 'write', 'pipeline');

        // Write tools — rag category
        $writesRag = ['rag_index', 'raptor_build'];
        $this->classify($writesRag, 'write', 'rag');

        // Write tools — agent category
        $writesAgent = ['submit_for_review', 'post_agent_message', 'propose_tool'];
        $this->classify($writesAgent, 'write', 'agent');

        // Write tools — genealogy category
        $writesGenealogy = [
            'generate_record_hints', 'generate_tree_hints', 'update_hint_status',
            'create_research_task', 'log_research_search',
        ];
        $this->classify($writesGenealogy, 'write', 'genealogy');

        // Write tools — search category
        $this->classify(['internet_archive_download'], 'write', 'search');

        // Destructive tools — requires confirmation
        try {
            DB::update("
                UPDATE agent_tool_registry
                SET risk_level = 'destructive', category = 'rag', requires_confirmation = 1, max_calls_per_run = 2
                WHERE name = 'rag_delete_documents'
            ");
        } catch (\Exception $e) {}

        // Per-tool call limits for write tools
        $limits = [
            'submit_for_review' => 3,
            'propose_tool' => 1,
            'fix_stalled_job' => 3,
            'adjust_job_config' => 5,
        ];

        foreach ($limits as $name => $limit) {
            try {
                DB::update("UPDATE agent_tool_registry SET max_calls_per_run = ? WHERE name = ?", [$limit, $name]);
            } catch (\Exception $e) {}
        }
    }

    private function classify(array $names, string $riskLevel, string $category): void
    {
        if (empty($names)) return;

        $placeholders = implode(',', array_fill(0, count($names), '?'));
        try {
            DB::update(
                "UPDATE agent_tool_registry SET risk_level = ?, category = ? WHERE name IN ({$placeholders})",
                array_merge([$riskLevel, $category], $names)
            );
        } catch (\Exception $e) {
            // Table or columns may not exist yet
        }
    }

    public function down(): void
    {
        try {
            DB::update("UPDATE agent_tool_registry SET risk_level = 'read', category = NULL, requires_confirmation = 0, max_calls_per_run = NULL");
        } catch (\Exception $e) {}
    }
};
