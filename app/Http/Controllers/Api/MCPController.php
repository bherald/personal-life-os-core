<?php

namespace App\Http\Controllers\Api;

use App\Engine\MCPRouter;
use App\Engine\OllamaToolCaller;
use App\Services\AIService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * MCP Controller
 *
 * API endpoints for MCP tool calling and status
 * E01 Phase 3: Now uses AIService for resilient AI operations
 */
class MCPController extends Controller
{
    private MCPRouter $mcpRouter;
    private OllamaToolCaller $toolCaller;
    private AIService $aiService;

    public function __construct(AIService $aiService)
    {
        $this->mcpRouter = new MCPRouter();
        $this->toolCaller = new OllamaToolCaller();
        $this->aiService = $aiService;
    }

    /**
     * Get MCP tool calling status
     *
     * @return JsonResponse
     */
    public function status(): JsonResponse
    {
        return response()->json($this->toolCaller->getStatus());
    }

    /**
     * Get available tools catalog
     *
     * @return JsonResponse
     */
    public function tools(): JsonResponse
    {
        $tools = $this->mcpRouter->getAvailableTools();

        return response()->json([
            'total' => count($tools),
            'tools' => $tools,
            'grouped_by_server' => $this->groupByServer($tools),
        ]);
    }

    /**
     * Get status of all MCP servers
     *
     * @return JsonResponse
     */
    public function servers(): JsonResponse
    {
        // Recreate router to get fresh config (in case it was updated)
        $this->mcpRouter = new MCPRouter();

        return response()->json($this->mcpRouter->getAllServersStatus());
    }

    /**
     * Call a tool via Ollama with native tool calling
     * E01 Phase 3: Uses AIService for resilient tool calling
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function call(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'request' => 'required|string|max:2000',
        ]);

        try {
            $start = microtime(true);

            // Use AIService's native tool calling with resilience (circuit breaker + retry)
            $result = $this->aiService->processWithTools($validated['request'], [
                'temperature' => 0.1,
                'max_tokens' => 2000,
            ], 5); // Max 5 tool calling iterations

            $duration = round((microtime(true) - $start) * 1000);

            return response()->json([
                'success' => $result['success'],
                'request' => $validated['request'],
                'response' => $result['response'] ?? null,
                'provider' => $result['provider'] ?? null,
                'duration_ms' => $duration,
                'error' => $result['error'] ?? null,
            ]);
        } catch (Exception $e) {
            \Log::error('Tool calling failed', [
                'request' => $validated['request'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Call a specific MCP tool directly
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function callDirect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'server' => 'required|string',
            'tool' => 'required|string',
            'params' => 'array',
        ]);

        try {
            $start = microtime(true);

            $result = $this->mcpRouter->callTool(
                $validated['server'],
                $validated['tool'],
                $validated['params'] ?? []
            );

            $duration = round((microtime(true) - $start) * 1000);

            $this->recordDirectToolCall($validated['server'], $validated['tool'], true, (int) $duration);

            return response()->json([
                'success' => true,
                'response' => $result,
                'duration_ms' => $duration,
            ]);
        } catch (Exception $e) {
            $duration = round((microtime(true) - ($start ?? microtime(true))) * 1000);
            $this->recordDirectToolCall($validated['server'], $validated['tool'], false, (int) $duration, $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear tool catalog cache
     *
     * @return JsonResponse
     */
    public function clearCache(): JsonResponse
    {
        $this->mcpRouter->clearCache();

        return response()->json([
            'success' => true,
            'message' => 'Tool catalog cache cleared',
        ]);
    }

    /**
     * Test a specific tool
     *
     * @param Request $request
     * @param string $server
     * @param string $tool
     * @return JsonResponse
     */
    public function testTool(Request $request, string $server, string $tool): JsonResponse
    {
        $validated = $request->validate([
            'params' => 'array',
        ]);

        try {
            $start = microtime(true);

            $result = $this->mcpRouter->callTool(
                $server,
                $tool,
                $validated['params'] ?? []
            );

            $duration = round((microtime(true) - $start) * 1000);

            return response()->json([
                'success' => true,
                'result' => $result,
                'duration_ms' => $duration,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get MCP configuration
     *
     * @return JsonResponse
     */
    public function getConfig(): JsonResponse
    {
        $config = config('mcp.servers');

        return response()->json([
            'servers' => $config,
            'router' => config('mcp.router'),
            'ollama_tool_calling' => config('mcp.ollama_tool_calling'),
        ]);
    }

    /**
     * Update server enabled status
     *
     * @param Request $request
     * @param string $server
     * @return JsonResponse
     */
    public function updateServer(Request $request, string $server): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        try {
            $configPath = config_path('mcp.php');

            // Read the config file
            $configContent = file_get_contents($configPath);

            // Find the server block and its enabled status
            // Strategy: Find 'server_name' => [ and then find the first 'enabled' => value after it
            $serverPattern = "/'$server'\s*=>\s*\[/";

            if (!preg_match($serverPattern, $configContent, $matches, PREG_OFFSET_CAPTURE)) {
                return response()->json([
                    'success' => false,
                    'error' => "Server '{$server}' not found in configuration",
                ], 404);
            }

            $serverStart = $matches[0][1];

            // Find the enabled line after the server definition
            $enabledPattern = "/('enabled'\s*=>\s*)(true|false)(\s*,)/";
            $searchFrom = substr($configContent, $serverStart);

            if (!preg_match($enabledPattern, $searchFrom, $enabledMatch, PREG_OFFSET_CAPTURE)) {
                return response()->json([
                    'success' => false,
                    'error' => "Could not find 'enabled' field for server '{$server}'",
                ], 500);
            }

            // Calculate the absolute position in the full content
            $enabledPos = $serverStart + $enabledMatch[2][1];
            $enabledLength = strlen($enabledMatch[2][0]);

            // Replace the enabled value
            $newValue = $validated['enabled'] ? 'true' : 'false';
            $newContent = substr_replace($configContent, $newValue, $enabledPos, $enabledLength);

            // Write back to file
            file_put_contents($configPath, $newContent);

            // Clear PHP OPcache for this file if enabled
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($configPath, true);
            }

            // Clear Laravel config cache to force reload without routing through artisan.
            $configCachePath = base_path('bootstrap/cache/config.php');
            if (file_exists($configCachePath)) {
                @unlink($configCachePath);
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($configCachePath, true);
                }
            }

            // Recreate MCPRouter to reload config
            $this->mcpRouter = new MCPRouter();

            // Clear cache to refresh tool catalog
            $this->mcpRouter->clearCache();

            return response()->json([
                'success' => true,
                'message' => "Server '{$server}' " . ($validated['enabled'] ? 'enabled' : 'disabled') . ' (persistent)',
                'server' => $server,
                'enabled' => $validated['enabled'],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update server configuration: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Group tools by server
     */
    private function groupByServer(array $tools): array
    {
        $grouped = [];

        foreach ($tools as $tool) {
            $server = $tool['server'] ?? 'unknown';

            if (!isset($grouped[$server])) {
                $grouped[$server] = [
                    'count' => 0,
                    'tools' => [],
                    'description' => $tool['server_description'] ?? '',
                ];
            }

            $grouped[$server]['count']++;
            $grouped[$server]['tools'][] = [
                'name' => $tool['name'] ?? 'unknown',
                'description' => $tool['description'] ?? '',
            ];
        }

        return $grouped;
    }

    /**
     * Tool usage analytics
     */
    public function analytics(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 7);
        $days = min(max($days, 1), 90);

        $topTools = DB::select("
            SELECT tool_name,
                   COUNT(*) as call_count,
                   SUM(success) as success_count,
                   SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as fail_count,
                   ROUND(AVG(duration_ms)) as avg_duration_ms,
                   MAX(duration_ms) as max_duration_ms,
                   ROUND(100.0 * SUM(success) / COUNT(*), 1) as success_rate
            FROM mcp_tool_calls
            WHERE created_at >= NOW() - INTERVAL ? DAY
            GROUP BY tool_name
            ORDER BY call_count DESC
            LIMIT 30
        ", [$days]);

        $byServer = DB::select("
            SELECT COALESCE(mcp_server, 'internal') as server,
                   COUNT(*) as call_count,
                   SUM(success) as success_count,
                   ROUND(AVG(duration_ms)) as avg_duration_ms,
                   ROUND(100.0 * SUM(success) / COUNT(*), 1) as success_rate
            FROM mcp_tool_calls
            WHERE created_at >= NOW() - INTERVAL ? DAY
            GROUP BY mcp_server
            ORDER BY call_count DESC
        ", [$days]);

        $byAgent = DB::select("
            SELECT COALESCE(agent_id, 'manual') as agent_id,
                   COUNT(*) as call_count,
                   SUM(success) as success_count,
                   ROUND(100.0 * SUM(success) / COUNT(*), 1) as success_rate
            FROM mcp_tool_calls
            WHERE created_at >= NOW() - INTERVAL ? DAY
            GROUP BY agent_id
            ORDER BY call_count DESC
            LIMIT 20
        ", [$days]);

        $dailyTrend = DB::select("
            SELECT DATE(created_at) as date,
                   COUNT(*) as call_count,
                   SUM(success) as success_count,
                   SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as fail_count,
                   ROUND(AVG(duration_ms)) as avg_duration_ms
            FROM mcp_tool_calls
            WHERE created_at >= NOW() - INTERVAL ? DAY
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ", [$days]);

        $recentErrors = DB::select("
            SELECT tool_name, agent_id, error_message, duration_ms, created_at
            FROM mcp_tool_calls
            WHERE success = 0
              AND created_at >= NOW() - INTERVAL ? DAY
            ORDER BY created_at DESC
            LIMIT 20
        ", [$days]);

        $totals = DB::selectOne("
            SELECT COUNT(*) as total_calls,
                   SUM(success) as total_success,
                   ROUND(AVG(duration_ms)) as avg_duration_ms,
                   ROUND(100.0 * SUM(success) / GREATEST(COUNT(*), 1), 1) as overall_success_rate
            FROM mcp_tool_calls
            WHERE created_at >= NOW() - INTERVAL ? DAY
        ", [$days]);

        return response()->json([
            'success' => true,
            'period_days' => $days,
            'totals' => [
                'calls' => (int) ($totals->total_calls ?? 0),
                'successes' => (int) ($totals->total_success ?? 0),
                'avg_duration_ms' => (int) ($totals->avg_duration_ms ?? 0),
                'success_rate' => (float) ($totals->overall_success_rate ?? 0),
            ],
            'top_tools' => $topTools,
            'by_server' => $byServer,
            'by_agent' => $byAgent,
            'daily_trend' => $dailyTrend,
            'recent_errors' => $recentErrors,
        ]);
    }

    /**
     * Record a direct MCP tool call for analytics
     */
    private function recordDirectToolCall(string $server, string $tool, bool $success, int $durationMs, ?string $error = null): void
    {
        try {
            DB::insert("
                INSERT INTO mcp_tool_calls (tool_name, mcp_server, mcp_tool, caller, success, duration_ms, error_message, created_at)
                VALUES (?, ?, ?, 'api', ?, ?, ?, NOW())
            ", [
                substr("{$server}/{$tool}", 0, 150),
                substr($server, 0, 50),
                substr($tool, 0, 100),
                $success ? 1 : 0,
                $durationMs,
                $error ? substr($error, 0, 65535) : null,
            ]);
        } catch (\Throwable $e) {
            Log::debug("MCPController: Failed to record tool call analytics", ['error' => $e->getMessage()]);
        }
    }
}
