<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'ops';

    private const KEY = 'scheduler_api_runtime_policy';

    private const DESCRIPTION = 'Table-owned scheduler operator API history projection and guarded cleanup retention policy.';

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'schema' => 'plos.scheduler_api_runtime_policy.v1',
            'history' => [
                'embedded_limit' => 10,
                'default_limit' => 50,
                'max_limit' => 100,
            ],
            'cleanup' => [
                'default_retention_days' => 30,
                'min_retention_days' => 7,
                'max_retention_days' => 3650,
            ],
            'policy_notes' => [
                'authentication' => 'All scheduler API projections and mutations require the authenticated operator session.',
                'cleanup' => 'History cleanup deletes only completed run rows older than an explicitly validated retention period.',
                'failure_behavior' => 'Missing, malformed, or out-of-range policy stops history projection and cleanup before querying or deleting run rows.',
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
        if (! $exists) {
            DB::table('system_configs')->insert([
                'section' => self::SECTION,
                'config_key' => self::KEY,
                'config_value' => $this->encodedPolicy(),
                'data_type' => 'json',
                'description' => self::DESCRIPTION,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::forget('syscfg:'.self::SECTION.'.'.self::KEY);
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->where('config_value', $this->encodedPolicy())
            ->where('description', self::DESCRIPTION)
            ->delete();
        Cache::forget('syscfg:'.self::SECTION.'.'.self::KEY);
    }

    private function encodedPolicy(): string
    {
        return json_encode(
            $this->policy(),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }
};
