<?php

/**
 * N92 — DM-Soundex + Extended Family Matching (no DB changes, service-only)
 * N101 — Register get_person_full agent tool
 * N104 — No migration needed (service-only change in PersonService)
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // N101: get_person_full — extended person data for agent
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, permissions, risk_level, category, max_calls_per_run, enabled, created_at, updated_at)
            VALUES
                ('get_person_full', 'App\\\\Services\\\\Genealogy\\\\GenealogyService', 'getPersonFull',
                 'Get full extended person data including name variants (all recorded name forms), external IDs (FamilySearch/Ancestry/FindAGrave), GPS research tasks, and per-repository search coverage (negative evidence map per GPS Element 1). Use instead of get_person when you need a complete research picture.',
                 '{\"person_id\": {\"type\": \"integer\", \"description\": \"Person ID\", \"required\": true}}',
                 '[\"genealogy:read\"]', 'read', 'genealogy', 10, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                description = VALUES(description),
                parameters = VALUES(parameters),
                enabled = 1,
                updated_at = NOW()
        ");
    }

    public function down(): void
    {
        DB::delete("DELETE FROM agent_tool_registry WHERE name = 'get_person_full'");
    }
};
