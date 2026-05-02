<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3: Process Health Flag Service
 *
 * Manages tiered escalation for stuck processes:
 * - 1x threshold → warning (monitoring only, dashboard shows yellow)
 * - 2x threshold → flagged (notification sent, dashboard shows orange)
 * - 4x threshold → presumed_failed (recoverable, dashboard shows red)
 * - 8x threshold → hard_fail (auto-reset applied)
 *
 * Jobs can "resurrect" by calling clearFlag() on successful completion.
 *
 * @see docs/STUCK-PROCESS-DETECTION-ROADMAP.md
 */
class ProcessHealthFlagService
{
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_FLAGGED = 'flagged';
    public const LEVEL_PRESUMED_FAILED = 'presumed_failed';
    public const LEVEL_HARD_FAIL = 'hard_fail';

    public const LEVEL_MULTIPLIERS = [
        self::LEVEL_WARNING => 1,
        self::LEVEL_FLAGGED => 2,
        self::LEVEL_PRESUMED_FAILED => 4,
        self::LEVEL_HARD_FAIL => 8,
    ];

    public const BASE_THRESHOLDS = [
        'file_registry_sync_runs' => 360,
        'joplin_queue_jobs' => 120,
        'email_reply_drafts' => 60,
    ];

    public function getThresholdMinutes(string $tableName, string $level): int
    {
        $baseThreshold = self::BASE_THRESHOLDS[$tableName] ?? 360;
        $multiplier = self::LEVEL_MULTIPLIERS[$level] ?? 1;
        return $baseThreshold * $multiplier;
    }

    public function calculateLevel(string $tableName, int $minutesSinceActivity): ?string
    {
        $baseThreshold = self::BASE_THRESHOLDS[$tableName] ?? 360;

        if ($minutesSinceActivity >= $baseThreshold * 8) {
            return self::LEVEL_HARD_FAIL;
        }
        if ($minutesSinceActivity >= $baseThreshold * 4) {
            return self::LEVEL_PRESUMED_FAILED;
        }
        if ($minutesSinceActivity >= $baseThreshold * 2) {
            return self::LEVEL_FLAGGED;
        }
        if ($minutesSinceActivity >= $baseThreshold) {
            return self::LEVEL_WARNING;
        }

        return null;
    }

    public function flagOrEscalate(string $tableName, int $recordId, int $minutesSinceActivity, array $context = []): array
    {
        $result = [
            'action' => 'none',
            'flag_level' => null,
            'is_new' => false,
            'escalated' => false,
        ];

        $newLevel = $this->calculateLevel($tableName, $minutesSinceActivity);

        if (!$newLevel) {
            return $result;
        }

        $existingFlag = $this->getActiveFlag($tableName, $recordId);

        if ($existingFlag) {
            $currentLevelOrder = array_search($existingFlag->flag_level, array_keys(self::LEVEL_MULTIPLIERS));
            $newLevelOrder = array_search($newLevel, array_keys(self::LEVEL_MULTIPLIERS));

            if ($newLevelOrder > $currentLevelOrder) {
                $this->escalateFlag($existingFlag->id, $newLevel, $minutesSinceActivity, $context);
                $result['action'] = 'escalated';
                $result['flag_level'] = $newLevel;
                $result['escalated'] = true;
                $result['previous_level'] = $existingFlag->flag_level;

                Log::info('ProcessHealthFlagService: Escalated flag', [
                    'table' => $tableName,
                    'record_id' => $recordId,
                    'from_level' => $existingFlag->flag_level,
                    'to_level' => $newLevel,
                    'minutes_since_activity' => $minutesSinceActivity,
                ]);
            } else {
                $result['action'] = 'unchanged';
                $result['flag_level'] = $existingFlag->flag_level;
            }
        } else {
            $this->createFlag($tableName, $recordId, $newLevel, $minutesSinceActivity, $context);
            $result['action'] = 'created';
            $result['flag_level'] = $newLevel;
            $result['is_new'] = true;

            Log::info('ProcessHealthFlagService: Created flag', [
                'table' => $tableName,
                'record_id' => $recordId,
                'level' => $newLevel,
                'minutes_since_activity' => $minutesSinceActivity,
            ]);
        }

        return $result;
    }

    private function createFlag(string $tableName, int $recordId, string $level, int $minutesSinceActivity, array $context): void
    {
        DB::insert("
            INSERT INTO process_health_flags (table_name, record_id, flag_level, flagged_at, minutes_since_activity, context_data)
            VALUES (?, ?, ?, NOW(), ?, ?)
        ", [
            $tableName,
            $recordId,
            $level,
            $minutesSinceActivity,
            !empty($context) ? json_encode($context) : null,
        ]);
    }

    private function escalateFlag(int $flagId, string $newLevel, int $minutesSinceActivity, array $context): void
    {
        DB::update("
            UPDATE process_health_flags
            SET flag_level = ?, escalated_at = NOW(), minutes_since_activity = ?, context_data = ?
            WHERE id = ?
        ", [
            $newLevel,
            $minutesSinceActivity,
            !empty($context) ? json_encode($context) : null,
            $flagId,
        ]);
    }

    public function getActiveFlag(string $tableName, int $recordId): ?object
    {
        $results = DB::select("
            SELECT * FROM process_health_flags
            WHERE table_name = ? AND record_id = ? AND cleared_at IS NULL
            LIMIT 1
        ", [$tableName, $recordId]);

        return $results[0] ?? null;
    }

    public function clearFlag(string $tableName, int $recordId, string $reason = 'completed', ?string $notes = null): bool
    {
        $updated = DB::update("
            UPDATE process_health_flags
            SET cleared_at = NOW(), clear_reason = ?, notes = ?
            WHERE table_name = ? AND record_id = ? AND cleared_at IS NULL
        ", [$reason, $notes, $tableName, $recordId]);

        if ($updated > 0) {
            Log::info('ProcessHealthFlagService: Cleared flag (resurrection)', [
                'table' => $tableName,
                'record_id' => $recordId,
                'reason' => $reason,
            ]);
        }

        return $updated > 0;
    }

    public function getActiveFlags(?string $tableName = null, ?string $level = null): array
    {
        $where = ['cleared_at IS NULL'];
        $params = [];

        if ($tableName) {
            $where[] = 'table_name = ?';
            $params[] = $tableName;
        }
        if ($level) {
            $where[] = 'flag_level = ?';
            $params[] = $level;
        }

        return DB::select("
            SELECT * FROM process_health_flags
            WHERE " . implode(' AND ', $where) . "
            ORDER BY flagged_at DESC
        ", $params);
    }

    public function getHardFailFlags(?string $tableName = null): array
    {
        $where = "flag_level = ? AND cleared_at IS NULL";
        $params = [self::LEVEL_HARD_FAIL];

        if ($tableName) {
            $where .= " AND table_name = ?";
            $params[] = $tableName;
        }

        return DB::select("
            SELECT * FROM process_health_flags WHERE {$where}
        ", $params);
    }

    public function getFlagCountsByLevel(?string $tableName = null): array
    {
        $where = 'cleared_at IS NULL';
        $params = [];

        if ($tableName) {
            $where .= ' AND table_name = ?';
            $params[] = $tableName;
        }

        $results = DB::select("
            SELECT flag_level, COUNT(*) as count
            FROM process_health_flags
            WHERE {$where}
            GROUP BY flag_level
        ", $params);

        $counts = [
            self::LEVEL_WARNING => 0,
            self::LEVEL_FLAGGED => 0,
            self::LEVEL_PRESUMED_FAILED => 0,
            self::LEVEL_HARD_FAIL => 0,
        ];

        foreach ($results as $row) {
            $counts[$row->flag_level] = $row->count;
        }

        return $counts;
    }

    public function processStuckRecords(
        string $tableName,
        string $statusColumn,
        string $statusValue,
        string $activityColumn
    ): array {
        $summary = [
            'checked' => 0,
            'warning' => 0,
            'flagged' => 0,
            'presumed_failed' => 0,
            'hard_fail' => 0,
            'escalated' => 0,
        ];

        // Allowlist of valid column names to prevent SQL injection
        $validColumns = ['status', 'last_run_status', 'state', 'processing_status'];
        $validActivityColumns = ['updated_at', 'created_at', 'heartbeat_at', 'started_at', 'last_activity_at'];

        if (!in_array($statusColumn, $validColumns, true) || !in_array($activityColumn, $validActivityColumns, true)) {
            Log::warning('ProcessHealthFlagService: Invalid column names', [
                'status_column' => $statusColumn,
                'activity_column' => $activityColumn,
            ]);
            return $summary;
        }

        $records = DB::select("
            SELECT id, {$activityColumn} FROM {$tableName}
            WHERE {$statusColumn} = ?
        ", [$statusValue]);

        foreach ($records as $record) {
            $summary['checked']++;

            $activityTime = $record->{$activityColumn};
            if (!$activityTime) {
                continue;
            }

            $minutesSinceActivity = (int) round((time() - strtotime($activityTime)) / 60);

            $result = $this->flagOrEscalate($tableName, $record->id, $minutesSinceActivity);

            if ($result['flag_level']) {
                $summary[$result['flag_level']]++;
            }
            if ($result['escalated']) {
                $summary['escalated']++;
            }
        }

        return $summary;
    }
}
