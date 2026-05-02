<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Register probe_unhealthy_providers tool for ai-ops agent
        $exists = DB::selectOne("SELECT id FROM agent_tool_registry WHERE name = 'probe_unhealthy_providers'");
        if (!$exists) {
            DB::insert(
                "INSERT INTO agent_tool_registry
                    (name, service_class, method, description, parameters, returns_description,
                     permissions, risk_level, category, requires_confirmation, max_calls_per_run,
                     enabled, source, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    'probe_unhealthy_providers',
                    'App\\Services\\AIOperationsService',
                    'probeUnhealthyProviders',
                    'Probe all unhealthy/open-circuit LLM providers and attempt recovery. Sends test requests to external APIs and resets circuits for providers that respond successfully.',
                    json_encode([]),
                    'Object with probed count, recovered provider list, and still_unhealthy details',
                    json_encode(['system:write']),
                    'write',
                    'ai_operations',
                    false,
                    3,
                    true,
                    'manual',
                ]
            );
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM agent_tool_registry WHERE name = 'probe_unhealthy_providers'");
    }
};
