<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'llm';

    private const KEY = 'provider_role_bias_policy';

    /** @return array<string,mixed> */
    private function defaultPolicy(): array
    {
        return [
            'enabled' => true,
            'version' => 1,
            'max_abs_bias' => 20,
            'roles' => [
                'embedding' => [
                    'ollama_jetson' => 10,
                ],
                'health' => [
                    'ollama_jetson' => 12,
                ],
            ],
            'notes' => 'Role-specific provider bias is table-owned. It gives Jetson more embedding/health work without changing global priority or promoting it to quality routing.',
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->first(['config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            $policy = [];
        }

        $merged = $this->mergePolicy($policy, $this->defaultPolicy());

        DB::table('system_configs')->updateOrInsert(
            ['section' => self::SECTION, 'config_key' => self::KEY],
            [
                'config_value' => json_encode($merged, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Table-owned role-specific provider routing bias for using local LLM capacity without global priority changes.',
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

        $row = DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->first(['config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            return;
        }

        unset($policy['roles']['embedding']['ollama_jetson'], $policy['roles']['health']['ollama_jetson']);
        foreach (['embedding', 'health'] as $role) {
            if (($policy['roles'][$role] ?? []) === []) {
                unset($policy['roles'][$role]);
            }
        }

        DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string,mixed>  $existing
     * @param  array<string,mixed>  $defaults
     * @return array<string,mixed>
     */
    private function mergePolicy(array $existing, array $defaults): array
    {
        $existing['enabled'] = $existing['enabled'] ?? $defaults['enabled'];
        $existing['version'] = $existing['version'] ?? $defaults['version'];
        $existing['max_abs_bias'] = $existing['max_abs_bias'] ?? $defaults['max_abs_bias'];
        $existing['notes'] = $existing['notes'] ?? $defaults['notes'];

        $roles = is_array($existing['roles'] ?? null) ? $existing['roles'] : [];
        foreach ($defaults['roles'] as $role => $biases) {
            $roles[$role] = array_replace(
                is_array($roles[$role] ?? null) ? $roles[$role] : [],
                $biases
            );
        }
        $existing['roles'] = $roles;

        return $existing;
    }
};
