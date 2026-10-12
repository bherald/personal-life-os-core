<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AUDIT_KEY = 'runtime_policy_leak_audit';

    private const FINGERPRINT = '9e767bf422e6e6f809df3250e5cdc0ce5551283ffb1d344bd6a7685eb03c4f13';

    private const OVERRIDE = [
        'classification' => 'intentional_safety_rail',
        'reason' => 'Read-only content-free contract-group reporting cap; aggregate coverage is limit-independent, the exact MCP caller uses compact mode, production has no scheduled caller or current contract rows, and the service independently bounds details to 1..100.',
    ];

    public function up(): void
    {
        $this->updateOverride(true);
    }

    public function down(): void
    {
        $this->updateOverride(false);
    }

    private function updateOverride(bool $add): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', self::AUDIT_KEY)
            ->first(['config_value']);
        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy) || ! is_array($policy['classifier'] ?? null)) {
            return;
        }

        $overrides = is_array($policy['classifier']['finding_overrides'] ?? null)
            ? $policy['classifier']['finding_overrides']
            : [];
        if ($add) {
            $overrides[self::FINGERPRINT] = self::OVERRIDE;
        } elseif (($overrides[self::FINGERPRINT] ?? null) === self::OVERRIDE) {
            unset($overrides[self::FINGERPRINT]);
        }
        $policy['classifier']['finding_overrides'] = $overrides;

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', self::AUDIT_KEY)
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        Cache::forget('syscfg:ops.'.self::AUDIT_KEY);
    }
};
