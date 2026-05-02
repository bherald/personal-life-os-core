<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N91 — Relationship proposal enhancements
 *
 * Gaps fixed:
 * 1. Add marriage_date/marriage_place columns — eliminates fragile regex parsing
 * 2. Add occupation/notes columns — agent can now propose these fields
 * 3. Update agent_tool_registry to expose the new parameters
 *
 * Post-approval coverage rebuild (auto-trigger ancestor_paths + priority scores)
 * is handled in FamilyService::applyProposal() — no schema change needed there.
 * Evidence source transfer to genealogy_citations is also FamilyService-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add new columns to genealogy_proposed_relationships
        $addCols = [
            "ALTER TABLE genealogy_proposed_relationships
             ADD COLUMN proposed_marriage_date VARCHAR(50) NULL
                 COMMENT 'For spouse proposals: marriage date in GEDCOM format'
             AFTER proposed_death_place",

            "ALTER TABLE genealogy_proposed_relationships
             ADD COLUMN proposed_marriage_place VARCHAR(255) NULL
                 COMMENT 'For spouse proposals: marriage location'
             AFTER proposed_marriage_date",

            "ALTER TABLE genealogy_proposed_relationships
             ADD COLUMN proposed_occupation VARCHAR(255) NULL
                 COMMENT 'Proposed person occupation'
             AFTER proposed_marriage_place",

            "ALTER TABLE genealogy_proposed_relationships
             ADD COLUMN proposed_notes TEXT NULL
                 COMMENT 'Research notes to attach to new person'
             AFTER proposed_occupation",
        ];

        foreach ($addCols as $sql) {
            try {
                DB::statement($sql);
            } catch (\Throwable $e) {
                if (!str_contains($e->getMessage(), 'Duplicate column name')) {
                    throw $e;
                }
            }
        }

        // Update agent_tool_registry: add new parameters to propose_relationship
        $params = json_encode([
            'person_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Existing person ID in tree',
            ],
            'relationship_type' => [
                'type' => 'string',
                'required' => true,
                'description' => 'parent, child, sibling, or spouse',
            ],
            'proposed_name' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Full name of proposed relative',
            ],
            'proposed_sex' => [
                'type' => 'string',
                'required' => false,
                'description' => 'M or F (optional)',
            ],
            'proposed_birth_date' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Birth date in GEDCOM format (e.g. ABT 1843, BEF 1850, 12 MAR 1843)',
            ],
            'proposed_birth_place' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Birth place (Town, County, State, Country)',
            ],
            'proposed_death_date' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Death date in GEDCOM format',
            ],
            'proposed_death_place' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Death place',
            ],
            'proposed_marriage_date' => [
                'type' => 'string',
                'required' => false,
                'description' => 'For spouse proposals: marriage date in GEDCOM format',
            ],
            'proposed_marriage_place' => [
                'type' => 'string',
                'required' => false,
                'description' => 'For spouse proposals: marriage location',
            ],
            'proposed_occupation' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Occupation of proposed person (from census, directory, etc.)',
            ],
            'proposed_notes' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Research notes to attach to the new person record',
            ],
            'confidence' => [
                'type' => 'float',
                'required' => false,
                'description' => 'Confidence 0.0-1.0 (default 0.5)',
            ],
            'evidence_summary' => [
                'type' => 'string',
                'required' => true,
                'description' => 'How the relationship was determined — cite source documents',
            ],
            'evidence_sources' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Array of source citation strings (URL, record ID, or full citation)',
            ],
        ]);

        DB::table('agent_tool_registry')
            ->where('name', 'propose_relationship')
            ->update(['parameters' => $params]);
    }

    public function down(): void
    {
        foreach (['proposed_notes', 'proposed_occupation', 'proposed_marriage_place', 'proposed_marriage_date'] as $col) {
            try {
                DB::statement("ALTER TABLE genealogy_proposed_relationships DROP COLUMN {$col}");
            } catch (\Throwable $e) {
                // ignore if already removed
            }
        }
    }
};
