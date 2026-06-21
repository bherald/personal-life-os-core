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
            'default_limit' => 5,
            'policy_notes' => [
                'default_limit' => 'Default RAGSearch result limit when workflow node config does not provide limit.',
                'overrides' => 'Workflow node config remains the per-run override for limit and document_type.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'rag')
            ->where('config_key', 'search_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $policy = array_replace_recursive($this->policy(), $existing);

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'rag', 'config_key' => 'search_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for RAGSearch semantic result limits.',
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
            ->where('section', 'rag')
            ->where('config_key', 'search_runtime_policy')
            ->delete();
    }
};
