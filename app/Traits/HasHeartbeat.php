<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2: Heartbeat trait for long-running processes
 *
 * Jobs/commands should use this trait and call updateHeartbeat() periodically
 * during long operations. This enables stuck process detection with shorter
 * thresholds without risking false positives.
 *
 * Usage:
 *   use HasHeartbeat;
 *
 *   // In constructor or setup:
 *   $this->initHeartbeat('file_registry_sync_runs', $runId, 20);
 *
 *   // During processing loop:
 *   foreach ($items as $index => $item) {
 *       $this->processItem($item);
 *       $this->checkHeartbeat(); // Updates if interval elapsed
 *   }
 *
 * @see docs/STUCK-PROCESS-DETECTION-ROADMAP.md
 */
trait HasHeartbeat
{
    protected string $heartbeatTable = '';
    protected int $heartbeatRecordId = 0;
    protected int $heartbeatIntervalMinutes = 20;
    protected ?int $lastHeartbeatTime = null;

    /** Allowlist of tables that support heartbeat_at column */
    private static array $validHeartbeatTables = [
        'file_registry_sync_runs',
        'joplin_queue_jobs',
        'email_reply_drafts',
        'scheduled_job_runs',
    ];

    protected function initHeartbeat(string $table, int $recordId, int $intervalMinutes = 20): void
    {
        $this->heartbeatTable = $table;
        $this->heartbeatRecordId = $recordId;
        $this->heartbeatIntervalMinutes = $intervalMinutes;
        $this->lastHeartbeatTime = time();

        $this->updateHeartbeat(true);
    }

    protected function checkHeartbeat(): bool
    {
        if (!$this->heartbeatTable || !$this->heartbeatRecordId) {
            return false;
        }

        $elapsed = time() - ($this->lastHeartbeatTime ?? 0);
        $intervalSeconds = $this->heartbeatIntervalMinutes * 60;

        if ($elapsed >= $intervalSeconds) {
            return $this->updateHeartbeat();
        }

        return false;
    }

    protected function updateHeartbeat(bool $initial = false): bool
    {
        if (!$this->heartbeatTable || !$this->heartbeatRecordId) {
            return false;
        }

        if (!in_array($this->heartbeatTable, self::$validHeartbeatTables, true)) {
            Log::warning('HasHeartbeat: Invalid table', ['table' => $this->heartbeatTable]);
            return false;
        }

        try {
            $updated = DB::update(
                "UPDATE {$this->heartbeatTable} SET heartbeat_at = NOW() WHERE id = ?",
                [$this->heartbeatRecordId]
            );

            $this->lastHeartbeatTime = time();

            if (!$initial) {
                Log::debug('Heartbeat updated', [
                    'table' => $this->heartbeatTable,
                    'record_id' => $this->heartbeatRecordId,
                ]);
            }

            return $updated > 0;

        } catch (\Exception $e) {
            Log::warning('Failed to update heartbeat', [
                'table' => $this->heartbeatTable,
                'record_id' => $this->heartbeatRecordId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    protected function clearHeartbeat(): void
    {
        if (!$this->heartbeatTable || !$this->heartbeatRecordId) {
            return;
        }

        if (!in_array($this->heartbeatTable, self::$validHeartbeatTables, true)) {
            return;
        }

        try {
            DB::update(
                "UPDATE {$this->heartbeatTable} SET heartbeat_at = NULL WHERE id = ?",
                [$this->heartbeatRecordId]
            );
        } catch (\Exception $e) {
            Log::debug('Failed to clear heartbeat', [
                'table' => $this->heartbeatTable,
                'record_id' => $this->heartbeatRecordId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function getMinutesSinceHeartbeat(string $table, int $recordId): ?int
    {
        if (!in_array($table, self::$validHeartbeatTables, true)) {
            return null;
        }

        $results = DB::select(
            "SELECT heartbeat_at FROM {$table} WHERE id = ? LIMIT 1",
            [$recordId]
        );

        $record = $results[0] ?? null;
        if (!$record || !$record->heartbeat_at) {
            return null;
        }

        return (int) round((time() - strtotime($record->heartbeat_at)) / 60);
    }
}
