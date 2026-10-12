<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'agents';

    private const KEY = 'skill_audit_runtime_policy';

    private const DESCRIPTION = 'Validated runtime limits for the PLOS agent skill audit and its optional SkillSpector sidecar.';

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'schema' => 'plos.agent_skill_audit_runtime_policy.v1',
            'skillspector' => [
                'timeout_seconds' => 120,
            ],
            'policy_notes' => [
                'skillspector.timeout_seconds' => 'Per-skill SkillSpector process timeout. Missing or invalid values stop the audit before sidecar process dispatch.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $exists = DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->exists();
        if ($exists) {
            return;
        }

        DB::table('system_configs')->insert([
            'section' => self::SECTION,
            'config_key' => self::KEY,
            'config_value' => json_encode($this->policy(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'data_type' => 'json',
            'description' => self::DESCRIPTION,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Cache::forget('syscfg:'.self::SECTION.'.'.self::KEY);
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->first(['config_value', 'data_type', 'description']);
        if ($row === null) {
            return;
        }

        $expected = json_encode($this->policy(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ((string) $row->config_value !== $expected
            || (string) $row->data_type !== 'json'
            || (string) $row->description !== self::DESCRIPTION) {
            return;
        }

        DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->delete();
        Cache::forget('syscfg:'.self::SECTION.'.'.self::KEY);
    }
};
