<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GR-11: HyperGraph Edges — N-ary relation table for multi-entity facts.
 *
 * Standard binary KG triples express pairwise relations (A → predicate → B).
 * HyperEdges capture facts involving 3+ entities simultaneously, e.g.:
 *   "John Smith married Mary Jones in Ohio in 1880"
 * which cannot be faithfully decomposed into binary triples.
 *
 * Reference: HyperGraphRAG (NeurIPS 2025)
 */
return new class extends Migration
{
    public function getConnection(): string
    {
        return 'pgsql_rag';
    }

    public function up(): void
    {
        DB::connection('pgsql_rag')->statement("
            CREATE TABLE IF NOT EXISTS knowledge_graph_hyperedges (
                id                 BIGSERIAL PRIMARY KEY,
                source_document_id BIGINT NOT NULL,
                predicate          VARCHAR(100) NOT NULL,
                participants       JSONB NOT NULL,
                raw_text           TEXT,
                confidence         NUMERIC(4,3) DEFAULT 1.000,
                metadata           JSONB,
                created_at         TIMESTAMP DEFAULT NOW(),
                updated_at         TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::connection('pgsql_rag')->statement(
            'CREATE INDEX IF NOT EXISTS idx_kg_hyperedges_document
             ON knowledge_graph_hyperedges (source_document_id)'
        );

        DB::connection('pgsql_rag')->statement(
            "CREATE INDEX IF NOT EXISTS idx_kg_hyperedges_predicate
             ON knowledge_graph_hyperedges (predicate)"
        );

        DB::connection('pgsql_rag')->statement(
            "CREATE INDEX IF NOT EXISTS idx_kg_hyperedges_participants
             ON knowledge_graph_hyperedges USING gin (participants)"
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_rag')->statement(
            'DROP TABLE IF EXISTS knowledge_graph_hyperedges'
        );
    }
};
