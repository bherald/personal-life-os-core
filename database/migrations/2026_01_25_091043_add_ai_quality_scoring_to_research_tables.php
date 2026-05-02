<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds AI quality scoring and human review fields to research tables.
     * These are in PostgreSQL (pgsql_rag connection).
     */
    public function up(): void
    {
        // Add columns to research_results (Topics system)
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE research_results
            ADD COLUMN IF NOT EXISTS ai_quality_score DECIMAL(3,2) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS ai_has_findings BOOLEAN DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS ai_recommendation VARCHAR(20) DEFAULT NULL
        ");

        // Comments must be separate statements
        DB::connection('pgsql_rag')->statement("COMMENT ON COLUMN research_results.ai_quality_score IS 'AI-assigned quality score 0.0-1.0'");
        DB::connection('pgsql_rag')->statement("COMMENT ON COLUMN research_results.ai_has_findings IS 'Whether AI found actionable information'");
        DB::connection('pgsql_rag')->statement("COMMENT ON COLUMN research_results.ai_recommendation IS 'index, reject, review, or needs_research'");

        // Add columns to research_facts (Missions system)
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE research_facts
            ADD COLUMN IF NOT EXISTS ai_quality_score DECIMAL(3,2) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS ai_recommendation VARCHAR(20) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS human_entered BOOLEAN DEFAULT FALSE,
            ADD COLUMN IF NOT EXISTS human_entered_by VARCHAR(100) DEFAULT NULL
        ");

        DB::connection('pgsql_rag')->statement("COMMENT ON COLUMN research_facts.ai_quality_score IS 'AI-assigned quality score 0.0-1.0'");
        DB::connection('pgsql_rag')->statement("COMMENT ON COLUMN research_facts.ai_recommendation IS 'index, reject, review, or needs_research'");
        DB::connection('pgsql_rag')->statement("COMMENT ON COLUMN research_facts.human_entered IS 'True if manually entered by human'");
        DB::connection('pgsql_rag')->statement("COMMENT ON COLUMN research_facts.human_entered_by IS 'Username who entered the fact'");

        // Add columns to research_missions for recurring and RAG control
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE research_missions
            ADD COLUMN IF NOT EXISTS auto_index_to_rag BOOLEAN DEFAULT FALSE,
            ADD COLUMN IF NOT EXISTS recurrence_schedule VARCHAR(50) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS last_refresh_at TIMESTAMP DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS parent_mission_id UUID DEFAULT NULL
        ");

        DB::connection('pgsql_rag')->statement("COMMENT ON COLUMN research_missions.auto_index_to_rag IS 'If true, auto-index to RAG without human approval'");
        DB::connection('pgsql_rag')->statement("COMMENT ON COLUMN research_missions.recurrence_schedule IS 'Cron or keyword like daily/weekly/monthly'");
        DB::connection('pgsql_rag')->statement("COMMENT ON COLUMN research_missions.last_refresh_at IS 'When mission was last refreshed/re-run'");
        DB::connection('pgsql_rag')->statement("COMMENT ON COLUMN research_missions.parent_mission_id IS 'Original mission if this is a refresh run'");

        // Add index for recurring missions lookup
        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_research_missions_recurrence
            ON research_missions(recurrence_schedule)
            WHERE recurrence_schedule IS NOT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE research_results
            DROP COLUMN IF EXISTS ai_quality_score,
            DROP COLUMN IF EXISTS ai_has_findings,
            DROP COLUMN IF EXISTS ai_recommendation
        ");

        DB::connection('pgsql_rag')->statement("
            ALTER TABLE research_facts
            DROP COLUMN IF EXISTS ai_quality_score,
            DROP COLUMN IF EXISTS ai_recommendation,
            DROP COLUMN IF EXISTS human_entered,
            DROP COLUMN IF EXISTS human_entered_by
        ");

        DB::connection('pgsql_rag')->statement("
            ALTER TABLE research_missions
            DROP COLUMN IF EXISTS auto_index_to_rag,
            DROP COLUMN IF EXISTS recurrence_schedule,
            DROP COLUMN IF EXISTS last_refresh_at,
            DROP COLUMN IF EXISTS parent_mission_id
        ");

        DB::connection('pgsql_rag')->statement("
            DROP INDEX IF EXISTS idx_research_missions_recurrence
        ");
    }
};
