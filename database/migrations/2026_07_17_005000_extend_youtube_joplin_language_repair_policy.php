<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function policyExtension(): array
    {
        return [
            'joplin_language_repair_fetch_batch_size' => 50,
            'joplin_language_repair_formatter_batch_size' => 1,
            'joplin_language_repair_preview_limit' => 10,
            'policy_notes' => [
                'joplin_language_repair_fetch_batch_size' => 'Default Joplin file fetch batch for the manual transcript-language repair command.',
                'joplin_language_repair_formatter_batch_size' => 'Formatter pacing group for the manual repair loop; 1 preserves a policy-owned pause after each formatted note.',
                'joplin_language_repair_preview_limit' => 'Maximum candidate detail lines shown by a dry run; aggregate counts remain complete.',
                'joplin_language_repair_write_scope' => 'Write mode requires an explicit positive --limit or explicit --all; table policy never grants write scope.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'youtube')
            ->where('config_key', 'transcript_runtime_policy')
            ->first(['config_value']);
        $existing = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $policy = array_replace_recursive($this->policyExtension(), $existing);

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'youtube', 'config_key' => 'transcript_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for YouTube transcript fetch, formatting, language repair, retry, backoff, validation, and rate-limit timing.',
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
            ->where('section', 'youtube')
            ->where('config_key', 'transcript_runtime_policy')
            ->first(['config_value']);
        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            return;
        }

        foreach (array_keys($this->policyExtension()) as $key) {
            if ($key !== 'policy_notes') {
                unset($policy[$key]);
            }
        }
        if (is_array($policy['policy_notes'] ?? null)) {
            foreach (array_keys($this->policyExtension()['policy_notes']) as $key) {
                unset($policy['policy_notes'][$key]);
            }
        }

        DB::table('system_configs')
            ->where('section', 'youtube')
            ->where('config_key', 'transcript_runtime_policy')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
