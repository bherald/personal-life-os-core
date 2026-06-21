<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function policyPatch(): array
    {
        return [
            'proposal_change_type' => 'source_add',
            'proposal_change_types' => ['source_add', 'notes_append'],
            'reason' => 'Auto-ignored below-actionability Genea review noise: writeback disallowed and confidence below review threshold.',
            'policy_notes' => [
                'selection' => 'Targets pending genealogy_finding rows in a single tree whose proposal change type is in proposal_change_types, whose actionability gate contains below_actionability_confidence, and whose DS governance writeback_allowed flag is false.',
                'low_actionability_change_types' => 'source_add rows are weak source suggestions; notes_append rows are queue-completion artifacts. Both are ignored only after below-actionability and writeback-denied gates are present.',
                'decision' => 'ignore is used for low-actionability throughput noise; reject remains reserved for operator-reviewed incorrect evidence.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'genealogy')
            ->where('config_key', 'weak_source_add_cleanup_policy')
            ->first(['config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            $policy = [];
        }

        $patch = $this->policyPatch();
        $notes = array_replace(
            is_array($policy['policy_notes'] ?? null) ? $policy['policy_notes'] : [],
            $patch['policy_notes']
        );
        unset($patch['policy_notes']);

        $policy = array_replace($policy, $patch);
        $policy['policy_notes'] = $notes;

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'genealogy', 'config_key' => 'weak_source_add_cleanup_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for ignoring below-actionability Genea review noise without canonical data writes.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $this->updateScheduledJobNotes();
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'genealogy')
            ->where('config_key', 'weak_source_add_cleanup_policy')
            ->first(['config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            return;
        }

        unset($policy['proposal_change_types']);
        $policy['proposal_change_type'] = 'source_add';
        $policy['reason'] = 'Auto-ignored below-actionability source_add review noise: writeback disallowed and confidence below review threshold.';
        if (is_array($policy['policy_notes'] ?? null)) {
            unset($policy['policy_notes']['low_actionability_change_types']);
            $policy['policy_notes']['selection'] = 'Targets pending genealogy_finding rows in a single tree whose first proposal is source_add, whose actionability gate contains below_actionability_confidence, and whose DS governance writeback_allowed flag is false.';
            $policy['policy_notes']['decision'] = 'ignore is used for low-actionability throughput noise; reject remains reserved for operator-reviewed incorrect evidence.';
        }

        DB::table('system_configs')
            ->where('section', 'genealogy')
            ->where('config_key', 'weak_source_add_cleanup_policy')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function updateScheduledJobNotes(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $row = DB::table('scheduled_jobs')
            ->where('name', 'genealogy_weak_source_add_cleanup')
            ->first(['id', 'notes']);

        if (! $row) {
            return;
        }

        $notes = json_decode((string) ($row->notes ?? ''), true);
        if (! is_array($notes)) {
            $notes = [];
        }

        $notes['summary'] = 'Ignores only below-actionability genealogy_finding rows selected by system_configs.genealogy.weak_source_add_cleanup_policy.';
        $notes['cleanup_scope'] = [
            'source_add',
            'notes_append',
        ];

        DB::table('scheduled_jobs')
            ->where('id', $row->id)
            ->update([
                'description' => 'Table-driven Genea cleanup lane for below-actionability review noise.',
                'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
