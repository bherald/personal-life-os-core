<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Genealogy Change History Service
 *
 * Tracks all changes to genealogy entities for audit trail, undo capability,
 * and diff viewing. Implements automatic versioning for persons, families,
 * events, residences, sources, and media.
 *
 * Features:
 * - Field-level change tracking with old/new values
 * - Full entity snapshots for create/delete operations
 * - Batch grouping for bulk operations (e.g., GEDCOM imports)
 * - Diff viewer capability
 * - Undo support (restore previous values)
 */
class GenealogyChangeHistoryService
{
    /**
     * Supported entity types and their table names
     */
    private const ENTITY_TABLES = [
        'person' => 'genealogy_persons',
        'family' => 'genealogy_families',
        'event' => 'genealogy_events',
        'family_event' => 'genealogy_family_events',
        'residence' => 'genealogy_residences',
        'source' => 'genealogy_sources',
        'media' => 'genealogy_media',
        'citation' => 'genealogy_citations',
        'child' => 'genealogy_children',
    ];

    /**
     * Fields to exclude from change tracking (system fields)
     */
    private const EXCLUDED_FIELDS = [
        'id', 'created_at', 'updated_at', 'tree_id',
    ];

    /**
     * Current batch ID for grouping related changes
     */
    private ?string $currentBatchId = null;

    /**
     * Start a batch operation (groups related changes)
     *
     * @return string The batch ID
     */
    public function startBatch(): string
    {
        $this->currentBatchId = (string) Str::uuid();
        return $this->currentBatchId;
    }

    /**
     * End the current batch operation
     */
    public function endBatch(): void
    {
        $this->currentBatchId = null;
    }

    /**
     * Record a create action
     *
     * @param string $entityType Entity type (person, family, etc.)
     * @param int $entityId Entity ID
     * @param int $treeId Tree ID
     * @param array $data Created entity data
     * @param int|null $userId User who made the change
     * @param string|null $reason Optional reason for the change
     * @return int Change history ID
     */
    public function recordCreate(
        string $entityType,
        int $entityId,
        int $treeId,
        array $data,
        ?int $userId = null,
        ?string $reason = null
    ): int {
        return $this->recordChange(
            $treeId,
            $entityType,
            $entityId,
            'create',
            null,
            null,
            null,
            $data,
            $userId,
            $reason
        );
    }

    /**
     * Record an update action with field-level tracking
     *
     * @param string $entityType Entity type
     * @param int $entityId Entity ID
     * @param int $treeId Tree ID
     * @param array $oldData Previous entity data
     * @param array $newData Updated entity data
     * @param int|null $userId User who made the change
     * @param string|null $reason Optional reason
     * @return array Array of change history IDs (one per changed field)
     */
    public function recordUpdate(
        string $entityType,
        int $entityId,
        int $treeId,
        array $oldData,
        array $newData,
        ?int $userId = null,
        ?string $reason = null
    ): array {
        $changeIds = [];
        $changedFields = $this->detectChanges($oldData, $newData);

        foreach ($changedFields as $field => $change) {
            $changeIds[] = $this->recordChange(
                $treeId,
                $entityType,
                $entityId,
                'update',
                $field,
                $change['old'],
                $change['new'],
                null,
                $userId,
                $reason
            );
        }

        return $changeIds;
    }

    /**
     * Record a delete action with full snapshot
     *
     * @param string $entityType Entity type
     * @param int $entityId Entity ID
     * @param int $treeId Tree ID
     * @param array $data Entity data before deletion
     * @param int|null $userId User who made the change
     * @param string|null $reason Optional reason
     * @return int Change history ID
     */
    public function recordDelete(
        string $entityType,
        int $entityId,
        int $treeId,
        array $data,
        ?int $userId = null,
        ?string $reason = null
    ): int {
        return $this->recordChange(
            $treeId,
            $entityType,
            $entityId,
            'delete',
            null,
            null,
            null,
            $data,
            $userId,
            $reason
        );
    }

    /**
     * Core method to record a change
     */
    private function recordChange(
        int $treeId,
        string $entityType,
        int $entityId,
        string $action,
        ?string $fieldName,
        ?string $oldValue,
        ?string $newValue,
        ?array $fullSnapshot,
        ?int $userId,
        ?string $reason
    ): int {
        $sql = "
            INSERT INTO genealogy_change_history
            (tree_id, entity_type, entity_id, action, field_name, old_value, new_value, old_data, new_data, changed_by, changed_at, change_reason, batch_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
        ";

        // Determine which snapshot column to use based on action
        $oldData = null;
        $newData = null;
        if ($action === 'create') {
            $newData = $fullSnapshot ? json_encode($fullSnapshot) : null;
        } elseif ($action === 'delete') {
            $oldData = $fullSnapshot ? json_encode($fullSnapshot) : null;
        }

        DB::insert($sql, [
            $treeId,
            $entityType,
            $entityId,
            $action,
            $fieldName,
            $oldValue,
            $newValue,
            $oldData,
            $newData,
            $userId,
            $reason,
            $this->currentBatchId,
        ]);

        $changeId = (int) DB::getPdo()->lastInsertId();

        Log::debug('GenealogyChangeHistoryService: Change recorded', [
            'change_id' => $changeId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'field' => $fieldName,
            'batch_id' => $this->currentBatchId,
        ]);

        return $changeId;
    }

    /**
     * Detect changed fields between old and new data
     *
     * @param array $oldData Previous data
     * @param array $newData Updated data
     * @return array Changed fields with old/new values
     */
    private function detectChanges(array $oldData, array $newData): array
    {
        $changes = [];

        // Normalize arrays (convert objects to arrays, remove excluded fields)
        $oldData = $this->normalizeData($oldData);
        $newData = $this->normalizeData($newData);

        // Check all fields in new data
        foreach ($newData as $field => $newValue) {
            if (in_array($field, self::EXCLUDED_FIELDS, true)) {
                continue;
            }

            $oldValue = $oldData[$field] ?? null;

            // Convert to string for comparison
            $oldStr = $this->valueToString($oldValue);
            $newStr = $this->valueToString($newValue);

            if ($oldStr !== $newStr) {
                $changes[$field] = [
                    'old' => $oldStr,
                    'new' => $newStr,
                ];
            }
        }

        // Check for removed fields (in old but not in new)
        foreach ($oldData as $field => $oldValue) {
            if (in_array($field, self::EXCLUDED_FIELDS, true)) {
                continue;
            }

            if (!array_key_exists($field, $newData)) {
                $changes[$field] = [
                    'old' => $this->valueToString($oldValue),
                    'new' => null,
                ];
            }
        }

        return $changes;
    }

    /**
     * Normalize data array for comparison
     */
    private function normalizeData(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (is_object($value)) {
                $value = (array) $value;
            }
            $normalized[$key] = $value;
        }
        return $normalized;
    }

    /**
     * Convert value to string for storage
     */
    private function valueToString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        return (string) $value;
    }

    /**
     * Get change history for an entity
     *
     * @param string $entityType Entity type
     * @param int $entityId Entity ID
     * @param int $limit Maximum records
     * @param int $offset Offset for pagination
     * @return array Change history records
     */
    public function getEntityHistory(string $entityType, int $entityId, int $limit = 50, int $offset = 0): array
    {
        $changes = DB::select("
            SELECT ch.*, u.name as changed_by_name
            FROM genealogy_change_history ch
            LEFT JOIN users u ON u.id = ch.changed_by
            WHERE ch.entity_type = ? AND ch.entity_id = ?
            ORDER BY ch.changed_at DESC
            LIMIT ? OFFSET ?
        ", [$entityType, $entityId, $limit, $offset]);

        return array_map(function ($change) {
            if ($change->old_data) {
                $change->old_data = json_decode($change->old_data, true);
            }
            if ($change->new_data) {
                $change->new_data = json_decode($change->new_data, true);
            }
            return $change;
        }, $changes);
    }

    /**
     * Get all changes for a tree within a time range
     *
     * @param int $treeId Tree ID
     * @param string|null $since Start datetime
     * @param string|null $until End datetime
     * @param int $limit Maximum records
     * @return array Change records
     */
    public function getTreeHistory(int $treeId, ?string $since = null, ?string $until = null, int $limit = 100): array
    {
        $params = [$treeId];
        $whereClauses = ['ch.tree_id = ?'];

        if ($since) {
            $whereClauses[] = 'ch.changed_at >= ?';
            $params[] = $since;
        }
        if ($until) {
            $whereClauses[] = 'ch.changed_at <= ?';
            $params[] = $until;
        }

        $params[] = $limit;

        $sql = "
            SELECT ch.*, u.name as changed_by_name
            FROM genealogy_change_history ch
            LEFT JOIN users u ON u.id = ch.changed_by
            WHERE " . implode(' AND ', $whereClauses) . "
            ORDER BY ch.changed_at DESC
            LIMIT ?
        ";

        return DB::select($sql, $params);
    }

    /**
     * Get changes by batch ID
     *
     * @param string $batchId Batch UUID
     * @return array Changes in the batch
     */
    public function getBatchChanges(string $batchId): array
    {
        return DB::select("
            SELECT ch.*, u.name as changed_by_name
            FROM genealogy_change_history ch
            LEFT JOIN users u ON u.id = ch.changed_by
            WHERE ch.batch_id = ?
            ORDER BY ch.changed_at ASC, ch.id ASC
        ", [$batchId]);
    }

    /**
     * Generate a diff view for an entity's changes
     *
     * @param string $entityType Entity type
     * @param int $entityId Entity ID
     * @param int|null $fromChangeId Starting change ID (null = beginning)
     * @param int|null $toChangeId Ending change ID (null = current)
     * @return array Diff information
     */
    public function getDiff(string $entityType, int $entityId, ?int $fromChangeId = null, ?int $toChangeId = null): array
    {
        // Get all changes in range
        $params = [$entityType, $entityId];
        $whereClauses = ['entity_type = ?', 'entity_id = ?'];

        if ($fromChangeId) {
            $whereClauses[] = 'id >= ?';
            $params[] = $fromChangeId;
        }
        if ($toChangeId) {
            $whereClauses[] = 'id <= ?';
            $params[] = $toChangeId;
        }

        $changes = DB::select("
            SELECT * FROM genealogy_change_history
            WHERE " . implode(' AND ', $whereClauses) . "
            ORDER BY changed_at ASC, id ASC
        ", $params);

        // Build diff structure
        $diff = [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'from_change_id' => $fromChangeId,
            'to_change_id' => $toChangeId,
            'changes_count' => count($changes),
            'fields' => [],
            'actions' => [],
        ];

        foreach ($changes as $change) {
            $diff['actions'][] = [
                'id' => $change->id,
                'action' => $change->action,
                'field' => $change->field_name,
                'old_value' => $change->old_value,
                'new_value' => $change->new_value,
                'changed_by' => $change->changed_by,
                'changed_at' => $change->changed_at,
                'reason' => $change->change_reason,
            ];

            // Track field-level changes
            if ($change->field_name && $change->action === 'update') {
                if (!isset($diff['fields'][$change->field_name])) {
                    $diff['fields'][$change->field_name] = [
                        'original' => $change->old_value,
                        'current' => $change->new_value,
                        'changes' => 0,
                    ];
                }
                $diff['fields'][$change->field_name]['current'] = $change->new_value;
                $diff['fields'][$change->field_name]['changes']++;
            }
        }

        return $diff;
    }

    /**
     * Restore an entity to a previous state
     *
     * @param int $changeId Change ID to restore to
     * @param int|null $userId User performing the restore
     * @return array Result with restored data
     */
    public function restoreToChange(int $changeId, ?int $userId = null): array
    {
        // Get the change record
        $change = DB::selectOne("SELECT * FROM genealogy_change_history WHERE id = ?", [$changeId]);

        if (!$change) {
            return ['success' => false, 'error' => 'Change not found'];
        }

        $entityType = $change->entity_type;
        $entityId = $change->entity_id;
        $table = self::ENTITY_TABLES[$entityType] ?? null;

        if (!$table) {
            return ['success' => false, 'error' => 'Unknown entity type'];
        }

        // For delete actions, restore from old_data snapshot
        if ($change->action === 'delete' && $change->old_data) {
            $restoreData = json_decode($change->old_data, true);
            return $this->restoreDeletedEntity($table, $entityType, $entityId, $change->tree_id, $restoreData, $userId);
        }

        // For update actions, restore the old value
        if ($change->action === 'update' && $change->field_name) {
            return $this->restoreFieldValue($table, $entityType, $entityId, $change->tree_id, $change->field_name, $change->old_value, $userId);
        }

        return ['success' => false, 'error' => 'Cannot restore this type of change'];
    }

    /**
     * Restore a deleted entity
     */
    private function restoreDeletedEntity(string $table, string $entityType, int $entityId, int $treeId, array $data, ?int $userId): array
    {
        // Remove system fields that shouldn't be inserted
        unset($data['id']);

        // Validate column names contain only safe characters (alphanumeric + underscore)
        $fields = array_keys($data);
        foreach ($fields as $field) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
                Log::warning('GenealogyChangeHistoryService: Invalid column name in restore data', ['field' => $field]);
                return ['success' => false, 'error' => 'Invalid column name in restore data'];
            }
        }
        $quotedFields = array_map(fn($f) => "`{$f}`", $fields);
        $placeholders = array_fill(0, count($fields), '?');
        $values = array_values($data);

        $sql = "INSERT INTO {$table} (" . implode(', ', $quotedFields) . ") VALUES (" . implode(', ', $placeholders) . ")";

        try {
            DB::insert($sql, $values);
            $newId = (int) DB::getPdo()->lastInsertId();

            // Record the restore action
            $this->recordCreate($entityType, $newId, $treeId, $data, $userId, "Restored from change #{$entityId}");

            return [
                'success' => true,
                'new_entity_id' => $newId,
                'restored_data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('GenealogyChangeHistoryService: Restore failed', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Restore a single field value
     */
    private function restoreFieldValue(string $table, string $entityType, int $entityId, int $treeId, string $fieldName, ?string $oldValue, ?int $userId): array
    {
        // Validate field name contains only safe characters
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $fieldName)) {
            Log::warning('GenealogyChangeHistoryService: Invalid field name in restore', ['field' => $fieldName]);
            return ['success' => false, 'error' => 'Invalid field name'];
        }

        // Get current value for change tracking
        $current = DB::selectOne("SELECT `{$fieldName}` FROM {$table} WHERE id = ?", [$entityId]);

        if (!$current) {
            return ['success' => false, 'error' => 'Entity not found'];
        }

        try {
            // Update the field
            DB::update("UPDATE {$table} SET `{$fieldName}` = ?, updated_at = NOW() WHERE id = ?", [$oldValue, $entityId]);

            // Record the restore as an update
            $this->recordChange(
                $treeId,
                $entityType,
                $entityId,
                'update',
                $fieldName,
                $current->$fieldName,
                $oldValue,
                null,
                $userId,
                "Restored field '{$fieldName}' to previous value"
            );

            return [
                'success' => true,
                'field' => $fieldName,
                'restored_value' => $oldValue,
                'previous_value' => $current->$fieldName,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get change statistics for a tree
     *
     * @param int $treeId Tree ID
     * @param int $days Number of days to look back
     * @return array Statistics
     */
    public function getStatistics(int $treeId, int $days = 30): array
    {
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total_changes,
                SUM(CASE WHEN action = 'create' THEN 1 ELSE 0 END) as creates,
                SUM(CASE WHEN action = 'update' THEN 1 ELSE 0 END) as updates,
                SUM(CASE WHEN action = 'delete' THEN 1 ELSE 0 END) as deletes,
                COUNT(DISTINCT entity_type) as entity_types_modified,
                COUNT(DISTINCT changed_by) as unique_users,
                COUNT(DISTINCT batch_id) as batch_operations
            FROM genealogy_change_history
            WHERE tree_id = ?
              AND changed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ", [$treeId, $days]);

        $byEntityType = DB::select("
            SELECT entity_type, action, COUNT(*) as count
            FROM genealogy_change_history
            WHERE tree_id = ?
              AND changed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY entity_type, action
            ORDER BY entity_type, action
        ", [$treeId, $days]);

        $mostChangedEntities = DB::select("
            SELECT entity_type, entity_id, COUNT(*) as change_count
            FROM genealogy_change_history
            WHERE tree_id = ?
              AND changed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY entity_type, entity_id
            ORDER BY change_count DESC
            LIMIT 10
        ", [$treeId, $days]);

        return [
            'summary' => [
                'total_changes' => (int) $stats->total_changes,
                'creates' => (int) $stats->creates,
                'updates' => (int) $stats->updates,
                'deletes' => (int) $stats->deletes,
                'entity_types_modified' => (int) $stats->entity_types_modified,
                'unique_users' => (int) $stats->unique_users,
                'batch_operations' => (int) $stats->batch_operations,
            ],
            'by_entity_type' => $byEntityType,
            'most_changed_entities' => $mostChangedEntities,
            'period_days' => $days,
        ];
    }

    /**
     * Purge old change history (for maintenance)
     *
     * @param int $treeId Tree ID
     * @param int $retentionDays Days to retain
     * @return int Number of records deleted
     */
    public function purgeOldHistory(int $treeId, int $retentionDays = 365): int
    {
        return DB::delete("
            DELETE FROM genealogy_change_history
            WHERE tree_id = ?
              AND changed_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ", [$treeId, $retentionDays]);
    }
}
