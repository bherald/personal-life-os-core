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
            'max_retries_per_chunk' => 2,
            'retry_delay_seconds' => 1,
            'inter_chunk_delay_seconds' => 2,
            'timeout_seconds' => 300,
            'multipart_send_budget_seconds' => 2,
            'multipart_delivery_safety_margin_seconds' => 5,
            'emergency_retry_seconds' => 60,
            'emergency_expire_seconds' => 3600,
            'policy_notes' => [
                'max_retries_per_chunk' => 'Default retries for each PushoverNotify multipart chunk when workflow node config does not provide max_retries_per_chunk.',
                'retry_delay_seconds' => 'Default delay between retries for a failed PushoverNotify chunk.',
                'inter_chunk_delay_seconds' => 'Default delay between multipart PushoverNotify sends.',
                'timeout_seconds' => 'Default node timeout used by PushoverNotify timeout propagation when workflow node config does not provide timeout_seconds.',
                'multipart_send_budget_seconds' => 'Per-part send budget used before multipart delivery starts.',
                'multipart_delivery_safety_margin_seconds' => 'Safety margin retained before multipart delivery starts.',
                'emergency_retry_seconds' => 'Default emergency-priority retry value when workflow node config does not provide retry.',
                'emergency_expire_seconds' => 'Default emergency-priority expire value when workflow node config does not provide expire.',
                'ownership' => 'PushoverNotify workflow-node delivery defaults are table-owned here; explicit workflow node config remains the per-run override.',
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
            ->where('config_key', 'pushover_notify_runtime_policy')
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
            ['section' => 'notifications', 'config_key' => 'pushover_notify_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for PushoverNotify workflow-node delivery defaults.',
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
            ->where('config_key', 'pushover_notify_runtime_policy')
            ->delete();
    }
};
