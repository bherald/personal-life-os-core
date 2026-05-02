<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NARA Sprint: Register census-specific search tool + update SKILL.md guidance.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Register nara_search_census agent tool
        DB::insert(
            "INSERT IGNORE INTO agent_tool_registry
                (name, description, service_class, method, category, risk_level, parameters, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                'nara_search_census',
                'Search NARA specifically for US Census records (1790-1950). Supports year-specific searches with state/county filters. Use for any US ancestor who may appear in federal census.',
                'App\\Services\\Genealogy\\GenealogySourceService',
                'searchNARACensus',
                'research',
                'read',
                json_encode([
                    'required' => ['surname'],
                    'optional' => ['given_name', 'year', 'state', 'county', 'limit'],
                ]),
            ]
        );
    }

    public function down(): void
    {
        DB::delete("DELETE FROM agent_tool_registry WHERE name = 'nara_search_census'");
    }
};
