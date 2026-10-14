<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\SystemConfigService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

trait PreservesSystemConfig
{
    /** @var array<string,array{exists:bool,config_value:?string,data_type:?string,description:?string}> */
    private array $preservedSystemConfigs = [];

    protected function preserveSystemConfig(string $section, string $key): void
    {
        $compound = "{$section}.{$key}";
        if (array_key_exists($compound, $this->preservedSystemConfigs)) {
            return;
        }

        $row = DB::selectOne(
            'SELECT config_value, data_type, description
             FROM system_configs
             WHERE section = ? AND config_key = ?
             LIMIT 1',
            [$section, $key]
        );

        $this->preservedSystemConfigs[$compound] = [
            'exists' => $row !== null,
            'config_value' => $row?->config_value,
            'data_type' => $row?->data_type,
            'description' => $row?->description,
        ];
    }

    protected function restorePreservedSystemConfigs(): void
    {
        foreach ($this->preservedSystemConfigs as $compound => $state) {
            [$section, $key] = explode('.', $compound, 2);

            if (! $state['exists']) {
                DB::delete(
                    'DELETE FROM system_configs WHERE section = ? AND config_key = ?',
                    [$section, $key]
                );
                $this->forgetSystemConfigCache($section, $key);

                continue;
            }

            DB::statement(
                'INSERT INTO system_configs (section, config_key, config_value, data_type, description, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    config_value = VALUES(config_value),
                    data_type = VALUES(data_type),
                    description = VALUES(description),
                    updated_at = NOW()',
                [
                    $section,
                    $key,
                    $state['config_value'],
                    $state['data_type'] ?? 'string',
                    $state['description'],
                ]
            );
            $this->forgetSystemConfigCache($section, $key);
        }

        $this->preservedSystemConfigs = [];
    }

    protected function setSystemConfigValue(
        string $section,
        string $key,
        string $value,
        string $dataType = 'string',
        string $description = 'test override',
    ): void {
        $this->preserveSystemConfig($section, $key);

        DB::statement(
            'INSERT INTO system_configs (section, config_key, config_value, data_type, description, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                config_value = VALUES(config_value),
                data_type = VALUES(data_type),
                description = VALUES(description),
                updated_at = NOW()',
            [$section, $key, $value, $dataType, $description]
        );

        $this->forgetSystemConfigCache($section, $key);
    }

    protected function setActiveProfile(string $name, string $description = 'test active profile'): void
    {
        $this->setSystemConfigValue('routing', 'active_profile', $name, 'string', $description);
    }

    protected function forgetSystemConfigCache(string $section, string $key): void
    {
        $compound = "{$section}.{$key}";
        app(SystemConfigService::class)->forget($compound);
        Cache::forget("syscfg:{$compound}");
    }
}
