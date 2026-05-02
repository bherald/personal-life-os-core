<?php

namespace App\Services;

use App\Support\PgVector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Agent Episodic Memory Service (AG-2)
 *
 * Distills run-level episode streams into narrative summaries with embeddings,
 * then recalls relevant past experiences during future runs.
 *
 * Pattern: Same as procedural memory — MySQL for structured data, pgvector
 * for embeddings, non-fatal throughout.
 *
 * Flow:
 *  1. DISTILL: After each run, fetch episodes → LLM narrative → store + embed
 *  2. RECALL: Before execution, semantic search → inject as "Past Experience"
 *  3. ANNOTATE: Agents can add notes to current run's summary
 *  4. ARCHIVE: Soft-delete old low-importance summaries
 */
class AgentEpisodicMemoryService
{
    // Episodic memory config — config/agent_memory.php is primary (SC-2.6)
    private const SEMANTIC_MIN_SIMILARITY = 0.30;
    private const MAX_RECALL_SUMMARIES = 2;
    private const MAX_CONTEXT_TOKENS = 400;
    private const RECENCY_HALF_LIFE_DAYS = 14;
    private const DEFAULT_RETENTION_DAYS = 90;

    private AIService $aiService;

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    // =========================================================================
    // DISTILL: Convert run episodes into narrative summary
    // =========================================================================

    /**
     * Distill all episodes from a completed run into a narrative summary.
     *
     * Called by AgentLoopService after each run (success or failure).
     * LLM-distills if available, falls back to mechanical summary.
     */
    public function distillRunEpisodes(string $agentId, string $sessionId, string $task, array $runResult): ?int
    {
        try {
            // Check if summary already exists for this session
            $existing = DB::select("
                SELECT id FROM agent_episode_summaries WHERE session_id = ? LIMIT 1
            ", [$sessionId]);

            if (!empty($existing)) {
                return (int) $existing[0]->id;
            }

            // Fetch episodes for this session
            $episodes = DB::select("
                SELECT event_type, summary, details, tokens_used, duration_ms, created_at
                FROM agent_episodes
                WHERE agent_id = ? AND session_id = ?
                ORDER BY created_at ASC
            ", [$agentId, $sessionId]);

            $episodeCount = count($episodes);

            // Extract tool usage from run result
            $toolCalls = $runResult['tool_calls'] ?? [];
            $toolsUsed = is_array($toolCalls)
                ? array_values(array_unique(array_column($toolCalls, 'tool')))
                : [];
            $toolCount = count($toolsUsed);

            // Determine outcome
            $success = $runResult['success'] ?? false;
            $hasError = !empty($runResult['error']);
            $outcome = $this->determineOutcome($success, $hasError, $episodes);

            // Calculate importance
            $importance = $this->calculateImportance($agentId, $task, $outcome, $episodes);

            // Duration and tokens
            $durationMs = (int) ($runResult['duration_ms'] ?? 0);
            $tokensUsed = (int) ($runResult['tokens_used'] ?? 0);

            // Extract hybrid metrics if available (passed from AgentLoopService)
            $hybridMetrics = $runResult['hybrid_metrics'] ?? [];

            // Generate narrative summary
            $summary = $this->generateSummary($agentId, $task, $episodes, $outcome, $toolsUsed, $durationMs, $hybridMetrics);

            // Store in MySQL
            DB::insert("
                INSERT INTO agent_episode_summaries
                    (agent_id, session_id, task, summary, outcome, importance, tools_used,
                     tool_count, tokens_used, duration_ms, episode_count, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ", [
                $agentId,
                $sessionId,
                substr($task, 0, 500),
                $summary,
                $outcome,
                $importance,
                json_encode($toolsUsed),
                $toolCount,
                $tokensUsed,
                $durationMs,
                $episodeCount,
            ]);

            $summaryId = (int) DB::getPdo()->lastInsertId();

            // Store embedding (non-fatal)
            $this->storeEmbedding($summaryId, $agentId, $summary);

            // AG-3: Create bidirectional memory links (non-fatal)
            try {
                app(AgentMemoryEvolutionService::class)->linkAfterDistill($summaryId, $agentId);
            } catch (\Throwable $e) {
                Log::debug('EpisodicMemory: linkAfterDistill failed (non-fatal)', ['error' => $e->getMessage()]);
            }

            Log::debug("EpisodicMemory: Distilled run", [
                'agent_id' => $agentId,
                'session_id' => $sessionId,
                'summary_id' => $summaryId,
                'outcome' => $outcome,
                'importance' => $importance,
                'episode_count' => $episodeCount,
            ]);

            return $summaryId;

        } catch (\Throwable $e) {
            Log::debug("EpisodicMemory: Distillation failed (non-fatal)", [
                'agent_id' => $agentId,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Determine the outcome classification for a run.
     */
    private function determineOutcome(bool $success, bool $hasError, array $episodes): string
    {
        if ($hasError) {
            return 'error';
        }

        if (!$success) {
            return 'failure';
        }

        // Check for partial success indicators in episodes
        $hasWarnings = false;
        foreach ($episodes as $ep) {
            if (in_array($ep->event_type, ['budget_exceeded', 'loop_kill', 'hallucination_blocked'])) {
                return 'partial';
            }
            if ($ep->event_type === 'error') {
                $hasWarnings = true;
            }
        }

        return $hasWarnings ? 'partial' : 'success';
    }

    /**
     * Calculate importance score for a run summary.
     *
     * Base 0.50 + modifiers (capped at 1.00):
     *  - error/failure outcome: +0.30
     *  - first-time task for this agent: +0.20
     *  - budget/loop/kill events: +0.15
     *  - hallucination_blocked: +0.20
     */
    private function calculateImportance(string $agentId, string $task, string $outcome, array $episodes): float
    {
        $importance = 0.50;

        // Outcome modifiers
        if (in_array($outcome, ['error', 'failure'])) {
            $importance += 0.30;
        }

        // First-time task check (no prior summaries with similar task for this agent)
        $priorCount = DB::select("
            SELECT COUNT(*) as cnt FROM agent_episode_summaries
            WHERE agent_id = ? AND task LIKE ?
        ", [$agentId, '%' . substr($task, 0, 50) . '%']);

        if (!empty($priorCount) && (int) $priorCount[0]->cnt === 0) {
            $importance += 0.20;
        }

        // Episode-level modifiers
        foreach ($episodes as $ep) {
            if (in_array($ep->event_type, ['budget_exceeded', 'loop_kill'])) {
                $importance += 0.15;
                break;
            }
        }

        foreach ($episodes as $ep) {
            if ($ep->event_type === 'hallucination_blocked') {
                $importance += 0.20;
                break;
            }
        }

        return min(1.00, round($importance, 2));
    }

    /**
     * Generate a narrative summary via LLM or mechanical fallback.
     */
    private function generateSummary(string $agentId, string $task, array $episodes, string $outcome, array $toolsUsed, int $durationMs, array $hybridMetrics = []): string
    {
        // Build episode text for LLM
        $episodeText = '';
        foreach ($episodes as $ep) {
            $episodeText .= "[{$ep->event_type}] {$ep->summary}\n";
        }

        // Try LLM distillation
        try {
            if (!empty($episodeText)) {
                $prompt = "Distill these agent events into a 2-3 sentence narrative summary. "
                    . "Focus on: what task was attempted, key decisions made, tools used, outcome, and any anomalies or notable observations.\n\n"
                    . "Agent: {$agentId}\n"
                    . "Task: {$task}\n"
                    . "Outcome: {$outcome}\n"
                    . "Events:\n{$episodeText}\n";

                // Inject quality signals so the narrative captures them
                if (!empty($hybridMetrics)) {
                    $prompt .= "\nQuality Signals:\n"
                        . "- Templates detected: " . ($hybridMetrics['template_detections'] ?? 0) . "\n"
                        . "- Claude escalations: " . ($hybridMetrics['claude_escalations'] ?? 0) . "\n"
                        . "- Review items submitted: " . ($hybridMetrics['review_items_submitted'] ?? 0) . "\n"
                        . "- Proposals filtered (vague/duplicate): " . ($hybridMetrics['proposals_filtered'] ?? 0) . "\n"
                        . "- Phases completed: " . ($hybridMetrics['phases_completed'] ?? 0) . "/" . ($hybridMetrics['total_phases'] ?? 0) . "\n"
                        . "- Providers used: " . implode(', ', array_values($hybridMetrics['phase_providers'] ?? [])) . "\n"
                        . "Include quality observations in the narrative.\n";
                }

                $prompt .= "Write ONLY the 2-3 sentence narrative. No headers, no bullet points.";

                $result = $this->aiService->process($prompt, [
                    'temperature' => 0.1,
                    'max_tokens' => 300,
                    'use_cache' => false,
                ]);

                if (($result['success'] ?? false) && !empty($result['response'])) {
                    $summary = trim($result['response']);
                    // Validate: must be non-empty and reasonable length
                    if (strlen($summary) >= 20 && strlen($summary) <= 2000) {
                        return $summary;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::debug("EpisodicMemory: LLM distillation failed, using mechanical fallback", [
                'error' => $e->getMessage(),
            ]);
        }

        // Mechanical fallback
        return $this->mechanicalSummary($task, $outcome, $toolsUsed, $durationMs, count($episodes));
    }

    /**
     * Mechanical fallback summary when LLM is unavailable.
     */
    private function mechanicalSummary(string $task, string $outcome, array $toolsUsed, int $durationMs, int $episodeCount): string
    {
        $toolList = !empty($toolsUsed) ? implode(', ', array_slice($toolsUsed, 0, 5)) : 'none';
        $toolCountStr = count($toolsUsed);
        $durationStr = $durationMs > 0 ? "{$durationMs}ms" : 'unknown';

        return "Executed {$task}. Used {$toolCountStr} tools ({$toolList}). "
            . "Outcome: {$outcome}. Duration: {$durationStr}. "
            . "Recorded {$episodeCount} events.";
    }

    // =========================================================================
    // RECALL: Semantic search for relevant past experiences
    // =========================================================================

    /**
     * Recall relevant past run summaries for a given task.
     *
     * Scoring: similarity × importance × recency_decay
     */
    public function recallForTask(string $agentId, string $task, int $limit = 3, ?string $targetAgentId = null): array
    {
        try {
            $searchAgent = $targetAgentId ?? $agentId;

            // Try semantic recall via pgvector
            $semanticMatches = $this->semanticRecall($searchAgent, $task, $limit * 4);

            if ($semanticMatches === null) {
                // pgvector unavailable — fall back to keyword search
                return $this->keywordRecall($searchAgent, $task, $limit);
            }

            if (empty($semanticMatches)) {
                return [];
            }

            // Fetch full summaries from MySQL
            $ids = array_keys($semanticMatches);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $summaries = DB::select("
                SELECT id, agent_id, task, summary, outcome, importance, tools_used,
                       tool_count, tokens_used, duration_ms, notes, created_at
                FROM agent_episode_summaries
                WHERE id IN ({$placeholders})
                  AND is_archived = 0
            ", $ids);

            // Score and rank
            $scored = [];
            foreach ($summaries as $s) {
                $similarity = $semanticMatches[$s->id] ?? 0;
                $importance = (float) $s->importance;
                $recency = $this->recencyDecay($s->created_at);

                $score = $similarity * $importance * $recency;

                $scored[] = [
                    'id' => $s->id,
                    'agent_id' => $s->agent_id,
                    'task' => $s->task,
                    'summary' => $s->summary,
                    'outcome' => $s->outcome,
                    'importance' => $importance,
                    'similarity' => round($similarity, 3),
                    'recency' => round($recency, 3),
                    'score' => round($score, 4),
                    'tools_used' => json_decode($s->tools_used, true) ?? [],
                    'tool_count' => (int) $s->tool_count,
                    'duration_ms' => (int) $s->duration_ms,
                    'notes' => $s->notes,
                    'created_at' => $s->created_at,
                ];
            }

            // Sort by composite score descending
            usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

            return array_slice($scored, 0, $limit);

        } catch (\Throwable $e) {
            Log::debug("EpisodicMemory: Recall failed (non-fatal)", [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Build formatted context string for system prompt injection.
     *
     * Returns null if no relevant episodes found (saves tokens).
     */
    public function buildContextForTask(string $agentId, string $task): ?string
    {
        $episodes = $this->recallForTask($agentId, $task, config('agent_memory.episodic.max_recall_summaries', self::MAX_RECALL_SUMMARIES));

        if (empty($episodes)) {
            return null;
        }

        $parts = ["## Past Experience\n"];
        $tokenEstimate = 10; // header

        foreach ($episodes as $ep) {
            $outcomeTag = strtoupper($ep['outcome']);
            $date = substr($ep['created_at'], 0, 10);
            $entry = "**[{$outcomeTag}] {$date}**: {$ep['summary']}";

            if ($ep['notes']) {
                $entry .= " _Note: {$ep['notes']}_";
            }

            // Rough token estimate: ~0.75 tokens per character
            $entryTokens = (int) (strlen($entry) * 0.75);
            if ($tokenEstimate + $entryTokens > config('agent_memory.episodic.max_context_tokens', self::MAX_CONTEXT_TOKENS)) {
                break;
            }

            $parts[] = $entry;
            $tokenEstimate += $entryTokens;
        }

        // Need at least one episode beyond the header
        if (count($parts) < 2) {
            return null;
        }

        // AG-3: 1-hop linked memory traversal — enrich with memories linked to the top match
        try {
            $topId = $episodes[0]['id'] ?? null;
            if ($topId) {
                $linked = app(AgentMemoryEvolutionService::class)->getLinkedContext((int) $topId);
                foreach ($linked as $link) {
                    $outcomeTag = strtoupper($link['outcome']);
                    $linkLabel  = $link['link_type'] === 'evolved_from' ? 'SUPERSEDED BY' : strtoupper($link['link_type']);
                    $entry = "**[{$outcomeTag}] {$link['date']} ({$linkLabel})**: {$link['summary']}";
                    $entryTokens = (int) (strlen($entry) * 0.75);
                    if ($tokenEstimate + $entryTokens > config('agent_memory.episodic.max_context_tokens', self::MAX_CONTEXT_TOKENS)) {
                        break;
                    }
                    $parts[] = $entry;
                    $tokenEstimate += $entryTokens;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('EpisodicMemory: linked context enrichment failed (non-fatal)', ['error' => $e->getMessage()]);
        }

        return implode("\n\n", $parts);
    }

    /**
     * SB-1: Cross-agent knowledge synthesis.
     *
     * Recalls high-importance episodes from OTHER agents that are relevant
     * to the current agent's task. Enables knowledge sharing across the
     * agent ecosystem without explicit inter-agent communication.
     *
     * Only returns success/partial episodes with importance >= 0.6 from
     * the last 7 days. Limited to 2 entries to avoid prompt bloat.
     */
    public function recallCrossAgentInsights(string $excludeAgentId, string $task, int $limit = 2): ?string
    {
        try {
            // Semantic search across all agents except the current one
            $allMatches = $this->semanticRecall(null, $task, $limit * 5);

            if (empty($allMatches)) {
                return null;
            }

            $ids = array_keys($allMatches);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // Fetch high-importance, recent, successful episodes from OTHER agents
            $summaries = DB::select("
                SELECT id, agent_id, task, summary, outcome, importance, created_at
                FROM agent_episode_summaries
                WHERE id IN ({$placeholders})
                  AND agent_id != ?
                  AND outcome IN ('success', 'partial')
                  AND importance >= 0.6
                  AND is_archived = 0
                  AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY importance DESC, created_at DESC
            ", [...$ids, $excludeAgentId]);

            if (empty($summaries)) {
                return null;
            }

            $parts = ["## Cross-Agent Insights\n"];
            $count = 0;

            foreach ($summaries as $s) {
                if ($count >= $limit) break;
                $date = substr($s->created_at, 0, 10);
                $parts[] = "**[{$s->agent_id}] {$date}**: {$s->summary}";
                $count++;
            }

            return $count > 0 ? implode("\n\n", $parts) : null;

        } catch (\Throwable $e) {
            Log::debug('EpisodicMemory: Cross-agent recall failed (non-fatal)', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Recency decay factor: exponential decay with configurable half-life.
     */
    private function recencyDecay(string $createdAt): float
    {
        try {
            $daysAgo = max(0, (time() - strtotime($createdAt)) / 86400);
            return pow(0.5, $daysAgo / config('agent_memory.episodic.recency_half_life_days', self::RECENCY_HALF_LIFE_DAYS));
        } catch (\Throwable $e) {
            Log::debug('AgentEpisodicMemoryService: recency decay calculation failed', ['error' => $e->getMessage()]);
            return 0.5; // Safe default
        }
    }

    // =========================================================================
    // EMBEDDING: Semantic storage/recall via pgvector
    // =========================================================================

    /**
     * Generate and store an embedding for a summary.
     */
    private function storeEmbedding(int $summaryId, string $agentId, string $text): bool
    {
        try {
            $result = $this->aiService->generateEmbedding($text);

            if (!($result['success'] ?? false) || empty($result['embedding'])) {
                Log::debug("EpisodicMemory: Embedding generation failed", [
                    'summary_id' => $summaryId,
                    'error' => $result['error'] ?? 'no embedding returned',
                ]);
                return false;
            }

            $embeddingStr = PgVector::literal($result['embedding']);

            DB::connection('pgsql_rag')->statement("
                INSERT INTO agent_episode_embeddings (summary_id, agent_id, embedding, created_at, updated_at)
                VALUES (?, ?, ?::vector, NOW(), NOW())
                ON CONFLICT (summary_id) DO UPDATE SET
                    embedding = EXCLUDED.embedding,
                    updated_at = NOW()
            ", [$summaryId, $agentId, $embeddingStr]);

            return true;

        } catch (\Throwable $e) {
            Log::debug("EpisodicMemory: Embedding storage failed (non-fatal)", [
                'summary_id' => $summaryId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Semantic recall via pgvector cosine similarity.
     *
     * Returns summary IDs with similarity scores, or null if pgvector unavailable.
     */
    private function semanticRecall(?string $agentId, string $task, int $limit = 12): ?array
    {
        try {
            $result = $this->aiService->generateEmbedding($task);

            if (!($result['success'] ?? false) || empty($result['embedding'])) {
                return null; // Signal caller to use keyword fallback
            }

            $embeddingStr = PgVector::literal($result['embedding']);
            $minSimilarity = config('agent_memory.episodic.semantic_min_similarity', self::SEMANTIC_MIN_SIMILARITY);

            // SB-1: null agentId = search across ALL agents
            if ($agentId !== null) {
                $rows = DB::connection('pgsql_rag')->select("
                    SELECT summary_id,
                           1 - (embedding <=> ?::vector) as similarity
                    FROM agent_episode_embeddings
                    WHERE agent_id = ?
                      AND 1 - (embedding <=> ?::vector) >= ?
                    ORDER BY embedding <=> ?::vector ASC
                    LIMIT ?
                ", [$embeddingStr, $agentId, $embeddingStr, $minSimilarity, $embeddingStr, $limit]);
            } else {
                $rows = DB::connection('pgsql_rag')->select("
                    SELECT summary_id,
                           1 - (embedding <=> ?::vector) as similarity
                    FROM agent_episode_embeddings
                    WHERE 1 - (embedding <=> ?::vector) >= ?
                    ORDER BY embedding <=> ?::vector ASC
                    LIMIT ?
                ", [$embeddingStr, $embeddingStr, $minSimilarity, $embeddingStr, $limit]);
            }

            if (empty($rows)) {
                return [];
            }

            $matches = [];
            foreach ($rows as $row) {
                $matches[$row->summary_id] = (float) $row->similarity;
            }

            return $matches;

        } catch (\Throwable $e) {
            Log::debug("EpisodicMemory: Semantic recall failed, will use keyword fallback", [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Keyword fallback recall when pgvector is unavailable.
     */
    private function keywordRecall(string $agentId, string $task, int $limit = 3): array
    {
        try {
            // Extract significant words from task
            $words = $this->extractKeywords($task);
            if (empty($words)) {
                return [];
            }

            // Build LIKE conditions for keyword matching
            $conditions = [];
            $bindings = [$agentId];
            foreach (array_slice($words, 0, 5) as $word) {
                $conditions[] = "(summary LIKE ? OR task LIKE ?)";
                $bindings[] = "%{$word}%";
                $bindings[] = "%{$word}%";
            }

            $whereKeywords = implode(' OR ', $conditions);

            $rows = DB::select("
                SELECT id, agent_id, task, summary, outcome, importance, tools_used,
                       tool_count, tokens_used, duration_ms, notes, created_at
                FROM agent_episode_summaries
                WHERE agent_id = ?
                  AND is_archived = 0
                  AND ({$whereKeywords})
                ORDER BY importance DESC, created_at DESC
                LIMIT ?
            ", array_merge($bindings, [$limit]));

            $results = [];
            foreach ($rows as $s) {
                $recency = $this->recencyDecay($s->created_at);
                $results[] = [
                    'id' => $s->id,
                    'agent_id' => $s->agent_id,
                    'task' => $s->task,
                    'summary' => $s->summary,
                    'outcome' => $s->outcome,
                    'importance' => (float) $s->importance,
                    'similarity' => 0.5, // Keyword match gets base similarity
                    'recency' => round($recency, 3),
                    'score' => round(0.5 * (float) $s->importance * $recency, 4),
                    'tools_used' => json_decode($s->tools_used, true) ?? [],
                    'tool_count' => (int) $s->tool_count,
                    'duration_ms' => (int) $s->duration_ms,
                    'notes' => $s->notes,
                    'created_at' => $s->created_at,
                ];
            }

            return $results;

        } catch (\Throwable $e) {
            Log::debug('AgentEpisodicMemoryService: semantic recall query failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Remove embedding when a summary is archived.
     */
    private function removeEmbedding(int $summaryId): void
    {
        try {
            DB::connection('pgsql_rag')->delete("
                DELETE FROM agent_episode_embeddings WHERE summary_id = ?
            ", [$summaryId]);
        } catch (\Throwable $e) {
            Log::debug('AgentEpisodicMemoryService: embedding removal failed', ['summary_id' => $summaryId, 'error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // AGENT TOOLS: Callable by agents via tool registry
    // =========================================================================

    /**
     * Agent tool: Search episodic memory for relevant past experiences.
     */
    public function recallEpisodes(array $params): array
    {
        $query = $params['query'] ?? '';
        $limit = min((int) ($params['limit'] ?? 3), 10);
        $agentId = $params['_agent_id'] ?? 'unknown';

        if (empty($query)) {
            return [
                'success' => false,
                'error' => 'Query parameter is required',
                'result_text' => 'Error: query parameter is required for episodic recall.',
            ];
        }

        $results = $this->recallForTask($agentId, $query, $limit);

        if (empty($results)) {
            return [
                'success' => true,
                'data' => [],
                'result_text' => "No relevant past experiences found for: {$query}",
            ];
        }

        // Format for agent consumption
        $lines = ["Found " . count($results) . " relevant past experiences:\n"];
        foreach ($results as $i => $ep) {
            $num = $i + 1;
            $outcomeTag = strtoupper($ep['outcome']);
            $date = substr($ep['created_at'], 0, 10);
            $tools = implode(', ', array_slice($ep['tools_used'], 0, 5));
            $lines[] = "{$num}. [{$outcomeTag}] {$date} (score: {$ep['score']}, importance: {$ep['importance']})";
            $lines[] = "   Task: {$ep['task']}";
            $lines[] = "   Summary: {$ep['summary']}";
            if ($tools) {
                $lines[] = "   Tools: {$tools}";
            }
            if ($ep['notes']) {
                $lines[] = "   Notes: {$ep['notes']}";
            }
            $lines[] = "";
        }

        return [
            'success' => true,
            'data' => $results,
            'result_text' => implode("\n", $lines),
        ];
    }

    /**
     * Agent tool: Add a note to the current run's episodic summary.
     *
     * If no summary exists yet for this session, stores the note for later
     * attachment during distillation.
     */
    public function saveEpisodeNote(array $params): array
    {
        $note = $params['note'] ?? '';
        $agentId = $params['_agent_id'] ?? 'unknown';
        $sessionId = $params['_session_id'] ?? '';

        if (empty($note)) {
            return [
                'success' => false,
                'error' => 'Note parameter is required',
                'result_text' => 'Error: note parameter is required.',
            ];
        }

        if (empty($sessionId)) {
            return [
                'success' => false,
                'error' => 'No active session',
                'result_text' => 'Error: no active session to attach note to.',
            ];
        }

        try {
            // Try to update existing summary for this session
            $updated = DB::update("
                UPDATE agent_episode_summaries
                SET notes = CASE
                    WHEN notes IS NULL THEN ?
                    ELSE CONCAT(notes, '\n', ?)
                END
                WHERE session_id = ? AND agent_id = ?
            ", [$note, $note, $sessionId, $agentId]);

            if ($updated > 0) {
                return [
                    'success' => true,
                    'result_text' => "Note saved to current run's episodic memory.",
                ];
            }

            // Summary doesn't exist yet (run still in progress)
            // Store note in agent_episodes as an observation for later distillation
            DB::insert("
                INSERT INTO agent_episodes (agent_id, session_id, event_type, summary, created_at)
                VALUES (?, ?, 'observation', ?, NOW())
            ", [$agentId, $sessionId, "Agent note: {$note}"]);

            return [
                'success' => true,
                'result_text' => "Note recorded as observation. Will be included in run summary after completion.",
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'result_text' => "Failed to save note: {$e->getMessage()}",
            ];
        }
    }

    // =========================================================================
    // ARCHIVE: Soft-delete old low-importance summaries
    // =========================================================================

    /**
     * Archive old, low-importance episodic summaries.
     *
     * Criteria: older than retentionDays AND importance < 0.60
     * Removes embeddings for archived summaries.
     */
    public function archiveOldEpisodes(?int $retentionDays = null): array
    {
        $retentionDays = $retentionDays ?? config('agent_memory.episodic.default_retention_days', self::DEFAULT_RETENTION_DAYS);
        try {
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

            // Find candidates for archival
            $candidates = DB::select("
                SELECT id FROM agent_episode_summaries
                WHERE is_archived = 0
                  AND created_at < ?
                  AND importance < 0.60
            ", [$cutoffDate]);

            $archived = 0;
            $embeddingsRemoved = 0;

            foreach ($candidates as $c) {
                DB::update("
                    UPDATE agent_episode_summaries SET is_archived = 1 WHERE id = ?
                ", [$c->id]);

                $this->removeEmbedding($c->id);

                $archived++;
                $embeddingsRemoved++;
            }

            // Get totals for reporting
            $totals = DB::select("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN is_archived = 0 THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN is_archived = 1 THEN 1 ELSE 0 END) as archived_total
                FROM agent_episode_summaries
            ");

            $total = $totals[0]->total ?? 0;
            $active = $totals[0]->active ?? 0;
            $archivedTotal = $totals[0]->archived_total ?? 0;

            Log::info("EpisodicMemory: Archival complete", [
                'archived_this_run' => $archived,
                'total_active' => $active,
                'total_archived' => $archivedTotal,
            ]);

            return [
                'archived' => $archived,
                'embeddings_removed' => $embeddingsRemoved,
                'total_active' => (int) $active,
                'total_archived' => (int) $archivedTotal,
                'retention_days' => $retentionDays,
            ];

        } catch (\Throwable $e) {
            Log::error("EpisodicMemory: Archival failed", ['error' => $e->getMessage()]);
            return [
                'archived' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    // =========================================================================
    // STATS: Dashboard and reporting
    // =========================================================================

    /**
     * Get episodic memory statistics.
     */
    public function getStats(?string $agentId = null): array
    {
        try {
            $whereAgent = $agentId ? "WHERE agent_id = ?" : "";
            $bindings = $agentId ? [$agentId] : [];

            // Overall counts
            $totals = DB::select("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN is_archived = 0 THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN is_archived = 1 THEN 1 ELSE 0 END) as archived,
                    AVG(importance) as avg_importance,
                    AVG(tool_count) as avg_tools,
                    AVG(duration_ms) as avg_duration_ms,
                    AVG(tokens_used) as avg_tokens
                FROM agent_episode_summaries
                {$whereAgent}
            ", $bindings);

            // Outcome distribution
            $outcomes = DB::select("
                SELECT outcome, COUNT(*) as cnt
                FROM agent_episode_summaries
                {$whereAgent}
                GROUP BY outcome
            ", $bindings);

            $outcomeDist = [];
            foreach ($outcomes as $o) {
                $outcomeDist[$o->outcome] = (int) $o->cnt;
            }

            // Per-agent breakdown
            $perAgent = DB::select("
                SELECT agent_id,
                       COUNT(*) as total,
                       SUM(CASE WHEN is_archived = 0 THEN 1 ELSE 0 END) as active,
                       AVG(importance) as avg_importance,
                       SUM(CASE WHEN outcome = 'success' THEN 1 ELSE 0 END) as successes,
                       SUM(CASE WHEN outcome IN ('error', 'failure') THEN 1 ELSE 0 END) as failures
                FROM agent_episode_summaries
                GROUP BY agent_id
                ORDER BY total DESC
            ");

            // Embedding count
            $embeddingCount = 0;
            try {
                $embResult = DB::connection('pgsql_rag')->select("
                    SELECT COUNT(*) as cnt FROM agent_episode_embeddings
                ");
                $embeddingCount = (int) ($embResult[0]->cnt ?? 0);
            } catch (\Throwable $e) {
                Log::debug('AgentEpisodicMemoryService: pgvector embedding count query failed', ['error' => $e->getMessage()]);
            }

            $t = $totals[0] ?? (object) [];

            return [
                'total' => (int) ($t->total ?? 0),
                'active' => (int) ($t->active ?? 0),
                'archived' => (int) ($t->archived ?? 0),
                'avg_importance' => round((float) ($t->avg_importance ?? 0), 2),
                'avg_tools' => round((float) ($t->avg_tools ?? 0), 1),
                'avg_duration_ms' => (int) ($t->avg_duration_ms ?? 0),
                'avg_tokens' => (int) ($t->avg_tokens ?? 0),
                'outcomes' => $outcomeDist,
                'per_agent' => array_map(fn($a) => [
                    'agent_id' => $a->agent_id,
                    'total' => (int) $a->total,
                    'active' => (int) $a->active,
                    'avg_importance' => round((float) $a->avg_importance, 2),
                    'successes' => (int) $a->successes,
                    'failures' => (int) $a->failures,
                ], $perAgent),
                'embeddings' => $embeddingCount,
            ];

        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Extract significant keywords from a task description.
     */
    private function extractKeywords(string $text): array
    {
        $stopWords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been',
            'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
            'could', 'should', 'may', 'might', 'shall', 'can', 'to', 'of', 'in',
            'for', 'on', 'with', 'at', 'by', 'from', 'as', 'into', 'through',
            'during', 'before', 'after', 'above', 'below', 'between', 'under',
            'and', 'but', 'or', 'nor', 'not', 'so', 'yet', 'both', 'either',
            'neither', 'each', 'every', 'all', 'any', 'few', 'more', 'most',
            'other', 'some', 'such', 'no', 'only', 'same', 'than', 'too',
            'very', 'just', 'because', 'if', 'when', 'while', 'although',
            'this', 'that', 'these', 'those', 'it', 'its'];

        $words = preg_split('/\W+/', strtolower($text));
        $words = array_filter($words, fn($w) => strlen($w) > 2 && !in_array($w, $stopWords));

        return array_values(array_unique($words));
    }
}
