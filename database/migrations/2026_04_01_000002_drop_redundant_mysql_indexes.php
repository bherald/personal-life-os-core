<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * INF-16: Drop 75 redundant single-column MySQL indexes.
 *
 * Each is a single-column index (col) that is redundant with a composite
 * index (col, other_col) due to MySQL's leftmost prefix rule. The composite
 * index serves both single-column and multi-column lookups.
 *
 * ~1GB overhead, slows every INSERT/UPDATE on affected tables.
 * Identified 2026-03-29 during DBA audit (282 raw pairs = 75 unique).
 */
return new class extends Migration
{
    private array $redundantIndexes = [
        ['agent_benchmarks', 'agent_benchmarks_run_id_index'],
        ['agent_benchmarks', 'agent_benchmarks_task_key_index'],
        ['agent_benchmarks', 'run_id'],       // dev variant of above
        ['agent_benchmarks', 'task_key'],      // dev variant of above
        ['agent_episode_summaries', 'idx_aes_agent'],
        ['agent_handoff_agents', 'agent_handoff_agents_is_active_index'],
        ['agent_handoff_routing_rules', 'agent_handoff_routing_rules_is_active_index'],
        ['agent_handoffs', 'agent_handoffs_created_at_index'],
        ['agent_handoffs', 'agent_handoffs_source_agent_id_index'],
        ['agent_handoffs', 'agent_handoffs_target_agent_id_index'],
        ['agent_sessions', 'agent_sessions_user_id_index'],
        ['dead_letter_queue', 'dead_letter_queue_job_type_index'],
        ['distributed_agents', 'distributed_agents_status_index'],
        ['distributed_tasks', 'distributed_tasks_status_index'],
        ['email_reply_drafts', 'email_reply_drafts_status_index'],
        ['email_suggested_actions', 'email_suggested_actions_status_index'],
        ['email_suggested_actions', 'email_suggested_actions_type_index'],
        ['evidence_correlations', 'idx_citation1'],
        ['evidence_correlations', 'idx_person'],
        ['evidence_correlations', 'idx_tree'],
        ['fan_cooccurrences', 'idx_person_id'],
        ['file_bundle_members', 'idx_bundle_members_bundle'],
        ['file_collection_items', 'idx_collection_items_collection'],
        ['file_registry_faces', 'idx_face_file'],
        ['file_registry_faces', 'idx_face_person_name'],
        ['file_registry_faces', 'idx_frf_cluster_id'],
        ['file_registry_similar_images', 'file_registry_similar_images_file_id_a_index'],
        ['file_registry_similar_videos', 'file_registry_similar_videos_video_hash_id_1_index'],
        ['file_versions', 'idx_versions_file'],
        ['genealogy_citations', 'idx_person'],
        ['genealogy_dna_kits', 'idx_person_id'],
        ['genealogy_dna_matches', 'idx_kit_id'],
        ['genealogy_dna_segments', 'idx_chromosome'],
        ['genealogy_dna_triangulation', 'idx_kit_id'],
        ['genealogy_dna_triangulation_groups', 'idx_kit'],
        ['genealogy_duplicate_pairs', 'idx_tree'],
        ['genealogy_events', 'idx_person'],
        ['genealogy_external_connections', 'idx_tree'],
        ['genealogy_external_records', 'idx_service'],
        ['genealogy_face_match_queue', 'genealogy_face_match_queue_media_id_index'],
        ['genealogy_face_match_queue', 'genealogy_face_match_queue_tree_id_index'],
        ['genealogy_fan_members', 'idx_cluster_id'],
        ['genealogy_name_variations', 'idx_tree'],
        ['genealogy_newspaper_clippings', 'idx_source'],
        ['genealogy_persons', 'idx_surname'],
        ['genealogy_research_fact_links', 'idx_grfl_fact'],
        ['genealogy_research_fact_links', 'idx_grfl_person'],
        ['genealogy_residences', 'idx_person'],
        ['genealogy_schema_extensions', 'idx_schema_ext_tree'],
        ['genealogy_search_coverage', 'idx_person_id'],
        ['genealogy_source_conflicts', 'idx_person_id'],
        ['guardrail_confirmations', 'guardrail_confirmations_status_index'],
        ['guardrail_events', 'guardrail_events_created_at_index'],
        ['guardrail_events', 'guardrail_events_operation_index'],
        ['guardrail_rules', 'guardrail_rules_is_active_index'],
        ['human_review_tasks', 'human_review_tasks_status_index'],
        ['human_review_tasks', 'human_review_tasks_task_type_index'],
        ['joplin_attachment_index', 'idx_note_id'],
        ['log_analysis_snapshots', 'log_analysis_snapshots_scanned_at_index'],
        ['node_execution_inputs', 'node_execution_id'],
        ['ollama_models', 'ollama_models_instance_id_index'],
        ['rss_feed_health', 'rss_feed_health_status_index'],
        ['skill_versions', 'idx_skill_name'],
        ['system_alerts', 'system_alerts_alert_type_index'],
        ['system_alerts', 'system_alerts_fingerprint_index'],
        ['system_alerts', 'system_alerts_triggered_at_index'],
        ['system_configs', 'system_configs_section_index'],
        ['system_errors', 'idx_system_errors_occurred_at'],
        ['system_errors', 'idx_system_errors_resolved_at'],
        ['system_errors', 'system_errors_occurred_at_index'],
        ['system_errors', 'system_errors_resolved_at_index'],
        ['system_health_snapshots', 'idx_health_snapshots_status'],
        ['system_health_snapshots', 'system_health_snapshots_health_status_index'],
        ['workflow_diagnostics', 'idx_workflow_diagnostics_workflow_id'],
        ['workflow_diagnostics', 'workflow_diagnostics_workflow_id_unique'],
        ['workflows', 'idx_workflows_active'],
        ['youtube_transcripts', 'idx_video_id'],
    ];

    public function up(): void
    {
        $dropped = 0;
        $skipped = 0;

        foreach ($this->redundantIndexes as [$table, $index]) {
            try {
                $exists = DB::selectOne(
                    "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
                    [$table, $index]
                );

                if ($exists) {
                    DB::statement("DROP INDEX `{$index}` ON `{$table}`");
                    $dropped++;
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                Log::warning("INF-16: Failed to drop {$table}.{$index}", ['error' => $e->getMessage()]);
                $skipped++;
            }
        }

        Log::info("INF-16: Dropped {$dropped} redundant indexes, skipped {$skipped}");
    }

    public function down(): void
    {
        // Not reversible without storing original column definitions.
        // These indexes are provably redundant — re-adding them wastes space.
        Log::info('INF-16: Rollback is a no-op — redundant indexes should not be recreated');
    }
};
