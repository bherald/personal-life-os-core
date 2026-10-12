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

        $this->mergeScheduledJobNotes('genealogy_agent_assess', [
            'runtime' => [
                'exhausted_fallback_max_data_gap_only_exhaustion_score' => 0.9,
            ],
            'training' => [
                'data_gap_only_exhaustion_policy' => 'When exhausted fallback is enabled, high data-gap candidates without pending hints must stay below this exhaustion score before being requeued. Pending-hint candidates may still pass through the separate pending-hint floor.',
            ],
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $row = DB::table('scheduled_jobs')
            ->where('name', 'genealogy_agent_assess')
            ->first(['notes']);

        if (! $row || ! is_string($row->notes ?? null)) {
            return;
        }

        $notes = json_decode((string) $row->notes, true);
        if (! is_array($notes)) {
            return;
        }

        unset($notes['runtime']['exhausted_fallback_max_data_gap_only_exhaustion_score']);
        unset($notes['training']['data_gap_only_exhaustion_policy']);

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
