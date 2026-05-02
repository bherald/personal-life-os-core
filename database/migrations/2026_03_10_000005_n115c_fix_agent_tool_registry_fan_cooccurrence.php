<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N115c — Fix agent_tool_registry: fan_extract_cooccurrences search_result_text optional
 *
 * The hybrid framework cannot inject search_result_text (no context source).
 * FANCooccurrenceService::extractFromSearchResult() already handles empty string
 * gracefully (early-returns with 0 extracted). Making it optional prevents
 * "Missing required parameter" errors in framework-driven analyze phase runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            UPDATE agent_tool_registry
            SET parameters = JSON_SET(parameters, '$.search_result_text.required', FALSE)
            WHERE name = 'fan_extract_cooccurrences'
        ");
    }

    public function down(): void
    {
        DB::statement("
            UPDATE agent_tool_registry
            SET parameters = JSON_SET(parameters, '$.search_result_text.required', TRUE)
            WHERE name = 'fan_extract_cooccurrences'
        ");
    }
};
