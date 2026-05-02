<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Track every version of every SKILL.md file
        DB::statement("
            CREATE TABLE IF NOT EXISTS skill_versions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                skill_name VARCHAR(100) NOT NULL,
                version VARCHAR(20) NOT NULL COMMENT 'Semver from frontmatter',
                content_hash VARCHAR(64) NOT NULL COMMENT 'SHA-256 of full SKILL.md content',
                frontmatter JSON NOT NULL COMMENT 'Parsed frontmatter snapshot',
                body_text MEDIUMTEXT NOT NULL COMMENT 'Full markdown body snapshot',
                full_content MEDIUMTEXT NOT NULL COMMENT 'Complete SKILL.md file content for rollback',
                change_summary VARCHAR(500) DEFAULT NULL COMMENT 'What changed from previous version',
                changed_by VARCHAR(100) DEFAULT 'system' COMMENT 'Who triggered the version: system, agent, human',
                is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Currently deployed version',
                tools_count INT UNSIGNED NOT NULL DEFAULT 0,
                tool_phases_count INT UNSIGNED NOT NULL DEFAULT 0,
                permissions JSON DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_skill_name (skill_name),
                INDEX idx_skill_active (skill_name, is_active),
                INDEX idx_content_hash (content_hash),
                INDEX idx_created (created_at),
                UNIQUE KEY uk_skill_hash (skill_name, content_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Add skill_version tracking to agent_sessions
        DB::statement("
            ALTER TABLE agent_sessions
            ADD COLUMN skill_version VARCHAR(20) DEFAULT NULL COMMENT 'SKILL.md version used for this session'
            AFTER agent_name
        ");

        // Add skill_version tracking to agent_episodes
        DB::statement("
            ALTER TABLE agent_episodes
            ADD COLUMN skill_version VARCHAR(20) DEFAULT NULL COMMENT 'SKILL.md version active during this episode'
            AFTER agent_id
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE agent_episodes DROP COLUMN IF EXISTS skill_version");
        DB::statement("ALTER TABLE agent_sessions DROP COLUMN IF EXISTS skill_version");
        DB::statement("DROP TABLE IF EXISTS skill_versions");
    }
};
