<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_agent_research_queue')
            ->update([
                'max_parallel' => 4,
                'updated_at' => now(),
            ]);
        $this->mergeScheduledJobNotes('genealogy_agent_research_queue', [
            'runtime' => [
                'hard_cap' => 4,
                'resource_profile' => 'genealogy_llm',
                'backlog_metric' => 'genealogy_queue',
            ],
            'training' => [
                'version' => 1,
                'growth_policy' => 'max_parallel and runtime.hard_cap are table-owned; code should only resolve live capacity and safety gates.',
            ],
        ]);

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_supervisor')
            ->update([
                'command' => 'genealogy:supervisor --tree=4 --json --compact',
                'updated_at' => now(),
            ]);
        $this->mergeScheduledJobNotes('genealogy_supervisor', [
            'runtime' => [
                'runtime_mode' => 'supervisor',
                'workload_family' => 'genealogy',
                'resource_profile' => 'db',
                'backlog_metric' => 'genealogy_queue',
                'report_category' => 'Genealogy',
            ],
            'supervises' => [
                'genealogy_agent_assess',
                'genealogy_agent_research_queue',
                'genealogy:media-rag-index',
            ],
            'training' => [
                'version' => 1,
                'uses_private_llm_capacity' => true,
                'review_soft_cap' => 50,
                'weak_source_add_policy' => 'throttle broad worker fan-out when below-threshold writeback-disallowed source_add findings saturate review.',
                'growth_policy' => 'derive worker cap from genealogy_agent_research_queue.max_parallel and runtime.hard_cap; use healthy private text LLM capacity only when review quality is not saturated.',
                'quality_notes' => 'More GPU can raise throughput through table values, but weak review noise must throttle worker fan-out.',
                'finding_min_confidence_source' => 'review_type_registry.actionability_min_confidence',
            ],
            'safety' => [
                'canonical_writes' => false,
                'face_identity_automation' => false,
                'operator_review_required' => true,
            ],
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_agent_research_queue')
            ->update([
                'max_parallel' => 3,
                'updated_at' => now(),
            ]);

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_supervisor')
            ->update([
                'command' => 'genealogy:supervisor --tree=4 --json --compact',
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string,mixed>  $patch
     */
    private function mergeScheduledJobNotes(string $name, array $patch): void
    {
        $row = DB::table('scheduled_jobs')
            ->where('name', $name)
            ->first(['notes']);

        $current = [];
        if ($row && is_string($row->notes ?? null) && trim((string) $row->notes) !== '') {
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
