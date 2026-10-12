<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AUDIT_KEY = 'runtime_policy_leak_audit';

    private const OVERRIDES = [
        '81f46b462669632580bd81742940aa04a55b60d13127aeb5462815b472110f6d' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'Manual-only find-similar default; every invocation can supply --threshold and no scheduled video:hash job owns it.',
        ],
        'ed8c9845a4306494c7871647360521124fbafc3c7c483ec8ed906c655316362e' => [
            'classification' => 'intentional_safety_rail',
            'reason' => 'Manual-only batch safety default; every invocation can supply --limit and no scheduled video:hash job owns it.',
        ],
    ];

    public function up(): void
    {
        $this->updateOverrides(true);
    }

    public function down(): void
    {
        $this->updateOverrides(false);
    }

    private function updateOverrides(bool $add): void
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
        foreach (self::OVERRIDES as $fingerprint => $override) {
            if ($add) {
                $overrides[$fingerprint] = $override;

                continue;
            }

            if (($overrides[$fingerprint] ?? null) === $override) {
                unset($overrides[$fingerprint]);
            }
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
