<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AUDIT_KEY = 'runtime_policy_leak_audit';

    private const OVERRIDES = [
        '2c415301936298651662f44e597f22416ef4405bf7907d129e7884fe9be5c738' => [
            'classification' => 'intentional_safety_rail',
            'reason' => 'Manual direct-task batch safety default; repository search and production scheduled_jobs contain no caller, the limit is overrideable and capped at 10, and execution requires explicit confirmations.',
        ],
        '29ae51c9cea5f3e375aa8789e006ef62b57e2b6a43b5af7885feda56b3cdd967' => [
            'classification' => 'intentional_safety_rail',
            'reason' => 'Manual per-task timeout safety default; repository search and production scheduled_jobs contain no caller, the value is overrideable and bounded to 1..60 minutes, and execution requires explicit confirmations.',
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
