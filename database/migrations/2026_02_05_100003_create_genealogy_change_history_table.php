<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create genealogy_change_history table for entity versioning
 *
 * Tracks all changes to genealogy entities (persons, families, events, etc.)
 * for audit trail, undo capability, and diff viewing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE genealogy_change_history (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tree_id INT UNSIGNED NOT NULL COMMENT 'Tree this change belongs to',
                entity_type VARCHAR(50) NOT NULL COMMENT 'person, family, event, residence, source, media, etc.',
                entity_id INT UNSIGNED NOT NULL COMMENT 'ID of the entity that was changed',
                action ENUM('create', 'update', 'delete') NOT NULL,
                field_name VARCHAR(100) NULL COMMENT 'Field that was changed (NULL for create/delete)',
                old_value TEXT NULL COMMENT 'Previous value (NULL for create)',
                new_value TEXT NULL COMMENT 'New value (NULL for delete)',
                old_data JSON NULL COMMENT 'Full entity snapshot before change (for delete/complex changes)',
                new_data JSON NULL COMMENT 'Full entity snapshot after change (for create/complex changes)',
                changed_by INT UNSIGNED NULL COMMENT 'User ID who made the change',
                changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                change_reason VARCHAR(255) NULL COMMENT 'Optional reason/note for the change',
                batch_id CHAR(36) NULL COMMENT 'UUID to group related changes (e.g., GEDCOM import)',
                INDEX idx_tree_id (tree_id),
                INDEX idx_entity (entity_type, entity_id),
                INDEX idx_changed_at (changed_at),
                INDEX idx_changed_by (changed_by),
                INDEX idx_batch_id (batch_id),
                INDEX idx_action (action),
                CONSTRAINT fk_change_history_tree
                    FOREIGN KEY (tree_id)
                    REFERENCES genealogy_trees(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create index for efficient entity history lookup
        DB::statement("
            CREATE INDEX idx_entity_history ON genealogy_change_history(entity_type, entity_id, changed_at DESC)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('genealogy_change_history');
    }
};
