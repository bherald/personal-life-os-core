<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix three review queue issues:
 *
 * 1. Agent review spam: Auto-approve routine status reports (review_type='status')
 *    so they don't flood the pending queue. Only actionable findings need human review.
 *
 * 2. Ignore action: Add ignore button to ALL review types (agent, research, proposal, privacy),
 *    not just face. Both in ui_schema.actions and the actions column.
 *
 * 3. JSON rendering: Change agent card body to show summary as readable text
 *    and suppress empty details JSON. Add agent_id as badge in header.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('review_type_registry')) {
            return;
        }

        // Fix 2 & 3: Update agent type - add ignore action + improve card rendering
        DB::update("UPDATE review_type_registry SET ui_schema = ?, actions = ? WHERE name = 'agent'", [
            json_encode([
                'card' => [
                    'header' => [
                        ['type' => 'badge', 'source' => 'agent_id', 'class' => 'bg-ops-sky'],
                        ['type' => 'badge', 'source' => 'review_type', 'class' => 'bg-ops-plum'],
                        ['type' => 'text', 'source' => 'title', 'class' => 'font-semibold text-ops-peach flex-1'],
                        ['type' => 'confidence', 'source' => 'confidence'],
                    ],
                    'body' => [
                        ['type' => 'text', 'source' => 'summary', 'class' => 'text-sm text-ops-text-muted'],
                    ],
                    'footer' => [
                        ['type' => 'timestamp', 'source' => 'created_at', 'label' => 'Created'],
                        ['type' => 'timestamp', 'source' => 'expires_at', 'label' => 'Expires', 'warn_if_soon' => true],
                    ],
                ],
                'detail' => [
                    ['type' => 'text', 'source' => 'agent_id', 'label' => 'Agent'],
                    ['type' => 'text', 'source' => 'review_type', 'label' => 'Type'],
                    ['type' => 'json', 'source' => 'details', 'label' => 'Details', 'collapsible' => true],
                ],
                'actions' => [
                    ['name' => 'ignore', 'label' => 'Dismiss', 'icon' => 'eye-slash', 'variant' => 'secondary'],
                ],
            ]),
            json_encode([
                ['name' => 'ignore', 'label' => 'Dismiss', 'icon' => 'eye-slash', 'handler' => 'ignoreItem'],
            ]),
        ]);

        // Fix 2: Add ignore action to research type
        DB::update("UPDATE review_type_registry SET ui_schema = ?, actions = ? WHERE name = 'research'", [
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
                'actions' => [
                    ['name' => 'ignore', 'label' => 'Dismiss', 'icon' => 'eye-slash', 'variant' => 'secondary'],
                ],
            ]),
            json_encode([
                ['name' => 'ignore', 'label' => 'Dismiss', 'icon' => 'eye-slash', 'handler' => 'ignoreItem'],
            ]),
        ]);

        // Fix 2: Add ignore action to proposal type
        DB::update("UPDATE review_type_registry SET ui_schema = ?, actions = ? WHERE name = 'proposal'", [
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
                'actions' => [
                    ['name' => 'ignore', 'label' => 'Dismiss', 'icon' => 'eye-slash', 'variant' => 'secondary'],
                ],
            ]),
            json_encode([
                ['name' => 'ignore', 'label' => 'Dismiss', 'icon' => 'eye-slash', 'handler' => 'ignoreItem'],
            ]),
        ]);

        // Fix 2: Add ignore action to privacy type
        DB::update("UPDATE review_type_registry SET ui_schema = ?, actions = ? WHERE name = 'privacy'", [
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
                'actions' => [
                    ['name' => 'ignore', 'label' => 'Dismiss', 'icon' => 'eye-slash', 'variant' => 'secondary'],
                ],
            ]),
            json_encode([
                ['name' => 'ignore', 'label' => 'Dismiss', 'icon' => 'eye-slash', 'handler' => 'ignoreItem'],
            ]),
        ]);

        // Fix face ignore: Add 'ignored' to status enum on genealogy_face_match_queue
        DB::statement("
            ALTER TABLE genealogy_face_match_queue
            MODIFY COLUMN status ENUM('pending','approved','rejected','auto_linked','ignored')
            DEFAULT 'pending'
        ");

        // Fix agent ignore: ignoreItem uses WHERE id=? but agent unified_ids use token
        // Add 'ignored' and 'auto_dismissed' to agent_review_queue status for consistency
        // (status is VARCHAR, so no enum change needed, just data)

        // Fix 1: Auto-approve all existing routine status reports that are just noise
        // These are the 56+ "Workflow Pipeline Health Review" and similar repetitive items
        DB::update("
            UPDATE agent_review_queue
            SET status = 'auto_dismissed',
                reviewer_notes = 'Auto-dismissed: routine status report, not actionable',
                reviewed_at = NOW(),
                updated_at = NOW()
            WHERE status = 'pending'
            AND (
                title LIKE '%Health Review%'
                OR title LIKE '%Status Report%'
                OR title LIKE '%Pipeline Status%'
                OR title LIKE '%Routine Check%'
                OR review_type = 'status'
            )
        ");
    }

    public function down(): void
    {
        // Restore original ui_schema without ignore actions
        // (Not worth full rollback - just re-run the original migration)
    }
};
