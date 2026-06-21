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
            'emergency_retry_seconds' => 60,
            'emergency_expire_seconds' => 3600,
            'send_connect_timeout_seconds' => 5,
            'send_request_timeout_seconds' => 30,
            'receipt_connect_timeout_seconds' => 5,
            'receipt_request_timeout_seconds' => 10,
            'policy_notes' => [
                'emergency_retry_seconds' => 'Fallback retry interval for emergency-priority Pushover sends when caller data does not provide retry.',
                'emergency_expire_seconds' => 'Fallback expiry window for emergency-priority Pushover sends when caller data does not provide expire.',
                'send_connect_timeout_seconds' => 'Connect timeout for Pushover message send API calls.',
                'send_request_timeout_seconds' => 'Request timeout for Pushover message send API calls.',
                'receipt_connect_timeout_seconds' => 'Connect timeout for Pushover receipt status API calls.',
                'receipt_request_timeout_seconds' => 'Request timeout for Pushover receipt status API calls.',
                'ownership' => 'NotificationController Pushover delivery timing defaults are table-owned here; caller-provided retry/expire and PushoverRateLimitPolicy remain higher-priority inputs.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'notifications')
            ->where('config_key', 'pushover_runtime_policy')
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
            ['section' => 'notifications', 'config_key' => 'pushover_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for NotificationController Pushover send and receipt timing defaults.',
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
            ->where('section', 'notifications')
            ->where('config_key', 'pushover_runtime_policy')
            ->delete();
    }
};
