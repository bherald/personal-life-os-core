<?php

/**
 * N96 — GPS Proof Argument Generator
 * Register the generate_gps_proof agent tool.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, permissions, risk_level, category, max_calls_per_run, enabled, created_at, updated_at)
            VALUES
                ('generate_gps_proof', 'App\\\\Services\\\\Genealogy\\\\GPSProofGeneratorService', 'generateProofArgument',
                 'GPS Element 5: Generate a structured Genealogical Proof Standard proof argument for a specific genealogical question. Draws ONLY from DB-sourced evidence (sources, citations, events, conflicts, search coverage). Uses temperature 0.2. Validates citations post-generation. No genealogy platform does this automatically. Use in report phase after evidence correlation is complete.',
                 '{\"person_id\": {\"type\": \"integer\", \"description\": \"Person ID\", \"required\": true}, \"question\": {\"type\": \"string\", \"description\": \"The genealogical question to prove (e.g. Who were the parents of X?)\", \"required\": true}}',
                 '[\"genealogy:read\"]', 'read', 'genealogy', 2, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                description = VALUES(description),
                parameters = VALUES(parameters),
                enabled = 1,
                updated_at = NOW()
        ");
    }

    public function down(): void
    {
        DB::delete("DELETE FROM agent_tool_registry WHERE name = 'generate_gps_proof'");
    }
};
