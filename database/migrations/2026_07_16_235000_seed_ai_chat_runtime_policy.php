<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHAT_SECTION = 'ai_chat';

    private const CHAT_KEY = 'runtime_policy';

    private const CHAT_DESCRIPTION = 'Table-owned direct Ollama streaming and RAG fallback policy for AI chat.';

    private const AUDIT_PLACEHOLDER_FINGERPRINT = 'e980d987bd40c0f3446dda147806ed0fe5ba898e1abe1c1576b1b2614d781353';

    private const AUDIT_PLACEHOLDER_REASON = 'Nullable timeout_minutes is an empty Agent Doctor response-shape placeholder, not a runtime timeout definition.';

    /** @return array<string,mixed> */
    private function chatPolicy(): array
    {
        return [
            'schema' => 'plos.ai_chat_runtime_policy.v1',
            'direct_ollama_stream' => [
                'idle_timeout_seconds' => 600,
                'absolute_timeout_seconds' => 0,
                'connect_timeout_seconds' => 5,
                'progress_log_interval_seconds' => 30,
                'num_predict' => 2000,
                'temperature' => 0.8,
            ],
            'rag' => [
                'fallback_cosine_threshold' => 0.60,
            ],
            'policy_notes' => [
                'direct_ollama_stream' => 'No transport wall clock; stop only after bounded inactivity or an optional operator absolute limit.',
                'rag' => 'Minimum cosine relevance required before chat treats RAG results as answer context instead of using web fallback.',
                'failure_behavior' => 'Missing, malformed, or out-of-range policy stops the affected chat path before model dispatch.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $exists = DB::table('system_configs')
            ->where('section', self::CHAT_SECTION)
            ->where('config_key', self::CHAT_KEY)
            ->exists();
        if (! $exists) {
            DB::table('system_configs')->insert([
                'section' => self::CHAT_SECTION,
                'config_key' => self::CHAT_KEY,
                'config_value' => $this->encodedChatPolicy(),
                'data_type' => 'json',
                'description' => self::CHAT_DESCRIPTION,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->updateAuditOverride(true);
        Cache::forget('syscfg:'.self::CHAT_SECTION.'.'.self::CHAT_KEY);
        Cache::forget('syscfg:ops.runtime_policy_leak_audit');
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', self::CHAT_SECTION)
            ->where('config_key', self::CHAT_KEY)
            ->where('config_value', $this->encodedChatPolicy())
            ->where('description', self::CHAT_DESCRIPTION)
            ->delete();
        $this->updateAuditOverride(false);
        Cache::forget('syscfg:'.self::CHAT_SECTION.'.'.self::CHAT_KEY);
        Cache::forget('syscfg:ops.runtime_policy_leak_audit');
    }

    private function updateAuditOverride(bool $add): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->first(['config_value']);
        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy) || ! is_array($policy['classifier'] ?? null)) {
            return;
        }

        $overrides = is_array($policy['classifier']['finding_overrides'] ?? null)
            ? $policy['classifier']['finding_overrides']
            : [];
        if ($add) {
            $overrides[self::AUDIT_PLACEHOLDER_FINGERPRINT] = [
                'classification' => 'runtime_value_reference',
                'reason' => self::AUDIT_PLACEHOLDER_REASON,
            ];
        } elseif (($overrides[self::AUDIT_PLACEHOLDER_FINGERPRINT]['reason'] ?? null) === self::AUDIT_PLACEHOLDER_REASON) {
            unset($overrides[self::AUDIT_PLACEHOLDER_FINGERPRINT]);
        }
        $policy['classifier']['finding_overrides'] = $overrides;

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    private function encodedChatPolicy(): string
    {
        return json_encode($this->chatPolicy(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
};
