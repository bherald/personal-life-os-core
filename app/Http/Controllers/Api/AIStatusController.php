<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AIService;
use App\Services\OllamaProgressClient;
use App\Services\OpenAICompatibleProgressClient;
use Illuminate\Http\JsonResponse;

/**
 * AIStatusController
 * E01 Phase 3: Now uses AIService for resilient AI operations
 */
class AIStatusController extends Controller
{
    private AIService $aiService;

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Get AI service status.
     * E01 Phase 3: Enhanced with AIService health stats
     */
    public function status(): JsonResponse
    {
        $aiHealth = $this->aiService->getHealthStats();
        $progressSnapshot = app(OllamaProgressClient::class)->progressSnapshot();
        $compatibleSnapshot = app(OpenAICompatibleProgressClient::class)->progressSnapshot();
        $activeGenerations = array_merge(
            $progressSnapshot['active_generations'],
            $compatibleSnapshot['active_generations']
        );
        $publicActiveGenerations = array_map(static fn (array $entry): array => [
            'state' => $entry['state'] ?? 'unknown',
            'elapsed_seconds' => $entry['elapsed_seconds'] ?? 0,
            'downloaded_bytes' => $entry['downloaded_bytes'] ?? 0,
            'seconds_since_progress' => $entry['seconds_since_progress'] ?? null,
            'queue_wait_ms' => $entry['queue_wait_ms'] ?? null,
            'idle_timeout_seconds' => $entry['idle_timeout_seconds'] ?? null,
            'transport_wall_clock_disabled' => $entry['transport_wall_clock_disabled'] ?? false,
        ], $activeGenerations);

        return response()->json([
            'services' => $aiHealth['providers'] ?? [],
            'ai_health' => $aiHealth,
            'active_generations' => $publicActiveGenerations,
            'active_generation_count' => count($activeGenerations),
            'active_generation_monitor_available' => $progressSnapshot['available'] && $compatibleSnapshot['available'],
            'recommendation' => $this->getRecommendation($aiHealth),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Test AI connectivity
     * E01 Phase 3: Uses AIService for resilient testing
     */
    public function test(): JsonResponse
    {
        $results = ['gateway' => null];

        // Test the table-driven provider gateway. The selected provider may be
        // Ollama, a local_llm sidecar, Codex, or an admitted external provider.
        $startTime = microtime(true);
        $result = $this->aiService->process("Respond with only 'OK'", ['max_tokens' => 10]);
        $elapsed = round((microtime(true) - $startTime) * 1000);

        if ($result['success']) {
            $results['gateway'] = [
                'status' => 'success',
                'response_time_ms' => $elapsed,
                'response' => substr($result['response'], 0, 100),
                'provider' => $result['provider'] ?? 'unknown',
            ];
        } else {
            $results['gateway'] = [
                'status' => 'error',
                'error' => $result['error'] ?? 'Unknown error',
                'attempts' => $result['attempts'] ?? [],
            ];
        }

        return response()->json([
            'tests' => $results,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get recommendation based on status
     */
    private function getRecommendation(array $health): string
    {
        $availableProviders = array_filter(
            $health['providers'] ?? [],
            static fn (array $provider): bool => ($provider['available'] ?? false) === true
        );
        if ($availableProviders !== []) {
            return 'AI provider pool operational - using table-driven routing';
        }

        return 'No table-admitted AI provider available - check llm_instances and routing policy';
    }
}
