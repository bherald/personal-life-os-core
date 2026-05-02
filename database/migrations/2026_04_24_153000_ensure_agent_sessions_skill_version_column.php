<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $column = DB::selectOne("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'agent_sessions'
              AND COLUMN_NAME = 'skill_version'
            LIMIT 1
        ");

        if ($column) {
            return;
        }

        DB::statement("
            ALTER TABLE agent_sessions
            ADD COLUMN skill_version VARCHAR(20) DEFAULT NULL
            COMMENT 'SKILL.md version used for this session'
            AFTER agent_name
        ");
    }

    public function down(): void
    {
        $column = DB::selectOne("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'agent_sessions'
              AND COLUMN_NAME = 'skill_version'
            LIMIT 1
        ");

        if (! $column) {
            return;
        }

        DB::statement('ALTER TABLE agent_sessions DROP COLUMN skill_version');
    }
};
