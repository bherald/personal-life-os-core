<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add ui_schema column to review_type_registry for dynamic Vue rendering.
 *
 * UI schema defines what controls to render for each review item:
 * - fields: Array of field definitions with type, label, source, etc.
 * - layout: Optional layout configuration (grid, flex)
 * - actions: Custom action buttons with icons and handlers
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('review_type_registry')) {
            return;
        }

        // Add ui_schema column if not exists
        if (!Schema::hasColumn('review_type_registry', 'ui_schema')) {
            DB::statement("
                ALTER TABLE review_type_registry
                ADD COLUMN ui_schema JSON DEFAULT NULL
                COMMENT 'Dynamic UI schema for Vue rendering'
                AFTER field_mapping
            ");
        }

        // Update existing types with UI schemas
        $this->updateUiSchemas();
    }

    public function down(): void
    {
        if (Schema::hasColumn('review_type_registry', 'ui_schema')) {
            DB::statement("ALTER TABLE review_type_registry DROP COLUMN ui_schema");
        }
    }

    private function updateUiSchemas(): void
    {
        // Agent Findings UI schema
        DB::update("UPDATE review_type_registry SET ui_schema = ? WHERE name = 'agent'", [
            json_encode([
                'card' => [
                    'header' => [
                        ['type' => 'badge', 'source' => 'review_type', 'class' => 'bg-ops-sky'],
                        ['type' => 'text', 'source' => 'title', 'class' => 'font-semibold text-ops-peach flex-1'],
                        ['type' => 'confidence', 'source' => 'confidence'],
                    ],
                    'body' => [
                        ['type' => 'text', 'source' => 'summary', 'class' => 'text-sm text-ops-text-muted'],
                        ['type' => 'json', 'source' => 'details', 'label' => 'Details', 'collapsible' => true],
                    ],
                    'footer' => [
                        ['type' => 'timestamp', 'source' => 'created_at', 'label' => 'Created'],
                        ['type' => 'timestamp', 'source' => 'expires_at', 'label' => 'Expires', 'warn_if_soon' => true],
                    ],
                ],
                'detail' => [
                    ['type' => 'text', 'source' => 'agent_id', 'label' => 'Agent'],
                    ['type' => 'text', 'source' => 'review_type', 'label' => 'Type'],
                    ['type' => 'json', 'source' => 'details', 'label' => 'Full Details', 'expanded' => true],
                ],
            ])
        ]);

        // Research Facts UI schema
        DB::update("UPDATE review_type_registry SET ui_schema = ? WHERE name = 'research'", [
            json_encode([
                'card' => [
                    'header' => [
                        ['type' => 'badge', 'source' => 'fact_type', 'class' => 'bg-ops-butterscotch'],
                        ['type' => 'badge', 'source' => 'domain_category', 'class' => 'bg-ops-plum'],
                        ['type' => 'confidence', 'source' => 'confidence'],
                    ],
                    'body' => [
                        ['type' => 'text', 'source' => 'summary', 'class' => 'text-sm'],
                        ['type' => 'text', 'source' => 'verification_summary', 'label' => 'Verification', 'class' => 'text-xs text-ops-text-muted mt-2'],
                    ],
                    'footer' => [
                        ['type' => 'link_list', 'source' => 'source_urls', 'label' => 'Sources'],
                        ['type' => 'text', 'source' => 'mission_title', 'label' => 'Mission'],
                    ],
                ],
            ])
        ]);

        // Genealogy Proposals UI schema
        DB::update("UPDATE review_type_registry SET ui_schema = ? WHERE name = 'proposal'", [
            json_encode([
                'card' => [
                    'header' => [
                        ['type' => 'badge', 'source' => 'relationship_type', 'class' => 'bg-ops-lilac'],
                        ['type' => 'text', 'source' => 'person_name', 'prefix' => 'For: ', 'class' => 'text-ops-sky'],
                        ['type' => 'confidence', 'source' => 'confidence'],
                    ],
                    'body' => [
                        ['type' => 'text', 'source' => 'proposed_name', 'label' => 'Proposed Person', 'class' => 'font-semibold'],
                        ['type' => 'text', 'source' => 'proposed_birth_date', 'label' => 'Birth'],
                        ['type' => 'text', 'source' => 'proposed_birth_place', 'label' => 'Place'],
                        ['type' => 'text', 'source' => 'summary', 'label' => 'Evidence', 'class' => 'text-sm text-ops-text-muted mt-2'],
                    ],
                    'footer' => [
                        ['type' => 'json', 'source' => 'evidence_sources', 'label' => 'Sources', 'compact' => true],
                    ],
                ],
            ])
        ]);

        // Face Matches UI schema
        DB::update("UPDATE review_type_registry SET ui_schema = ? WHERE name = 'face'", [
            json_encode([
                'card' => [
                    'layout' => 'horizontal', // Image on left, content on right
                    'image' => [
                        'type' => 'image',
                        'source' => 'image_url',
                        'fallback' => 'user-circle',
                        'size' => 'lg',
                        'class' => 'rounded-lg',
                    ],
                    'header' => [
                        ['type' => 'badge', 'source' => 'match_type', 'class' => 'bg-ops-green'],
                        ['type' => 'confidence', 'source' => 'confidence'],
                    ],
                    'body' => [
                        ['type' => 'text', 'source' => 'face_name', 'label' => 'Face', 'fallback' => 'Unknown'],
                        ['type' => 'text', 'source' => 'suggested_person_name', 'label' => 'Suggested', 'class' => 'text-ops-sky'],
                    ],
                    'footer' => [
                        ['type' => 'timestamp', 'source' => 'created_at'],
                    ],
                ],
                'actions' => [
                    ['name' => 'link', 'label' => 'Link to Person', 'icon' => 'link', 'variant' => 'primary'],
                    ['name' => 'ignore', 'label' => 'Ignore', 'icon' => 'eye-slash', 'variant' => 'secondary'],
                ],
            ])
        ]);

        // Privacy Requests UI schema
        DB::update("UPDATE review_type_registry SET ui_schema = ? WHERE name = 'privacy'", [
            json_encode([
                'card' => [
                    'header' => [
                        ['type' => 'badge', 'source' => 'broker_name', 'class' => 'bg-ops-peach'],
                        ['type' => 'text', 'source' => 'subject_name', 'prefix' => 'Subject: '],
                        ['type' => 'confidence', 'source' => 'confidence'],
                    ],
                    'body' => [
                        ['type' => 'link', 'source' => 'profile_url', 'label' => 'Profile URL', 'external' => true],
                        ['type' => 'link', 'source' => 'broker_url', 'label' => 'Broker Site', 'external' => true],
                        ['type' => 'text', 'source' => 'summary', 'label' => 'AI Notes', 'class' => 'text-sm text-ops-text-muted'],
                    ],
                    'footer' => [
                        ['type' => 'timestamp', 'source' => 'created_at'],
                    ],
                ],
            ])
        ]);
    }
};
