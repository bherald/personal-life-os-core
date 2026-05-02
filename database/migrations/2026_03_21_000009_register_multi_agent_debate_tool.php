<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::insert(
            "INSERT IGNORE INTO agent_tool_registry
                (name, description, service_class, method, category, risk_level, parameters, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                'multi_agent_debate',
                'Run a task through 2-3 reasoning perspectives (conservative/creative/analytical) and synthesize the best answer. Modes: diverse, adversarial, consensus.',
                'App\\Services\\MultiAgentDebateService',
                'runDebate',
                'reasoning',
                'read',
                json_encode([
                    'required' => ['task'],
                    'optional' => ['mode', 'context'],
                ]),
            ]
        );
    }

    public function down(): void
    {
        DB::delete("DELETE FROM agent_tool_registry WHERE name = 'multi_agent_debate'");
    }
};
