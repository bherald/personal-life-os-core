<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_MODEL_ROLE_REGEX = '/\\b(capacity_model_roles|role_fit|model_roles|quality|standard|fast|gpu_target|host_affinity|max_concurrent)\\b/i';

    private const NEW_MODEL_ROLE_REGEX = '/\\b(capacity_model_roles|role_fit|model_roles|gpu_target|host_affinity|max_concurrent)\\b|\\b(modelRole|model_role|model-role)\\b.*\\b(quality|standard|fast)\\b|\\b(quality|standard|fast)\\b.*\\b(modelRole|model_role|model-role)\\b/i';

    public function up(): void
    {
        $this->replaceModelRolePattern(self::OLD_MODEL_ROLE_REGEX, self::NEW_MODEL_ROLE_REGEX);
    }

    public function down(): void
    {
        $this->replaceModelRolePattern(self::NEW_MODEL_ROLE_REGEX, self::OLD_MODEL_ROLE_REGEX);
    }

    private function replaceModelRolePattern(string $from, string $to): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->first(['config_value']);

        $policy = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;

        if (! is_array($policy) || ! is_array($policy['patterns'] ?? null)) {
            return;
        }

        $changed = false;
        foreach ($policy['patterns'] as &$pattern) {
            if (! is_array($pattern) || ($pattern['name'] ?? null) !== 'model_or_capacity_role_policy') {
                continue;
            }

            if (($pattern['regex'] ?? null) === $from) {
                $pattern['regex'] = $to;
                $changed = true;
            }
        }
        unset($pattern);

        if (! $changed) {
            return;
        }

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'ops', 'config_key' => 'runtime_policy_leak_audit'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Observe-only scanner for hardcoded growth limits and runtime policy leaks.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
};
