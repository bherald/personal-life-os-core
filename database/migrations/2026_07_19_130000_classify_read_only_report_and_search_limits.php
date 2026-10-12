<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AUDIT_KEY = 'runtime_policy_leak_audit';

    private const OVERRIDES = [
        '7c53f24053e861969d06a818c8bcd856740eba367f8211ca46fb893172c427fe' => [
            'classification' => 'intentional_safety_rail',
            'reason' => 'Read-only genealogy sprint report row-inspection bound; it limits evidence displayed to the operator and does not schedule, mutate, or authorize genealogy work.',
        ],
        'cf31dbbaea0aab2975b3f3efa699fd5b4c5aec4621fcb23f3ab3690396d695ab' => [
            'classification' => 'intentional_safety_rail',
            'reason' => 'Read-only sanitized trajectory evaluation bound; it limits retained steps compared and does not run agents, change custody, or mutate review state.',
        ],
        'ce71ae6ac4f1b277bf12790137dca5983ca51914009182a32cbe403758a46008' => [
            'classification' => 'intentional_safety_rail',
            'reason' => 'Read-only face-bridge alignment sample bound; it limits diagnostic rows returned and the command exposes no repair or metadata-write path.',
        ],
        '17e44186328df8fda2ecab66265cf80cf03ff33a2076e8da28a27c6c40090180' => [
            'classification' => 'intentional_safety_rail',
            'reason' => 'Read-only genealogy health sample bound per issue; aggregate checks remain independent and the command performs no tree or media mutation.',
        ],
        'a6e0098517e024287fd8c5f045ffdac9e952e5092de82bae4f07ebaca1276d76' => [
            'classification' => 'intentional_safety_rail',
            'reason' => 'Read-only extraction-provenance contract display bound; it limits diagnostic contract detail while preserving aggregate coverage and performs no content writes.',
        ],
        '602ff1400703094109d5b9283f7d532368dd8a016ea1cf4884a49f048b05eb9c' => [
            'classification' => 'intentional_safety_rail',
            'reason' => 'Read-only news source inventory display bound; it limits feed rows shown and does not fetch articles, change workflows, or alter source configuration.',
        ],
        'f6cd3b533158c89cce95370c8e8b742631f5953362795ac461fdd2a202424b55' => [
            'classification' => 'intentional_safety_rail',
            'reason' => 'Manual graph-search result bound; it limits interactive retrieval and display only and does not control scheduled graph work or mutate graph data.',
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
