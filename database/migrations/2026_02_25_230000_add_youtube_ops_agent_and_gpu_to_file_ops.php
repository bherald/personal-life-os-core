<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // =====================================================================
        // 1. YOUTUBE-OPS AGENT TOOLS
        // =====================================================================

        $youtubeTools = [
            // ── ASSESS PHASE ──────────────────────────────────────────────
            [
                'name' => 'youtube_watchlater_health',
                'service_class' => 'App\\Services\\YouTubeOpsService',
                'method' => 'getWatchLaterHealth',
                'description' => 'Get Watch Later pipeline health overview — last workflow 14 run status, node-level results, 7-day run history, and scheduled job status. This is the baseline assessment tool.',
                'parameters' => '[]',
                'returns_description' => 'Array with last_run details, node_results, last_7_days stats, and scheduled_job status',
                'permissions' => '["youtube:read"]',
                'risk_level' => 'read',
                'category' => 'youtube',
            ],
            [
                'name' => 'youtube_transcript_stats',
                'service_class' => 'App\\Services\\YouTubeOpsService',
                'method' => 'getTranscriptStats',
                'description' => 'Get transcript storage statistics — total transcripts, counts by source method (showing fallback chain effectiveness), word count metrics, and 30-day daily activity.',
                'parameters' => '[]',
                'returns_description' => 'Array with total, by_language, by_source, avg/max word_count, and recent_activity',
                'permissions' => '["youtube:read"]',
                'risk_level' => 'read',
                'category' => 'youtube',
            ],
            [
                'name' => 'youtube_joplin_sync_status',
                'service_class' => 'App\\Services\\YouTubeOpsService',
                'method' => 'getJoplinSyncStatus',
                'description' => 'Get Joplin sync status for Watch Later notes — total notes, category folder counts, key points coverage (sampled), and categorization breakdown.',
                'parameters' => '[]',
                'returns_description' => 'Array with joplin_path, total_notes, category_folders, folder_names, and key_points_sample with coverage_pct',
                'permissions' => '["youtube:read"]',
                'risk_level' => 'read',
                'category' => 'youtube',
            ],
            [
                'name' => 'youtube_rag_index_status',
                'service_class' => 'App\\Services\\YouTubeOpsService',
                'method' => 'getRagIndexStatus',
                'description' => 'Get RAG index status for YouTube transcripts — indexed vs total, drift count, recent 7-day indexing activity, and coverage percentage. RAG is on PostgreSQL.',
                'parameters' => '[]',
                'returns_description' => 'Array with total_transcripts, rag_indexed, drift, recent_7d_indexed, coverage_pct',
                'permissions' => '["youtube:read"]',
                'risk_level' => 'read',
                'category' => 'youtube',
            ],
            [
                'name' => 'youtube_recent_runs',
                'service_class' => 'App\\Services\\YouTubeOpsService',
                'method' => 'getRecentRuns',
                'description' => 'Get recent workflow 14 (Watch Later) execution history — status, duration, and node-level summary for each run. Shows pipeline trend.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 10, 'description' => 'Maximum number of runs to return (default 10)'],
                ]),
                'returns_description' => 'Array with workflow_id, workflow_name, and runs array with status, timestamps, duration, and node_summary',
                'permissions' => '["youtube:read"]',
                'risk_level' => 'read',
                'category' => 'youtube',
            ],

            // ── ACT PHASE ─────────────────────────────────────────────────
            [
                'name' => 'youtube_transcript_quality_check',
                'service_class' => 'App\\Services\\YouTubeOpsService',
                'method' => 'checkTranscriptQuality',
                'description' => 'Check transcript quality for recent videos — word count analysis, source method distribution, repetition detection, gibberish scoring, filler word density, and phrase loop detection. Returns per-transcript quality score (0-1) and specific issues.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 20, 'description' => 'Number of recent transcripts to check (default 20)'],
                ]),
                'returns_description' => 'Array with checked count, avg_word_count, avg_quality_score (0-1), by_source_method, quality_issues list with type/detail, and issue_count',
                'permissions' => '["youtube:read"]',
                'risk_level' => 'read',
                'category' => 'youtube',
            ],
            [
                'name' => 'youtube_joplin_integrity_check',
                'service_class' => 'App\\Services\\YouTubeOpsService',
                'method' => 'checkJoplinIntegrity',
                'description' => 'Verify Joplin notes integrity — duplicate detection, categorized vs uncategorized counts, category folder health. Run when watchlater_health shows issues.',
                'parameters' => '[]',
                'returns_description' => 'Array with total_notes, categorized, uncategorized, category_folders, duplicates_found, duplicate_titles, and integrity status',
                'permissions' => '["youtube:read"]',
                'risk_level' => 'read',
                'category' => 'youtube',
            ],
            [
                'name' => 'youtube_retry_failed_videos',
                'service_class' => 'App\\Services\\YouTubeOpsService',
                'method' => 'retryFailedVideos',
                'description' => 'Retry transcript fetching for videos that failed in recent workflow 14 runs. LIMIT TO 3 PER RUN to avoid API quota exhaustion. Submit for review first if more than 3 need retry.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 3, 'description' => 'Maximum videos to retry (default 3)'],
                ]),
                'returns_description' => 'Array with retried count and results array with video_id, status, method/error',
                'permissions' => '["youtube:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'youtube',
                'max_calls_per_run' => 1,
            ],
            [
                'name' => 'youtube_cleanup_stale_transcripts',
                'service_class' => 'App\\Services\\YouTubeOpsService',
                'method' => 'cleanupStaleTranscripts',
                'description' => 'Clean up transcripts older than retention period. Safe operation that removes only orphaned/stale data.',
                'parameters' => json_encode([
                    'daysOld' => ['type' => 'integer', 'required' => false, 'default' => 365, 'description' => 'Delete transcripts older than this many days (default 365)'],
                ]),
                'returns_description' => 'Array with deleted count, retention_days, and message',
                'permissions' => '["youtube:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'youtube',
                'requires_confirmation' => 1,
            ],
        ];

        foreach ($youtubeTools as $tool) {
            try {
                $columns = 'name, service_class, method, description, parameters, returns_description, permissions, risk_level, category, enabled, source';
                $placeholders = '?, ?, ?, ?, ?, ?, ?, ?, ?, 1, \'config\'';
                $values = [
                    $tool['name'],
                    $tool['service_class'],
                    $tool['method'],
                    $tool['description'],
                    $tool['parameters'],
                    $tool['returns_description'],
                    $tool['permissions'],
                    $tool['risk_level'],
                    $tool['category'],
                ];

                if (isset($tool['requires_confirmation'])) {
                    $columns .= ', requires_confirmation';
                    $placeholders .= ', ?';
                    $values[] = $tool['requires_confirmation'];
                }

                if (isset($tool['max_calls_per_run'])) {
                    $columns .= ', max_calls_per_run';
                    $placeholders .= ', ?';
                    $values[] = $tool['max_calls_per_run'];
                }

                DB::insert("
                    INSERT INTO agent_tool_registry ({$columns})
                    VALUES ({$placeholders})
                ", $values);
            } catch (\Exception $e) {
                // Skip duplicates (idempotent)
            }
        }

        // =====================================================================
        // 2. YOUTUBE-OPS SCHEDULED JOB
        // =====================================================================

        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'youtube_ops_agent'");
        if (!$exists) {
            DB::insert("
                INSERT INTO scheduled_jobs
                (name, description, command, cron_expression, job_type, enabled, category,
                 timeout_minutes, run_in_background, without_overlapping, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                'youtube_ops_agent',
                'YouTube Watch Later pipeline health: transcript acquisition, Joplin sync, key points, RAG indexing',
                'youtube-ops',
                '*/30 * * * *',
                'agent_task',
                1,
                'Agent',
                10,
                1,
                1,
                json_encode(['notify' => true]),
            ]);
        }

        // =====================================================================
        // 3. DISABLE WORKFLOWS 13 (daily_digest) AND 15 (manual)
        // =====================================================================

        $hasWorkflows = DB::select("SHOW TABLES LIKE 'workflows'");
        if ($hasWorkflows) {
            DB::update("UPDATE workflows SET active = 0 WHERE id IN (13, 15)");
        }
        DB::update("
            UPDATE scheduled_jobs SET enabled = 0
            WHERE (command LIKE '%workflow:run 13%' OR command LIKE '%workflow:run 15%'
                   OR name LIKE '%youtube_daily_digest%' OR name LIKE '%youtube_manual%')
        ");

        // =====================================================================
        // 4. ADD GPU CONTENTION TOOL TO FILE-OPS
        // =====================================================================

        try {
            DB::insert("
                INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, returns_description,
                 permissions, risk_level, category, enabled, source)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'config')
            ", [
                'file_gpu_contention_status',
                'App\\Services\\AIOperationsService',
                'getGPUStatus',
                'Get GPU utilization and lock status — GPU/memory utilization percentages, temperature, and whether Ollama or Whisper locks are held. Use to correlate enrichment backlog stalls with GPU contention.',
                '[]',
                'Array with available flag, gpu_utilization_pct, memory_utilization_pct, memory_used_mb, memory_total_mb, temperature_c',
                '["file:read", "system:read"]',
                'read',
                'file',
            ]);
        } catch (\Exception $e) {
            // Skip if already exists
        }
    }

    public function down(): void
    {
        // Remove youtube-ops tools
        $youtubeToolNames = [
            'youtube_watchlater_health', 'youtube_transcript_stats', 'youtube_joplin_sync_status',
            'youtube_rag_index_status', 'youtube_recent_runs', 'youtube_transcript_quality_check',
            'youtube_joplin_integrity_check', 'youtube_retry_failed_videos', 'youtube_cleanup_stale_transcripts',
        ];
        $placeholders = implode(',', array_fill(0, count($youtubeToolNames), '?'));
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ($placeholders)", $youtubeToolNames);

        // Remove youtube-ops scheduled job
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'youtube_ops_agent'");

        // Re-enable workflows 13 and 15
        DB::update("UPDATE workflows SET active = 1 WHERE id IN (13, 15)");
        DB::update("
            UPDATE scheduled_jobs SET enabled = 1
            WHERE (command LIKE '%workflow:run 13%' OR command LIKE '%workflow:run 15%'
                   OR name LIKE '%youtube_daily_digest%' OR name LIKE '%youtube_manual%')
        ");

        // Remove GPU tool from file-ops
        DB::delete("DELETE FROM agent_tool_registry WHERE name = 'file_gpu_contention_status'");
    }
};
