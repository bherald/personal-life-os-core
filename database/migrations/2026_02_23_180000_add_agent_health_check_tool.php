<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::insert("
                INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, returns_description, permissions, source)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'config')
            ", [
                'agent_health_check',
                'AIOperationsService',
                'getAgentHealthCheck',
                'Health check across all agent_task scheduled jobs. Detects silent failures: zero-result outputs, missed schedules, stuck identical output, high error rates, and duration anomalies.',
                json_encode([]),
                'Array with agents (status per agent), alerts (actionable issues), and summary string',
                json_encode(['system:read']),
            ]);
        } catch (\Exception $e) {
            // Skip if already exists (idempotent)
            if (!str_contains($e->getMessage(), 'Duplicate entry')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM agent_tool_registry WHERE name = 'agent_health_check'");
    }
};
