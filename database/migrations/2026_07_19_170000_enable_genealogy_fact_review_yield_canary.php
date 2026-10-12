<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB = 'genealogy_agent_research_queue';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', self::JOB)
            ->update([
                'max_parallel' => 1,
                'updated_at' => now(),
            ]);

        $this->mergeNotes([
            'runtime' => [
                'quality_analysis_enabled' => true,
                'quality_analysis_model_role' => 'standard',
                'quality_analysis_canary_state' => 'active',
                'quality_analysis_canary_max_parallel' => 1,
                'proposal_quality_policy' => 'proof_grade_ft4_v1',
            ],
            'training' => [
                'version' => 3,
                'fact_review_yield_policy' => 'Scheduled queue research uses one admitted standard-role model lane to convert record evidence into proof-grade FT4 fact or source proposals. Search telemetry alone remains deferred.',
                'fact_review_required_evidence' => [
                    'stable_record_locator',
                    'record_level_citation',
                    'record_title',
                    'record_text_naming_target',
                    'name_identity_anchor',
                    'corroborating_identity_anchor',
                    'live_current_value_comparison',
                    'actionability_confidence',
                ],
                'canary_exit' => 'Review one heterogeneous production cohort for supported proposal yield, duplicate rate, unsupported-claim rate, latency, and provider routing before raising max_parallel.',
            ],
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $row = DB::table('scheduled_jobs')
            ->where('name', self::JOB)
            ->first(['max_parallel', 'notes']);
        if ($row === null) {
            return;
        }

        $notes = $this->decodeNotes($row->notes ?? null);
        $canaryOwnsParallelism = (int) ($row->max_parallel ?? 0) === 1
            && ($notes['runtime']['quality_analysis_canary_state'] ?? null) === 'active'
            && ($notes['runtime']['quality_analysis_canary_max_parallel'] ?? null) === 1
            && ($notes['runtime']['proposal_quality_policy'] ?? null) === 'proof_grade_ft4_v1';
        foreach ([
            'quality_analysis_enabled',
            'quality_analysis_model_role',
            'quality_analysis_canary_state',
            'quality_analysis_canary_max_parallel',
            'proposal_quality_policy',
        ] as $key) {
            unset($notes['runtime'][$key]);
        }
        foreach (['fact_review_yield_policy', 'fact_review_required_evidence', 'canary_exit'] as $key) {
            unset($notes['training'][$key]);
        }
        if (($notes['training']['version'] ?? null) === 3) {
            $notes['training']['version'] = 2;
        }

        DB::table('scheduled_jobs')
            ->where('name', self::JOB)
            ->update([
                'max_parallel' => $canaryOwnsParallelism ? 6 : (int) $row->max_parallel,
                'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    /** @param array<string, mixed> $patch */
    private function mergeNotes(array $patch): void
    {
        $row = DB::table('scheduled_jobs')
            ->where('name', self::JOB)
            ->first(['notes']);
        if ($row === null) {
            return;
        }

        $notes = $this->recursiveMerge($this->decodeNotes($row->notes ?? null), $patch);
        DB::table('scheduled_jobs')
            ->where('name', self::JOB)
            ->update([
                'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    /** @return array<string, mixed> */
    private function decodeNotes(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
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
