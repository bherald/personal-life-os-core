<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fix unqualified service_class names that cause "class not found" errors.
        // Use bound parameters to preserve backslashes in namespace separators.
        DB::update(
            "UPDATE agent_tool_registry SET service_class = ? WHERE name = ? AND service_class NOT LIKE 'App\\\\%'",
            ['App\\Services\\AIOperationsService', 'agent_health_check']
        );

        DB::update(
            "UPDATE agent_tool_registry SET service_class = ? WHERE name = ? AND service_class NOT LIKE 'App\\\\%'",
            ['App\\Services\\CodeQualityService', 'code_quality_check']
        );
    }

    public function down(): void
    {
        // No rollback needed - unqualified names were bugs
    }
};
