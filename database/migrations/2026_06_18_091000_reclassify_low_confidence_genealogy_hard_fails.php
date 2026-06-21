<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_review_queue') || ! Schema::hasTable('review_type_registry')) {
            return;
        }

        $policy = DB::table('review_type_registry')
            ->where('name', 'genealogy_finding')
            ->where('enabled', 1)
            ->first(['actionability_min_confidence', 'actionability_pushover_allowed']);

        $minConfidence = $policy?->actionability_min_confidence !== null
            ? (float) $policy->actionability_min_confidence
            : null;
        if ($minConfidence === null) {
            return;
        }

        $rows = DB::table('agent_review_queue')
            ->select(['id', 'details'])
            ->where('status', 'pending')
            ->where('review_type', 'genealogy_finding')
            ->where('confidence', '<', $minConfidence)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $details = json_decode((string) ($row->details ?? ''), true);
            if (! is_array($details)) {
                continue;
            }

            $gate = is_array($details['actionability_gate'] ?? null) ? $details['actionability_gate'] : [];
            $reasons = is_array($gate['reasons'] ?? null) ? $gate['reasons'] : [];
            if (! in_array('quality_gate_hard_fail', $reasons, true)) {
                continue;
            }

            $reasons[] = 'below_actionability_confidence';
            $gate['state'] = 'review_ready';
            $gate['reasons'] = array_values(array_unique(array_filter($reasons, 'is_string')));
            $gate['summary'] = 'Quality gate hard failure retained for review, but confidence is below the review-type operator-action threshold.';
            $gate['review_hub'] = true;
            $gate['pushover_allowed'] = false;
            $gate['min_confidence'] = $minConfidence;
            $gate['policy_source'] = 'review_type_registry';
            $gate['policy_review_type'] = 'genealogy_finding';
            $details['actionability_gate'] = $gate;

            DB::table('agent_review_queue')
                ->where('id', $row->id)
                ->update([
                    'details' => json_encode($details, JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('agent_review_queue')) {
            return;
        }

        $rows = DB::table('agent_review_queue')
            ->select(['id', 'details'])
            ->where('status', 'pending')
            ->where('review_type', 'genealogy_finding')
            ->where('confidence', '<', 0.8)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $details = json_decode((string) ($row->details ?? ''), true);
            if (! is_array($details) || ! is_array($details['actionability_gate'] ?? null)) {
                continue;
            }

            $gate = $details['actionability_gate'];
            if (($gate['policy_source'] ?? null) !== 'review_type_registry'
                || ! in_array('below_actionability_confidence', $gate['reasons'] ?? [], true)) {
                continue;
            }

            $gate['state'] = 'operator_actionable';
            $gate['reasons'] = array_values(array_filter(
                $gate['reasons'],
                static fn (mixed $reason): bool => $reason !== 'below_actionability_confidence'
            ));
            $gate['summary'] = 'Quality gate hard failures require operator attention.';
            $gate['pushover_allowed'] = true;
            unset($gate['min_confidence'], $gate['policy_source'], $gate['policy_review_type']);
            $details['actionability_gate'] = $gate;

            DB::table('agent_review_queue')
                ->where('id', $row->id)
                ->update([
                    'details' => json_encode($details, JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
        }
    }
};
