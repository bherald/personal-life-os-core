<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AUDIT_KEY = 'runtime_policy_leak_audit';

    private const OVERRIDES = [
        '981687c8d0cb52a4b2ae35615844423125c3f25a0a14c90b8003faa8ca55becc' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'Query decomposition requests the semantic fast role; AIService resolves the provider and concrete model from llm_instances role maps and route policy.',
        ],
        'd402d90b1c525c007daae0ec026b7a7837b83655f9d8cfbc81b97cb842377de7' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'RAPTOR summarization requests the semantic quality role; AIService resolves the provider and concrete model from llm_instances role maps and route policy.',
        ],
        'fc38d264555917a007a0861015394bf4cc0e03de23c44ee476afaa6f1c1992d7' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'Invoice classification requests the semantic fast role; AIService resolves the provider and concrete model from llm_instances role maps and route policy.',
        ],
        'dc1523a1be1a6439b16cf6d142ce2bdc0dcc3eefb0a5f1cf5b7aa8d98db65a6a' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'HyPE question generation requests the semantic fast role; AIService resolves the provider and concrete model from llm_instances role maps and route policy.',
        ],
        'e3b319495b0fd2a71ee3477835d81ef0554ab992a7dcab4524a5d51d816a11a6' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'Knowledge-graph fact verification requests the semantic fast role; AIService resolves the provider and concrete model from llm_instances role maps and route policy.',
        ],
        '59e586985496e9143a3218c0bd0f0d19a9ee3d2ff4af6e9524bc412c59ba886b' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'Multi-hop verification requests the semantic fast role; AIService resolves the provider and concrete model from llm_instances role maps and route policy.',
        ],
        'ac28f4b46309afa77e3d34abf93592207a28881cb5f6cd7d14ddd2f2768d2f46' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'Debate synthesis requests the semantic quality role; AIService resolves the provider and concrete model from llm_instances role maps and route policy.',
        ],
        'a9c40320f923f860b23f5638a54d2c7ef419f1eb3cae3565c061b6cf67b7d870' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'Semantic proposition extraction requests the semantic fast role through AIService process; the provider and concrete model come from llm_instances role maps and route policy.',
        ],
        'daeaa467a66109c73aee14dd2a6cd2e377db8dc79e1f7aae969ae3c9f8434bee' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'Contextual retrieval requests the semantic fast role; AIService resolves the provider and concrete model from llm_instances role maps and route policy.',
        ],
        'ae165bd5a9a7baa4b5f95a54b5792ac79e730940613b72213c96d5a0000e0a16' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'Iterative retrieval requests the semantic fast role; AIService resolves the provider and concrete model from llm_instances role maps and route policy.',
        ],
        'f697376393061dc6a2c16e10345010891aa90ff10beafec72573ab78eb1846db' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'Knowledge-graph extraction passes a semantic standard or scheduled role; AIService resolves its concrete model from llm_instances role maps and route policy.',
        ],
        'a1c1f8be0b9f837bfa4b5888f3466dc5c4a51c91ca754b5d94f0e850b251550b' => [
            'classification' => 'runtime_value_reference',
            'reason' => 'Multi-persona critique requests the semantic fast role; AIService resolves the provider and concrete model from llm_instances role maps and route policy.',
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
