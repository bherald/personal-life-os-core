<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'ops';

    private const KEY = 'scheduler_api_runtime_policy';

    private const SCHEMA = 'plos.scheduler_api_runtime_policy.v1';

    private const MARKER = '2026_07_17_scheduler_api_timeout_policy';

    /** @return array<string,int> */
    private function timeoutPolicy(): array
    {
        return [
            'default_minutes' => 60,
            'min_minutes' => 1,
            'max_minutes' => 1440,
        ];
    }

    public function up(): void
    {
        $policy = $this->policy();
        if ($policy === null || ($policy['schema'] ?? null) !== self::SCHEMA) {
            return;
        }

        $inserted = ! array_key_exists('job_timeout', $policy);
        if ($inserted) {
            $policy['job_timeout'] = $this->timeoutPolicy();
        }

        $policy['_migration_extensions'] = is_array($policy['_migration_extensions'] ?? null)
            ? $policy['_migration_extensions']
            : [];
        if (! array_key_exists(self::MARKER, $policy['_migration_extensions'])) {
            $policy['_migration_extensions'][self::MARKER] = [
                'inserted_job_timeout' => $inserted,
            ];
        }

        $this->updatePolicy($policy);
    }

    public function down(): void
    {
        $policy = $this->policy();
        $marker = is_array($policy['_migration_extensions'][self::MARKER] ?? null)
            ? $policy['_migration_extensions'][self::MARKER]
            : null;
        if ($policy === null || $marker === null) {
            return;
        }

        if (($marker['inserted_job_timeout'] ?? false) === true
            && ($policy['job_timeout'] ?? null) === $this->timeoutPolicy()) {
            unset($policy['job_timeout']);
        }

        unset($policy['_migration_extensions'][self::MARKER]);
        if (($policy['_migration_extensions'] ?? []) === []) {
            unset($policy['_migration_extensions']);
        }

        $this->updatePolicy($policy);
    }

    /** @return array<string,mixed>|null */
    private function policy(): ?array
    {
        if (! Schema::hasTable('system_configs')) {
            return null;
        }

        $value = DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->value('config_value');
        $policy = $value !== null ? json_decode((string) $value, true) : null;

        return is_array($policy) ? $policy : null;
    }

    /** @param array<string,mixed> $policy */
    private function updatePolicy(array $policy): void
    {
        DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        Cache::forget('syscfg:'.self::SECTION.'.'.self::KEY);
    }
};
