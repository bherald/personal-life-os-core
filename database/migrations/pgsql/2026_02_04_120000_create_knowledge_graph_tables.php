<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Knowledge Graph Tables for Entity-Relationship Storage
 *
 * Implements a semantic knowledge graph with:
 * - Subject-predicate-object triples
 * - Entity canonicalization with aliases
 * - Confidence scoring and provenance tracking
 * - Efficient graph traversal queries
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        // =========================================================================
        // Table: knowledge_graph_entities - Canonical entity definitions
        // =========================================================================
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS knowledge_graph_entities (
                id BIGSERIAL PRIMARY KEY,
                canonical_name TEXT NOT NULL,
                entity_type VARCHAR(50) NOT NULL,
                aliases JSONB DEFAULT '[]'::jsonb,
                properties JSONB DEFAULT '{}'::jsonb,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT chk_entity_type CHECK (entity_type IN (
                    'person', 'organization', 'location', 'concept', 'event',
                    'document', 'date', 'product', 'technology', 'other'
                ))
            )
        ");

        // Unique index on canonical name + type for deduplication
        DB::connection($this->connection)->statement("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_kg_entities_canonical
            ON knowledge_graph_entities (LOWER(canonical_name), entity_type)
        ");

        // GIN index for alias search
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_kg_entities_aliases
            ON knowledge_graph_entities USING GIN (aliases)
        ");

        // Index for type filtering
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_kg_entities_type
            ON knowledge_graph_entities (entity_type)
        ");

        // Full-text search on canonical name
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_kg_entities_name_fts
            ON knowledge_graph_entities USING GIN (to_tsvector('english', canonical_name))
        ");

        // =========================================================================
        // Table: knowledge_graph - Subject-predicate-object triples
        // =========================================================================
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS knowledge_graph (
                id BIGSERIAL PRIMARY KEY,
                source_document_id BIGINT REFERENCES rag_documents(id) ON DELETE SET NULL,
                subject TEXT NOT NULL,
                subject_type VARCHAR(50) NOT NULL,
                subject_entity_id BIGINT REFERENCES knowledge_graph_entities(id) ON DELETE SET NULL,
                predicate VARCHAR(100) NOT NULL,
                object TEXT NOT NULL,
                object_type VARCHAR(50) NOT NULL,
                object_entity_id BIGINT REFERENCES knowledge_graph_entities(id) ON DELETE SET NULL,
                confidence DECIMAL(4,3) DEFAULT 1.0,
                extracted_from TEXT,
                metadata JSONB DEFAULT '{}'::jsonb,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                CONSTRAINT chk_confidence CHECK (confidence >= 0 AND confidence <= 1),
                CONSTRAINT chk_subject_type CHECK (subject_type IN (
                    'person', 'organization', 'location', 'concept', 'event',
                    'document', 'date', 'product', 'technology', 'other'
                )),
                CONSTRAINT chk_object_type CHECK (object_type IN (
                    'person', 'organization', 'location', 'concept', 'event',
                    'document', 'date', 'product', 'technology', 'other'
                ))
            )
        ");

        // Index for finding triples by subject
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_kg_subject
            ON knowledge_graph (LOWER(subject))
        ");

        // Index for finding triples by object
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_kg_object
            ON knowledge_graph (LOWER(object))
        ");

        // Index for relationship queries
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_kg_predicate
            ON knowledge_graph (predicate)
        ");

        // Composite index for entity graph traversal
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_kg_subject_entity
            ON knowledge_graph (subject_entity_id)
            WHERE subject_entity_id IS NOT NULL
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_kg_object_entity
            ON knowledge_graph (object_entity_id)
            WHERE object_entity_id IS NOT NULL
        ");

        // Index for source document queries
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_kg_source_doc
            ON knowledge_graph (source_document_id)
            WHERE source_document_id IS NOT NULL
        ");

        // Full-text search on subject and object
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_kg_subject_fts
            ON knowledge_graph USING GIN (to_tsvector('english', subject))
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_kg_object_fts
            ON knowledge_graph USING GIN (to_tsvector('english', object))
        ");

        // Composite index for common queries
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_kg_triple_lookup
            ON knowledge_graph (LOWER(subject), predicate, LOWER(object))
        ");

        // Index for confidence filtering
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_kg_confidence
            ON knowledge_graph (confidence DESC)
            WHERE confidence >= 0.7
        ");

        // Add table comments
        DB::connection($this->connection)->statement("
            COMMENT ON TABLE knowledge_graph_entities IS 'Canonical entity definitions with aliases for knowledge graph deduplication'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN knowledge_graph_entities.aliases IS 'Array of alternative names/spellings for this entity'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN knowledge_graph_entities.properties IS 'Additional attributes like birth_date, headquarters, etc.'
        ");

        DB::connection($this->connection)->statement("
            COMMENT ON TABLE knowledge_graph IS 'Subject-predicate-object triples extracted from documents'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN knowledge_graph.predicate IS 'Relationship type: works_at, located_in, related_to, founded_by, etc.'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN knowledge_graph.confidence IS 'AI extraction confidence score 0-1'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON COLUMN knowledge_graph.extracted_from IS 'Source text snippet the triple was extracted from'
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS knowledge_graph");
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS knowledge_graph_entities");
    }
};
