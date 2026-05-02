<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Register speculative execution agent tools (S19).
     * 4 tools for agents to request, monitor, and manage speculative runs.
     */
    public function up(): void
    {
        // request_speculative — agent requests speculative execution for next run
        $this->insertTool(
            'request_speculative',
            'App\\Services\\SpeculativeExecutionService',
            'requestSpeculative',
            'Request speculative execution for the next run of this agent. Runs the same task through 2 different workflow modes in parallel and an LLM judge picks the best result. Use when you encounter high ambiguity or when previous similar tasks had mixed results. Do NOT use for routine monitoring.',
            json_encode([
                ['name' => 'agent_id', 'type' => 'string', 'required' => true, 'description' => 'Agent ID to request speculative execution for (usually your own)'],
                ['name' => 'reason', 'type' => 'string', 'required' => false, 'description' => 'Reason for requesting speculative execution'],
            ]),
            'Confirmation with flag TTL',
            json_encode(['system:write']),
            'write',
            'agent',
            2
        );

        // speculative_status — check status of a running speculative execution
        $this->insertTool(
            'speculative_status',
            'App\\Services\\SpeculativeExecutionService',
            'speculativeStatus',
            'Check the status of a running speculative execution. Returns branch statuses, winner (if complete), quality uplift, and cost.',
            json_encode([
                ['name' => 'spec_run_id', 'type' => 'string', 'required' => true, 'description' => 'Speculative run ID to check'],
            ]),
            'Run status, branch statuses, winner if complete',
            json_encode(['system:read']),
            'read',
            'agent',
            5
        );

        // speculative_stats — get aggregate statistics
        $this->insertTool(
            'speculative_stats',
            'App\\Services\\SpeculativeExecutionService',
            'speculativeStats',
            'Get aggregate speculative execution statistics: total runs, win rates per mode, average quality uplift, cost data, and auto-disabled agents.',
            json_encode([
                ['name' => 'agent_id', 'type' => 'string', 'required' => false, 'description' => 'Optional agent ID to filter stats'],
            ]),
            'Aggregate stats with mode wins, trigger breakdown, disabled agents',
            json_encode(['system:read']),
            'read',
            'agent',
            3
        );

        // cancel_speculative — cancel a running speculative run
        $this->insertTool(
            'cancel_speculative',
            'App\\Services\\SpeculativeExecutionService',
            'cancelSpeculative',
            'Cancel a running speculative execution. Use when the task is no longer relevant or resources are needed elsewhere.',
            json_encode([
                ['name' => 'spec_run_id', 'type' => 'string', 'required' => true, 'description' => 'Speculative run ID to cancel'],
            ]),
            'Success/failure of cancellation',
            json_encode(['system:write']),
            'write',
            'agent',
            2
        );
    }

    private function insertTool(
        string $name,
        string $serviceClass,
        string $method,
        string $description,
        string $parameters,
        string $returnsDescription,
        string $permissions,
        string $riskLevel,
        string $category,
        ?int $maxCallsPerRun
    ): void {
        try {
            DB::insert("
                INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, returns_description, permissions, risk_level, category, max_calls_per_run, source)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'config')
            ", [
                $name,
                $serviceClass,
                $method,
                $description,
                $parameters,
                $returnsDescription,
                $permissions,
                $riskLevel,
                $category,
                $maxCallsPerRun,
            ]);
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate entry')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ('request_speculative', 'speculative_status', 'speculative_stats', 'cancel_speculative')");
    }
};
