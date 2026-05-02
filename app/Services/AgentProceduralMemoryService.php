<?php

namespace App\Services;

use App\Support\PgVector;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Agent Procedural Memory Service (S16)
 *
 * Extracts, stores, retrieves, and manages learned procedures from agent episodes.
 * Implements the "Second Brain" pattern: episodic memory → pattern extraction →
 * synthesized procedures → cross-session retrieval.
 *
 * Flow:
 *  1. CAPTURE: After successful agent runs, extract tool sequences as procedures
 *  2. RECALL: Before agent execution, find matching procedures for the current task
 *  3. UPDATE: Track success/failure metrics per procedure
 *  4. CONSOLIDATE: Merge duplicates, retire stale, promote high-performers
 *  5. FAILURE MEMORY: Store negative procedures as guardrails
 */
class AgentProceduralMemoryService
{
    // Procedural memory config — config/agent_memory.php is primary (SC-2.6)
    private const MIN_SEQUENCE_LENGTH = 2;

    private const RECALL_MIN_SUCCESS_RATE = 0.70;

    private const RECALL_MIN_USES = 2;

    private const MAX_RECALL_PROCEDURES = 3;

    private const STALE_DAYS = 30;

    private const RETIRE_THRESHOLD = 0.30;

    private const RETIRE_MIN_USES = 5;

    private const MERGE_SIMILARITY_THRESHOLD = 0.80;

    private const SEMANTIC_MIN_SIMILARITY = 0.35;

    private AIService $aiService;

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    // =========================================================================
    // CAPTURE: Extract procedures from successful agent episodes
    // =========================================================================

    /**
     * Extract a procedure from a completed agent session's tool calls.
     *
     * Called by AgentLoopService after successful task completion.
     * Only captures if: (1) enough tool calls, (2) all succeeded, (3) not a duplicate.
     *
     * @param  string  $agentId  Agent that executed the task
     * @param  string  $sessionId  Session that produced the tool calls
     * @param  string  $task  Original task description (becomes trigger_pattern)
     * @param  array  $toolCalls  Array of [{tool, params, success, phase}]
     * @return array|null Created procedure or null if not captured
     */
    public function captureFromSession(string $agentId, string $sessionId, string $task, array $toolCalls): ?array
    {
        try {
            // Filter to successful tool calls only
            $successfulCalls = array_filter($toolCalls, fn ($tc) => $tc['success'] ?? false);

            if (count($successfulCalls) < config('agent_memory.procedural.min_sequence_length', self::MIN_SEQUENCE_LENGTH)) {
                return null;
            }

            // Build action sequence
            $actionSequence = [];
            foreach ($successfulCalls as $tc) {
                $actionSequence[] = [
                    'tool' => $tc['tool'],
                    'params' => $this->sanitizeParams($tc['params'] ?? []),
                    'phase' => $tc['phase'] ?? null,
                ];
            }

            // Check for duplicate: same agent + highly similar tool sequence
            if ($this->isDuplicate($agentId, $actionSequence)) {
                Log::debug('ProceduralMemory: Duplicate sequence skipped', [
                    'agent_id' => $agentId,
                    'tools' => array_column($actionSequence, 'tool'),
                ]);

                return null;
            }

            // Generate a concise name from the tool sequence
            $toolNames = array_unique(array_column($actionSequence, 'tool'));
            $name = $this->generateProcedureName($agentId, $toolNames, $task);

            // Determine if this is a negative procedure (any failures in original set)
            $totalCalls = count($toolCalls);
            $successCount = count($successfulCalls);
            $isNegative = $successCount < $totalCalls;
            $procedureType = $isNegative ? 'failure' : 'success';

            // AG-15: Extract strategy-level insight — why this pattern works/fails
            $strategyInsight = $this->extractStrategyInsight(
                $toolNames, $actionSequence, $toolCalls, $task, $procedureType
            );

            DB::insert('
                INSERT INTO agent_procedures
                    (agent_id, name, trigger_pattern, action_sequence, strategy_insight,
                     procedure_type, source_session_id, success_rate, times_used,
                     times_succeeded, last_used_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW(), NOW())
            ', [
                $agentId,
                substr($name, 0, 200),
                substr($task, 0, 500),
                json_encode($actionSequence),
                $strategyInsight,
                $procedureType,
                $sessionId,
                $isNegative ? 0.0 : 1.0,
                $isNegative ? 0 : 1,
            ]);

            $procedureId = DB::getPdo()->lastInsertId();

            // Generate and store semantic embedding for recall
            $embedded = $this->storeEmbedding((int) $procedureId, $agentId, $task);

            Log::info('ProceduralMemory: Captured procedure', [
                'id' => $procedureId,
                'agent_id' => $agentId,
                'name' => $name,
                'type' => $procedureType,
                'tools' => count($actionSequence),
                'embedded' => $embedded,
            ]);

            return [
                'id' => $procedureId,
                'name' => $name,
                'type' => $procedureType,
                'tools_count' => count($actionSequence),
            ];

        } catch (Exception $e) {
            Log::warning('ProceduralMemory: Capture failed (non-fatal)', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // =========================================================================
    // RECALL: Find matching procedures for a task
    // =========================================================================

    /**
     * Find procedures relevant to the current task.
     *
     * Primary: semantic recall via pgvector cosine similarity on trigger_pattern embeddings.
     * Fallback: Jaccard keyword matching if embeddings unavailable (Ollama down, no embeddings yet).
     *
     * @param  string  $agentId  Agent requesting procedures
     * @param  string  $task  Current task description
     * @return array Matching procedures with relevance scores
     */
    public function recallForTask(string $agentId, string $task): array
    {
        try {
            // Phase 3.5: factor the calling agent's reviewer-feedback
            // acceptance rate into procedure scoring. Agents whose recent
            // findings are getting accepted by the operator have their
            // procedures ranked higher; agents getting rejected are
            // penalized. Single lookup per call, threaded through both
            // the semantic and Jaccard scoring paths.
            $reviewerMultiplier = $this->getReviewerMultiplier($agentId);

            // Try semantic recall first
            $semanticMatches = $this->semanticRecall($agentId, $task);
            $usedSemantic = $semanticMatches !== null;

            if ($usedSemantic && ! empty($semanticMatches)) {
                $own = $this->buildScoredResults($agentId, $semanticMatches, 'semantic', $reviewerMultiplier);
            } else {
                // Fallback to Jaccard if semantic failed (null) or returned empty
                if (! $usedSemantic) {
                    Log::debug('ProceduralMemory: Using Jaccard fallback for recall', ['agent_id' => $agentId]);
                }
                $own = $this->recallForTaskJaccard($agentId, $task, $reviewerMultiplier);
            }

            // AG-4: Cross-agent shared recall — fill remaining slots from other agents' shared procedures
            $maxProcedures = config('agent_memory.procedural.max_recall_procedures', self::MAX_RECALL_PROCEDURES);
            $ownPositiveCount = count(array_filter($own, fn ($p) => $p['type'] === 'success'));
            if ($ownPositiveCount < $maxProcedures) {
                $shared = $this->recallSharedForTask($agentId, $task, $maxProcedures - $ownPositiveCount);
                if (! empty($shared)) {
                    $own = array_merge($own, $shared);
                }
            }

            return $own;

        } catch (\Throwable $e) {
            Log::warning('ProceduralMemory: Recall failed (non-fatal)', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * AG-4: Recall shared procedures from other agents.
     *
     * Only returns procedures with is_shared = 1 from agents other than $excludeAgentId.
     * Uses semantic search when available, falls back to a direct MySQL query.
     *
     * @param  string  $excludeAgentId  The calling agent (excluded from results)
     * @param  string  $task  Task description to match against
     * @param  int  $limit  Max shared procedures to return
     */
    public function recallSharedForTask(string $excludeAgentId, string $task, int $limit = 2): array
    {
        try {
            // Semantic: search procedure embeddings from other agents
            $result = $this->aiService->generateEmbedding($task);

            if (($result['success'] ?? false) && ! empty($result['embedding'])) {
                $embeddingStr = PgVector::literal($result['embedding']);

                $pgRows = DB::connection('pgsql_rag')->select('
                    SELECT procedure_id,
                           1 - (embedding <=> ?::vector) AS similarity
                    FROM agent_procedure_embeddings
                    WHERE agent_id != ?
                      AND 1 - (embedding <=> ?::vector) >= ?
                    ORDER BY embedding <=> ?::vector ASC
                    LIMIT ?
                ', [
                    $embeddingStr,
                    $excludeAgentId,
                    $embeddingStr,
                    config('agent_memory.procedural.semantic_min_similarity', self::SEMANTIC_MIN_SIMILARITY),
                    $embeddingStr,
                    $limit * 4,
                ]);

                if (! empty($pgRows)) {
                    $simMap = [];
                    foreach ($pgRows as $row) {
                        $simMap[(int) $row->procedure_id] = (float) $row->similarity;
                    }
                    $ids = array_keys($simMap);
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));

                    $procedures = DB::select("
                        SELECT id, agent_id, name, trigger_pattern, action_sequence, strategy_insight,
                               procedure_type, is_shared, success_rate, times_used,
                               times_succeeded, last_used_at, created_at
                        FROM agent_procedures
                        WHERE id IN ({$placeholders})
                          AND is_shared = 1
                          AND is_retired = 0
                          AND agent_id != ?
                    ", array_merge($ids, [$excludeAgentId]));

                    $scored = [];
                    foreach ($procedures as $proc) {
                        $similarity = $simMap[$proc->id] ?? 0;
                        $daysSince = $proc->last_used_at
                            ? (time() - strtotime($proc->last_used_at)) / 86400
                            : 999;
                        $recency = max(0.5, 1.0 - ($daysSince / 60));
                        $relevance = $similarity * (float) $proc->success_rate * $recency;
                        $actions = json_decode($proc->action_sequence, true) ?: [];

                        $scored[] = [
                            'id' => $proc->id,
                            'agent_id' => $proc->agent_id,
                            'is_shared' => true,
                            'name' => $proc->name,
                            'type' => $proc->procedure_type,
                            'trigger_pattern' => $proc->trigger_pattern,
                            'action_sequence' => $actions,
                            'tools' => array_column($actions, 'tool'),
                            'success_rate' => (float) $proc->success_rate,
                            'times_used' => $proc->times_used,
                            'strategy_insight' => $proc->strategy_insight ?? null,
                            'relevance' => round($relevance, 4),
                            'similarity' => round($similarity, 4),
                            'recall_method' => 'shared_semantic',
                        ];
                    }

                    usort($scored, fn ($a, $b) => $b['relevance'] <=> $a['relevance']);

                    return array_slice($scored, 0, $limit);
                }
            }

            // Fallback: top shared procedures from other agents by success_rate
            $procedures = DB::select('
                SELECT id, agent_id, name, trigger_pattern, action_sequence,
                       procedure_type, is_shared, success_rate, times_used,
                       last_used_at, created_at
                FROM agent_procedures
                WHERE is_shared = 1
                  AND is_retired = 0
                  AND agent_id != ?
                ORDER BY success_rate DESC, times_used DESC
                LIMIT ?
            ', [$excludeAgentId, $limit]);

            $results = [];
            foreach ($procedures as $proc) {
                $actions = json_decode($proc->action_sequence, true) ?: [];
                $results[] = [
                    'id' => $proc->id,
                    'agent_id' => $proc->agent_id,
                    'is_shared' => true,
                    'name' => $proc->name,
                    'type' => $proc->procedure_type,
                    'trigger_pattern' => $proc->trigger_pattern,
                    'action_sequence' => $actions,
                    'tools' => array_column($actions, 'tool'),
                    'success_rate' => (float) $proc->success_rate,
                    'times_used' => $proc->times_used,
                    'strategy_insight' => $proc->strategy_insight ?? null,
                    'relevance' => (float) $proc->success_rate * 0.5,
                    'similarity' => 0.0,
                    'recall_method' => 'shared_fallback',
                ];
            }

            return $results;

        } catch (\Throwable $e) {
            Log::debug('ProceduralMemory: recallSharedForTask failed (non-fatal)', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Build scored result array from semantic matches.
     *
     * Fetches full procedure data from MySQL, combines with pgvector similarity scores.
     */
    private function buildScoredResults(string $agentId, array $semanticMatches, string $method, float $reviewerMultiplier = 1.0): array
    {
        $procedureIds = array_keys($semanticMatches);
        $placeholders = implode(',', array_fill(0, count($procedureIds), '?'));

        $procedures = DB::select("
            SELECT id, agent_id, name, trigger_pattern, action_sequence, procedure_type,
                   is_shared, success_rate, times_used, times_succeeded, last_used_at, created_at
            FROM agent_procedures
            WHERE id IN ({$placeholders})
              AND is_retired = 0
              AND agent_id = ?
        ", [...$procedureIds, $agentId]);

        $scored = [];
        foreach ($procedures as $proc) {
            $similarity = $semanticMatches[$proc->id] ?? 0;

            $daysSinceUse = $proc->last_used_at
                ? (time() - strtotime($proc->last_used_at)) / 86400
                : 999;
            $recencyBoost = max(0.5, 1.0 - ($daysSinceUse / 60));

            // Phase 3.5: reviewer multiplier in [0.5, 1.5] applied
            // multiplicatively. A 0.5-acceptance-rate agent gets a
            // neutral 1.0 (0.5 + 0.5); 100% acceptance boosts to 1.5;
            // 0% acceptance penalizes to 0.5. No-feedback agents get
            // 1.0 (passed in by recallForTask).
            $relevance = $similarity * (float) $proc->success_rate * $recencyBoost * $reviewerMultiplier;

            $actions = json_decode($proc->action_sequence, true) ?: [];

            $scored[] = [
                'id' => $proc->id,
                'agent_id' => $proc->agent_id,
                'is_shared' => (bool) $proc->is_shared,
                'name' => $proc->name,
                'type' => $proc->procedure_type,
                'trigger_pattern' => $proc->trigger_pattern,
                'action_sequence' => $actions,
                'tools' => array_column($actions, 'tool'),
                'success_rate' => (float) $proc->success_rate,
                'times_used' => $proc->times_used,
                'relevance' => round($relevance, 4),
                'similarity' => round($similarity, 4),
                'reviewer_multiplier' => round($reviewerMultiplier, 3),
                'recall_method' => $method,
            ];
        }

        usort($scored, fn ($a, $b) => $b['relevance'] <=> $a['relevance']);

        return array_slice($scored, 0, config('agent_memory.procedural.max_recall_procedures', self::MAX_RECALL_PROCEDURES) * 2);
    }

    /**
     * Original Jaccard-based recall (fallback when embeddings unavailable).
     */
    private function recallForTaskJaccard(string $agentId, string $task, float $reviewerMultiplier = 1.0): array
    {
        $procedures = DB::select('
            SELECT id, name, trigger_pattern, action_sequence, procedure_type,
                   success_rate, times_used, times_succeeded, last_used_at, created_at
            FROM agent_procedures
            WHERE agent_id = ?
              AND is_retired = 0
            ORDER BY success_rate DESC, times_used DESC
        ', [$agentId]);

        if (empty($procedures)) {
            return [];
        }

        $scored = [];
        $taskKeywords = $this->extractKeywords($task);

        foreach ($procedures as $proc) {
            $patternKeywords = $this->extractKeywords($proc->trigger_pattern);
            $keywordOverlap = $this->jaccardSimilarity($taskKeywords, $patternKeywords);

            if ($keywordOverlap < 0.1) {
                continue;
            }

            $daysSinceUse = $proc->last_used_at
                ? (time() - strtotime($proc->last_used_at)) / 86400
                : 999;
            $recencyBoost = max(0.5, 1.0 - ($daysSinceUse / 60));

            // Phase 3.5 — see buildScoredResults comment.
            $relevance = $keywordOverlap * (float) $proc->success_rate * $recencyBoost * $reviewerMultiplier;

            $actions = json_decode($proc->action_sequence, true) ?: [];

            $scored[] = [
                'id' => $proc->id,
                'name' => $proc->name,
                'type' => $proc->procedure_type,
                'trigger_pattern' => $proc->trigger_pattern,
                'action_sequence' => $actions,
                'tools' => array_column($actions, 'tool'),
                'success_rate' => (float) $proc->success_rate,
                'times_used' => $proc->times_used,
                'relevance' => round($relevance, 4),
                'similarity' => round($keywordOverlap, 4),
                'reviewer_multiplier' => round($reviewerMultiplier, 3),
                'recall_method' => 'jaccard',
            ];
        }

        usort($scored, fn ($a, $b) => $b['relevance'] <=> $a['relevance']);

        return array_slice($scored, 0, config('agent_memory.procedural.max_recall_procedures', self::MAX_RECALL_PROCEDURES) * 2);
    }

    /**
     * Build a context string for injection into agent system prompt.
     *
     * Separates positive (do this) and negative (avoid this) procedures.
     *
     * @param  string  $agentId  Agent requesting context
     * @param  string  $task  Current task
     * @return string|null Formatted context or null if no relevant procedures
     */
    public function buildContextForTask(string $agentId, string $task): ?string
    {
        $procedures = $this->recallForTask($agentId, $task);

        if (empty($procedures)) {
            return null;
        }

        $positive = [];
        $negative = [];

        foreach ($procedures as $proc) {
            if ($proc['type'] === 'failure') {
                $negative[] = $proc;
            } elseif ($proc['success_rate'] >= config('agent_memory.procedural.recall_min_success_rate', self::RECALL_MIN_SUCCESS_RATE)
                && $proc['times_used'] >= config('agent_memory.procedural.recall_min_uses', self::RECALL_MIN_USES)) {
                $positive[] = $proc;
            }
        }

        $parts = [];

        if (! empty($positive)) {
            // Separate own-agent vs cross-agent shared procedures
            $ownPositive = array_filter($positive, fn ($p) => empty($p['is_shared']) || $p['agent_id'] === $agentId);
            $sharedPositive = array_filter($positive, fn ($p) => ! empty($p['is_shared']) && $p['agent_id'] !== $agentId);

            $maxOwn = config('agent_memory.procedural.max_recall_procedures', self::MAX_RECALL_PROCEDURES);

            if (! empty($ownPositive)) {
                $parts[] = '## Prior Learnings (Successful Procedures)';
                $parts[] = 'These tool sequences have worked well for similar tasks:';
                foreach (array_slice(array_values($ownPositive), 0, $maxOwn) as $proc) {
                    $tools = implode(' → ', $proc['tools']);
                    $rate = round($proc['success_rate'] * 100);
                    $parts[] = "- **{$proc['name']}** ({$rate}% success, used {$proc['times_used']}x): {$tools}";
                    $parts[] = "  Trigger: {$proc['trigger_pattern']}";
                    // AG-15: Include strategy insight if available
                    if (! empty($proc['strategy_insight'])) {
                        $parts[] = "  Strategy: {$proc['strategy_insight']}";
                    }
                }
            }

            // AG-4: Cross-agent shared procedures — shown as inspiration, not guaranteed executable
            if (! empty($sharedPositive)) {
                $parts[] = '';
                $parts[] = '## Shared Procedures from Other Agents';
                $parts[] = 'These patterns from other agents may be adaptable to this task:';
                foreach (array_slice(array_values($sharedPositive), 0, 2) as $proc) {
                    $tools = implode(' → ', $proc['tools']);
                    $rate = round($proc['success_rate'] * 100);
                    $parts[] = "- **{$proc['name']}** (from {$proc['agent_id']}, {$rate}% success): {$tools}";
                    $parts[] = "  Context: {$proc['trigger_pattern']}";
                }
            }
        }

        if (! empty($negative)) {
            $parts[] = '';
            $parts[] = '## Failure Memory (Avoid These Patterns)';
            $parts[] = 'These approaches have failed in similar situations:';
            foreach (array_slice($negative, 0, 2) as $proc) {
                $tools = implode(' → ', $proc['tools']);
                $parts[] = "- **{$proc['name']}**: {$tools}";
                $parts[] = "  Context: {$proc['trigger_pattern']}";
            }
        }

        return ! empty($parts) ? implode("\n", $parts) : null;
    }

    // =========================================================================
    // UPDATE: Track procedure usage and outcomes
    // =========================================================================

    /**
     * Record that a procedure was used, and whether it succeeded.
     *
     * @param  int  $procedureId  Procedure that was applied
     * @param  bool  $succeeded  Whether the outcome was successful
     */
    public function recordUsage(int $procedureId, bool $succeeded): void
    {
        try {
            if ($succeeded) {
                DB::update('
                    UPDATE agent_procedures
                    SET times_used = times_used + 1,
                        times_succeeded = times_succeeded + 1,
                        success_rate = (times_succeeded + 1) / (times_used + 1),
                        last_used_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ', [$procedureId]);
            } else {
                DB::update('
                    UPDATE agent_procedures
                    SET times_used = times_used + 1,
                        success_rate = times_succeeded / (times_used + 1),
                        last_used_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ', [$procedureId]);
            }
        } catch (Exception $e) {
            Log::debug('ProceduralMemory: Usage tracking failed', ['error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // REVIEWER FEEDBACK: Consume Phase 3 per-field audit blobs
    // =========================================================================

    /**
     * Phase 3.5 — derive a [0.5, 1.5] multiplier for procedure scoring
     * from the agent's per-agent reviewer-feedback acceptance rate.
     *
     * Mapping: 0% acceptance → 0.5 (penalty), 50% → 1.0 (neutral),
     * 100% → 1.5 (boost). Returns 1.0 when sample size is insufficient
     * (<5 proposals reviewed in the window) so a single bad day can't
     * tank an agent's procedures, and when no feedback exists at all.
     */
    private function getReviewerMultiplier(string $agentId): float
    {
        $summary = $this->getReviewerFeedbackSummary($agentId, 30);
        $sample = $summary['accepted_proposals'] + $summary['rejected_proposals'];
        if ($sample < 5 || $summary['acceptance_rate'] === null) {
            return 1.0;
        }

        return max(0.5, min(1.5, 0.5 + (float) $summary['acceptance_rate']));
    }

    /**
     * Read the operator's per-field decisions (Phase 3 audit blob) for an
     * agent's recent findings and summarize the signal.
     *
     * Audit blobs live in `agent_review_queue.reviewer_notes` as JSON with
     * `phase: "phase3_partial_apply"` — they record which proposals the
     * operator accepted, which were rejected with a structured code, and
     * how conflicts were resolved. This is the read side of that loop; a
     * future iteration will feed the summary into procedure scoring.
     *
     * Returns a per-agent rollup: acceptance rate, top reject-reason
     * histogram, sample size. Acceptance rate is `accepted / (accepted +
     * rejected)` across all proposals in the window (not per-review-row,
     * because a single row can carry 6+ proposals).
     *
     * @param  string  $agentId  e.g. 'genealogy-researcher', 'genealogy-records'
     * @param  int  $windowDays  lookback window, default 30
     * @return array{
     *     agent_id: string,
     *     window_days: int,
     *     total_reviews: int,
     *     accepted_proposals: int,
     *     rejected_proposals: int,
     *     acceptance_rate: float|null,
     *     reject_reason_histogram: array<string, int>,
     *     latest_reviewed_at: string|null,
     * }
     */
    public function getReviewerFeedbackSummary(string $agentId, int $windowDays = 30): array
    {
        $empty = [
            'agent_id' => $agentId,
            'window_days' => $windowDays,
            'total_reviews' => 0,
            'accepted_proposals' => 0,
            'rejected_proposals' => 0,
            'acceptance_rate' => null,
            'reject_reason_histogram' => [],
            'latest_reviewed_at' => null,
        ];
        if ($agentId === '') {
            return $empty;
        }

        try {
            $rows = DB::select(
                "SELECT reviewer_notes, reviewed_at
                 FROM agent_review_queue
                 WHERE agent_id = ?
                   AND reviewed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                   AND JSON_VALID(reviewer_notes)
                   AND JSON_UNQUOTE(JSON_EXTRACT(reviewer_notes, '$.phase')) = 'phase3_partial_apply'
                 ORDER BY reviewed_at DESC",
                [$agentId, $windowDays]
            );
        } catch (Exception $e) {
            Log::debug('ProceduralMemory: reviewer feedback query failed', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);

            return $empty;
        }

        if ($rows === []) {
            return $empty;
        }

        $accepted = 0;
        $rejected = 0;
        $reasons = [];
        foreach ($rows as $row) {
            $blob = json_decode($row->reviewer_notes ?? '', true);
            if (! is_array($blob) || ($blob['phase'] ?? null) !== 'phase3_partial_apply') {
                continue;
            }
            $acceptedIdx = is_array($blob['accepted_indices'] ?? null) ? $blob['accepted_indices'] : [];
            $rejectedIdx = is_array($blob['rejected_indices'] ?? null) ? $blob['rejected_indices'] : [];
            $accepted += count($acceptedIdx);
            $rejected += count($rejectedIdx);
            $codes = is_array($blob['reject_reason_codes'] ?? null) ? $blob['reject_reason_codes'] : [];
            foreach ($codes as $code) {
                $code = is_string($code) ? $code : 'other';
                $reasons[$code] = ($reasons[$code] ?? 0) + 1;
            }
        }

        $total = $accepted + $rejected;
        arsort($reasons);

        return [
            'agent_id' => $agentId,
            'window_days' => $windowDays,
            'total_reviews' => count($rows),
            'accepted_proposals' => $accepted,
            'rejected_proposals' => $rejected,
            'acceptance_rate' => $total > 0 ? round($accepted / $total, 4) : null,
            'reject_reason_histogram' => $reasons,
            'latest_reviewed_at' => $rows[0]->reviewed_at ?? null,
        ];
    }

    /**
     * Multi-agent feedback rollup. Runs getReviewerFeedbackSummary for
     * every agent_id that appears in the window; skips agents with no
     * Phase 3 blobs. Useful for the daily digest and the research-hub
     * agent-status endpoint.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getReviewerFeedbackForAllAgents(int $windowDays = 30): array
    {
        try {
            $rows = DB::select(
                "SELECT DISTINCT agent_id
                 FROM agent_review_queue
                 WHERE reviewed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                   AND JSON_VALID(reviewer_notes)
                   AND JSON_UNQUOTE(JSON_EXTRACT(reviewer_notes, '$.phase')) = 'phase3_partial_apply'",
                [$windowDays]
            );
        } catch (Exception $e) {
            Log::debug('ProceduralMemory: reviewer feedback agent lookup failed', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $agentId = (string) ($row->agent_id ?? '');
            if ($agentId === '') {
                continue;
            }
            $summary = $this->getReviewerFeedbackSummary($agentId, $windowDays);
            if ($summary['total_reviews'] > 0) {
                $out[] = $summary;
            }
        }
        // Sort by acceptance_rate DESC (null last), then by sample size.
        usort($out, function ($a, $b) {
            $ar = $a['acceptance_rate'] ?? -1;
            $br = $b['acceptance_rate'] ?? -1;
            if ($ar !== $br) {
                return $br <=> $ar;
            }

            return ($b['total_reviews'] ?? 0) <=> ($a['total_reviews'] ?? 0);
        });

        return $out;
    }

    /**
     * Daily reject-code rollup for the structured Phase 3 genealogy feedback
     * blobs written to agent_review_queue.reviewer_notes.
     *
     * @return array<int, array{
     *     date: string,
     *     agent_id: string,
     *     window_days: int,
     *     total_reviews: int,
     *     accepted_proposals: int,
     *     rejected_proposals: int,
     *     acceptance_rate: float|null,
     *     reject_reason_histogram: array<string, int>,
     *     latest_reviewed_at: string|null
     * }>
     */
    public function getReviewerFeedbackDailyRollup(int $windowDays = 30, ?string $agentId = null): array
    {
        $agentId = trim((string) $agentId);

        try {
            $params = [$windowDays];
            $agentSql = '';
            if ($agentId !== '') {
                $agentSql = ' AND agent_id = ?';
                $params[] = $agentId;
            }

            $rows = DB::select(
                "SELECT agent_id, reviewer_notes, reviewed_at
                 FROM agent_review_queue
                 WHERE reviewed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                   AND JSON_VALID(reviewer_notes)
                   AND JSON_UNQUOTE(JSON_EXTRACT(reviewer_notes, '$.phase')) = 'phase3_partial_apply'
                   {$agentSql}
                 ORDER BY reviewed_at DESC",
                $params
            );
        } catch (Exception $e) {
            Log::debug('ProceduralMemory: reviewer feedback daily rollup query failed', [
                'agent_id' => $agentId !== '' ? $agentId : null,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $rollup = [];
        foreach ($rows as $row) {
            $blob = json_decode($row->reviewer_notes ?? '', true);
            if (! is_array($blob) || ($blob['phase'] ?? null) !== 'phase3_partial_apply') {
                continue;
            }

            $date = $this->reviewFeedbackDate($row->reviewed_at ?? null);
            $rowAgent = (string) ($row->agent_id ?? '');
            if ($date === null || $rowAgent === '') {
                continue;
            }

            $key = $date.'|'.$rowAgent;
            if (! isset($rollup[$key])) {
                $rollup[$key] = [
                    'date' => $date,
                    'agent_id' => $rowAgent,
                    'window_days' => $windowDays,
                    'total_reviews' => 0,
                    'accepted_proposals' => 0,
                    'rejected_proposals' => 0,
                    'acceptance_rate' => null,
                    'reject_reason_histogram' => [],
                    'latest_reviewed_at' => null,
                ];
            }

            $accepted = is_array($blob['accepted_indices'] ?? null) ? $blob['accepted_indices'] : [];
            $rejected = is_array($blob['rejected_indices'] ?? null) ? $blob['rejected_indices'] : [];
            $rollup[$key]['total_reviews']++;
            $rollup[$key]['accepted_proposals'] += count($accepted);
            $rollup[$key]['rejected_proposals'] += count($rejected);

            $reviewedAt = (string) ($row->reviewed_at ?? '');
            if (
                $reviewedAt !== ''
                && (
                    $rollup[$key]['latest_reviewed_at'] === null
                    || strcmp($reviewedAt, (string) $rollup[$key]['latest_reviewed_at']) > 0
                )
            ) {
                $rollup[$key]['latest_reviewed_at'] = $reviewedAt;
            }

            $codes = is_array($blob['reject_reason_codes'] ?? null) ? $blob['reject_reason_codes'] : [];
            foreach ($codes as $code) {
                $code = is_string($code) && $code !== '' ? $code : 'other';
                $rollup[$key]['reject_reason_histogram'][$code] = (
                    $rollup[$key]['reject_reason_histogram'][$code] ?? 0
                ) + 1;
            }
        }

        foreach ($rollup as &$row) {
            $total = $row['accepted_proposals'] + $row['rejected_proposals'];
            $row['acceptance_rate'] = $total > 0
                ? round($row['accepted_proposals'] / $total, 4)
                : null;
            arsort($row['reject_reason_histogram']);
        }
        unset($row);

        $out = array_values($rollup);
        usort($out, static function (array $a, array $b): int {
            $dateCompare = strcmp((string) $b['date'], (string) $a['date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return strcmp((string) $a['agent_id'], (string) $b['agent_id']);
        });

        return $out;
    }

    /**
     * Daily packet-level reason-code rollup for genealogy review packets.
     *
     * This intentionally stays separate from the Phase 3 per-field reviewer
     * feedback rollups because packet decisions do not carry accepted/rejected
     * proposal counts and should not affect agent acceptance-rate scoring.
     *
     * @return array<int, array{
     *     date: string,
     *     agent_id: string,
     *     window_days: int,
     *     total_decisions: int,
     *     action_histogram: array<string, int>,
     *     reason_code_histogram: array<string, int>,
     *     latest_decision_at: string|null
     * }>
     */
    public function getReviewPacketReasonCodeDailyRollup(int $windowDays = 30, ?string $agentId = null): array
    {
        $agentId = trim((string) $agentId);

        try {
            $params = [$windowDays];
            $agentSql = '';
            if ($agentId !== '') {
                $agentSql = ' AND agent_id = ?';
                $params[] = $agentId;
            }

            $rows = DB::select(
                "SELECT agent_id, details, updated_at
                 FROM agent_review_queue
                 WHERE review_type = 'genealogy_review_packet'
                   AND updated_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                   AND JSON_VALID(details)
                   {$agentSql}
                 ORDER BY updated_at DESC",
                $params
            );
        } catch (Exception $e) {
            Log::debug('ProceduralMemory: review packet reason-code rollup query failed', [
                'agent_id' => $agentId !== '' ? $agentId : null,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $cutoff = now()->subDays($windowDays)->startOfDay()->getTimestamp();
        $rollup = [];
        foreach ($rows as $row) {
            $rowAgent = (string) ($row->agent_id ?? '');
            if ($rowAgent === '') {
                continue;
            }

            $details = json_decode((string) ($row->details ?? ''), true);
            if (! is_array($details)) {
                continue;
            }

            $decisionLog = is_array($details['decision_log'] ?? null) ? $details['decision_log'] : [];
            foreach ($decisionLog as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
                $reasonCode = is_string($meta['reason_code'] ?? null) ? trim($meta['reason_code']) : '';
                if ($reasonCode === '') {
                    continue;
                }

                $decisionAt = (string) ($entry['created_at'] ?? $row->updated_at ?? '');
                if (! $this->reviewFeedbackWithinWindow($decisionAt, $cutoff)) {
                    continue;
                }

                $date = $this->reviewFeedbackDate($decisionAt);
                if ($date === null) {
                    continue;
                }

                $action = is_string($entry['action'] ?? null) && trim($entry['action']) !== ''
                    ? trim($entry['action'])
                    : 'unknown';
                $key = $date.'|'.$rowAgent;
                if (! isset($rollup[$key])) {
                    $rollup[$key] = [
                        'date' => $date,
                        'agent_id' => $rowAgent,
                        'window_days' => $windowDays,
                        'total_decisions' => 0,
                        'action_histogram' => [],
                        'reason_code_histogram' => [],
                        'latest_decision_at' => null,
                    ];
                }

                $rollup[$key]['total_decisions']++;
                $rollup[$key]['action_histogram'][$action] = (
                    $rollup[$key]['action_histogram'][$action] ?? 0
                ) + 1;
                $rollup[$key]['reason_code_histogram'][$reasonCode] = (
                    $rollup[$key]['reason_code_histogram'][$reasonCode] ?? 0
                ) + 1;

                if (
                    $rollup[$key]['latest_decision_at'] === null
                    || strcmp($decisionAt, (string) $rollup[$key]['latest_decision_at']) > 0
                ) {
                    $rollup[$key]['latest_decision_at'] = $decisionAt;
                }
            }
        }

        foreach ($rollup as &$row) {
            arsort($row['action_histogram']);
            arsort($row['reason_code_histogram']);
        }
        unset($row);

        $out = array_values($rollup);
        usort($out, static function (array $a, array $b): int {
            $dateCompare = strcmp((string) $b['date'], (string) $a['date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return strcmp((string) $a['agent_id'], (string) $b['agent_id']);
        });

        return $out;
    }

    private function reviewFeedbackDate(mixed $reviewedAt): ?string
    {
        $value = (string) $reviewedAt;
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            return substr($value, 0, 10);
        }

        return null;
    }

    private function reviewFeedbackWithinWindow(string $value, int $cutoff): bool
    {
        $timestamp = strtotime($value);

        return $timestamp !== false && $timestamp >= $cutoff;
    }

    // =========================================================================
    // CONSOLIDATE: Merge, retire, promote
    // =========================================================================

    /**
     * Run consolidation across all agent procedures.
     *
     * Called by knowledge-curator agent or artisan command.
     * Actions: merge similar, retire stale/low-performers, promote high-performers.
     *
     * @return array Summary of consolidation actions
     */
    public function consolidate(): array
    {
        $stats = [
            'merged' => 0,
            'retired' => 0,
            'promoted' => 0,
            'total_active' => 0,
            'total_retired' => 0,
        ];

        try {
            // 1. Retire stale procedures (unused for STALE_DAYS + low success)
            // Get IDs before retiring so we can clean up embeddings
            $toRetire = DB::select('
                SELECT id FROM agent_procedures
                WHERE is_retired = 0
                  AND is_canonical = 0
                  AND (
                      (last_used_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND success_rate < ?)
                      OR (times_used >= ? AND success_rate < ?)
                  )
            ', [
                config('agent_memory.procedural.stale_days', self::STALE_DAYS),
                config('agent_memory.procedural.recall_min_success_rate', self::RECALL_MIN_SUCCESS_RATE),
                config('agent_memory.procedural.retire_min_uses', self::RETIRE_MIN_USES),
                config('agent_memory.procedural.retire_threshold', self::RETIRE_THRESHOLD),
            ]);

            if (! empty($toRetire)) {
                $retireIds = array_column(array_map(fn ($r) => (array) $r, $toRetire), 'id');
                $placeholders = implode(',', array_fill(0, count($retireIds), '?'));

                $retired = DB::update("
                    UPDATE agent_procedures
                    SET is_retired = 1, updated_at = NOW()
                    WHERE id IN ({$placeholders})
                ", $retireIds);
                $stats['retired'] = $retired;

                // Clean up embeddings for retired procedures
                foreach ($retireIds as $rid) {
                    $this->removeEmbedding($rid);
                }
            }

            // 2. Promote high-performers to canonical
            $promoted = DB::update('
                UPDATE agent_procedures
                SET is_canonical = 1, updated_at = NOW()
                WHERE is_retired = 0
                  AND is_canonical = 0
                  AND times_used >= 5
                  AND success_rate >= 0.90
            ');
            $stats['promoted'] = $promoted;

            // 3. Merge similar procedures per agent
            $stats['merged'] = $this->mergeSimilarProcedures();

            // 4. AG-4: Auto-promote canonical success procedures to is_shared = 1
            $shared = DB::update("
                UPDATE agent_procedures
                SET is_shared = 1, updated_at = NOW()
                WHERE is_canonical = 1
                  AND is_retired = 0
                  AND is_shared = 0
                  AND procedure_type = 'success'
            ");
            $stats['shared'] = $shared;

            // 5. Count totals
            $counts = DB::select('
                SELECT
                    SUM(CASE WHEN is_retired = 0 THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN is_retired = 1 THEN 1 ELSE 0 END) as retired,
                    SUM(CASE WHEN is_shared = 1 AND is_retired = 0 THEN 1 ELSE 0 END) as shared
                FROM agent_procedures
            ');
            $stats['total_active'] = $counts[0]->active ?? 0;
            $stats['total_retired'] = $counts[0]->retired ?? 0;
            $stats['total_shared'] = $counts[0]->shared ?? 0;

            Log::info('ProceduralMemory: Consolidation complete', $stats);

        } catch (Exception $e) {
            Log::error('ProceduralMemory: Consolidation failed', ['error' => $e->getMessage()]);
            $stats['error'] = $e->getMessage();
        }

        return $stats;
    }

    /**
     * AG-4: Manually promote a specific procedure to is_shared = 1.
     */
    public function promoteToShared(int $procedureId): bool
    {
        try {
            $updated = DB::update('
                UPDATE agent_procedures
                SET is_shared = 1, updated_at = NOW()
                WHERE id = ? AND is_retired = 0
            ', [$procedureId]);

            return $updated > 0;
        } catch (\Throwable $e) {
            Log::warning('ProceduralMemory: promoteToShared failed', ['id' => $procedureId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Merge similar procedures within each agent.
     *
     * Two procedures are "similar" if their tool sequences have a Jaccard index
     * above MERGE_SIMILARITY_THRESHOLD. The one with higher success rate survives;
     * the other is retired with a merge note.
     */
    private function mergeSimilarProcedures(): int
    {
        $merged = 0;

        $agents = DB::select('
            SELECT DISTINCT agent_id FROM agent_procedures WHERE is_retired = 0
        ');

        foreach ($agents as $agent) {
            $procedures = DB::select('
                SELECT id, name, action_sequence, success_rate, times_used
                FROM agent_procedures
                WHERE agent_id = ? AND is_retired = 0
                ORDER BY success_rate DESC, times_used DESC
            ', [$agent->agent_id]);

            $retired = [];
            for ($i = 0; $i < count($procedures); $i++) {
                if (in_array($procedures[$i]->id, $retired)) {
                    continue;
                }

                $seqA = array_column(json_decode($procedures[$i]->action_sequence, true) ?: [], 'tool');

                for ($j = $i + 1; $j < count($procedures); $j++) {
                    if (in_array($procedures[$j]->id, $retired)) {
                        continue;
                    }

                    $seqB = array_column(json_decode($procedures[$j]->action_sequence, true) ?: [], 'tool');
                    $similarity = $this->jaccardSimilarity($seqA, $seqB);

                    if ($similarity >= config('agent_memory.procedural.merge_similarity_threshold', self::MERGE_SIMILARITY_THRESHOLD)) {
                        // Retire the weaker one; aggregate usage counts into the survivor
                        $survivorId = $procedures[$i]->id;
                        $loserId = $procedures[$j]->id;

                        DB::update('
                            UPDATE agent_procedures
                            SET times_used = times_used + ?,
                                times_succeeded = times_succeeded + ?,
                                success_rate = CASE
                                    WHEN (times_used + ?) > 0
                                    THEN (times_succeeded + ?) / (times_used + ?)
                                    ELSE success_rate
                                END,
                                updated_at = NOW()
                            WHERE id = ?
                        ', [
                            $procedures[$j]->times_used,
                            (int) round($procedures[$j]->times_used * $procedures[$j]->success_rate),
                            $procedures[$j]->times_used,
                            (int) round($procedures[$j]->times_used * $procedures[$j]->success_rate),
                            $procedures[$j]->times_used,
                            $survivorId,
                        ]);

                        DB::update('
                            UPDATE agent_procedures
                            SET is_retired = 1, updated_at = NOW()
                            WHERE id = ?
                        ', [$loserId]);

                        $this->removeEmbedding($loserId);

                        $retired[] = $loserId;
                        $merged++;
                    }
                }
            }
        }

        return $merged;
    }

    // =========================================================================
    // AGENT TOOLS: Methods called by registered agent tools
    // =========================================================================

    /**
     * Tool: recall_procedures — query past procedures matching context.
     */
    public function recallProcedures(array $params): array
    {
        $agentId = $params['agent_id'] ?? 'unknown';
        $query = $params['query'] ?? $params['task'] ?? '';

        if (empty($query)) {
            return ['success' => false, 'error' => 'Query/task description required'];
        }

        $procedures = $this->recallForTask($agentId, $query);

        if (empty($procedures)) {
            return [
                'success' => true,
                'result_text' => 'No matching procedures found for this task. This appears to be a new type of task for this agent.',
                'procedures' => [],
            ];
        }

        $method = $procedures[0]['recall_method'] ?? 'unknown';
        $lines = ['Found '.count($procedures)." relevant procedure(s) (recall: {$method}):"];
        foreach ($procedures as $proc) {
            $tools = implode(' → ', $proc['tools']);
            $rate = round($proc['success_rate'] * 100);
            $type = $proc['type'] === 'failure' ? '[FAILURE]' : '[SUCCESS]';
            $sim = $proc['similarity'] ?? 0;
            $lines[] = "- {$type} **{$proc['name']}** (similarity: {$sim}, relevance: {$proc['relevance']}, success: {$rate}%, used: {$proc['times_used']}x)";
            $lines[] = "  Tools: {$tools}";
            $lines[] = "  Trigger: {$proc['trigger_pattern']}";
        }

        return [
            'success' => true,
            'result_text' => implode("\n", $lines),
            'procedures' => $procedures,
        ];
    }

    /**
     * Tool: save_procedure — manually save a procedure from current context.
     */
    public function saveProcedure(array $params): array
    {
        $agentId = $params['agent_id'] ?? 'unknown';
        $name = $params['name'] ?? null;
        $triggerPattern = $params['trigger_pattern'] ?? $params['trigger'] ?? null;
        $toolSequence = $params['tool_sequence'] ?? $params['tools'] ?? null;
        $procedureType = $params['type'] ?? 'success';

        if (! $name || ! $triggerPattern || ! $toolSequence) {
            return ['success' => false, 'error' => 'Required: name, trigger_pattern, tool_sequence'];
        }

        // Normalize tool_sequence: accept either array of tool names or full action objects
        $actionSequence = [];
        if (is_array($toolSequence)) {
            foreach ($toolSequence as $item) {
                if (is_string($item)) {
                    $actionSequence[] = ['tool' => $item, 'params' => []];
                } elseif (is_array($item)) {
                    $actionSequence[] = [
                        'tool' => $item['tool'] ?? $item['name'] ?? 'unknown',
                        'params' => $item['params'] ?? [],
                        'phase' => $item['phase'] ?? null,
                    ];
                }
            }
        }

        if (count($actionSequence) < 1) {
            return ['success' => false, 'error' => 'tool_sequence must contain at least 1 tool'];
        }

        try {
            DB::insert('
                INSERT INTO agent_procedures
                    (agent_id, name, trigger_pattern, action_sequence, procedure_type,
                     success_rate, times_used, times_succeeded, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 1.0000, 0, 0, NOW(), NOW())
            ', [
                $agentId,
                substr($name, 0, 200),
                substr($triggerPattern, 0, 500),
                json_encode($actionSequence),
                $procedureType === 'failure' ? 'failure' : 'success',
            ]);

            $id = DB::getPdo()->lastInsertId();

            // Generate and store semantic embedding
            $this->storeEmbedding((int) $id, $agentId, $triggerPattern);

            return [
                'success' => true,
                'result_text' => "Procedure '{$name}' saved (ID: {$id}, type: {$procedureType}, tools: ".count($actionSequence).')',
                'procedure_id' => $id,
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to save procedure: '.$e->getMessage()];
        }
    }

    /**
     * Tool: procedure_stats — view procedural memory statistics.
     */
    public function procedureStats(array $params): array
    {
        $agentId = $params['agent_id'] ?? null;

        try {
            $whereAgent = $agentId ? 'WHERE agent_id = ?' : '';
            $bindings = $agentId ? [$agentId] : [];

            $stats = DB::select("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN is_retired = 0 THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN is_retired = 1 THEN 1 ELSE 0 END) as retired,
                    SUM(CASE WHEN is_canonical = 1 THEN 1 ELSE 0 END) as canonical,
                    SUM(CASE WHEN procedure_type = 'failure' AND is_retired = 0 THEN 1 ELSE 0 END) as failure_memories,
                    ROUND(AVG(CASE WHEN is_retired = 0 THEN success_rate ELSE NULL END), 4) as avg_success_rate,
                    SUM(CASE WHEN is_retired = 0 THEN times_used ELSE 0 END) as total_uses,
                    COUNT(DISTINCT agent_id) as agents_with_memory
                FROM agent_procedures
                {$whereAgent}
            ", $bindings);

            $s = $stats[0];

            // Per-agent breakdown
            $perAgent = DB::select("
                SELECT agent_id,
                       COUNT(*) as procedures,
                       SUM(CASE WHEN is_retired = 0 THEN 1 ELSE 0 END) as active,
                       ROUND(AVG(CASE WHEN is_retired = 0 THEN success_rate ELSE NULL END), 4) as avg_rate,
                       SUM(times_used) as total_uses
                FROM agent_procedures
                {$whereAgent}
                GROUP BY agent_id
                ORDER BY active DESC
            ", $bindings);

            $lines = [
                'Procedural Memory Statistics'.($agentId ? " for {$agentId}" : ''),
                "Total: {$s->total} | Active: {$s->active} | Retired: {$s->retired} | Canonical: {$s->canonical}",
                "Failure memories: {$s->failure_memories} | Avg success rate: ".round(($s->avg_success_rate ?? 0) * 100).'%',
                "Total uses: {$s->total_uses} | Agents with memory: {$s->agents_with_memory}",
                '',
                'Per-agent breakdown:',
            ];

            foreach ($perAgent as $pa) {
                $rate = round(($pa->avg_rate ?? 0) * 100);
                $lines[] = "- {$pa->agent_id}: {$pa->active} active, {$rate}% avg, {$pa->total_uses} uses";
            }

            return [
                'success' => true,
                'result_text' => implode("\n", $lines),
                'stats' => (array) $s,
                'per_agent' => array_map(fn ($r) => (array) $r, $perAgent),
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Stats query failed: '.$e->getMessage()];
        }
    }

    /**
     * Tool: consolidate_procedures — run consolidation cycle.
     */
    public function consolidateProcedures(array $params): array
    {
        $stats = $this->consolidate();

        $lines = [
            'Consolidation complete:',
            "- Merged: {$stats['merged']} duplicate procedures",
            "- Retired: {$stats['retired']} stale/low-performing procedures",
            "- Promoted: {$stats['promoted']} procedures to canonical",
            "- Active: {$stats['total_active']} | Retired: {$stats['total_retired']}",
        ];

        if (isset($stats['error'])) {
            $lines[] = "- Error: {$stats['error']}";
        }

        return [
            'success' => ! isset($stats['error']),
            'result_text' => implode("\n", $lines),
            'stats' => $stats,
        ];
    }

    // =========================================================================
    // API: Methods for controller/UI consumption
    // =========================================================================

    /**
     * Get all procedures with optional filters.
     */
    public function getProcedures(array $filters = []): array
    {
        $where = ['1=1'];
        $bindings = [];

        if (! empty($filters['agent_id'])) {
            $where[] = 'agent_id = ?';
            $bindings[] = $filters['agent_id'];
        }

        if (isset($filters['is_retired'])) {
            $where[] = 'is_retired = ?';
            $bindings[] = (int) $filters['is_retired'];
        }

        if (isset($filters['is_canonical'])) {
            $where[] = 'is_canonical = ?';
            $bindings[] = (int) $filters['is_canonical'];
        }

        if (! empty($filters['type'])) {
            $where[] = 'procedure_type = ?';
            $bindings[] = $filters['type'];
        }

        $whereClause = implode(' AND ', $where);
        $limit = min($filters['limit'] ?? 50, 200);
        $bindings[] = $limit;

        return array_map(function ($row) {
            $row->action_sequence = json_decode($row->action_sequence, true) ?: [];

            return (array) $row;
        }, DB::select("
            SELECT * FROM agent_procedures
            WHERE {$whereClause}
            ORDER BY is_retired ASC, success_rate DESC, times_used DESC
            LIMIT ?
        ", $bindings));
    }

    /**
     * Get summary stats for dashboard.
     */
    public function getDashboardStats(): array
    {
        $stats = DB::select("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN is_retired = 0 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN is_retired = 1 THEN 1 ELSE 0 END) as retired,
                SUM(CASE WHEN is_canonical = 1 THEN 1 ELSE 0 END) as canonical,
                SUM(CASE WHEN procedure_type = 'failure' AND is_retired = 0 THEN 1 ELSE 0 END) as failure_memories,
                ROUND(AVG(CASE WHEN is_retired = 0 THEN success_rate ELSE NULL END) * 100, 1) as avg_success_rate,
                SUM(CASE WHEN is_retired = 0 THEN times_used ELSE 0 END) as total_uses,
                COUNT(DISTINCT agent_id) as agents_with_memory
            FROM agent_procedures
        ");

        $perAgent = DB::select('
            SELECT agent_id,
                   SUM(CASE WHEN is_retired = 0 THEN 1 ELSE 0 END) as active,
                   SUM(CASE WHEN is_retired = 1 THEN 1 ELSE 0 END) as retired,
                   ROUND(AVG(CASE WHEN is_retired = 0 THEN success_rate ELSE NULL END) * 100, 1) as avg_success_rate,
                   SUM(times_used) as total_uses,
                   MAX(last_used_at) as last_active
            FROM agent_procedures
            GROUP BY agent_id
            ORDER BY active DESC
        ');

        return [
            'summary' => (array) ($stats[0] ?? new \stdClass),
            'per_agent' => array_map(fn ($r) => (array) $r, $perAgent),
        ];
    }

    /**
     * Retire a specific procedure.
     */
    public function retireProcedure(int $id): bool
    {
        $result = DB::update('
            UPDATE agent_procedures SET is_retired = 1, updated_at = NOW() WHERE id = ?
        ', [$id]) > 0;

        if ($result) {
            $this->removeEmbedding($id);
        }

        return $result;
    }

    /**
     * Restore a retired procedure.
     */
    public function restoreProcedure(int $id): bool
    {
        return DB::update('
            UPDATE agent_procedures SET is_retired = 0, updated_at = NOW() WHERE id = ?
        ', [$id]) > 0;
    }

    // =========================================================================
    // EMBEDDING: Semantic memory via pgvector
    // =========================================================================

    /**
     * Generate and store an embedding for a procedure's trigger pattern.
     *
     * Stores in PostgreSQL agent_procedure_embeddings table, bridging to
     * MySQL agent_procedures by procedure_id. Non-fatal — if embedding
     * fails, procedure still works via Jaccard fallback.
     */
    private function storeEmbedding(int $procedureId, string $agentId, string $triggerPattern): bool
    {
        try {
            $result = $this->aiService->generateEmbedding($triggerPattern);

            if (! ($result['success'] ?? false) || empty($result['embedding'])) {
                Log::debug('ProceduralMemory: Embedding generation failed', [
                    'procedure_id' => $procedureId,
                    'error' => $result['error'] ?? 'no embedding returned',
                ]);

                return false;
            }

            $embeddingStr = PgVector::literal($result['embedding']);

            DB::connection('pgsql_rag')->statement('
                INSERT INTO agent_procedure_embeddings (procedure_id, agent_id, embedding, created_at, updated_at)
                VALUES (?, ?, ?::vector, NOW(), NOW())
                ON CONFLICT (procedure_id) DO UPDATE SET
                    embedding = EXCLUDED.embedding,
                    updated_at = NOW()
            ', [$procedureId, $agentId, $embeddingStr]);

            return true;

        } catch (\Throwable $e) {
            Log::debug('ProceduralMemory: Embedding storage failed (non-fatal)', [
                'procedure_id' => $procedureId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Semantic recall via pgvector cosine similarity.
     *
     * Returns procedure IDs with similarity scores from PostgreSQL,
     * to be joined with MySQL procedure data.
     */
    private function semanticRecall(string $agentId, string $task, int $limit = 12): ?array
    {
        try {
            $result = $this->aiService->generateEmbedding($task);

            if (! ($result['success'] ?? false) || empty($result['embedding'])) {
                return null; // Signal caller to use Jaccard fallback
            }

            $embeddingStr = PgVector::literal($result['embedding']);

            $rows = DB::connection('pgsql_rag')->select('
                SELECT procedure_id,
                       1 - (embedding <=> ?::vector) as similarity
                FROM agent_procedure_embeddings
                WHERE agent_id = ?
                  AND 1 - (embedding <=> ?::vector) >= ?
                ORDER BY embedding <=> ?::vector ASC
                LIMIT ?
            ', [$embeddingStr, $agentId, $embeddingStr, config('agent_memory.procedural.semantic_min_similarity', self::SEMANTIC_MIN_SIMILARITY), $embeddingStr, $limit]);

            if (empty($rows)) {
                return []; // No matches, but semantic search worked
            }

            $matches = [];
            foreach ($rows as $row) {
                $matches[$row->procedure_id] = (float) $row->similarity;
            }

            return $matches;

        } catch (\Throwable $e) {
            Log::debug('ProceduralMemory: Semantic recall failed, will use Jaccard', [
                'error' => $e->getMessage(),
            ]);

            return null; // Signal Jaccard fallback
        }
    }

    /**
     * Remove embedding when a procedure is retired.
     */
    private function removeEmbedding(int $procedureId): void
    {
        try {
            DB::connection('pgsql_rag')->delete('
                DELETE FROM agent_procedure_embeddings WHERE procedure_id = ?
            ', [$procedureId]);
        } catch (\Throwable $e) {
            Log::debug('AgentProceduralMemoryService: procedure embedding removal failed', ['procedure_id' => $procedureId, 'error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Check if an action sequence is a duplicate of an existing procedure.
     */
    private function isDuplicate(string $agentId, array $actionSequence): bool
    {
        $existing = DB::select('
            SELECT action_sequence FROM agent_procedures
            WHERE agent_id = ? AND is_retired = 0
        ', [$agentId]);

        $newTools = array_column($actionSequence, 'tool');

        foreach ($existing as $proc) {
            $existingTools = array_column(json_decode($proc->action_sequence, true) ?: [], 'tool');
            if ($this->jaccardSimilarity($newTools, $existingTools) >= config('agent_memory.procedural.merge_similarity_threshold', self::MERGE_SIMILARITY_THRESHOLD)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Jaccard similarity between two arrays (set intersection / set union).
     */
    private function jaccardSimilarity(array $a, array $b): float
    {
        if (empty($a) && empty($b)) {
            return 1.0;
        }
        if (empty($a) || empty($b)) {
            return 0.0;
        }

        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * Extract keywords from a text for matching.
     */
    private function extractKeywords(string $text): array
    {
        $text = strtolower($text);
        // Remove common stop words
        $stopWords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been',
            'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
            'shall', 'should', 'may', 'might', 'can', 'could', 'must', 'and', 'or',
            'but', 'not', 'no', 'if', 'then', 'else', 'when', 'where', 'which',
            'who', 'what', 'how', 'all', 'each', 'every', 'both', 'few', 'more',
            'most', 'other', 'some', 'such', 'than', 'too', 'very', 'just', 'only',
            'own', 'same', 'so', 'also', 'as', 'at', 'by', 'for', 'from', 'in',
            'into', 'of', 'on', 'to', 'up', 'with', 'that', 'this', 'it', 'its',
            'your', 'their', 'our', 'my', 'run', 'check', 'perform', 'execute',
        ];

        $words = preg_split('/[\s\-_.,;:!?()[\]{}]+/', $text);
        $words = array_filter($words, fn ($w) => strlen($w) >= 3 && ! in_array($w, $stopWords));

        return array_values(array_unique($words));
    }

    /**
     * Sanitize tool params for storage (remove overly-specific values).
     */
    private function sanitizeParams(array $params): array
    {
        // Remove runtime-specific values that shouldn't be replayed literally
        $removeKeys = ['session_id', 'timestamp', 'nonce', 'token'];

        foreach ($removeKeys as $key) {
            unset($params[$key]);
        }

        return $params;
    }

    /**
     * Generate a human-readable name for a procedure.
     */
    private function generateProcedureName(string $agentId, array $toolNames, string $task): string
    {
        // Take first few words of the task as the name basis
        $words = explode(' ', trim($task));
        $taskSummary = implode(' ', array_slice($words, 0, 6));

        if (strlen($taskSummary) > 60) {
            $taskSummary = substr($taskSummary, 0, 57).'...';
        }

        $toolCount = count($toolNames);

        return "{$taskSummary} ({$toolCount} tools)";
    }

    /**
     * AG-15: Extract strategy-level insight from a tool sequence.
     *
     * Heuristic extraction (no LLM call) that identifies:
     * - Workflow pattern (gather→analyze→report vs. single-shot)
     * - Tool diversity and ordering rationale
     * - Failure patterns and recovery strategies
     * - Phase progression patterns
     */
    private function extractStrategyInsight(
        array $toolNames,
        array $actionSequence,
        array $allToolCalls,
        string $task,
        string $procedureType
    ): ?string {
        $parts = [];
        $totalCalls = count($allToolCalls);
        $successCount = count(array_filter($allToolCalls, fn ($tc) => $tc['success'] ?? false));
        $uniqueTools = count($toolNames);

        // Pattern: tool diversity
        if ($uniqueTools >= 4) {
            $parts[] = "Broad exploration strategy — {$uniqueTools} distinct tools used";
        } elseif ($uniqueTools === 1) {
            $parts[] = 'Focused single-tool strategy — repeated '.$toolNames[0];
        }

        // Pattern: phase progression
        $phases = array_unique(array_filter(array_column($actionSequence, 'phase')));
        if (count($phases) > 1) {
            $parts[] = 'Multi-phase: '.implode(' → ', $phases);
        }

        // Pattern: success/failure ratio insight
        if ($procedureType === 'failure' && $totalCalls > 0) {
            $failRate = round((($totalCalls - $successCount) / $totalCalls) * 100);
            $failedTools = array_unique(array_column(
                array_filter($allToolCalls, fn ($tc) => ! ($tc['success'] ?? false)),
                'tool'
            ));
            $parts[] = "Failure pattern ({$failRate}% fail rate): ".implode(', ', array_slice($failedTools, 0, 3)).' unreliable';
        } elseif ($successCount === $totalCalls && $totalCalls >= 3) {
            $parts[] = "Reliable pattern — {$totalCalls}/{$totalCalls} calls succeeded";
        }

        // Pattern: tool ordering (gather-first vs action-first)
        if (count($actionSequence) >= 3) {
            $firstTools = array_slice(array_column($actionSequence, 'tool'), 0, 2);
            $readTools = ['get_', 'list_', 'search_', 'recall_', 'check_'];
            $isGatherFirst = false;
            foreach ($firstTools as $tool) {
                foreach ($readTools as $prefix) {
                    if (str_starts_with($tool, $prefix)) {
                        $isGatherFirst = true;
                        break 2;
                    }
                }
            }
            if ($isGatherFirst) {
                $parts[] = 'Gather-first approach — data collection before action';
            }
        }

        return ! empty($parts) ? implode('. ', $parts).'.' : null;
    }
}
