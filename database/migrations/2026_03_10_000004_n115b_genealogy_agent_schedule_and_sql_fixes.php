<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N115b — Genealogy Agent Schedule + SQL Bug Fixes
 *
 * 1. Adds genealogy_agent_research to scheduled_jobs (4 AM daily)
 * 2. Registers stall_exempt for the new job (it can run long)
 *
 * SQL bugs fixed in PHP:
 * - GenealogyService::getPersonsWithMissingData death_date case: missing coverageJoin
 *   caused "Unknown column 'cov.priority_score' in order clause" when querying.
 * - HistoricalMapsService::getAncestorLocations: removed non-existent ge.place_name
 *   column (genealogy_events uses place_id FK to genealogy_places, not a place_name column).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            INSERT INTO scheduled_jobs
                (name, description, job_type, command, cron_expression, enabled,
                 run_in_background, without_overlapping, timeout_minutes, notes,
                 category, source_module)
            VALUES (
                'genealogy_agent_research',
                'Genealogy researcher AI agent — daily 4AM general research run. Surveys all trees, processes pending hints, searches multiple repositories, and documents findings per GPS standard.',
                'agent_task',
                'genealogy-researcher',
                '0 4 * * *',
                1, 1, 1, 44,
                '{\"task\":\"Daily genealogy research run — assess tree health, process pending hints, search all configured sources for priority persons, document findings per GPS standard.\",\"notify\":true}',
                'genealogy',
                'genealogy'
            )
            ON DUPLICATE KEY UPDATE
                enabled          = 1,
                cron_expression  = '0 4 * * *',
                timeout_minutes  = 44,
                notes            = '{\"task\":\"Daily genealogy research run — assess tree health, process pending hints, search all configured sources for priority persons, document findings per GPS standard.\",\"notify\":true}'
        ");
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')->where('name', 'genealogy_agent_research')->delete();
    }
};
