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
            'timeout_seconds' => 1800,
            'tries' => 1,
            'queue' => 'long-running',
            'policy_notes' => [
                'timeout_seconds' => 'Queue timeout for ExecuteEmailSuggestionScan jobs.',
                'tries' => 'Queue retry attempts for ExecuteEmailSuggestionScan jobs.',
                'queue' => 'Queue name used when dispatching ExecuteEmailSuggestionScan jobs.',
                'ownership' => 'ExecuteEmailSuggestionScan queue runtime defaults are table-owned here.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'email')
            ->where('config_key', 'suggestion_scan_job_runtime_policy')
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
            ['section' => 'email', 'config_key' => 'suggestion_scan_job_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for ExecuteEmailSuggestionScan queue jobs.',
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
            ->where('section', 'email')
            ->where('config_key', 'suggestion_scan_job_runtime_policy')
            ->delete();
    }
};
