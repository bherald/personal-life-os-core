<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AG-3: Memory Evolution + Bidirectional Links
 *
 * Stores bidirectional links between agent memory records.
 * Created by AgentMemoryEvolutionService after each episodic distillation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE agent_memory_links (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                agent_id    VARCHAR(100)    NOT NULL,
                source_type ENUM('episodic','procedural') NOT NULL DEFAULT 'episodic',
                source_id   BIGINT UNSIGNED NOT NULL,
                target_type ENUM('episodic','procedural') NOT NULL DEFAULT 'episodic',
                target_id   BIGINT UNSIGNED NOT NULL,
                link_type   ENUM('related','extends','evolved_from') NOT NULL DEFAULT 'related',
                strength    DECIMAL(3,2)    NOT NULL DEFAULT 0.50,
                created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_source       (source_type, source_id),
                KEY idx_target       (target_type, target_id),
                KEY idx_agent_source (agent_id, source_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS agent_memory_links");
    }
};
