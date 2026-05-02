<?php

namespace App\Http\Controllers\Api;

use App\Engine\AIRouter;
use App\Services\AIService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * AIStatusController
 * E01 Phase 3: Now uses AIService for resilient AI operations
 */
class AIStatusController extends Controller
{
    private AIService $aiService;
    private AIRouter $aiRouter;

    public function __construct(AIService $aiService, AIRouter $aiRouter)
    {
        $this->aiService = $aiService;
        $this->aiRouter = $aiRouter;
    }

    /**
     * Get AI service status (Ollama + Claude)
     * E01 Phase 3: Enhanced with AIService health stats
     */
    public function status(): JsonResponse
    {
        $routerStatus = $this->aiRouter->getStatus();
        $aiHealth = $this->aiService->getHealthStats();

        return response()->json([
            'services' => $routerStatus,
            'ai_health' => $aiHealth,
            'recommendation' => $this->getRecommendation($routerStatus),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Test AI connectivity
     * E01 Phase 3: Uses AIService for resilient testing
     */
    public function test(): JsonResponse
    {
        $results = [
            'ollama' => null,
            'claude' => null,
        ];

        // Test Ollama via AIService
        $startTime = microtime(true);
        $result = $this->aiService->process("Respond with only 'OK'", ['max_tokens' => 10]);
        $elapsed = round((microtime(true) - $startTime) * 1000);

        if ($result['success']) {
            $results['ollama'] = [
                'status' => 'success',
                'response_time_ms' => $elapsed,
                'response' => substr($result['response'], 0, 100),
                'provider' => $result['provider'] ?? 'unknown',
            ];
        } else {
            $results['ollama'] = [
                'status' => 'error',
                'error' => $result['error'] ?? 'Unknown error',
                'attempts' => $result['attempts'] ?? [],
            ];
        }

        // Test Claude directly (bypass AIService resilience to test specific provider)
        if ($this->aiRouter->isClaudeAvailable()) {
            try {
                $startTime = microtime(true);
                $response = $this->aiRouter->processWithAI("Respond with only 'OK'", ['ai_mode' => 'claude', 'max_tokens' => 10]);
                $elapsed = round((microtime(true) - $startTime) * 1000);
                $results['claude'] = [
                    'status' => 'success',
                    'response_time_ms' => $elapsed,
                    'response' => substr($response, 0, 100),
                ];
            } catch (\Exception $e) {
                $results['claude'] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'tests' => $results,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get recommendation based on status
     */
    private function getRecommendation(array $status): string
    {
        if ($status['ollama']['available']) {
            $backup = '';
            if ($status['claude']['cli_available']) {
                $backup = ' (Claude CLI backup available)';
            } elseif ($status['claude']['api_configured']) {
                $backup = ' (Claude API backup available)';
            }
            return 'Offline mode operational - using local Ollama' . $backup;
        }

        if ($status['claude']['available']) {
            if ($status['claude']['cli_available']) {
                return 'Using Claude Code CLI (Ollama unavailable)';
            }
            return 'Using Claude API (Ollama unavailable)';
        }

        return 'No AI service available - check configuration';
    }
}
