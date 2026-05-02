<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Change History Service
 *
 * Tracks all changes to genealogy entities for audit trail,
 * undo/revert support, and activity feed display.
 */
class ChangeHistoryService
{
    public function recordChange(
        string $entityType,
        int $entityId,
        string $field,
        ?string $oldValue,
        ?string $newValue,
        string $changedBy = 'system',
        string $source = 'manual',
        ?string $notes = null
    ): int {
        if ($oldValue === $newValue) {
            return 0; // No change
        }

        DB::insert(
            "INSERT INTO genealogy_change_history (entity_type, entity_id, action, field_name, old_value, new_value, changed_by, changed_at)
             VALUES (?, ?, 'update', ?, ?, ?, ?, NOW())",
            [$entityType, $entityId, $field, $oldValue, $newValue, $changedBy]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    public function recordMultipleChanges(
        string $entityType,
        int $entityId,
        array $changes,
        string $changedBy = 'system',
        string $source = 'manual',
        ?string $notes = null
    ): int {
        $recorded = 0;
        foreach ($changes as $field => $values) {
            $old = $values['old'] ?? null;
            $new = $values['new'] ?? null;
            if ($old !== $new) {
                $this->recordChange($entityType, $entityId, $field, $old, $new, $changedBy, $source, $notes);
                $recorded++;
            }
        }
        return $recorded;
    }

    public function getHistory(string $entityType, int $entityId, int $limit = 50): array
    {
        return DB::select(
            "SELECT * FROM genealogy_change_history
             WHERE entity_type = ? AND entity_id = ?
             ORDER BY changed_at DESC
             LIMIT ?",
            [$entityType, $entityId, $limit]
        );
    }

    public function getFieldHistory(string $entityType, int $entityId, string $field): array
    {
        return DB::select(
            "SELECT * FROM genealogy_change_history
             WHERE entity_type = ? AND entity_id = ? AND field_name = ?
             ORDER BY changed_at DESC",
            [$entityType, $entityId, $field]
        );
    }

    public function revertChange(int $changeId): array
    {
        $change = DB::selectOne("SELECT * FROM genealogy_change_history WHERE id = ?", [$changeId]);
        if (!$change) {
            return ['success' => false, 'error' => 'Change record not found'];
        }

        $table = match ($change->entity_type) {
            'person' => 'genealogy_persons',
            'family' => 'genealogy_families',
            'event' => 'genealogy_events',
            'source' => 'genealogy_sources',
            default => null,
        };

        if (!$table) {
            return ['success' => false, 'error' => "Unknown entity type: {$change->entity_type}"];
        }

        // Apply revert
        $field = $change->field_name;
        // Whitelist allowed fields to prevent SQL injection
        $allowedFields = [
            'given_names', 'surname', 'sex', 'birth_date', 'birth_place', 'death_date', 'death_place',
            'notes', 'description', 'title', 'date', 'place', 'type',
            'husband_id', 'wife_id', 'marriage_date', 'marriage_place',
        ];

        if (!in_array($field, $allowedFields)) {
            return ['success' => false, 'error' => "Field not revertable: {$field}"];
        }

        DB::update(
            "UPDATE {$table} SET `{$field}` = ?, updated_at = NOW() WHERE id = ?",
            [$change->old_value, $change->entity_id]
        );

        // Record the revert as a new change
        $this->recordChange(
            $change->entity_type,
            $change->entity_id,
            $change->field_name,
            $change->new_value,
            $change->old_value,
            'system',
            'manual',
            "Reverted change #{$changeId}"
        );

        Log::info('GenealogyChangeHistory: Change reverted', [
            'change_id' => $changeId,
            'entity_type' => $change->entity_type,
            'entity_id' => $change->entity_id,
            'field' => $field,
        ]);

        return ['success' => true, 'reverted_change_id' => $changeId];
    }

    public function getRecentChanges(int $treeId, int $limit = 50): array
    {
        // Get changes for persons in this tree
        return DB::select(
            "SELECT gch.*, gp.given_name, gp.surname
             FROM genealogy_change_history gch
             LEFT JOIN genealogy_persons gp ON gp.id = gch.entity_id AND gch.entity_type = 'person'
             WHERE gch.entity_type = 'person'
             AND gch.entity_id IN (SELECT id FROM genealogy_persons WHERE tree_id = ?)
             ORDER BY gch.changed_at DESC
             LIMIT ?",
            [$treeId, $limit]
        );
    }

    public function getDiff(int $changeId): array
    {
        $change = DB::selectOne("SELECT * FROM genealogy_change_history WHERE id = ?", [$changeId]);
        if (!$change) {
            return ['error' => 'Not found'];
        }

        return [
            'id' => $change->id,
            'entity_type' => $change->entity_type,
            'entity_id' => $change->entity_id,
            'field' => $change->field_name,
            'old' => $change->old_value,
            'new' => $change->new_value,
            'changed_by' => $change->changed_by,
            'timestamp' => $change->changed_at,
        ];
    }

    public function getStats(?int $treeId = null): array
    {
        $params = [];
        $treeFilter = '';
        if ($treeId) {
            $treeFilter = "AND entity_id IN (SELECT id FROM genealogy_persons WHERE tree_id = ?)";
            $params[] = $treeId;
        }

        $byAction = DB::select(
            "SELECT action, COUNT(*) as count
             FROM genealogy_change_history
             WHERE entity_type = 'person' {$treeFilter}
             GROUP BY action",
            $params
        );

        $total = DB::selectOne(
            "SELECT COUNT(*) as count FROM genealogy_change_history WHERE 1=1 " .
            ($treeId ? "AND entity_type = 'person' {$treeFilter}" : ''),
            $params
        );

        return [
            'total_changes' => $total->count ?? 0,
            'by_action' => array_column(
                array_map(fn($r) => ['action' => $r->action, 'count' => $r->count], $byAction),
                'count', 'action'
            ),
        ];
    }
}
