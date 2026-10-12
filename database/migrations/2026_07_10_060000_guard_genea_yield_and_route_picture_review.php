<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FACE_REVIEW_SERVICE = 'App\\Services\\Genealogy\\FaceMatchReviewDecisionService';

    public function up(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            $this->mergeScheduledJobNotes('genealogy_agent_assess', [
                'runtime' => [
                    'research_yield_guard_enabled' => true,
                    'research_yield_guard_window_hours' => 24,
                    'research_yield_guard_min_submissions' => 50,
                    'research_yield_guard_max_auto_reject_rate' => 0.8,
                    'research_yield_guard_agent_id' => 'genealogy-records',
                    'research_yield_guard_pause_assessment' => true,
                ],
                'training' => [
                    'research_yield_guard_policy' => 'Pause new assessment/refill when the table-owned review window has enough samples and broad low-quality source-search hits dominate submissions. Existing queue history is preserved; operator review and face identity decisions remain unchanged.',
                    'source_search_all_policy' => 'Use repository-specific searches first. source_search_all requires at least two identity anchors and a recent-search check; same-name-only hits are coverage evidence, not operator review findings.',
                ],
            ]);
        }

        if (Schema::hasTable('review_type_registry')) {
            DB::table('review_type_registry')
                ->where('name', 'face')
                ->update([
                    'label' => 'Picture Matches',
                    'service_class' => self::FACE_REVIEW_SERVICE,
                    'approve_method' => 'approve',
                    'reject_method' => 'reject',
                    'batch_enabled' => 0,
                    'updated_at' => now(),
                ]);
        }

        Cache::forget('review_type_registry');
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            $row = DB::table('scheduled_jobs')
                ->where('name', 'genealogy_agent_assess')
                ->first(['notes']);
            $notes = $row && is_string($row->notes ?? null)
                ? json_decode((string) $row->notes, true)
                : null;

            if (is_array($notes)) {
                foreach ([
                    'research_yield_guard_enabled',
                    'research_yield_guard_window_hours',
                    'research_yield_guard_min_submissions',
                    'research_yield_guard_max_auto_reject_rate',
                    'research_yield_guard_agent_id',
                    'research_yield_guard_pause_assessment',
                ] as $key) {
                    unset($notes['runtime'][$key]);
                }
                unset(
                    $notes['training']['research_yield_guard_policy'],
                    $notes['training']['source_search_all_policy']
                );

                if (($notes['runtime'] ?? null) === []) {
                    unset($notes['runtime']);
                }
                if (($notes['training'] ?? null) === []) {
                    unset($notes['training']);
                }

                DB::table('scheduled_jobs')
                    ->where('name', 'genealogy_agent_assess')
                    ->update([
                        'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                    ]);
            }
        }

        if (Schema::hasTable('review_type_registry')) {
            DB::table('review_type_registry')
                ->where('name', 'face')
                ->where('service_class', self::FACE_REVIEW_SERVICE)
                ->update([
                    'label' => 'Face Matches',
                    'service_class' => null,
                    'approve_method' => null,
                    'reject_method' => null,
                    'batch_enabled' => 1,
                    'updated_at' => now(),
                ]);
        }

        Cache::forget('review_type_registry');
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function mergeScheduledJobNotes(string $name, array $patch): void
    {
        $row = DB::table('scheduled_jobs')->where('name', $name)->first(['notes']);
        if (! $row) {
            return;
        }

        $current = is_string($row->notes ?? null)
            ? json_decode((string) $row->notes, true)
            : [];
        if (! is_array($current)) {
            $current = [];
        }

        DB::table('scheduled_jobs')
            ->where('name', $name)
            ->update([
                'notes' => json_encode($this->recursiveMerge($current, $patch), JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function recursiveMerge(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (is_array($value) && is_array($base[$key] ?? null)) {
                $base[$key] = $this->recursiveMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
};
