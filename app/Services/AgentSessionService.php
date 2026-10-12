<?php

namespace App\Services;

use App\Services\UnifiedCli\CliWorkflowExecutionScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Agent Session Service
 *
 * Maintains conversation state across API calls for AI agents.
 * Pattern: OpenAI Agents SDK session management
 *
 * Features:
 * - Session creation, retrieval, update, expiration
 * - Message history with role/content/timestamp
 * - Context storage (RAG results, tool states)
 * - Agent state persistence (workflow step, variables)
 * - Session isolation per user/workflow
 * - Automatic cleanup of expired sessions
 */
class AgentSessionService
{
    private const DEFAULT_TTL_HOURS = 24;

    private const MAX_MESSAGES_PER_SESSION = 100;

    private const DEFAULT_LIMIT = 50;

    private const MAX_HISTORY_PAGE_LIMIT = 200;

    private ?bool $durableHistoryAvailable = null;

    /**
     * Create a new agent session
     *
     * @param  string|null  $userId  User/owner identifier
     * @param  string  $sessionType  Session type (chat, workflow, agent)
     * @param  array  $options  Optional settings (ttl_hours, workflow_id, agent_name, metadata)
     * @return array Session data with session_id
     */
    public function createSession(
        ?string $userId = null,
        string $sessionType = 'chat',
        array $options = []
    ): array {
        $sessionId = $options['session_id'] ?? Str::uuid()->toString();
        $ttlHours = $options['ttl_hours'] ?? self::DEFAULT_TTL_HOURS;
        $now = now();
        $expiresAt = $now->copy()->addHours($ttlHours);

        DB::insert('
            INSERT INTO agent_sessions
            (session_id, user_id, workflow_id, session_type, agent_name, messages, context, agent_state, metadata, status, expires_at, last_activity_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ', [
            $sessionId,
            $userId,
            $options['workflow_id'] ?? null,
            $sessionType,
            $options['agent_name'] ?? null,
            json_encode([]),  // Empty messages array
            json_encode($options['context'] ?? []),
            json_encode($options['agent_state'] ?? []),
            json_encode($options['metadata'] ?? []),
            'active',
            $expiresAt,
            $now,
            $now,
            $now,
        ]);

        $id = DB::getPdo()->lastInsertId();

        Log::info('AgentSession: Created new session', [
            'id' => $id,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'session_type' => $sessionType,
            'ttl_hours' => $ttlHours,
        ]);

        return [
            'id' => (int) $id,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'workflow_id' => $options['workflow_id'] ?? null,
            'session_type' => $sessionType,
            'agent_name' => $options['agent_name'] ?? null,
            'messages' => [],
            'context' => $options['context'] ?? [],
            'agent_state' => $options['agent_state'] ?? [],
            'metadata' => $options['metadata'] ?? [],
            'total_tokens' => 0,
            'message_count' => 0,
            'status' => 'active',
            'expires_at' => $expiresAt->toIso8601String(),
            'last_activity_at' => $now->toIso8601String(),
            'created_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Get a session by session_id
     *
     * @param  string  $sessionId  Session identifier
     * @param  bool  $updateActivity  Whether to update last_activity_at
     * @return array|null Session data or null if not found/expired
     */
    public function getSession(string $sessionId, bool $updateActivity = true): ?array
    {
        $session = DB::selectOne(
            "SELECT * FROM agent_sessions WHERE session_id = ? AND status != 'expired' LIMIT 1",
            [$sessionId]
        );

        if (! $session) {
            return null;
        }

        // Check if session has expired
        if ($session->expires_at && strtotime($session->expires_at) < time()) {
            $this->expireSession($sessionId);

            return null;
        }

        // Update activity timestamp if requested
        if ($updateActivity) {
            $now = now();
            DB::update(
                'UPDATE agent_sessions SET last_activity_at = ?, updated_at = ? WHERE session_id = ?',
                [$now, $now, $sessionId]
            );
        }

        return $this->formatSession($session);
    }

    /**
     * Get a session by database ID
     *
     * @param  int  $id  Database ID
     * @return array|null Session data or null if not found
     */
    public function getSessionById(int $id): ?array
    {
        $session = DB::selectOne(
            'SELECT * FROM agent_sessions WHERE id = ? LIMIT 1',
            [$id]
        );

        if (! $session) {
            return null;
        }

        return $this->formatSession($session);
    }

    /**
     * Add a message to the session
     *
     * @param  string  $sessionId  Session identifier
     * @param  string  $role  Message role (user, assistant, system, tool)
     * @param  string  $content  Message content
     * @param  array  $messageMetadata  Optional metadata for this message
     * @return bool True if message was added successfully
     */
    public function addMessage(
        string $sessionId,
        string $role,
        string $content,
        array $messageMetadata = []
    ): bool {
        return DB::transaction(function () use ($sessionId, $role, $content, $messageMetadata): bool {
            $session = DB::table('agent_sessions')
                ->select('id', 'messages', 'message_count', 'total_tokens', 'status')
                ->where('session_id', $sessionId)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                Log::debug('AgentSession: Cannot add message - session not found', ['session_id' => $sessionId]);

                return false;
            }

            if (($session->status ?? null) !== 'active') {
                Log::debug('AgentSession: Skipping message append for inactive session', [
                    'session_id' => $sessionId,
                    'status' => $session->status,
                ]);

                return false;
            }

            $messages = json_decode((string) $session->messages, true) ?? [];
            if (count($messages) >= self::MAX_MESSAGES_PER_SESSION
                && (CliWorkflowExecutionScope::current() === null || $this->hasDurableHistory())) {
                array_shift($messages);
            }

            $now = now();
            $message = [
                'role' => $role,
                'content' => $content,
                'timestamp' => $now->toIso8601String(),
            ];
            if ($messageMetadata !== []) {
                $message['metadata'] = $messageMetadata;
            }
            $messages[] = $message;

            $tokenEstimate = (int) (strlen($content) / 1.5);
            $newMessageCount = (int) $session->message_count + 1;

            if ($this->hasDurableHistory()) {
                DB::table('agent_session_messages')->insert([
                    'agent_session_id' => (int) $session->id,
                    'ordinal' => $newMessageCount,
                    'role' => mb_substr($role, 0, 32),
                    'content' => $content,
                    'metadata' => $messageMetadata !== [] ? json_encode($messageMetadata, JSON_UNESCAPED_SLASHES) : null,
                    'token_estimate' => $tokenEstimate,
                    'occurred_at' => $now,
                    'created_at' => $now,
                ]);
            }

            return DB::table('agent_sessions')
                ->where('id', $session->id)
                ->update([
                    'messages' => json_encode($messages, JSON_UNESCAPED_SLASHES),
                    'message_count' => $newMessageCount,
                    'total_tokens' => (int) $session->total_tokens + $tokenEstimate,
                    'last_activity_at' => $now,
                    'updated_at' => $now,
                ]) > 0;
        });
    }

    /**
     * Get messages from a session
     *
     * @param  string  $sessionId  Session identifier
     * @param  int|null  $limit  Maximum messages to return (null = all)
     * @param  bool  $newestFirst  Return newest messages first
     * @return array Messages array
     */
    public function getMessages(string $sessionId, ?int $limit = null, bool $newestFirst = false): array
    {
        $session = DB::selectOne(
            'SELECT messages FROM agent_sessions WHERE session_id = ? LIMIT 1',
            [$sessionId]
        );

        if (! $session) {
            return [];
        }

        $messages = json_decode($session->messages, true) ?? [];

        if ($newestFirst) {
            $messages = array_reverse($messages);
        }

        if ($limit !== null && $limit > 0) {
            $messages = array_slice($messages, 0, $limit);
        }

        return $messages;
    }

    /**
     * Read a stable, newest-first page from the lossless durable transcript.
     * The returned head cursor excludes messages appended after the first page.
     */
    public function getMessageHistoryPage(string $sessionId, ?string $cursor = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min($limit, self::MAX_HISTORY_PAGE_LIMIT));
        $session = DB::table('agent_sessions')
            ->select('id', 'message_count')
            ->where('session_id', $sessionId)
            ->first();

        if (! $session || ! $this->hasDurableHistory()) {
            return [
                'session_id' => $sessionId,
                'order' => 'newest_first',
                'messages' => [],
                'next_cursor' => null,
                'history_head_cursor' => null,
                'durable' => false,
            ];
        }

        $maxOrdinal = (int) (DB::table('agent_session_messages')
            ->where('agent_session_id', $session->id)
            ->max('ordinal') ?? 0);
        $upperOrdinal = $cursor === null
            ? $maxOrdinal
            : $this->decodeHistoryCursor($cursor, $sessionId);
        $headCursor = $this->encodeHistoryCursor($sessionId, $upperOrdinal);

        $rows = DB::table('agent_session_messages')
            ->where('agent_session_id', $session->id)
            ->where('ordinal', '<=', $upperOrdinal)
            ->orderByDesc('ordinal')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $messages = $rows->map(static fn (object $row): array => [
            'ordinal' => (int) $row->ordinal,
            'role' => $row->role,
            'content' => $row->content,
            'timestamp' => (string) $row->occurred_at,
            'metadata' => $row->metadata ? (json_decode((string) $row->metadata, true) ?? []) : [],
            'token_estimate' => (int) $row->token_estimate,
        ])->values()->all();
        $lastOrdinal = $messages !== [] ? (int) end($messages)['ordinal'] : 0;

        return [
            'session_id' => $sessionId,
            'order' => 'newest_first',
            'messages' => $messages,
            'next_cursor' => $hasMore ? $this->encodeHistoryCursor($sessionId, $lastOrdinal - 1) : null,
            'history_head_cursor' => $headCursor,
            'durable' => true,
            'message_count' => (int) $session->message_count,
        ];
    }

    /**
     * Update session context
     *
     * @param  string  $sessionId  Session identifier
     * @param  array  $context  New context data (merged with existing)
     * @param  bool  $replace  If true, replace context entirely instead of merging
     * @return bool True if updated successfully
     */
    public function updateContext(string $sessionId, array $context, bool $replace = false): bool
    {
        $session = DB::selectOne(
            "SELECT id, context FROM agent_sessions WHERE session_id = ? AND status = 'active' LIMIT 1",
            [$sessionId]
        );

        if (! $session) {
            return false;
        }

        if ($replace) {
            $newContext = $context;
        } else {
            $existingContext = json_decode($session->context, true) ?? [];
            $newContext = array_merge($existingContext, $context);
        }

        $now = now();
        $affected = DB::update(
            'UPDATE agent_sessions SET context = ?, last_activity_at = ?, updated_at = ? WHERE id = ?',
            [json_encode($newContext), $now, $now, $session->id]
        );

        return $affected > 0;
    }

    /**
     * Update agent state
     *
     * @param  string  $sessionId  Session identifier
     * @param  array  $state  New state data (merged with existing)
     * @param  bool  $replace  If true, replace state entirely instead of merging
     * @return bool True if updated successfully
     */
    public function updateAgentState(string $sessionId, array $state, bool $replace = false): bool
    {
        $session = DB::selectOne(
            "SELECT id, agent_state FROM agent_sessions WHERE session_id = ? AND status = 'active' LIMIT 1",
            [$sessionId]
        );

        if (! $session) {
            return false;
        }

        if ($replace) {
            $newState = $state;
        } else {
            $existingState = json_decode($session->agent_state, true) ?? [];
            $newState = array_merge($existingState, $state);
        }

        $now = now();
        $affected = DB::update(
            'UPDATE agent_sessions SET agent_state = ?, last_activity_at = ?, updated_at = ? WHERE id = ?',
            [json_encode($newState), $now, $now, $session->id]
        );

        return $affected > 0;
    }

    /**
     * Update session metadata
     *
     * @param  string  $sessionId  Session identifier
     * @param  array  $metadata  New metadata (merged with existing)
     * @return bool True if updated successfully
     */
    public function updateMetadata(string $sessionId, array $metadata): bool
    {
        $session = DB::selectOne(
            'SELECT id, metadata FROM agent_sessions WHERE session_id = ? LIMIT 1',
            [$sessionId]
        );

        if (! $session) {
            return false;
        }

        $existingMetadata = json_decode($session->metadata, true) ?? [];
        $newMetadata = array_merge($existingMetadata, $metadata);

        $now = now();
        $affected = DB::update(
            'UPDATE agent_sessions SET metadata = ?, updated_at = ? WHERE id = ?',
            [json_encode($newMetadata), $now, $session->id]
        );

        return $affected > 0;
    }

    /**
     * Extend session expiration
     *
     * @param  string  $sessionId  Session identifier
     * @param  int  $additionalHours  Hours to add to current expiration
     * @return bool True if extended successfully
     */
    public function extendSession(string $sessionId, int $additionalHours = 24): bool
    {
        $now = now();
        $newExpiration = $now->copy()->addHours($additionalHours);

        $affected = DB::update(
            "UPDATE agent_sessions SET expires_at = ?, updated_at = ? WHERE session_id = ? AND status = 'active'",
            [$newExpiration, $now, $sessionId]
        );

        if ($affected > 0) {
            Log::info('AgentSession: Extended session', [
                'session_id' => $sessionId,
                'additional_hours' => $additionalHours,
                'new_expiration' => $newExpiration->toIso8601String(),
            ]);
        }

        return $affected > 0;
    }

    /**
     * Mark session as expired
     *
     * @param  string  $sessionId  Session identifier
     * @return bool True if expired successfully
     */
    public function expireSession(string $sessionId): bool
    {
        $now = now();
        $affected = DB::update(
            "UPDATE agent_sessions SET status = 'expired', updated_at = ? WHERE session_id = ?",
            [$now, $sessionId]
        );

        if ($affected > 0) {
            Log::info('AgentSession: Session expired', ['session_id' => $sessionId]);
        }

        return $affected > 0;
    }

    /**
     * Mark session as completed
     *
     * @param  string  $sessionId  Session identifier
     * @return bool True if completed successfully
     */
    public function completeSession(string $sessionId): bool
    {
        $now = now();
        $affected = DB::update(
            "UPDATE agent_sessions SET status = 'completed', updated_at = ? WHERE session_id = ? AND status = 'active'",
            [$now, $sessionId]
        );

        if ($affected > 0) {
            Log::info('AgentSession: Session completed', ['session_id' => $sessionId]);
        }

        return $affected > 0;
    }

    /**
     * Pause a session (can be resumed later)
     *
     * @param  string  $sessionId  Session identifier
     * @return bool True if paused successfully
     */
    public function pauseSession(string $sessionId): bool
    {
        $now = now();
        $affected = DB::update(
            "UPDATE agent_sessions SET status = 'paused', updated_at = ? WHERE session_id = ? AND status = 'active'",
            [$now, $sessionId]
        );

        return $affected > 0;
    }

    /**
     * Resume a paused session
     *
     * @param  string  $sessionId  Session identifier
     * @param  int|null  $extendHours  Optional: extend expiration by this many hours
     * @return bool True if resumed successfully
     */
    public function resumeSession(string $sessionId, ?int $extendHours = null): bool
    {
        $now = now();

        $sql = "UPDATE agent_sessions SET status = 'active', last_activity_at = ?, updated_at = ?";

        if ($extendHours !== null) {
            $newExpiration = $now->copy()->addHours($extendHours);
            $sql .= ', expires_at = ?';
        }

        $sql .= " WHERE session_id = ? AND status = 'paused'";

        if ($extendHours !== null) {
            $affected = DB::update($sql, [$now, $now, $newExpiration, $sessionId]);
        } else {
            $affected = DB::update($sql, [$now, $now, $sessionId]);
        }

        return $affected > 0;
    }

    /**
     * Get sessions for a user
     *
     * @param  string  $userId  User identifier
     * @param  string|null  $status  Filter by status
     * @param  int  $limit  Maximum sessions to return
     * @return array List of sessions
     */
    public function getUserSessions(string $userId, ?string $status = null, int $limit = 20): array
    {
        $params = [$userId];
        $sql = 'SELECT * FROM agent_sessions WHERE user_id = ?';

        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY last_activity_at DESC LIMIT ?';
        $params[] = $limit;

        $sessions = DB::select($sql, $params);

        return array_map([$this, 'formatSession'], $sessions);
    }

    /**
     * Get sessions by workflow
     *
     * @param  string  $workflowId  Workflow identifier
     * @param  string|null  $status  Filter by status
     * @param  int  $limit  Maximum sessions to return
     * @return array List of sessions
     */
    public function getWorkflowSessions(string $workflowId, ?string $status = null, int $limit = 20): array
    {
        $params = [$workflowId];
        $sql = 'SELECT * FROM agent_sessions WHERE workflow_id = ?';

        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY last_activity_at DESC LIMIT ?';
        $params[] = $limit;

        $sessions = DB::select($sql, $params);

        return array_map([$this, 'formatSession'], $sessions);
    }

    /**
     * Find or create a session
     *
     * @param  string|null  $userId  User identifier
     * @param  string  $sessionType  Session type
     * @param  array  $options  Session options (including session_id if resuming)
     * @return array Session data
     */
    public function findOrCreateSession(?string $userId, string $sessionType = 'chat', array $options = []): array
    {
        // If session_id provided, try to get existing session
        if (! empty($options['session_id'])) {
            $existing = $this->getSession($options['session_id']);
            if ($existing !== null) {
                return $existing;
            }
        }

        // Create new session
        return $this->createSession($userId, $sessionType, $options);
    }

    /**
     * Clean up expired sessions
     *
     * @param  int  $batchSize  Maximum sessions to clean up in one call
     * @return int Number of sessions cleaned up
     */
    public function cleanupExpiredSessions(int $batchSize = 100): int
    {
        $now = now();

        // First, mark expired sessions (TTL exceeded)
        $marked = DB::update("
            UPDATE agent_sessions
            SET status = 'expired', updated_at = ?
            WHERE status = 'active' AND expires_at < ?
            LIMIT ?
        ", [$now, $now, $batchSize]);

        // Also mark stale sessions — active but no activity for 2+ hours (killed processes)
        $staleThreshold = $now->copy()->subHours(2);
        $stale = DB::update("
            UPDATE agent_sessions
            SET status = 'expired', updated_at = ?
            WHERE status = 'active' AND last_activity_at < ?
            LIMIT ?
        ", [$now, $staleThreshold, $batchSize]);
        $marked += $stale;

        // Optionally delete very old expired sessions (older than 30 days)
        $thirtyDaysAgo = $now->copy()->subDays(30);
        $deleted = DB::delete("
            DELETE FROM agent_sessions
            WHERE status = 'expired' AND updated_at < ?
            LIMIT ?
        ", [$thirtyDaysAgo, $batchSize]);

        if ($marked > 0 || $deleted > 0) {
            Log::info('AgentSession: Cleanup completed', [
                'marked_expired' => $marked,
                'deleted_old' => $deleted,
            ]);
        }

        return $marked + $deleted;
    }

    /**
     * Get session statistics
     *
     * @return array Session statistics
     */
    public function getStats(): array
    {
        // Status counts
        $statusCounts = DB::select('
            SELECT status, COUNT(*) as count
            FROM agent_sessions
            GROUP BY status
        ');

        $byStatus = [];
        foreach ($statusCounts as $row) {
            $byStatus[$row->status] = (int) $row->count;
        }

        // Type counts for active sessions
        $typeCounts = DB::select("
            SELECT session_type, COUNT(*) as count
            FROM agent_sessions
            WHERE status = 'active'
            GROUP BY session_type
        ");

        $byType = [];
        foreach ($typeCounts as $row) {
            $byType[$row->session_type] = (int) $row->count;
        }

        // Total active sessions
        $totalActive = DB::selectOne("
            SELECT COUNT(*) as total FROM agent_sessions WHERE status = 'active'
        ");

        // Sessions expiring in next hour
        $expiringHour = now()->addHour();
        $expiringSoon = DB::selectOne("
            SELECT COUNT(*) as total FROM agent_sessions WHERE status = 'active' AND expires_at < ?
        ", [$expiringHour]);

        // Average messages per session
        $avgMessages = DB::selectOne('
            SELECT AVG(message_count) as avg_messages FROM agent_sessions WHERE message_count > 0
        ');

        return [
            'total_active' => (int) $totalActive->total,
            'by_status' => $byStatus,
            'by_type' => $byType,
            'expiring_within_hour' => (int) ($expiringSoon->total ?? 0),
            'avg_messages_per_session' => round((float) ($avgMessages->avg_messages ?? 0), 1),
        ];
    }

    /**
     * Delete a session permanently
     *
     * @param  string  $sessionId  Session identifier
     * @return bool True if deleted successfully
     */
    public function deleteSession(string $sessionId): bool
    {
        $deleted = DB::transaction(function () use ($sessionId): int {
            $session = DB::table('agent_sessions')->select('id')->where('session_id', $sessionId)->first();
            if (! $session) {
                return 0;
            }

            if ($this->hasDurableHistory()) {
                DB::table('agent_session_messages')->where('agent_session_id', $session->id)->delete();
            }

            return DB::table('agent_sessions')->where('id', $session->id)->delete();
        });

        if ($deleted > 0) {
            Log::info('AgentSession: Session deleted', ['session_id' => $sessionId]);
        }

        return $deleted > 0;
    }

    /**
     * Clear all messages from a session (keep session)
     *
     * @param  string  $sessionId  Session identifier
     * @return bool True if cleared successfully
     */
    public function clearMessages(string $sessionId): bool
    {
        return DB::transaction(function () use ($sessionId): bool {
            $session = DB::table('agent_sessions')
                ->select('id')
                ->where('session_id', $sessionId)
                ->lockForUpdate()
                ->first();
            if (! $session) {
                return false;
            }

            if ($this->hasDurableHistory()) {
                DB::table('agent_session_messages')->where('agent_session_id', $session->id)->delete();
            }

            return DB::table('agent_sessions')->where('id', $session->id)->update([
                'messages' => json_encode([]),
                'message_count' => 0,
                'total_tokens' => 0,
                'updated_at' => now(),
            ]) > 0;
        });
    }

    /**
     * Build chat context for AIService from session messages
     *
     * @param  string  $sessionId  Session identifier
     * @param  int|null  $maxMessages  Maximum messages to include
     * @return array Context array suitable for AIService
     */
    public function buildChatContext(string $sessionId, ?int $maxMessages = null): array
    {
        $messages = CliWorkflowExecutionScope::current() !== null
            ? $this->completeChatMessages($sessionId)
            : $this->getMessages($sessionId, $maxMessages);

        return array_map(function ($msg) {
            return [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
        }, $messages);
    }

    /** CLI prompts use the existing full transcript, never the bounded display cache. */
    private function completeChatMessages(string $sessionId): array
    {
        return DB::transaction(function () use ($sessionId): array {
            $session = DB::table('agent_sessions')->select('id', 'messages', 'message_count')
                ->where('session_id', $sessionId)->lockForUpdate()->first();
            if (! $session) {
                return [];
            }

            $count = (int) $session->message_count;
            if ($this->hasDurableHistory()) {
                $rows = DB::table('agent_session_messages')->where('agent_session_id', $session->id)
                    ->where('ordinal', '<=', $count)->orderBy('ordinal')->get();
                if ($rows->count() === $count && $rows->every(
                    static fn (object $row, int $index): bool => (int) $row->ordinal === $index + 1
                )) {
                    return $rows->map(static fn (object $row): array => [
                        'role' => $row->role, 'content' => $row->content,
                    ])->all();
                }
            }

            // Older installations may have only the JSON transcript. It is usable
            // only when it still contains every recorded message.
            $messages = json_decode((string) $session->messages, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($messages) && array_is_list($messages) && count($messages) === $count) {
                return $messages;
            }

            throw new RuntimeException('The supporting agent session history is incomplete. Restore its full transcript or explicitly start a new session; no shortened prompt was sent.');
        });
    }

    /**
     * Format a session row to consistent array structure
     *
     * @param  object  $session  Database row
     * @return array Formatted session data
     */
    private function formatSession(object $session): array
    {
        return [
            'id' => (int) $session->id,
            'session_id' => $session->session_id,
            'user_id' => $session->user_id,
            'workflow_id' => $session->workflow_id,
            'session_type' => $session->session_type,
            'agent_name' => $session->agent_name,
            'messages' => json_decode($session->messages, true) ?? [],
            'context' => json_decode($session->context, true) ?? [],
            'agent_state' => json_decode($session->agent_state, true) ?? [],
            'metadata' => json_decode($session->metadata, true) ?? [],
            'total_tokens' => (int) $session->total_tokens,
            'message_count' => (int) $session->message_count,
            'status' => $session->status,
            'expires_at' => $session->expires_at,
            'last_activity_at' => $session->last_activity_at,
            'created_at' => $session->created_at,
            'updated_at' => $session->updated_at,
        ];
    }

    private function encodeHistoryCursor(string $sessionId, int $ordinal): string
    {
        $payload = $this->base64UrlEncode(json_encode([
            'v' => 1,
            'session_id' => $sessionId,
            'ordinal' => max(0, $ordinal),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $payload, $this->cursorSigningKey(), true);

        return $payload.'.'.$this->base64UrlEncode($signature);
    }

    private function decodeHistoryCursor(string $cursor, string $sessionId): int
    {
        $parts = explode('.', $cursor, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('Invalid agent session history cursor.');
        }

        [$payload, $providedSignature] = $parts;
        $signature = $this->base64UrlDecode($providedSignature);
        $expected = hash_hmac('sha256', $payload, $this->cursorSigningKey(), true);
        $decodedPayload = $this->base64UrlDecode($payload);
        $decoded = is_string($decodedPayload) ? json_decode($decodedPayload, true) : null;

        if (! is_string($signature) || ! hash_equals($expected, $signature)
            || ! is_array($decoded) || ($decoded['v'] ?? null) !== 1
            || ($decoded['session_id'] ?? null) !== $sessionId
            || ! is_int($decoded['ordinal'] ?? null) || $decoded['ordinal'] < 0) {
            throw new InvalidArgumentException('Invalid agent session history cursor.');
        }

        return $decoded['ordinal'];
    }

    private function cursorSigningKey(): string
    {
        $key = (string) config('app.key', '');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        if ($key === '') {
            throw new RuntimeException('Application key is required for agent session history cursors.');
        }

        return $key;
    }

    private function hasDurableHistory(): bool
    {
        return $this->durableHistoryAvailable ??= Schema::hasTable('agent_session_messages');
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string|false
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
