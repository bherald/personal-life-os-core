<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateGenealogyYieldPolicy(true);
        $this->updateMemoryBackfillPolicy(true);
        Cache::forget('operator_evidence:agent_doctor:v1');
    }

    public function down(): void
    {
        $this->updateGenealogyYieldPolicy(false);
        $this->updateMemoryBackfillPolicy(false);
        Cache::forget('operator_evidence:agent_doctor:v1');
    }

    private function updateGenealogyYieldPolicy(bool $corrected): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $row = DB::table('scheduled_jobs')
            ->where('name', 'genealogy_agent_assess')
            ->first(['notes']);
        $notes = $row && is_string($row->notes ?? null)
            ? json_decode((string) $row->notes, true)
            : null;
        if (! is_array($notes)) {
            return;
        }

        $notes['runtime'] = is_array($notes['runtime'] ?? null) ? $notes['runtime'] : [];
        $notes['training'] = is_array($notes['training'] ?? null) ? $notes['training'] : [];

        if ($corrected) {
            unset($notes['runtime']['research_yield_guard_max_auto_reject_rate']);
            $notes['runtime']['research_yield_guard_max_non_useful_rate'] = 0.8;
            $notes['training']['research_yield_guard_policy'] = 'Pause new assessment/refill when the table-owned review window has enough terminal decisions and rejected, ignored, dismissed, or expired outcomes dominate accepted/resolved findings. Pending and preview-only rows stay visible but do not count as useful or non-useful terminal decisions.';
        } else {
            unset(
                $notes['runtime']['research_yield_guard_max_non_useful_rate']
            );
            $notes['runtime']['research_yield_guard_max_auto_reject_rate'] = 0.8;
            $notes['training']['research_yield_guard_policy'] = 'Pause new assessment/refill when the table-owned review window has enough samples and broad low-quality source-search hits dominate submissions. Existing queue history is preserved; operator review and face identity decisions remain unchanged.';
        }

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_agent_assess')
            ->update([
                'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function updateMemoryBackfillPolicy(bool $corrected): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $row = DB::table('scheduled_jobs')
            ->where('name', 'episodic_memory_backfill_recent')
            ->first(['notes']);
        $notes = $row && is_string($row->notes ?? null)
            ? json_decode((string) $row->notes, true)
            : [];
        if (! is_array($notes)) {
            $notes = [];
        }

        $notes['runtime'] = is_array($notes['runtime'] ?? null) ? $notes['runtime'] : [];
        if ($corrected) {
            $notes['runtime']['selection_policy'] = 'oldest_undistilled_first';
            $notes['runtime']['raw_episode_retention'] = 'preserved';
        } else {
            unset(
                $notes['runtime']['selection_policy'],
                $notes['runtime']['raw_episode_retention']
            );
            if ($notes['runtime'] === []) {
                unset($notes['runtime']);
            }
        }

        DB::table('scheduled_jobs')
            ->where('name', 'episodic_memory_backfill_recent')
            ->update([
                'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
