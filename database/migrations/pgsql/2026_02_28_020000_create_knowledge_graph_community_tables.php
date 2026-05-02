<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONNECTION = 'pgsql_rag';

    public function up(): void
    {
        $db = DB::connection(self::CONNECTION);

        // Community detection run history
        $db->statement("
            CREATE TABLE IF NOT EXISTS knowledge_graph_detection_runs (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                entity_count INT NOT NULL,
                triple_count INT NOT NULL,
                communities_detected INT NOT NULL DEFAULT 0,
                levels INT NOT NULL DEFAULT 0,
                resolution_params JSONB DEFAULT '[]'::jsonb,
                modularity_scores JSONB DEFAULT '{}'::jsonb,
                duration_ms INT,
                reports_generated INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");

        // Community assignments (output of Leiden algorithm)
        $db->statement("
            CREATE TABLE IF NOT EXISTS knowledge_graph_communities (
                id BIGSERIAL PRIMARY KEY,
                community_id INT NOT NULL,
                level INT NOT NULL DEFAULT 0,
                parent_community_id BIGINT REFERENCES knowledge_graph_communities(id) ON DELETE SET NULL,
                entity_ids JSONB NOT NULL DEFAULT '[]'::jsonb,
                edge_count INT NOT NULL DEFAULT 0,
                entity_count INT NOT NULL DEFAULT 0,
                modularity_score FLOAT,
                detection_run_id UUID REFERENCES knowledge_graph_detection_runs(id) ON DELETE CASCADE,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        $db->statement("CREATE INDEX IF NOT EXISTS idx_kgc_community_level ON knowledge_graph_communities(community_id, level)");
        $db->statement("CREATE INDEX IF NOT EXISTS idx_kgc_detection_run ON knowledge_graph_communities(detection_run_id)");
        $db->statement("CREATE INDEX IF NOT EXISTS idx_kgc_parent ON knowledge_graph_communities(parent_community_id)");
        $db->statement("CREATE INDEX IF NOT EXISTS idx_kgc_level ON knowledge_graph_communities(level)");

        // Community reports (LLM-generated summaries with embeddings)
        $db->statement("
            CREATE TABLE IF NOT EXISTS knowledge_graph_community_reports (
                id BIGSERIAL PRIMARY KEY,
                community_id BIGINT NOT NULL REFERENCES knowledge_graph_communities(id) ON DELETE CASCADE,
                level INT NOT NULL,
                title TEXT,
                summary TEXT NOT NULL,
                key_entities JSONB DEFAULT '[]'::jsonb,
                key_relationships JSONB DEFAULT '[]'::jsonb,
                themes JSONB DEFAULT '[]'::jsonb,
                rating FLOAT,
                embedding vector(768),
                token_count INT,
                detection_run_id UUID REFERENCES knowledge_graph_detection_runs(id) ON DELETE CASCADE,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        $db->statement("CREATE INDEX IF NOT EXISTS idx_kgcr_community ON knowledge_graph_community_reports(community_id)");
        $db->statement("CREATE INDEX IF NOT EXISTS idx_kgcr_level ON knowledge_graph_community_reports(level)");
        $db->statement("
            CREATE INDEX IF NOT EXISTS idx_kgcr_embedding ON knowledge_graph_community_reports
            USING hnsw (embedding vector_cosine_ops)
            WITH (m = 16, ef_construction = 64)
        ");

        // Entity-community membership (supports overlapping)
        $db->statement("
            CREATE TABLE IF NOT EXISTS knowledge_graph_entity_communities (
                id BIGSERIAL PRIMARY KEY,
                entity_id BIGINT NOT NULL REFERENCES knowledge_graph_entities(id) ON DELETE CASCADE,
                community_id BIGINT NOT NULL REFERENCES knowledge_graph_communities(id) ON DELETE CASCADE,
                membership_score FLOAT DEFAULT 1.0,
                is_bridge BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT NOW(),
                UNIQUE(entity_id, community_id)
            )
        ");

        $db->statement("CREATE INDEX IF NOT EXISTS idx_kgec_entity ON knowledge_graph_entity_communities(entity_id)");
        $db->statement("CREATE INDEX IF NOT EXISTS idx_kgec_community ON knowledge_graph_entity_communities(community_id)");
        $db->statement("CREATE INDEX IF NOT EXISTS idx_kgec_bridge ON knowledge_graph_entity_communities(is_bridge) WHERE is_bridge = TRUE");

        // Add community tracking columns to entities
        try {
            $db->statement("ALTER TABLE knowledge_graph_entities ADD COLUMN primary_community_id BIGINT REFERENCES knowledge_graph_communities(id) ON DELETE SET NULL");
        } catch (\Exception $e) {
            // Column may already exist
        }

        try {
            $db->statement("ALTER TABLE knowledge_graph_entities ADD COLUMN degree INT DEFAULT 0");
        } catch (\Exception $e) {
        }

        try {
            $db->statement("ALTER TABLE knowledge_graph_entities ADD COLUMN pagerank FLOAT DEFAULT 0.0");
        } catch (\Exception $e) {
        }

        try {
            $db->statement("ALTER TABLE knowledge_graph_entities ADD COLUMN last_community_run UUID");
        } catch (\Exception $e) {
        }

        $db->statement("CREATE INDEX IF NOT EXISTS idx_kge_community ON knowledge_graph_entities(primary_community_id)");
        $db->statement("CREATE INDEX IF NOT EXISTS idx_kge_pagerank ON knowledge_graph_entities(pagerank DESC)");
    }

    public function down(): void
    {
        $db = DB::connection(self::CONNECTION);

        // Drop indexes first
        $db->statement("DROP INDEX IF EXISTS idx_kge_pagerank");
        $db->statement("DROP INDEX IF EXISTS idx_kge_community");

        // Drop columns from entities
        try {
            $db->statement("ALTER TABLE knowledge_graph_entities DROP COLUMN IF EXISTS last_community_run");
            $db->statement("ALTER TABLE knowledge_graph_entities DROP COLUMN IF EXISTS pagerank");
            $db->statement("ALTER TABLE knowledge_graph_entities DROP COLUMN IF EXISTS degree");
            $db->statement("ALTER TABLE knowledge_graph_entities DROP COLUMN IF EXISTS primary_community_id");
        } catch (\Exception $e) {
        }

        // Drop tables in dependency order
        $db->statement("DROP TABLE IF EXISTS knowledge_graph_entity_communities CASCADE");
        $db->statement("DROP TABLE IF EXISTS knowledge_graph_community_reports CASCADE");
        $db->statement("DROP TABLE IF EXISTS knowledge_graph_communities CASCADE");
        $db->statement("DROP TABLE IF EXISTS knowledge_graph_detection_runs CASCADE");
    }
};
