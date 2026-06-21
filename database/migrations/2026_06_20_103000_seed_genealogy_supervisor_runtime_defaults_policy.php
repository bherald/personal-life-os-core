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
            'queue_low' => 8,
            'media_rag_limit' => 20,
            'media_rag_seconds' => 45,
            'policy_notes' => [
                'ownership' => 'Fallback Genea supervisor runtime defaults are table-owned here and overridden by scheduled_jobs.genealogy_supervisor.notes.training.',
                'queue_low' => 'Pending genealogy_research_queue threshold that allows the supervisor to refill assessment work when scheduled job notes omit queue_low.',
                'media_rag_limit' => 'Bounded genealogy media RAG records per supervisor pass when scheduled job notes omit media_rag_limit.',
                'media_rag_seconds' => 'Wall-clock budget for the bounded media RAG batch when scheduled job notes omit media_rag_seconds.',
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
            ->where('config_key', 'supervisor_runtime_defaults')
            ->first(['config_value']);

        $existing = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $policy = array_replace_recursive($this->policy(), $existing);

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'genealogy', 'config_key' => 'supervisor_runtime_defaults'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Fallback runtime defaults for GenealogySupervisorCommand; scheduled_jobs notes remain the primary policy owner.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'genealogy')
            ->where('config_key', 'supervisor_runtime_defaults')
            ->first(['config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            return;
        }

        foreach (['queue_low', 'media_rag_limit', 'media_rag_seconds'] as $key) {
            unset($policy[$key]);
        }

        if (is_array($policy['policy_notes'] ?? null)) {
            foreach (['ownership', 'queue_low', 'media_rag_limit', 'media_rag_seconds'] as $key) {
                unset($policy['policy_notes'][$key]);
            }
        }

        if ($policy === [] || $policy === ['policy_notes' => []]) {
            DB::table('system_configs')
                ->where('section', 'genealogy')
                ->where('config_key', 'supervisor_runtime_defaults')
                ->delete();

            return;
        }

        DB::table('system_configs')
            ->where('section', 'genealogy')
            ->where('config_key', 'supervisor_runtime_defaults')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
