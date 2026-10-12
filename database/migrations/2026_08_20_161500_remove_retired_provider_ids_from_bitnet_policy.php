<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const RETIRED_IDS = [
        'cerebras_free',
        'groq_free',
        'deepinfra_free',
        'openrouter_free',
        'gemini_free',
        'mistral_free',
        'sambanova_free',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('llm_instances') || ! Schema::hasColumn('llm_instances', 'config')) {
            return;
        }

        $rawConfig = DB::table('llm_instances')
            ->where('instance_id', 'bitnet_87')
            ->value('config');

        if ($rawConfig === null) {
            return;
        }

        try {
            $config = json_decode((string) $rawConfig, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Refusing BitNet policy cleanup: config is not valid JSON.', 0, $exception);
        }

        if (! is_array($config)) {
            throw new RuntimeException('Refusing BitNet policy cleanup: config must be a JSON object.');
        }

        $rules = $config['route_policy']['prefilter_deny_rules'] ?? [];
        if (! is_array($rules)) {
            throw new RuntimeException('Refusing BitNet policy cleanup: prefilter_deny_rules must be an array.');
        }

        foreach ($rules as $ruleIndex => $rule) {
            if (! is_array($rule) || ! isset($rule['all_groups']) || ! is_array($rule['all_groups'])) {
                continue;
            }

            foreach ($rule['all_groups'] as $groupIndex => $group) {
                if (! is_array($group)) {
                    continue;
                }

                $rules[$ruleIndex]['all_groups'][$groupIndex] = array_values(array_filter(
                    $group,
                    static fn ($term): bool => ! is_string($term) || ! in_array($term, self::RETIRED_IDS, true)
                ));
            }
        }

        $config['route_policy']['prefilter_deny_rules'] = $rules;

        DB::table('llm_instances')
            ->where('instance_id', 'bitnet_87')
            ->update([
                'config' => json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

        foreach (['llm_instances_all', 'llm_instances_healthy', 'external_api_providers'] as $key) {
            Cache::forget($key);
        }
    }

    public function down(): void
    {
        // Do not recreate identifiers for operator-retired providers.
    }
};
