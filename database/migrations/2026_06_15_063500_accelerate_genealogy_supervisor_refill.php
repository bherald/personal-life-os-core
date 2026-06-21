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
            ->where('name', 'genealogy_supervisor')
            ->update([
                'command' => 'genealogy:supervisor --tree=4 --queue-low=8 --json --compact',
                'cron_expression' => '*/5 * * * *',
                'timeout_minutes' => 30,
                'updated_at' => now(),
            ]);
        $this->mergeScheduledJobNotes('genealogy_supervisor', [
            'training' => [
                'queue_low' => 8,
                'refill_cadence' => '*/5 * * * *',
                'growth_policy' => 'RTC 2026-06-15: keep active FT4 queue refilled more often through genealogy_supervisor, while broad writes and face/photo identity remain operator-gated.',
            ],
        ]);

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_agent_assess')
            ->update([
                'timeout_minutes' => 60,
                'updated_at' => now(),
            ]);
        $this->mergeScheduledJobNotes('genealogy_agent_assess', [
            'runtime' => [
                'runtime_mode' => 'direct_assess',
                'resource_profile' => 'default',
                'backlog_metric' => 'genealogy_queue',
                'report_category' => 'Genealogy',
                'assess_limit' => 50,
            ],
            'training' => [
                'version' => 1,
                'refill_policy' => 'Use runtime.assess_limit to keep enough source-backed FT4 candidates queued for the active genealogy_agent_research_queue workers.',
            ],
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_supervisor')
            ->update([
                'command' => 'genealogy:supervisor --tree=4 --json --compact',
                'cron_expression' => '*/15 * * * *',
                'updated_at' => now(),
            ]);

        $this->mergeScheduledJobNotes('genealogy_agent_assess', [
            'runtime' => [
                'assess_limit' => 20,
            ],
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
