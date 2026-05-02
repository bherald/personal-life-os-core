<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint Items 6+7:
 * - rag_propositions (PostgreSQL) — atomic fact storage for proposition-based indexing
 * - agent_semantic_memory + agent_semantic_fact_sources (MySQL) — cross-agent fact store
 */
return new class extends Migration
{
    public function up(): void
    {
        // Item 6: Proposition-based indexing table (PostgreSQL)
        DB::connection('pgsql_rag')->statement("
            CREATE TABLE IF NOT EXISTS rag_propositions (
                id BIGSERIAL PRIMARY KEY,
                document_id INT NOT NULL,
                chunk_index INT NOT NULL DEFAULT 0,
                proposition_text TEXT NOT NULL,
                subject VARCHAR(255),
                predicate VARCHAR(255),
                object_value VARCHAR(500),
                confidence DECIMAL(5,4) DEFAULT 0.5,
                extraction_method VARCHAR(20) DEFAULT 'heuristic',
                embedding VECTOR(768),
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_rag_propositions_doc ON rag_propositions (document_id)
        ");

        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_rag_propositions_subject ON rag_propositions (subject)
        ");

        // Item 7: Semantic memory tables (MySQL)
        DB::statement("
            CREATE TABLE IF NOT EXISTS agent_semantic_memory (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                entity_type VARCHAR(50) NOT NULL,
                entity_id INT UNSIGNED NOT NULL,
                fact_type VARCHAR(50) NOT NULL,
                fact_key VARCHAR(100) NOT NULL,
                fact_value TEXT NOT NULL,
                confidence DECIMAL(5,4) NOT NULL DEFAULT 0.5,
                consensus_status ENUM('agreed','disputed','evolving') NOT NULL DEFAULT 'agreed',
                source_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_challenged_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_entity (entity_type, entity_id),
                INDEX idx_fact_key (fact_key),
                INDEX idx_consensus (consensus_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS agent_semantic_fact_sources (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                memory_id BIGINT UNSIGNED NOT NULL,
                source_type VARCHAR(50) NOT NULL,
                source_id INT UNSIGNED NULL,
                confidence DECIMAL(5,4) DEFAULT 0.5,
                agent_id VARCHAR(100) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_memory (memory_id),
                INDEX idx_agent (agent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        DB::connection('pgsql_rag')->statement("DROP TABLE IF EXISTS rag_propositions");
        DB::statement("DROP TABLE IF EXISTS agent_semantic_fact_sources");
        DB::statement("DROP TABLE IF EXISTS agent_semantic_memory");
    }
};
