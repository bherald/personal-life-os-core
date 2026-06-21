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
            'tree_search_max_results_per_type' => 100,
            'person_search_max_results' => 100,
            'person_profile_max_related_rows' => 100,
            'family_profile_max_related_rows' => 100,
            'tree_status_max_tree_rows' => 25,
            'research_task_queue_max_results' => 200,
            'media_rag_batch_max_results' => 100,
            'media_rag_batch_max_seconds' => 120,
            'rag_index_batch_max_results' => 100,
            'person_embedding_batch_max_results' => 500,
            'media_htr_batch_max_results' => 20,
            'policy_notes' => [
                'tree_search_max_results_per_type' => 'Maximum rows returned for each tree_search result type.',
                'person_search_max_results' => 'Maximum rows returned by person_search.',
                'person_profile_max_related_rows' => 'Maximum related rows per section in person_profile.',
                'family_profile_max_related_rows' => 'Maximum related rows per section in family_profile.',
                'tree_status_max_tree_rows' => 'Maximum row budget passed into each tree_status sub-audit.',
                'research_task_queue_max_results' => 'Maximum rows returned by research_task_queue.',
                'media_rag_batch_max_results' => 'Maximum media rows a Genea MCP media_rag_batch call may request.',
                'media_rag_batch_max_seconds' => 'Maximum runtime budget a Genea MCP media_rag_batch call may request.',
                'rag_index_batch_max_results' => 'Maximum rows a Genea MCP rag_index_batch call may request.',
                'person_embedding_batch_max_results' => 'Maximum rows a Genea MCP person_embedding_batch call may request.',
                'media_htr_batch_max_results' => 'Maximum rows a Genea MCP media_htr_batch call may request.',
                'ownership' => 'GenealogyMCPService high-use search/profile/status/task/media batch bounds are table-owned here; code constants are fallback-only.',
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
            ->where('config_key', 'mcp_runtime_policy')
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
            ['section' => 'genealogy', 'config_key' => 'mcp_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for high-use Genealogy MCP search, profile, status, task, and media batch bounds.',
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

        DB::table('system_configs')
            ->where('section', 'genealogy')
            ->where('config_key', 'mcp_runtime_policy')
            ->delete();
    }
};
