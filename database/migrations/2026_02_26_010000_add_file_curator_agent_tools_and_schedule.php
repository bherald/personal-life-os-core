<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ─── 1. Shared agent tools (used by multiple agents) ───
        $sharedTools = [
            [
                'name' => 'submit_for_review',
                'service_class' => 'App\\Services\\AgentLoopService',
                'method' => 'submitForReview',
                'description' => 'Submit a finding, suggestion, or alert for human review. Items appear in the Knowledge Hub Review tab.',
                'parameters' => json_encode([
                    'agent_id' => ['type' => 'string', 'required' => true, 'description' => 'The agent submitting the review'],
                    'review_type' => ['type' => 'string', 'required' => false, 'default' => 'finding', 'description' => 'Type: finding, suggestion, alert, proposal'],
                    'title' => ['type' => 'string', 'required' => true, 'description' => 'Short title for the review item'],
                    'summary' => ['type' => 'string', 'required' => true, 'description' => 'Detailed summary of the finding'],
                    'confidence' => ['type' => 'number', 'required' => false, 'description' => 'Confidence score 0.0-1.0. Items >= 0.9 are auto-approved.'],
                    'priority' => ['type' => 'integer', 'required' => false, 'default' => 0, 'description' => 'Priority: 0=normal, 1=high, 2=urgent'],
                ]),
                'returns_description' => 'Array with success, review_id, status (pending or auto-approved)',
                'permissions' => '["system:write"]',
                'risk_level' => 'write',
                'category' => 'agent',
                'max_calls_per_run' => 3,
            ],
            [
                'name' => 'post_agent_message',
                'service_class' => 'App\\Services\\AgentLoopService',
                'method' => 'postAgentMessage',
                'description' => 'Post a message to peer agents via the inter-agent message bus. Messages can target a specific agent or broadcast to all (*). Messages expire after TTL.',
                'parameters' => json_encode([
                    'from_agent' => ['type' => 'string', 'required' => true, 'description' => 'The sending agent ID'],
                    'to_agent' => ['type' => 'string', 'required' => false, 'default' => '*', 'description' => 'Target agent ID or * for broadcast'],
                    'message_type' => ['type' => 'string', 'required' => false, 'default' => 'info', 'description' => 'Type: info, alert, finding, request'],
                    'subject' => ['type' => 'string', 'required' => true, 'description' => 'Message subject line'],
                    'body' => ['type' => 'string', 'required' => true, 'description' => 'Message body text'],
                    'priority' => ['type' => 'integer', 'required' => false, 'default' => 0, 'description' => 'Priority: 0=normal, 1=high, 2=urgent'],
                    'ttl_hours' => ['type' => 'integer', 'required' => false, 'default' => 24, 'description' => 'Hours before message expires'],
                ]),
                'returns_description' => 'Array with success, message_id, expires_at',
                'permissions' => '["system:write"]',
                'risk_level' => 'write',
                'category' => 'agent',
            ],
            [
                'name' => 'get_agent_messages',
                'service_class' => 'App\\Services\\AgentLoopService',
                'method' => 'getAgentMessages',
                'description' => 'Get recent inter-agent messages addressed to this agent or broadcast. Check at start of each run for alerts from peer agents.',
                'parameters' => json_encode([
                    'agentId' => ['type' => 'string', 'required' => false, 'description' => 'Filter to messages for this agent (default: all)'],
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 20, 'description' => 'Maximum messages to return'],
                ]),
                'returns_description' => 'Array of messages with from_agent, to_agent, message_type, subject, body, priority, created_at',
                'permissions' => '["system:read"]',
                'risk_level' => 'read',
                'category' => 'agent',
            ],
            [
                'name' => 'get_pending_reviews',
                'service_class' => 'App\\Services\\AgentLoopService',
                'method' => 'getPendingReviews',
                'description' => 'Get review items pending human decision. Check before submitting duplicates.',
                'parameters' => json_encode([
                    'agentId' => ['type' => 'string', 'required' => false, 'description' => 'Filter to reviews from this agent (default: all)'],
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 50, 'description' => 'Maximum items to return'],
                ]),
                'returns_description' => 'Array of pending review items with title, summary, priority, created_at',
                'permissions' => '["system:read"]',
                'risk_level' => 'read',
                'category' => 'agent',
            ],
        ];

        foreach ($sharedTools as $tool) {
            $exists = DB::selectOne("SELECT id FROM agent_tool_registry WHERE name = ?", [$tool['name']]);
            if (!$exists) {
                DB::insert("
                    INSERT INTO agent_tool_registry
                    (name, service_class, method, description, parameters, returns_description,
                     permissions, risk_level, category, enabled, source, max_calls_per_run, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'config', ?, NOW(), NOW())
                ", [
                    $tool['name'],
                    $tool['service_class'],
                    $tool['method'],
                    $tool['description'],
                    $tool['parameters'],
                    $tool['returns_description'],
                    $tool['permissions'],
                    $tool['risk_level'],
                    $tool['category'],
                    $tool['max_calls_per_run'] ?? null,
                ]);
            }
        }

        // ─── 2. File curator-specific tools ───
        $curatorTools = [
            [
                'name' => 'file_uncategorized_files',
                'service_class' => 'App\\Services\\FileRegistryService',
                'method' => 'getUncategorizedFiles',
                'description' => 'Get files with no AI tags, no document type, or no category assigned. These are curation gaps needing attention.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 100, 'description' => 'Maximum files to return'],
                    'extension_filter' => ['type' => 'string', 'required' => false, 'description' => 'Filter by extension (e.g., pdf, jpg)'],
                ]),
                'returns_description' => 'Array with total_uncategorized count and file list with uuid, filename, extension, path, status',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_tag_quality_report',
                'service_class' => 'App\\Services\\AIAutoTagService',
                'method' => 'getTagQualityReport',
                'description' => 'Analyze AI tag quality: tag distribution, low-confidence tags, generic/unhelpful tags, common misclassification patterns, and overall quality score.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 50, 'description' => 'Sample size for quality analysis'],
                ]),
                'returns_description' => 'Array with quality_score (0-1), tag_distribution, generic_tags, low_confidence_tags, misclassification_patterns, recommendations',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_folder_distribution',
                'service_class' => 'App\\Services\\FileRegistryService',
                'method' => 'getFolderDistribution',
                'description' => 'Analyze how files are distributed across top-level folders. Detect overstuffed folders, sparse folders, and organizational patterns.',
                'parameters' => json_encode([
                    'depth' => ['type' => 'integer', 'required' => false, 'default' => 2, 'description' => 'Folder depth to analyze (1=top-level, 2=two levels)'],
                ]),
                'returns_description' => 'Array with folder_counts (path => count), largest_folders, empty_folders, avg_files_per_folder',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_recent_ingestions',
                'service_class' => 'App\\Services\\FileRegistryService',
                'method' => 'getRecentIngestions',
                'description' => 'Get files registered since a given time period. Useful for identifying new files needing curation attention.',
                'parameters' => json_encode([
                    'hours' => ['type' => 'integer', 'required' => false, 'default' => 24, 'description' => 'Look back N hours (default 24)'],
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 100, 'description' => 'Maximum files to return'],
                ]),
                'returns_description' => 'Array with count and file list (uuid, filename, extension, path, created_at, ai_document_type)',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_duplicates_pending',
                'service_class' => 'App\\Services\\FileRegistryService',
                'method' => 'getDuplicatesStats',
                'description' => 'Get duplicate file statistics: exact duplicates (same content hash) by status. Shows pending pairs needing resolution.',
                'parameters' => '[]',
                'returns_description' => 'Array with total_duplicate_groups, by_status (pending, resolved, ignored), total_wasted_bytes',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_suggest_categories',
                'service_class' => 'App\\Services\\FileRegistryService',
                'method' => 'suggestCategories',
                'description' => 'Analyze uncategorized files and suggest document_type and category based on filename, extension, path, and existing AI tags. Returns suggestions only, does not modify files.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 50, 'description' => 'Maximum files to analyze'],
                ]),
                'returns_description' => 'Array of suggestions: [{uuid, filename, current_type, suggested_type, suggested_category, confidence, reasoning}]',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_review_ai_tags',
                'service_class' => 'App\\Services\\AIAutoTagService',
                'method' => 'reviewTagQuality',
                'description' => 'Review AI-generated tags for quality issues: generic tags (file, document, unknown), contradictory tags, low-confidence tags needing re-analysis, and misclassification patterns.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 50, 'description' => 'Number of recently tagged files to review'],
                ]),
                'returns_description' => 'Array with issues_found, generic_tag_files, contradictory_tags, low_confidence_files, re_analysis_candidates',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_tag_consistency_check',
                'service_class' => 'App\\Services\\AIAutoTagService',
                'method' => 'checkTagConsistency',
                'description' => 'Detect tag inconsistencies across the file registry: same content type tagged differently, tags that overlap or conflict, tag drift over time.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 100, 'description' => 'Number of files to sample for consistency check'],
                ]),
                'returns_description' => 'Array with consistency_score, inconsistencies [{file_uuid_a, file_uuid_b, issue, tags_a, tags_b}], tag_drift_patterns',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
            [
                'name' => 'file_duplicates_recommend',
                'service_class' => 'App\\Services\\FileRegistryService',
                'method' => 'recommendDuplicateResolutions',
                'description' => 'Analyze duplicate pairs and recommend which copy to keep based on: metadata completeness, folder location, modification dates, file quality. Recommendations only, does not delete.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 20, 'description' => 'Maximum duplicate pairs to analyze'],
                ]),
                'returns_description' => 'Array of recommendations: [{pair_id, keep_uuid, remove_uuid, reasoning, confidence}]',
                'permissions' => '["file:read"]',
                'risk_level' => 'read',
                'category' => 'file',
            ],
        ];

        foreach ($curatorTools as $tool) {
            $exists = DB::selectOne("SELECT id FROM agent_tool_registry WHERE name = ?", [$tool['name']]);
            if (!$exists) {
                DB::insert("
                    INSERT INTO agent_tool_registry
                    (name, service_class, method, description, parameters, returns_description,
                     permissions, risk_level, category, enabled, source, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'config', NOW(), NOW())
                ", [
                    $tool['name'],
                    $tool['service_class'],
                    $tool['method'],
                    $tool['description'],
                    $tool['parameters'],
                    $tool['returns_description'],
                    $tool['permissions'],
                    $tool['risk_level'],
                    $tool['category'],
                ]);
            }
        }

        // ─── 3. Scheduled job ───
        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'file_curator_agent'");
        if (!$exists) {
            DB::insert("
                INSERT INTO scheduled_jobs
                (name, description, command, cron_expression, job_type, enabled, category,
                 timeout_minutes, run_in_background, without_overlapping, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                'file_curator_agent',
                'File curator agent: tag quality, categorization coverage, tag consistency, duplicate advisory',
                'file-curator',
                '0 */4 * * *',
                'agent_task',
                1,
                'Agent',
                15,
                1,
                1,
                json_encode(['notify' => true]),
            ]);
        }
    }

    public function down(): void
    {
        // Remove curator-specific tools only (shared tools used by other agents)
        $curatorToolNames = [
            'file_uncategorized_files',
            'file_tag_quality_report',
            'file_folder_distribution',
            'file_recent_ingestions',
            'file_duplicates_pending',
            'file_suggest_categories',
            'file_review_ai_tags',
            'file_tag_consistency_check',
            'file_duplicates_recommend',
        ];

        $placeholders = implode(',', array_fill(0, count($curatorToolNames), '?'));
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ($placeholders)", $curatorToolNames);

        // Remove shared tools (only if no other agent needs them — safe to keep)
        // DB::delete("DELETE FROM agent_tool_registry WHERE name IN ('submit_for_review', 'post_agent_message', 'get_agent_messages', 'get_pending_reviews')");

        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'file_curator_agent'");
    }
};
