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
            'connection_error_patterns' => [
                'cURL error 7',
                'cURL error 28',
                'cURL error 6',
                'Connection refused',
                'Failed to connect',
                'Could not resolve host',
                'Network is unreachable',
                'Connection reset',
                'Connection timed out',
                'Operation timed out',
                'SSL connection timeout',
                'ETIMEDOUT',
                'ECONNREFUSED',
                'ENOTFOUND',
                'ECONNRESET',
            ],
            'fallback_directory_mode' => '0755',
            'notification_file_preview_limit' => 10,
            'notification_filename_max_chars' => 50,
            'policy_notes' => [
                'connection_error_patterns' => 'Error fragments that switch workflow-node storage into local fallback mode.',
                'fallback_directory_mode' => 'Octal directory mode used when creating storage/app/fallback-* directories.',
                'notification_file_preview_limit' => 'Maximum fallback filenames listed in one notification message.',
                'notification_filename_max_chars' => 'Maximum characters shown for each fallback filename in one notification message.',
                'ownership' => 'Workflow fallback storage runtime defaults are table-owned here.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'workflow')
            ->where('config_key', 'fallback_storage_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $defaultPolicy = $this->policy();
        $policy = array_replace($defaultPolicy, $existing);
        if (array_key_exists('connection_error_patterns', $existing)) {
            $policy['connection_error_patterns'] = $existing['connection_error_patterns'];
        }

        $existingNotes = $existing['policy_notes'] ?? null;
        $policy['policy_notes'] = is_array($existingNotes)
            ? array_replace($defaultPolicy['policy_notes'], $existingNotes)
            : $defaultPolicy['policy_notes'];

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'workflow', 'config_key' => 'fallback_storage_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for workflow-node fallback storage behavior and notifications.',
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
            ->where('section', 'workflow')
            ->where('config_key', 'fallback_storage_runtime_policy')
            ->delete();
    }
};
