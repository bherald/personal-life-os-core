<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GR-1: Bi-Temporal Edges for Knowledge Graph
 *
 * Adds temporal modeling to knowledge_graph triples:
 * - Transaction time (t_expired): when edge was invalidated in the system
 * - Valid time (valid_from/valid_until): when fact was true in the real world
 * - Temporal metadata: type classification and AI confidence
 * - Supersession tracking: links invalidated edges to their replacements
 *
 * Creates edge history audit table and extends quality metrics.
 *
 * Uses raw SQL per project standards — NO Eloquent/Query Builder
 */
return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        // =========================================================================
        // Add bi-temporal columns to knowledge_graph
        // =========================================================================

        // Transaction time: NULL = active (not expired)
        DB::connection($this->connection)->statement("
            ALTER TABLE knowledge_graph
            ADD COLUMN IF NOT EXISTS t_expired TIMESTAMP DEFAULT NULL
        ");

        // Valid time: when fact was true in real world
        DB::connection($this->connection)->statement("
            ALTER TABLE knowledge_graph
            ADD COLUMN IF NOT EXISTS valid_from TIMESTAMP DEFAULT NULL
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE knowledge_graph
            ADD COLUMN IF NOT EXISTS valid_until TIMESTAMP DEFAULT NULL
        ");

        // Temporal classification
        DB::connection($this->connection)->statement("
            ALTER TABLE knowledge_graph
            ADD COLUMN IF NOT EXISTS temporal_type VARCHAR(20) DEFAULT 'unknown'
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE knowledge_graph
            ADD CONSTRAINT chk_kg_temporal_type
            CHECK (temporal_type IN ('ongoing', 'point_in_time', 'period', 'unknown'))
        ");

        // AI confidence in extracted temporal data
        DB::connection($this->connection)->statement("
            ALTER TABLE knowledge_graph
            ADD COLUMN IF NOT EXISTS temporal_confidence DECIMAL(4,3) DEFAULT NULL
        ");

        // Link to newer contradicting triple
        DB::connection($this->connection)->statement("
            ALTER TABLE knowledge_graph
            ADD COLUMN IF NOT EXISTS superseded_by BIGINT DEFAULT NULL
                REFERENCES knowledge_graph(id) ON DELETE SET NULL
        ");

        // =========================================================================
        // Indexes for temporal queries
        // =========================================================================

        // Partial index: active edges only (covers all BFS/expansion queries)
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_kg_active
            ON knowledge_graph (subject_entity_id, object_entity_id)
            WHERE t_expired IS NULL
        ");

        // Valid time range queries
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_kg_valid_range
            ON knowledge_graph (valid_from, valid_until)
            WHERE valid_from IS NOT NULL
        ");

        // Expired edges lookup
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_kg_expired
            ON knowledge_graph (t_expired)
            WHERE t_expired IS NOT NULL
        ");

        // =========================================================================
        // Table: knowledge_graph_edge_history — Audit trail for edge changes
        // =========================================================================
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS knowledge_graph_edge_history (
                id BIGSERIAL PRIMARY KEY,
                triple_id BIGINT NOT NULL REFERENCES knowledge_graph(id) ON DELETE CASCADE,
                action VARCHAR(20) NOT NULL,
                old_values JSONB DEFAULT NULL,
                reason TEXT,
                caused_by_triple_id BIGINT REFERENCES knowledge_graph(id) ON DELETE SET NULL,
                actor VARCHAR(50) DEFAULT 'system',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT chk_edge_history_action
                CHECK (action IN ('created', 'invalidated', 'superseded', 'temporal_updated', 'restored'))
            )
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_edge_history_triple
            ON knowledge_graph_edge_history (triple_id, created_at ASC)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_edge_history_caused_by
            ON knowledge_graph_edge_history (caused_by_triple_id)
            WHERE caused_by_triple_id IS NOT NULL
        ");

        // =========================================================================
        // Extend kg_quality_runs with temporal metrics
        // =========================================================================
        DB::connection($this->connection)->statement("
            ALTER TABLE kg_quality_runs
            ADD COLUMN IF NOT EXISTS temporal_coverage DECIMAL(5,4) DEFAULT NULL
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE kg_quality_runs
            ADD COLUMN IF NOT EXISTS stale_valid_time_count INTEGER DEFAULT NULL
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE kg_quality_runs
            ADD COLUMN IF NOT EXISTS invalidation_rate DECIMAL(5,4) DEFAULT NULL
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE kg_quality_runs
            ADD COLUMN IF NOT EXISTS active_triple_count INTEGER DEFAULT NULL
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE kg_quality_runs
            ADD COLUMN IF NOT EXISTS expired_triple_count INTEGER DEFAULT NULL
        ");

        // =========================================================================
        // Comments
        // =========================================================================
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN knowledge_graph.t_expired IS 'Transaction time: when edge was invalidated (NULL = active)'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN knowledge_graph.valid_from IS 'Valid time start: when fact became true in real world (NULL = unknown)'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN knowledge_graph.valid_until IS 'Valid time end: when fact stopped being true (NULL = ongoing/unknown)'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN knowledge_graph.temporal_type IS 'Temporal classification: ongoing, point_in_time, period, unknown'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN knowledge_graph.temporal_confidence IS 'AI confidence 0-1 in extracted temporal data'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN knowledge_graph.superseded_by IS 'ID of newer triple that replaced this one'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON TABLE knowledge_graph_edge_history IS 'Audit trail for knowledge graph edge lifecycle changes (GR-1 bi-temporal)'
        ");
    }

    public function down(): void
    {
        // Drop history table
        DB::connection($this->connection)->statement("
            DROP TABLE IF EXISTS knowledge_graph_edge_history
        ");

        // Drop indexes
        DB::connection($this->connection)->statement("DROP INDEX IF EXISTS idx_kg_active");
        DB::connection($this->connection)->statement("DROP INDEX IF EXISTS idx_kg_valid_range");
        DB::connection($this->connection)->statement("DROP INDEX IF EXISTS idx_kg_expired");

        // Drop constraint
        DB::connection($this->connection)->statement("
            ALTER TABLE knowledge_graph DROP CONSTRAINT IF EXISTS chk_kg_temporal_type
        ");

        // Drop columns from knowledge_graph
        DB::connection($this->connection)->statement("
            ALTER TABLE knowledge_graph
            DROP COLUMN IF EXISTS t_expired,
            DROP COLUMN IF EXISTS valid_from,
            DROP COLUMN IF EXISTS valid_until,
            DROP COLUMN IF EXISTS temporal_type,
            DROP COLUMN IF EXISTS temporal_confidence,
            DROP COLUMN IF EXISTS superseded_by
        ");

        // Drop columns from kg_quality_runs
        DB::connection($this->connection)->statement("
            ALTER TABLE kg_quality_runs
            DROP COLUMN IF EXISTS temporal_coverage,
            DROP COLUMN IF EXISTS stale_valid_time_count,
            DROP COLUMN IF EXISTS invalidation_rate,
            DROP COLUMN IF EXISTS active_triple_count,
            DROP COLUMN IF EXISTS expired_triple_count
        ");
    }
};
