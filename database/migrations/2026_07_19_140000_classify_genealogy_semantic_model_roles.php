<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AUDIT_KEY = 'runtime_policy_leak_audit';

    private const OVERRIDES = [
        '40179629ec065508344719990053a1f01a5f61ec4d70001d90d9a260b943f9d4' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'Genealogy media enrichment requests the semantic quality role; AIService resolves the provider and concrete model from llm_instances role maps and route policy.',
        ],
        '7c832138bb92cd4428b8cc56aed3f3ce5f0c9c752a44d6f806730fdc78823f13' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'GPS proof generation requests the semantic quality role; AIService resolves the provider and concrete model from llm_instances role maps and route policy.',
        ],
        'b234083e5365d44c713b10f04ad68ff736da6f9ea1b4a69cb176d79ff77e8fb3' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'Local match triage requests the semantic standard role; AIService resolves the provider and concrete model from llm_instances role maps and route policy.',
        ],
        'e3ffc2d4aa7264c1f606e9bd764768a65ca2572857b1d63c01852fe839e0d708' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'Local review cleanup requests the semantic fast role; AIService resolves the provider and concrete model from llm_instances role maps and route policy.',
        ],
        '0614a8c7fe6054a6c2c10483f09d2e9fbe445c03acb0fcb847a3d56fbd4a49b1' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'Local document extraction requests the semantic fast role; AIService resolves the provider and concrete model from llm_instances role maps and route policy.',
        ],
        '2f3f29d89af04469e7f1e3826fb6b47bbbb2d40dd04a5ce1d58741aa5e3679c4' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'Packet synthesis requests the semantic quality role; AIService resolves the provider and concrete model from llm_instances role maps and route policy.',
        ],
        '199dc24903603dc35198e8803d57e793ecdf4ece6436f97a6f4605546d77fa92' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'Local source-quality assessment requests the semantic standard role; AIService resolves the provider and concrete model from llm_instances role maps and route policy.',
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
            } elseif (($overrides[$fingerprint] ?? null) === $override) {
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
