<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N90 — Add root_person_id to genealogy_trees
 *
 * The root person is the tree owner / central person from whom all ancestor
 * research radiates. Required for:
 * - BFS ancestor path computation (rebuildAncestorPaths uses this, not heuristic)
 * - UI default: opening a tree defaults to root_person_id
 * - Agent orientation: list_trees returns root_person_id, agent calls get_person first
 * - Multi-tree support: each tree has independent root, no cross-tree bleed
 *
 * Personal installs may optionally seed a known default root person.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add column (try/catch for idempotency — MySQL has no ADD IF NOT EXISTS)
        try {
            DB::statement("
                ALTER TABLE genealogy_trees
                ADD COLUMN root_person_id INT UNSIGNED NULL
                    COMMENT 'Tree owner / central person; starting point for ancestor BFS and UI default'
                AFTER name
            ");
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'Duplicate column name')) {
                throw $e;
            }
        }

        if ((bool) env('PLOS_SEED_PERSONAL_GENEALOGY_ROOT', false)) {
            $rootPersonId = (int) env('PLOS_PERSONAL_GENEALOGY_ROOT_PERSON_ID');
            $treeId = (int) env('PLOS_PERSONAL_GENEALOGY_TREE_ID');

            if ($rootPersonId > 0 && $treeId > 0) {
                DB::update(
                    'UPDATE genealogy_trees SET root_person_id = ? WHERE id = ?',
                    [$rootPersonId, $treeId]
                );
            }
        }
    }

    public function down(): void
    {
        try {
            DB::statement('ALTER TABLE genealogy_trees DROP COLUMN root_person_id');
        } catch (\Throwable $e) {
            // Column may not exist
        }
    }
};
