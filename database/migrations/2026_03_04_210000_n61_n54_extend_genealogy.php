<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * N61 + N54: Extend proposed change types + register get_siblings tool
 *
 * N61: Adds 7 new change_types agents can propose:
 *   notes_append, residence_add, family_event_update, external_record_link,
 *   source_create, clipping_link, media_metadata_update
 *
 * N54: Registers get_siblings tool (GenealogyService::getPersonSiblings already exists)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1a. Extend genealogy_proposed_changes.change_type enum with 7 new values
        DB::statement("ALTER TABLE genealogy_proposed_changes MODIFY COLUMN change_type
            ENUM('fact_update','event_add','source_add','media_link',
                 'notes_append','residence_add','family_event_update',
                 'external_record_link','source_create','clipping_link',
                 'media_metadata_update') NOT NULL");

        // 1b. Register get_siblings tool in agent_tool_registry
        DB::table('agent_tool_registry')->updateOrInsert(
            ['name' => 'get_siblings'],
            [
                'service_class' => 'App\Services\Genealogy\GenealogyService',
                'method' => 'getPersonSiblings',
                'description' => 'Get all siblings of a person (persons sharing at least one parent family). Use in analyze phase to verify family completeness.',
                'parameters' => json_encode([
                    'person_id' => ['type' => 'integer', 'description' => 'Person ID to find siblings for', 'required' => true],
                ]),
                'permissions' => json_encode(['genealogy:read']),
                'category' => 'genealogy',
                'enabled' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 1c. Update change_proposal ui_schema: add change_type display in header (already present)
        // No schema change needed — the badge already shows change_type from header config
    }

    public function down(): void
    {
        // Revert enum to original 4 values
        DB::statement("ALTER TABLE genealogy_proposed_changes MODIFY COLUMN change_type
            ENUM('fact_update','event_add','source_add','media_link') NOT NULL");

        // Remove get_siblings tool
        DB::table('agent_tool_registry')->where('name', 'get_siblings')->delete();
    }
};
