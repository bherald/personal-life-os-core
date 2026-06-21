<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'enabled' => true,
            'default_limit' => 50,
            'max_limit' => 100,
            'status' => 'pending',
            'review_type' => 'genealogy_finding',
            'proposal_change_type' => 'source_add',
            'gate_reason' => 'below_actionability_confidence',
            'writeback_allowed' => false,
            'decision' => 'ignore',
            'reason' => 'Auto-ignored below-actionability source_add review noise: writeback disallowed and confidence below review threshold.',
            'require_token' => true,
            'require_tree_scope' => true,
            'policy_notes' => [
                'ownership' => 'Genealogy weak source_add review cleanup is table-owned here; command code fails closed if this policy is missing or invalid.',
                'safety' => 'Only review queue rows are ignored. Canonical person, source, citation, relationship, media, and face identity rows are not changed.',
                'selection' => 'Targets pending genealogy_finding rows in a single tree whose first proposal is source_add, whose actionability gate contains below_actionability_confidence, and whose DS governance writeback_allowed flag is false.',
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

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $defaultPolicy = $this->policy();
        $policy = array_replace($defaultPolicy, $existing);

        $existingNotes = $existing['policy_notes'] ?? null;
        $policy['policy_notes'] = is_array($existingNotes)
            ? array_replace($defaultPolicy['policy_notes'], $existingNotes)
            : $defaultPolicy['policy_notes'];

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'genealogy', 'config_key' => 'weak_source_add_cleanup_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for ignoring below-actionability Genea source_add review noise without canonical data writes.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $this->mergeScheduledJobNotes('genealogy_supervisor', [
            'training' => [
                'weak_source_add_cleanup_policy_source' => 'system_configs.genealogy.weak_source_add_cleanup_policy',
                'source_add_quality_rubric' => [
                    'same_name_only_source_hits_are_leads_not_facts',
                    'require_given_name_date_place_relative_fan_or_source_chain_identity_fit_before_operator_review',
                    'prefer_source_backed_review_packets_over_raw_source_add_rows',
                    'preserve_operator_actionable_rows_and_all_canonical_write_gates',
                    'treat_ignored_below_actionability_rows_as_negative_examples_for_future_source_search',
                ],
            ],
        ]);

        $this->mergeScheduledJobNotes('genealogy_agent_research_queue', [
            'training' => [
                'weak_source_add_cleanup_policy_source' => 'system_configs.genealogy.weak_source_add_cleanup_policy',
                'source_add_negative_examples' => [
                    'do_not_queue_same_name_only_nara_or_source_search_hits_without_identity_bridge',
                    'summarize weak hits in task history instead of creating operator review rows',
                    'escalate only source_add proposals that meet review_type_registry actionability confidence and identity-fit policy',
                ],
            ],
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', 'genealogy')
            ->where('config_key', 'weak_source_add_cleanup_policy')
            ->delete();
    }

    /**
     * @param  array<string,mixed>  $patch
     */
    private function mergeScheduledJobNotes(string $name, array $patch): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $row = DB::table('scheduled_jobs')
            ->where('name', $name)
            ->first(['notes']);

        if (! $row) {
            return;
        }

        $current = [];
        if (is_string($row->notes ?? null) && trim((string) $row->notes) !== '') {
            $decoded = json_decode((string) $row->notes, true);
            if (is_array($decoded)) {
                $current = $decoded;
            }
        }

        DB::table('scheduled_jobs')
            ->where('name', $name)
            ->update([
                'notes' => json_encode($this->recursiveMerge($current, $patch), JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $patch
     * @return array<string,mixed>
     */
    private function recursiveMerge(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (is_array($value) && array_is_list($value)) {
                $base[$key] = $value;

                continue;
            }

            if (is_array($value) && is_array($base[$key] ?? null)) {
                $base[$key] = $this->recursiveMerge($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
};
