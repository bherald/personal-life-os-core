<?php

namespace App\Services\Custody;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * C2 — Task Custody Record service (first concrete implementation of the
 * B6 lease/notification contract documented at
 * docs/plos-task-lease-contract.md).
 *
 * Surfaces supported:
 *   - scheduled_job (owner-driven release; parent death cannot prove work end)
 *   - agent_session (owner-driven release; cache loss is not work end)
 *   - horizon_job   (read-only — no liveness probe, Horizon authoritative)
 *   - mhc08_k3_job  (owner-driven release; parent death is not work end)
 *
 * This service intentionally does NOT replace the scheduler's existing
 * `running_pids` / `running_count` / zombie-detector paths. It coordinates
 * them — acquire records intent, release writes outcome, sweep triggers
 * the surface's existing cleanup when the holder is gone.
 */
class TaskCustodyService
{
    public const SURFACE_SCHEDULED_JOB = 'scheduled_job';

    public const SURFACE_AGENT_SESSION = 'agent_session';

    public const SURFACE_HORIZON_JOB = 'horizon_job';

    public const SURFACE_MHC08_K3_JOB = 'mhc08_k3_job';

    public const SURFACE_COMPUTE_EXECUTION = 'compute_execution';

    public const SURFACE_LLM_POOL_EXECUTION = 'llm_pool_execution';

    public const SURFACE_YOUTUBE_SOURCE_WRITE = 'youtube_source_write';

    private const MAX_ENVELOPE_BYTES = 16 * 1024;

    /**
     * Build the scheduled-job owner token from both PID and Linux process
     * start time. The start identity prevents a recycled PID from making a
     * dead holder appear live. Non-Linux or unreadable /proc hosts retain the
     * legacy PID token and conservative liveness behavior.
     */
    public function scheduledJobOwnerToken(?int $pid, int $runId): string
    {
        if ($pid === null || $pid <= 0) {
            return sprintf('pid:unknown:run:%d', $runId);
        }

        $startId = $this->readLinuxProcessStartId($pid);
        if ($startId === null) {
            return sprintf('pid:%d:run:%d', $pid, $runId);
        }

        return sprintf('pid:%d:start:%s:run:%d', $pid, $startId, $runId);
    }

    /**
     * Acquire custody of a task. Returns the custody row object on
     * success, or null if another owner already holds it. Idempotent
     * for the same owner_token — calling acquire twice returns the
     * existing row.
     */
    public function acquire(string $surface, string $surfaceRef, string $ownerToken, int $expiresInSeconds): ?object
    {
        if ($surface === self::SURFACE_LLM_POOL_EXECUTION) {
            throw new \RuntimeException('Pool execution admission requires its registered catalog and group lock.');
        }
        if ($surface === self::SURFACE_YOUTUBE_SOURCE_WRITE) {
            throw new \RuntimeException('Source write admission requires exact note intent and sync lock.');
        }
        // Uses findUnreleased (not findActive) because acquire must also
        // see expired-unreleased rows so it can release-inline and free
        // the partial-unique constraint for the new owner.
        $existing = $this->findUnreleased($surface, $surfaceRef);
        if ($existing) {
            $isExpired = (bool) (int) ($existing->is_expired ?? 0);

            if ($existing->owner_token === $ownerToken) {
                // Same owner re-acquiring their own record — idempotent
                // even if it has slipped past expiry (they're about to
                // hold() to extend anyway).
                return $existing;
            }

            if (! $isExpired) {
                // Live owner holds the slot; contract says acquire fails.
                return null;
            }

            // Expiry alone does not prove the holder has stopped. Reuse
            // sweep's liveness authority; live or uncertain owners retain
            // custody before the existing conditional dead-owner release.
            if ($this->hasLiveOwner($existing)) {
                return null;
            }
            if (! $this->releaseExpiredInline($existing)) {
                // The holder may have renewed after our expired snapshot, or
                // another recovery path may have changed the row. Never
                // preempt from stale evidence; the caller can retry.
                return null;
            }
        }

        try {
            DB::insert(
                'INSERT INTO task_custody_records
                    (surface, surface_ref, owner_token, acquired_at, expires_at, created_at, updated_at)
                 VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND), NOW(), NOW())',
                [$surface, $surfaceRef, $ownerToken, $expiresInSeconds]
            );

            $id = (int) DB::getPdo()->lastInsertId();

            return DB::selectOne('SELECT * FROM task_custody_records WHERE id = ?', [$id]);
        } catch (\Throwable $e) {
            // Partial unique on active_key catches concurrent acquires.
            Log::info('TaskCustodyService: concurrent acquire conflict', [
                'surface' => $surface,
                'surface_ref' => $surfaceRef,
                'owner_token' => $ownerToken,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extend the expiry of an active custody record. Optionally record a
     * short progress note. Returns true when the row was updated.
     *
     * Lease extension is monotonic — hold() never shortens expires_at. The
     * new expiry is `GREATEST(expires_at, NOW()) + extendBySeconds`, so a
     * caller that heartbeats early with a small extendBySeconds cannot
     * accidentally reduce the remaining lease it already held.
     */
    public function hold(int $custodyId, int $extendBySeconds, ?string $progressNote = null): bool
    {
        $note = $progressNote !== null ? mb_substr($progressNote, 0, 255) : null;

        $updated = DB::update(
            'UPDATE task_custody_records
             SET expires_at = DATE_ADD(GREATEST(expires_at, NOW()), INTERVAL ? SECOND),
                 progress_note = COALESCE(?, progress_note),
                 updated_at = NOW()
             WHERE id = ? AND released_at IS NULL AND surface NOT IN (?, ?)',
            [$extendBySeconds, $note, $custodyId, self::SURFACE_LLM_POOL_EXECUTION, self::SURFACE_YOUTUBE_SOURCE_WRITE]
        );

        return $updated > 0;
    }

    /**
     * Release a custody record with its terminal outcome. Idempotent:
     * calling with the same outcome on an already-released record is a
     * no-op that returns true. Calling with a DIFFERENT outcome returns
     * false (do not silently flip success→failure post-hoc).
     *
     * @param  array<string, mixed>  $resultEnvelope
     */
    public function release(int $custodyId, string $outcome, array $resultEnvelope = []): bool
    {
        if (! in_array($outcome, ['success', 'failure', 'cancel'], true)) {
            throw new \InvalidArgumentException("Invalid outcome: {$outcome}");
        }

        $current = DB::selectOne('SELECT * FROM task_custody_records WHERE id = ?', [$custodyId]);
        if ($current !== null && $current->surface === self::SURFACE_LLM_POOL_EXECUTION) {
            throw new \RuntimeException('Pool execution release requires its exact invocation and terminal owner CAS.');
        }
        if ($current !== null && $current->surface === self::SURFACE_YOUTUBE_SOURCE_WRITE) {
            throw new \RuntimeException('Source write release requires exact read-back and terminal owner CAS.');
        }
        if (! $current) {
            return false;
        }

        if ($current->released_at !== null) {
            // Idempotent when outcome matches; refuse on mismatch.
            return $current->outcome === $outcome;
        }

        $envelopeJson = $this->truncateEnvelope($resultEnvelope);

        $updated = DB::update(
            "UPDATE task_custody_records
             SET released_at = NOW(),
                 outcome = ?,
                 result_envelope = ?,
                 notification_state = COALESCE(notification_state, 'pending'),
                 updated_at = NOW()
             WHERE id = ? AND released_at IS NULL AND surface NOT IN (?, ?)",
            [$outcome, $envelopeJson, $custodyId, self::SURFACE_LLM_POOL_EXECUTION, self::SURFACE_YOUTUBE_SOURCE_WRITE]
        );

        return $updated > 0;
    }

    /**
     * Recovery sweep. For each expired-unreleased record:
     *   1. probe the owner's liveness for the surface
     *   2. if owner is dead, release the record with a structured
     *      `expired_no_holder` envelope and mark the notification pending
     *   3. return the list of recovered custody rows so the caller can
     *      also trigger surface-specific cleanup (e.g., scheduler's
     *      fixStuckJobs path for scheduled_job).
     *
     * @return array<int, object> recovered custody records
     */
    public function sweep(int $limit = 50): array
    {
        $expired = DB::select(
            'SELECT * FROM task_custody_records
             WHERE released_at IS NULL AND expires_at < NOW()
             ORDER BY expires_at ASC
             LIMIT ?',
            [$limit]
        );

        $recovered = [];
        foreach ($expired as $row) {
            if ($this->hasLiveOwner($row)) {
                // Holder is alive; leave it alone — they may just be
                // slow to release and the contract says self-extension
                // is how they ask for more time.
                continue;
            }

            $envelope = [
                'reason' => 'expired_no_holder',
                'detected_by' => 'custody_sweep',
                'surface' => $row->surface,
                'owner_token' => $row->owner_token,
                'expired_at' => $row->expires_at,
            ];

            $released = DB::update(
                "UPDATE task_custody_records
                 SET released_at = NOW(),
                     outcome = 'failure',
                     result_envelope = ?,
                     notification_state = 'pending',
                     updated_at = NOW()
                 WHERE id = ?
                   AND owner_token = ?
                   AND released_at IS NULL
                   AND expires_at < NOW()",
                [json_encode($envelope), $row->id, $row->owner_token]
            );

            if ($released > 0) {
                $row->outcome = 'failure';
                $row->result_envelope = json_encode($envelope);
                $row->released_at = date('Y-m-d H:i:s');
                $recovered[] = $row;

                Log::warning('TaskCustodyService: recovered expired custody record', [
                    'custody_id' => $row->id,
                    'surface' => $row->surface,
                    'surface_ref' => $row->surface_ref,
                    'owner_token' => $row->owner_token,
                ]);
            }
        }

        return $recovered;
    }

    /**
     * Return the sole row matching the lease-contract definition of
     * "active": unreleased AND unexpired. An expired-unreleased row is
     * NOT active per docs/plos-task-lease-contract.md (Acquire section);
     * callers looking for "does this surface currently have a live
     * owner?" get null for expired rows.
     *
     * For the acquire-path liveness probe that must also see expired
     * rows (to release-inline before reacquiring), use findUnreleased().
     */
    public function findActive(string $surface, string $surfaceRef): ?object
    {
        return DB::selectOne(
            'SELECT *, 0 AS is_expired
             FROM task_custody_records
             WHERE surface = ? AND surface_ref = ?
               AND released_at IS NULL
               AND expires_at >= NOW()
             LIMIT 1',
            [$surface, $surfaceRef]
        );
    }

    /**
     * Return the sole unreleased row for this surface/ref regardless of
     * expiry. Used by acquire() and sweep() — both need to see expired
     * rows to take recovery action. NOT equivalent to findActive: an
     * expired-unreleased row is returned here but not there.
     *
     * Expiry is computed in SQL (not PHP strtotime) to avoid a TZ
     * mismatch between the DB's timestamp interpretation and PHP's
     * default_timezone_get() when the two differ.
     */
    public function findUnreleased(string $surface, string $surfaceRef): ?object
    {
        return DB::selectOne(
            'SELECT *, (expires_at < NOW()) AS is_expired
             FROM task_custody_records
             WHERE surface = ? AND surface_ref = ? AND released_at IS NULL
             LIMIT 1',
            [$surface, $surfaceRef]
        );
    }

    /**
     * Release an expired unreleased record during acquire() so the
     * partial-unique constraint frees up for the new owner. Mirrors
     * what sweep() would do for the same row; this path just does it
     * eagerly instead of waiting for the next sweep tick.
     */
    private function releaseExpiredInline(object $row): bool
    {
        $envelope = [
            'reason' => 'expired_no_holder',
            'detected_by' => 'acquire_preempt',
            'surface' => $row->surface,
            'owner_token' => $row->owner_token,
            'expired_at' => $row->expires_at,
        ];

        $released = DB::update(
            "UPDATE task_custody_records
             SET released_at = NOW(),
                 outcome = 'failure',
                 result_envelope = ?,
                 notification_state = 'pending',
                 updated_at = NOW()
             WHERE id = ?
               AND owner_token = ?
               AND released_at IS NULL
               AND expires_at < NOW()",
            [json_encode($envelope), $row->id, $row->owner_token]
        );

        if ($released === 0) {
            Log::info('TaskCustodyService: skipped stale expired-row release', [
                'custody_id' => $row->id,
                'surface' => $row->surface,
                'surface_ref' => $row->surface_ref,
            ]);

            return false;
        }

        Log::info('TaskCustodyService: released expired row during acquire', [
            'custody_id' => $row->id,
            'surface' => $row->surface,
            'surface_ref' => $row->surface_ref,
        ]);

        return true;
    }

    /**
     * Returns true when the surface's liveness probe says the owner
     * token is still alive or cannot be proved dead. Both sweep and
     * competing acquire use this authority; release remains owner-driven.
     */
    public function hasLiveOwner(object $custody): bool
    {
        return match ($custody->surface) {
            // Parent PID/start death cannot prove descendants or remote work ended.
            // Retain expired scheduled custody until the owner publishes completion.
            self::SURFACE_SCHEDULED_JOB => true,
            // Parent death and cache-key absence do not prove physical work ended.
            self::SURFACE_MHC08_K3_JOB, self::SURFACE_AGENT_SESSION => true,
            // Horizon: no probe. Contract says Horizon owns liveness.
            // Treat as alive so sweep never force-releases a Horizon row;
            // the read-only mapping is observational.
            self::SURFACE_HORIZON_JOB => true,
            // Worker/transport death and age cannot prove remote descendant cleanup.
            self::SURFACE_COMPUTE_EXECUTION, self::SURFACE_LLM_POOL_EXECUTION, self::SURFACE_YOUTUBE_SOURCE_WRITE => true,
            default => true,
        };
    }

    private function isScheduledJobOwnerAlive(string $ownerToken): bool
    {
        if (! preg_match('/(?:^|:)pid:(\d+)(?:$|:)/', $ownerToken, $m)) {
            return true;
        }
        $pid = (int) $m[1];
        if ($pid <= 0) {
            return true;
        }

        if (PHP_OS_FAMILY !== 'Linux' || ! is_dir('/proc/self')) {
            // An unavailable local process probe cannot prove owner death.
            return true;
        }

        if (! is_dir("/proc/{$pid}")) {
            return false;
        }

        if (! preg_match('/(?:^|:)start:(\d+)(?:$|:)/', $ownerToken, $startMatch)) {
            return true;
        }

        $currentStartId = $this->readLinuxProcessStartId($pid);
        if ($currentStartId === null) {
            // /proc exists but its stat record is unreadable or malformed.
            // Preserve the holder rather than preempt from uncertainty.
            return true;
        }

        return hash_equals($startMatch[1], $currentStartId);
    }

    /**
     * Return Linux /proc stat field 22 (process start time in clock ticks).
     * Field 2 is parenthesized and may contain spaces or closing parentheses,
     * so parsing starts after its final closing delimiter.
     */
    private function readLinuxProcessStartId(int $pid): ?string
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if (! is_string($stat) || $stat === '') {
            return null;
        }

        $commEnd = strrpos($stat, ')');
        if ($commEnd === false) {
            return null;
        }

        $fields = preg_split('/\s+/', trim(substr($stat, $commEnd + 1)));
        $startId = $fields[19] ?? null;
        if (! is_string($startId) || ! ctype_digit($startId)) {
            return null;
        }

        $normalized = ltrim($startId, '0');

        return $normalized !== '' ? $normalized : '0';
    }

    private function isAgentSessionOwnerAlive(string $sessionRef): bool
    {
        try {
            $lockKey = "agent_session_lock:{$sessionRef}";

            return (bool) Redis::exists($lockKey);
        } catch (\Throwable $e) {
            // Redis unreachable — fail closed so sweep conservatively
            // leaves the record in place rather than force-releasing.
            return true;
        }
    }

    /**
     * Serialize the envelope to a size-bounded JSON string. If the
     * serialized envelope exceeds the limit, replace it with a minimal
     * valid-JSON summary envelope that retains the most operationally
     * useful fields (outcome_detail, duration_ms) when they fit.
     *
     * Byte-truncation of an already-encoded JSON string is unsafe — it
     * can produce malformed JSON that MySQL's JSON column will reject,
     * which would fail the release() UPDATE entirely.
     */
    private function truncateEnvelope(array $envelope): string
    {
        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '{"error":"envelope_encode_failed"}';
        }

        if (strlen($json) <= self::MAX_ENVELOPE_BYTES) {
            return $json;
        }

        $fallback = [
            'truncated' => true,
            'original_size_bytes' => strlen($json),
            'reason' => 'envelope_exceeded_limit',
        ];

        foreach (['outcome_detail', 'duration_ms', 'items_processed', 'error_class'] as $key) {
            if (array_key_exists($key, $envelope)) {
                $candidate = $fallback + [$key => $envelope[$key]];
                $candidateJson = json_encode($candidate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($candidateJson !== false && strlen($candidateJson) <= self::MAX_ENVELOPE_BYTES) {
                    $fallback = $candidate;
                }
            }
        }

        $out = json_encode($fallback, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $out !== false
            ? $out
            : '{"truncated":true,"reason":"envelope_exceeded_limit"}';
    }
}
