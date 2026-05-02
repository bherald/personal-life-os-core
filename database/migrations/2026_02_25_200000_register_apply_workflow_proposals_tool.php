<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Register the apply_workflow_proposals tool in agent_tool_registry.
 *
 * This tool allows hybrid workflow agents to submit structured proposals
 * (relationships, marriages) through the AgentProposalService adapter
 * instead of hardcoding domain service calls in the agent engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Check if already registered
        $exists = DB::selectOne(
            "SELECT id FROM agent_tool_registry WHERE name = 'apply_workflow_proposals'"
        );

        if (!$exists) {
            DB::insert("
                INSERT INTO agent_tool_registry
                (name, description, service_class, method, parameters, permissions, risk_level, category, enabled, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ", [
                'apply_workflow_proposals',
                'Process structured proposals from hybrid workflow LLM output. Handles relationship and marriage proposals for genealogy, extensible to other domains.',
                'App\\Services\\AgentProposalService',
                'processProposals',
                json_encode([
                    ['name' => 'finalData', 'type' => 'array', 'required' => true, 'description' => 'Full decoded JSON from LLM final phase (includes proposed_relationships, proposed_marriages)'],
                    ['name' => 'agentId', 'type' => 'string', 'required' => true, 'description' => 'Agent ID that generated the proposals'],
                    ['name' => 'context', 'type' => 'array', 'required' => false, 'description' => 'Runtime context (tree_id, session_id, etc.)'],
                ]),
                json_encode(['genealogy:write']),
                'write',
                'agent',
            ]);
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM agent_tool_registry WHERE name = 'apply_workflow_proposals'");
    }
};
