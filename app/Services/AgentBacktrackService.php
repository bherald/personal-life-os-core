<?php

namespace App\Services;

/**
 * AG-10: Backtracking on Failure
 *
 * Implements the EnCompass (MIT CSAIL, NeurIPS 2025) backtracking pattern
 * for agentic loops. Records branchpoints at each successful tool call and
 * rolls back to the last good state when consecutive failures cross a threshold.
 *
 * Lifecycle: instantiated fresh per agent run (not a singleton).
 * All state is per-run and is discarded when the run completes.
 *
 * Integration: AgentLoopService creates one instance at the start of the
 * agentic while-loop and calls recordSuccess / recordFailure after each tool.
 */
class AgentBacktrackService
{
    /** Consecutive tool failures before triggering a backtrack */
    private const FAILURE_THRESHOLD = 3;

    /** Maximum rollbacks per agent run (prevents infinite backtrack loops) */
    private const MAX_BACKTRACKS = 2;

    /** Maximum branchpoints to retain (memory guard) */
    private const MAX_BRANCHPOINTS = 3;

    /** Stored branchpoints: [{iteration, messages, tool, toolCalls}] */
    private array $branchpoints = [];

    /** Number of consecutive tool failures since last success */
    private int $consecutiveFailures = 0;

    /** Tools that have failed, for context injection */
    private array $failedTools = [];

    /** Total rollbacks performed this run */
    private int $backtracksPerformed = 0;

    // =========================================================================
    // Called by AgentLoopService after each tool execution
    // =========================================================================

    /**
     * Record a successful tool call as a potential rollback branchpoint.
     */
    public function recordSuccess(int $iteration, array $messages, string $toolName, array $toolCalls): void
    {
        $this->consecutiveFailures = 0;

        // Keep only the last MAX_BRANCHPOINTS snapshots
        if (count($this->branchpoints) >= self::MAX_BRANCHPOINTS) {
            array_shift($this->branchpoints);
        }

        $this->branchpoints[] = [
            'iteration'  => $iteration,
            'messages'   => $messages,   // Full snapshot — this is the rollback target
            'tool'       => $toolName,
            'tool_calls' => $toolCalls,  // Tool call history up to this point
        ];
    }

    /**
     * Record a failed tool call.
     */
    public function recordFailure(string $toolName, string $errorText): void
    {
        $this->consecutiveFailures++;
        $this->failedTools[] = $toolName;
    }

    // =========================================================================
    // Backtrack decision + execution
    // =========================================================================

    /**
     * Whether the current failure run warrants a rollback.
     */
    public function shouldBacktrack(): bool
    {
        return $this->consecutiveFailures >= self::FAILURE_THRESHOLD
            && !empty($this->branchpoints)
            && $this->backtracksPerformed < self::MAX_BACKTRACKS;
    }

    /**
     * Whether all backtrack budget is spent (caller should wind down the run).
     */
    public function exhausted(): bool
    {
        return $this->backtracksPerformed >= self::MAX_BACKTRACKS;
    }

    /**
     * Perform a backtrack. Returns the rolled-back state and a context message
     * to inject so the LLM understands why its history was pruned.
     *
     * @param array $availableTools All tool names currently available
     * @return array{messages: array, context: string, branchpoint_iteration: int}|null
     *         Null if no branchpoint available.
     */
    public function backtrack(array $availableTools = []): ?array
    {
        if (empty($this->branchpoints)) {
            return null;
        }

        $branchpoint = array_pop($this->branchpoints);
        $this->backtracksPerformed++;
        $this->consecutiveFailures = 0;

        $failedUnique = array_unique($this->failedTools);
        $this->failedTools = [];

        // Unused tools after rollback — suggest alternatives
        $usedSoFar = array_unique(array_column($branchpoint['tool_calls'], 'tool'));
        $unused = array_diff($availableTools, $usedSoFar);

        $context = sprintf(
            "BACKTRACK (rollback %d/%d): The last %d tool attempts failed. " .
            "I have rolled back the conversation to iteration %d where '%s' last succeeded. " .
            "Failed tools in that branch: %s. " .
            "%s" .
            "Please take a different approach — try a different search strategy, alternative tool, " .
            "or different parameters. Do NOT repeat the failed approach.",
            $this->backtracksPerformed,
            self::MAX_BACKTRACKS,
            self::FAILURE_THRESHOLD,
            $branchpoint['iteration'],
            $branchpoint['tool'],
            implode(', ', $failedUnique) ?: 'none recorded',
            !empty($unused)
                ? 'Tools not yet tried: ' . implode(', ', array_slice($unused, 0, 6)) . '. '
                : ''
        );

        return [
            'messages'              => $branchpoint['messages'],
            'context'               => $context,
            'branchpoint_iteration' => $branchpoint['iteration'],
            'failed_tools'          => $failedUnique,
        ];
    }

    // =========================================================================
    // State accessors (for episode recording)
    // =========================================================================

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function getBacktracksPerformed(): int
    {
        return $this->backtracksPerformed;
    }

    public function getBranchpointCount(): int
    {
        return count($this->branchpoints);
    }
}
