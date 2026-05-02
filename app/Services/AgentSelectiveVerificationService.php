<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AG-11: Selective Verification — Sherlock pattern (MS Research)
 *
 * Only verify tool outputs that have historically high error rates.
 * Avoids verification overhead on reliable tools while catching
 * error-prone outputs before they influence agent reasoning.
 *
 * Uses agent_procedures success_rate and tool call failure history
 * to decide which tools need output verification.
 */
class AgentSelectiveVerificationService
{
    /** Tools with success rate below this get their outputs verified */
    private const ERROR_PRONE_THRESHOLD = 0.75;

    /** Cache TTL for tool reliability scores (seconds) */
    private const CACHE_TTL = 3600;

    /**
     * Check if a tool's output should be verified based on historical reliability.
     *
     * @param string $agentId Agent running the tool
     * @param string $toolName Tool that was called
     * @return bool True if output should be verified
     */
    public function shouldVerify(string $agentId, string $toolName): bool
    {
        $reliability = $this->getToolReliability($agentId, $toolName);

        if ($reliability === null) {
            return false; // No history — trust by default
        }

        return $reliability < self::ERROR_PRONE_THRESHOLD;
    }

    /**
     * Build a verification prompt for the LLM to check tool output.
     *
     * @param string $toolName Tool that was called
     * @param string $toolOutput Output from the tool
     * @param float $reliability Historical success rate
     * @return string Verification instruction to inject into conversation
     */
    public function buildVerificationPrompt(string $toolName, string $toolOutput, float $reliability): string
    {
        $pct = round($reliability * 100);
        $preview = mb_substr($toolOutput, 0, 500);

        return "VERIFICATION REQUIRED: The tool '{$toolName}' has a {$pct}% historical success rate. " .
            "Before using this output, verify: (1) Is the data internally consistent? " .
            "(2) Does it match expected format? (3) Are there obvious errors or empty results that should trigger a retry? " .
            "If the output looks wrong, call the tool again with adjusted parameters.\n\nOutput to verify:\n{$preview}";
    }

    /**
     * Get tool reliability score (0.0-1.0) from procedural memory and recent history.
     *
     * @param string $agentId Agent ID
     * @param string $toolName Tool name
     * @return float|null Reliability score, or null if no history
     */
    public function getToolReliability(string $agentId, string $toolName): ?float
    {
        $cacheKey = "tool_reliability:{$agentId}:{$toolName}";
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === -1 ? null : (float) $cached;
        }

        try {
            // Check procedural memory success rate for this tool
            $procedural = DB::selectOne("
                SELECT AVG(success_rate) as avg_rate, COUNT(*) as cnt
                FROM agent_procedures
                WHERE agent_id = ? AND trigger_pattern LIKE ?
                  AND times_used >= 2
            ", [$agentId, "%{$toolName}%"]);

            if ($procedural && $procedural->cnt > 0 && $procedural->avg_rate !== null) {
                $rate = (float) $procedural->avg_rate;
                \Illuminate\Support\Facades\Cache::put($cacheKey, $rate, self::CACHE_TTL);
                return $rate;
            }

            // Fallback: check recent tool call episodes
            $recent = DB::selectOne("
                SELECT
                    SUM(CASE WHEN JSON_EXTRACT(details, '$.success') = true THEN 1 ELSE 0 END) as successes,
                    COUNT(*) as total
                FROM agent_episodes
                WHERE agent_id = ? AND event_type = 'tool_call'
                  AND JSON_EXTRACT(details, '$.tool') = ?
                  AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            ", [$agentId, $toolName]);

            if ($recent && $recent->total >= 3) {
                $rate = (float) ($recent->successes / $recent->total);
                \Illuminate\Support\Facades\Cache::put($cacheKey, $rate, self::CACHE_TTL);
                return $rate;
            }

            \Illuminate\Support\Facades\Cache::put($cacheKey, -1, self::CACHE_TTL);
            return null;

        } catch (\Throwable $e) {
            Log::debug('SelectiveVerification: reliability lookup failed', [
                'agent_id' => $agentId,
                'tool' => $toolName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
