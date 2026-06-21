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
            ->where('name', 'face_person_source_provenance_audit')
            ->update([
                'cron_expression' => '20 * * * *',
                'timeout_minutes' => 10,
                'updated_at' => now(),
            ]);

        $this->mergeScheduledJobNotes('face_person_source_provenance_audit', [
            'training' => [
                'priority' => 'operator_priority',
                'cadence' => 'hourly',
                'reason' => 'Face/photo provenance drift is the active operator-priority lane; keep all-person review rollups fresh while remaining observe-only.',
                'safety' => [
                    'read_only' => true,
                    'identity_writeback' => false,
                    'face_sync_recluster' => false,
                ],
            ],
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'face_person_source_provenance_audit')
            ->update([
                'cron_expression' => '20 */6 * * *',
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
            } else {
                $current = ['summary' => (string) $row->notes];
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
