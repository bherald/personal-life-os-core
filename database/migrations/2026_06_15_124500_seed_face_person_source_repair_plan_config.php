<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function config(): array
    {
        return [
            'default_limit' => 25,
            'max_limit' => 250,
            'max_offset' => 100000,
            'review_type' => 'genealogy_review_packet',
            'finding_type' => 'face_person_source_repair_plan',
            'agent_id' => 'genealogy-review-packet',
            'priority' => 2,
            'confidence' => 0.82,
            'expires_days' => 30,
            'sprint_boundary_label' => 'FT4 face/photo provenance repair review',
            'packet_identity_label' => 'FT4 face/photo provenance repair packet',
            'packet_identity_scope' => 'face_photo_provenance_repair',
            'refill_policy' => [
                'enabled' => true,
                'packet_limit' => 25,
                'offset_step' => 25,
                'max_packets_per_run' => 2,
                'max_pending_total' => 14,
                'per_status_pending_target' => 2,
                'status_order' => [
                    'face_export_person_conflict',
                    'face_trusted_export_no_source',
                    'face_untrusted_export_review',
                    'person_media_unbacked_confirmed',
                    'person_media_unbacked_unconfirmed',
                    'person_media_source_backed_unconfirmed',
                ],
            ],
            'actionable_status_order' => [
                'face_export_person_conflict',
                'face_linked_display_mismatch',
                'face_trusted_export_no_source',
                'face_untrusted_export_review',
                'face_no_export_provenance',
                'person_media_unbacked_confirmed',
                'person_media_unbacked_unconfirmed',
                'person_media_source_backed_unconfirmed',
            ],
            'status_actions' => [
                'face_export_person_conflict' => [
                    'action' => 'operator_resolve_export_person_conflict',
                    'manual_next_step' => 'Compare export provenance, linked FT person, and source-backed media before clearing or relinking any identity.',
                ],
                'face_linked_display_mismatch' => [
                    'action' => 'operator_review_face_display_label_mismatch',
                    'manual_next_step' => 'Decide whether the face label, linked FT person, or export provenance is the correct identity before any rename.',
                ],
                'face_trusted_export_no_source' => [
                    'action' => 'source_backfill_trusted_export_face',
                    'manual_next_step' => 'Back the trusted export face with a person-media citation or keep it pending until a source is found.',
                ],
                'face_untrusted_export_review' => [
                    'action' => 'operator_review_or_quarantine_untrusted_face_claim',
                    'manual_next_step' => 'Review the face claim against the original export; quarantine only after an operator confirms the claim is wrong.',
                ],
                'face_no_export_provenance' => [
                    'action' => 'operator_review_face_without_export_provenance',
                    'manual_next_step' => 'Find source/export support or keep the face identity untrusted until a provenance row exists.',
                ],
                'person_media_unbacked_confirmed' => [
                    'action' => 'operator_reopen_unbacked_confirmed_person_media',
                    'manual_next_step' => 'Review why the person-media photo was confirmed without citation backing before keeping or clearing that confirmation.',
                ],
                'person_media_unbacked_unconfirmed' => [
                    'action' => 'operator_review_unbacked_person_media_link',
                    'manual_next_step' => 'Add citation backing or leave the person-media photo link unconfirmed.',
                ],
                'person_media_source_backed_unconfirmed' => [
                    'action' => 'operator_confirm_source_backed_person_media',
                    'manual_next_step' => 'Review the cited source and face region before confirming the person-media photo link.',
                ],
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'faces', 'config_key' => 'face_person_source_repair_plan'],
            [
                'config_value' => json_encode($this->config(), JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Table-owned policy for ops:face-person-source-repair-plan review packet staging. Controls limits, status ordering, action labels, confidence, and packet routing; runtime fails closed when this row is missing or malformed.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', 'faces')
            ->where('config_key', 'face_person_source_repair_plan')
            ->where('config_value', json_encode($this->config(), JSON_UNESCAPED_SLASHES))
            ->delete();
    }
};
