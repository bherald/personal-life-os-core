<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Create agent_episode_summaries table (AG-2: Episodic Memory).
 *
 * Run-level narrative summaries distilled from agent_episodes after each run.
 * One embedding per summary stored in pgvector for semantic recall.
 * Bridges to agent_episode_embeddings (PostgreSQL) by summary id.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS agent_episode_summaries (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                agent_id VARCHAR(100) NOT NULL,
                session_id VARCHAR(100) NOT NULL,
                task VARCHAR(500) NOT NULL,
                summary TEXT NOT NULL,
                outcome ENUM('success','failure','partial','error') NOT NULL DEFAULT 'success',
                importance DECIMAL(3,2) NOT NULL DEFAULT 0.50,
                tools_used JSON NULL,
                tool_count SMALLINT UNSIGNED DEFAULT 0,
                tokens_used INT UNSIGNED DEFAULT 0,
                duration_ms INT UNSIGNED DEFAULT 0,
                episode_count SMALLINT UNSIGNED DEFAULT 0,
                notes TEXT NULL,
                is_archived TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_aes_agent (agent_id),
                INDEX idx_aes_session (session_id),
                INDEX idx_aes_outcome (outcome),
                INDEX idx_aes_agent_created (agent_id, created_at),
                INDEX idx_aes_importance (importance DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Register episodic memory tools in agent_tool_registry
        $tools = [
            [
                'name' => 'recall_episodes',
                'service_class' => 'App\\Services\\AgentEpisodicMemoryService',
                'method' => 'recallEpisodes',
                'description' => 'Search episodic memory for past run experiences relevant to the current task. Returns narrative summaries of previous runs ranked by relevance, importance, and recency.',
                'parameters' => json_encode([
                    'query' => ['type' => 'string', 'required' => true, 'description' => 'Description of current task or situation to find relevant past experiences for'],
                    'limit' => ['type' => 'integer', 'required' => false, 'description' => 'Maximum results to return (default: 3)'],
                ]),
                'returns_description' => 'List of relevant past run summaries with similarity scores and outcomes',
                'risk_level' => 'read',
                'category' => 'memory',
            ],
            [
                'name' => 'save_episode_note',
                'service_class' => 'App\\Services\\AgentEpisodicMemoryService',
                'method' => 'saveEpisodeNote',
                'description' => 'Add a note to the current run\'s episodic memory summary. Use to annotate observations, decisions, or anomalies worth remembering for future runs.',
                'parameters' => json_encode([
                    'note' => ['type' => 'string', 'required' => true, 'description' => 'The observation or note to attach to this run\'s episodic memory'],
                ]),
                'returns_description' => 'Confirmation that the note was saved',
                'risk_level' => 'write',
                'category' => 'memory',
            ],
        ];

        foreach ($tools as $tool) {
            $existing = DB::select("SELECT id FROM agent_tool_registry WHERE name = ?", [$tool['name']]);
            if (empty($existing)) {
                DB::insert("
                    INSERT INTO agent_tool_registry
                        (name, service_class, method, description, parameters, returns_description,
                         risk_level, category, enabled, source, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'config', NOW(), NOW())
                ", [
                    $tool['name'],
                    $tool['service_class'],
                    $tool['method'],
                    $tool['description'],
                    $tool['parameters'],
                    $tool['returns_description'],
                    $tool['risk_level'],
                    $tool['category'],
                ]);
            }
        }

        // Register weekly archival scheduled job
        $jobExists = DB::select("SELECT id FROM scheduled_jobs WHERE name = ?", ['episodic_memory_archive']);
        if (empty($jobExists)) {
            DB::insert("
                INSERT INTO scheduled_jobs (name, command, cron_expression, category, description, enabled, timeout_minutes, without_overlapping, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                'episodic_memory_archive',
                'episodic:memory --archive',
                '0 4 * * 0',  // Sunday 4 AM
                'Maintenance',
                'Archive old low-importance episodic memory summaries (90d retention, importance < 0.60)',
                1,
                10,
                1,
            ]);
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM scheduled_jobs WHERE name = ?", ['episodic_memory_archive']);

        DB::delete("DELETE FROM agent_tool_registry WHERE name IN (?, ?)", [
            'recall_episodes', 'save_episode_note',
        ]);

        DB::statement("DROP TABLE IF EXISTS agent_episode_summaries");
    }
};
