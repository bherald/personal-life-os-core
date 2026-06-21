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
            'default_node_timeout_seconds' => 300,
            'fallback_retry_max_attempts' => 1,
            'fallback_retry_backoff_seconds' => [5],
            'event_payload_max_bytes' => 64000,
            'event_string_truncate_chars' => 1000,
            'failure_alert_cooldown_seconds' => 300,
            'failure_alert_suppressed_errors_limit' => 10,
            'failure_alert_suppressed_errors_ttl_extra_seconds' => 60,
            'failure_alert_summary_schedule_extra_seconds' => 30,
            'failure_alert_summary_delay_extra_seconds' => 10,
            'failure_alert_summary_error_limit' => 5,
            'policy_notes' => [
                'default_node_timeout_seconds' => 'Fallback node timeout when workflow or node config does not provide one.',
                'fallback_retry_max_attempts' => 'Retry count used when the workflow has no retry config row.',
                'fallback_retry_backoff_seconds' => 'Retry backoff sequence used when the workflow has no retry config row.',
                'event_payload_max_bytes' => 'Maximum JSON payload size before workflow event payload strings are truncated.',
                'event_string_truncate_chars' => 'Maximum string length retained when workflow event payload truncation is required.',
                'failure_alert_cooldown_seconds' => 'Cache cooldown used to suppress repeated workflow failure notifications.',
                'failure_alert_suppressed_errors_limit' => 'Maximum suppressed failure messages retained for summary notifications.',
                'failure_alert_suppressed_errors_ttl_extra_seconds' => 'Extra TTL margin for suppressed failure message cache entries.',
                'failure_alert_summary_schedule_extra_seconds' => 'Extra TTL margin for the summary-scheduled cache marker.',
                'failure_alert_summary_delay_extra_seconds' => 'Delay after cooldown before sending the suppressed-failure summary.',
                'failure_alert_summary_error_limit' => 'Maximum unique suppressed error messages included in a summary notification.',
                'ownership' => 'WorkflowEngine runtime defaults are table-owned here; workflow-specific retry and node timeout rows still take precedence.',
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
            ->where('config_key', 'engine_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $defaultPolicy = $this->policy();
        $policy = array_replace($defaultPolicy, $existing);

        $existingBackoff = $existing['fallback_retry_backoff_seconds'] ?? null;
        $policy['fallback_retry_backoff_seconds'] = is_array($existingBackoff)
            ? array_values($existingBackoff)
            : $defaultPolicy['fallback_retry_backoff_seconds'];

        $existingNotes = $existing['policy_notes'] ?? null;
        $policy['policy_notes'] = is_array($existingNotes)
            ? array_replace($defaultPolicy['policy_notes'], $existingNotes)
            : $defaultPolicy['policy_notes'];

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'workflow', 'config_key' => 'engine_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for WorkflowEngine node timeout, event truncation, retry fallback, and failure alert throttling.',
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
            ->where('config_key', 'engine_runtime_policy')
            ->delete();
    }
};
