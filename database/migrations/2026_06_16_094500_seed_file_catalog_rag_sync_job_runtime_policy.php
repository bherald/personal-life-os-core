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
            'timeout_seconds' => 3600,
            'tries' => 1,
            'policy_notes' => [
                'timeout_seconds' => 'Horizon timeout for one queued file catalog RAG sync job.',
                'tries' => 'Retry attempts for queued file catalog RAG sync.',
                'queue_owner' => 'Queue placement remains explicit in the job constructor as long-running.',
                'limit_owner' => 'The per-run sync limit remains owned by the dispatching API/command/scheduled row.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'files')
            ->where('config_key', 'file_catalog_rag_sync_job_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $policy = array_replace_recursive($this->policy(), $existing);

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'files', 'config_key' => 'file_catalog_rag_sync_job_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for queued file catalog RAG sync jobs.',
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
            ->where('section', 'files')
            ->where('config_key', 'file_catalog_rag_sync_job_runtime_policy')
            ->delete();
    }
};
