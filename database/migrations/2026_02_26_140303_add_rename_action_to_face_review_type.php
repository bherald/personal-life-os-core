<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'rename' action to the face review type's ui_schema and actions columns.
     */
    public function up(): void
    {
        $row = DB::selectOne("SELECT id, actions, ui_schema FROM review_type_registry WHERE name = 'face'");
        if (!$row) {
            return;
        }

        // Update actions column
        $actions = json_decode($row->actions, true) ?: [];
        $hasRename = collect($actions)->contains(fn($a) => ($a['name'] ?? '') === 'rename');
        if (!$hasRename) {
            $actions[] = [
                'icon' => 'pencil',
                'name' => 'rename',
                'label' => 'Correct Name',
                'handler' => 'renameFace',
            ];
            DB::update("UPDATE review_type_registry SET actions = ? WHERE id = ?", [
                json_encode($actions),
                $row->id,
            ]);
        }

        // Update ui_schema.actions
        $uiSchema = json_decode($row->ui_schema, true) ?: [];
        $uiActions = $uiSchema['actions'] ?? [];
        $hasRenameUi = collect($uiActions)->contains(fn($a) => ($a['name'] ?? '') === 'rename');
        if (!$hasRenameUi) {
            $uiActions[] = [
                'icon' => 'pencil',
                'name' => 'rename',
                'label' => 'Correct Name',
                'variant' => 'secondary',
            ];
            $uiSchema['actions'] = $uiActions;
            DB::update("UPDATE review_type_registry SET ui_schema = ? WHERE id = ?", [
                json_encode($uiSchema),
                $row->id,
            ]);
        }
    }

    /**
     * Remove 'rename' action from the face review type.
     */
    public function down(): void
    {
        $row = DB::selectOne("SELECT id, actions, ui_schema FROM review_type_registry WHERE name = 'face'");
        if (!$row) {
            return;
        }

        // Remove from actions
        $actions = json_decode($row->actions, true) ?: [];
        $actions = array_values(array_filter($actions, fn($a) => ($a['name'] ?? '') !== 'rename'));
        DB::update("UPDATE review_type_registry SET actions = ? WHERE id = ?", [json_encode($actions), $row->id]);

        // Remove from ui_schema.actions
        $uiSchema = json_decode($row->ui_schema, true) ?: [];
        if (isset($uiSchema['actions'])) {
            $uiSchema['actions'] = array_values(array_filter($uiSchema['actions'], fn($a) => ($a['name'] ?? '') !== 'rename'));
            DB::update("UPDATE review_type_registry SET ui_schema = ? WHERE id = ?", [json_encode($uiSchema), $row->id]);
        }
    }
};
