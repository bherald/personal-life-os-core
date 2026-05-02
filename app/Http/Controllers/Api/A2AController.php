<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgentLoopService;
use App\Services\SkillLoaderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AG-18: A2A (Agent-to-Agent) Protocol Controller
 *
 * Implements Google A2A v0.3 endpoints for agent discovery and task submission.
 * External agents can discover PLOS agents via agent cards and submit tasks.
 *
 * Endpoints:
 *   GET  /api/a2a/.well-known/agent.json — Agent card (discovery)
 *   GET  /api/a2a/agents                 — List all agents
 *   GET  /api/a2a/agents/{id}            — Agent details
 *   POST /api/a2a/agents/{id}/tasks      — Submit task to agent
 *   GET  /api/a2a/agents/{id}/tasks/{taskId} — Task status
 */
class A2AController extends Controller
{
    /**
     * A2A Agent Card — discovery endpoint per Google A2A spec.
     */
    public function agentCard(): JsonResponse
    {
        $baseUrl = rtrim(config('app.url'), '/');

        return response()->json([
            'name' => 'PLOS Agent Framework',
            'description' => 'Personal Life OS — 14 autonomous agents for genealogy, research, file management, and system operations.',
            'url' => "{$baseUrl}/api/a2a",
            'version' => config('app.version', '3.78.0'),
            'protocol_version' => '0.3',
            'capabilities' => [
                'task_submission' => true,
                'task_status' => true,
                'agent_discovery' => true,
                'streaming' => false,
            ],
            'agents_endpoint' => "{$baseUrl}/api/a2a/agents",
            'authentication' => [
                'type' => 'none', // Single-user system
            ],
        ]);
    }

    /**
     * List all available agents with their capabilities.
     */
    public function listAgents(): JsonResponse
    {
        $skillLoader = app(SkillLoaderService::class);
        $skills = $skillLoader->getSkillIndex();

        $agents = [];
        foreach ($skills as $skill) {
            $agents[] = [
                'id' => $skill['name'],
                'name' => $skill['name'],
                'description' => $skill['description'] ?? '',
                'version' => $skill['version'] ?? '1.0.0',
                'schedule' => $skill['schedule'] ?? null,
                'workflow_mode' => $skill['workflow_mode'] ?? 'agentic',
                'permissions' => $skill['permissions'] ?? [],
                'status' => $this->getAgentStatus($skill['name']),
            ];
        }

        return response()->json([
            'agents' => $agents,
            'total' => count($agents),
        ]);
    }

    /**
     * Get single agent details.
     */
    public function getAgent(string $agentId): JsonResponse
    {
        $skillLoader = app(SkillLoaderService::class);
        $skill = $skillLoader->loadSkill($agentId);

        if (!$skill) {
            return response()->json(['error' => "Agent not found: {$agentId}"], 404);
        }

        $config = $skill['frontmatter'] ?? [];

        // Get recent session stats
        $recentSession = DB::selectOne(
            "SELECT status, created_at, updated_at,
                    TIMESTAMPDIFF(SECOND, created_at, COALESCE(updated_at, NOW())) * 1000 AS duration_ms
             FROM agent_sessions
             WHERE agent_name = ? ORDER BY created_at DESC LIMIT 1",
            [$agentId]
        );

        return response()->json([
            'id' => $agentId,
            'name' => $config['name'] ?? $agentId,
            'description' => $config['description'] ?? '',
            'version' => $config['version'] ?? '1.0.0',
            'schedule' => $config['schedule'] ?? null,
            'workflow_mode' => $config['workflow_mode'] ?? 'agentic',
            'max_iterations' => $config['max_iterations'] ?? 15,
            'permissions' => $config['permissions'] ?? [],
            'tool_phases' => $config['tool_phases'] ?? [],
            'last_run' => $recentSession ? [
                'status' => $recentSession->status,
                'at' => $recentSession->created_at,
                'duration_ms' => $recentSession->duration_ms,
            ] : null,
        ]);
    }

    /**
     * Submit a task to an agent (A2A task submission).
     */
    public function submitTask(Request $request, string $agentId): JsonResponse
    {
        $validated = $request->validate([
            'task' => 'required|string|max:5000',
            'context' => 'nullable|array',
            'notify' => 'nullable|boolean',
        ]);

        $skillLoader = app(SkillLoaderService::class);
        if (!$skillLoader->loadSkill($agentId)) {
            return response()->json(['error' => "Agent not found: {$agentId}"], 404);
        }

        try {
            $agentLoop = app(AgentLoopService::class);
            $result = $agentLoop->execute($agentId, $validated['task'], [
                'context' => $validated['context'] ?? [],
                'notify' => $validated['notify'] ?? false,
                'source' => 'a2a',
            ]);

            return response()->json([
                'task_id' => $result['session_id'] ?? null,
                'status' => $result['success'] ? 'completed' : 'failed',
                'response' => $result['response'] ?? null,
                'duration_ms' => $result['duration_ms'] ?? null,
                'error' => $result['error'] ?? null,
            ], $result['success'] ? 200 : 500);

        } catch (\Exception $e) {
            Log::error('A2A: Task submission failed', [
                'agent' => $agentId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get task status by session ID.
     */
    public function getTaskStatus(string $agentId, string $taskId): JsonResponse
    {
        $session = DB::selectOne(
            "SELECT id, agent_name, status, metadata, created_at, updated_at, total_tokens, message_count,
                    TIMESTAMPDIFF(SECOND, created_at, COALESCE(updated_at, NOW())) * 1000 AS duration_ms
             FROM agent_sessions
             WHERE id = ? AND agent_name = ?",
            [$taskId, $agentId]
        );

        if (!$session) {
            return response()->json(['error' => 'Task not found'], 404);
        }

        $meta = json_decode($session->metadata ?? '{}', true);

        return response()->json([
            'task_id' => $session->id,
            'agent' => $session->agent_name,
            'status' => $session->status,
            'task' => $meta['task'] ?? null,
            'created_at' => $session->created_at,
            'completed_at' => $session->updated_at,
            'duration_ms' => $session->duration_ms,
            'tokens_used' => $session->total_tokens,
            'tool_calls' => $session->message_count,
        ]);
    }

    private function getAgentStatus(string $agentId): string
    {
        $session = DB::selectOne(
            "SELECT status FROM agent_sessions WHERE agent_name = ? ORDER BY created_at DESC LIMIT 1",
            [$agentId]
        );

        return $session ? $session->status : 'idle';
    }
}
