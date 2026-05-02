<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OrchestratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Orchestrator API Controller
 *
 * Provides API endpoints for the Intelligent Orchestrator
 */
class OrchestratorController extends Controller
{
    private OrchestratorService $orchestrator;

    public function __construct(OrchestratorService $orchestrator)
    {
        $this->orchestrator = $orchestrator;
    }

    /**
     * Process a request through the orchestrator
     *
     * POST /api/orchestrator/process
     */
    public function process(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'request' => 'required|string|max:5000',
            'conversation_id' => 'nullable|integer|exists:conversations,id',
            'options' => 'nullable|array',
            'options.temperature' => 'nullable|numeric|min:0|max:2',
            'options.max_tokens' => 'nullable|integer|min:1|max:4000',
        ]);

        $result = $this->orchestrator->process(
            $validated['request'],
            $validated['conversation_id'] ?? null,
            $validated['options'] ?? []
        );

        return response()->json($result);
    }

    /**
     * Get orchestrator status and capabilities
     *
     * GET /api/orchestrator/status
     */
    public function status(): JsonResponse
    {
        $status = $this->orchestrator->getStatus();

        return response()->json($status);
    }

    /**
     * Get capabilities and help information
     *
     * GET /api/orchestrator/help
     */
    public function help(): JsonResponse
    {
        return response()->json([
            'version' => '1.0.0',
            'description' => 'Intelligent Orchestrator for PLOS AI Automation Framework',
            'intents' => [
                [
                    'name' => 'workflow_execution',
                    'description' => 'Execute a workflow by name',
                    'examples' => [
                        'run morning_weather',
                        'execute news_brief',
                        'trigger joplin_sync',
                    ],
                ],
                [
                    'name' => 'rag_search',
                    'description' => 'Search historical data using semantic search',
                    'examples' => [
                        'find executions from last week',
                        'search for weather data',
                        'what workflows ran today',
                    ],
                ],
                [
                    'name' => 'mcp_tool',
                    'description' => 'Call an MCP tool',
                    'examples' => [
                        'search my emails for important',
                        'list calendar events',
                        'get trending news topics',
                    ],
                ],
                [
                    'name' => 'multi_step',
                    'description' => 'Execute complex multi-step tasks',
                    'examples' => [
                        'search my notes for AI and create a summary',
                        'find similar documents and email results',
                    ],
                ],
                [
                    'name' => 'general_conversation',
                    'description' => 'General questions and conversation',
                    'examples' => [
                        'what can you do?',
                        'explain how workflows work',
                        'help me get started',
                    ],
                ],
            ],
            'usage' => [
                'endpoint' => 'POST /api/orchestrator/process',
                'payload' => [
                    'request' => 'Your natural language request',
                    'conversation_id' => 'Optional conversation ID for context',
                    'options' => [
                        'temperature' => 'AI temperature (0-2, default 0.7)',
                        'max_tokens' => 'Max response tokens (1-4000)',
                    ],
                ],
            ],
        ]);
    }
}
