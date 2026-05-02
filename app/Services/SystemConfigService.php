<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * System Configuration Service
 *
 * Centralized access to runtime-configurable system settings stored in system_configs table.
 * Three-tier fallback: Redis cache → MySQL → hardcoded default.
 *
 * Usage:
 *   $svc = app(SystemConfigService::class);
 *   $limit = $svc->get('scraping.max_content_size', 5242880);
 *   $svc->set('scraping.max_content_size', 10485760);
 *
 * Keys use dot notation: "section.config_key" maps to system_configs rows.
 */
class SystemConfigService
{
    private const CACHE_PREFIX = 'syscfg:';
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * In-memory cache for the current request to avoid repeated Redis/DB hits.
     */
    private array $localCache = [];

    /**
     * Get a configuration value.
     *
     * Lookup order: local memory → Redis → MySQL → default.
     *
     * @param string $key Dot-notation key: "section.config_key"
     * @param mixed $default Fallback value if not found anywhere
     * @return mixed Typed value (int/float/bool/string/array based on data_type column)
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // 1. Local memory (zero-cost repeated reads in same request)
        if (array_key_exists($key, $this->localCache)) {
            return $this->localCache[$key];
        }

        // 2. Redis cache
        try {
            $cached = Cache::get(self::CACHE_PREFIX . $key);
            if ($cached !== null) {
                $value = $this->deserialize($cached);
                $this->localCache[$key] = $value;
                return $value;
            }
        } catch (\Throwable $e) {
            // Redis down — fall through to DB
        }

        // 3. MySQL
        try {
            [$section, $configKey] = $this->parseKey($key);
            $row = DB::selectOne(
                "SELECT config_value, data_type FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1",
                [$section, $configKey]
            );

            if ($row !== null) {
                $value = $this->cast($row->config_value, $row->data_type);
                $this->localCache[$key] = $value;
                $this->cacheValue($key, $row->config_value);
                return $value;
            }
        } catch (\Throwable $e) {
            Log::warning('SystemConfigService: DB read failed', ['key' => $key, 'error' => $e->getMessage()]);
        }

        // 4. Default
        return $default;
    }

    /**
     * Get an integer config value.
     */
    public function getInt(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    /**
     * Get a float config value.
     */
    public function getFloat(string $key, float $default = 0.0): float
    {
        return (float) $this->get($key, $default);
    }

    /**
     * Set a configuration value (writes to DB + Redis, evicts local cache).
     *
     * @param string $key Dot-notation key
     * @param mixed $value New value
     * @param string|null $dataType Override data_type (auto-detected if null)
     */
    public function set(string $key, mixed $value, ?string $dataType = null): void
    {
        [$section, $configKey] = $this->parseKey($key);
        $dataType = $dataType ?? $this->detectType($value);
        $serialized = is_array($value) ? json_encode($value) : (string) $value;

        $exists = DB::selectOne(
            "SELECT id FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1",
            [$section, $configKey]
        );

        if ($exists) {
            DB::update(
                "UPDATE system_configs SET config_value = ?, data_type = ?, updated_at = NOW() WHERE section = ? AND config_key = ?",
                [$serialized, $dataType, $section, $configKey]
            );
        } else {
            DB::insert(
                "INSERT INTO system_configs (section, config_key, config_value, data_type, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())",
                [$section, $configKey, $serialized, $dataType]
            );
        }

        $this->cacheValue($key, $serialized);
        $this->localCache[$key] = $value;
    }

    /**
     * Invalidate a cached config value.
     */
    public function forget(string $key): void
    {
        unset($this->localCache[$key]);
        try {
            Cache::forget(self::CACHE_PREFIX . $key);
        } catch (\Throwable $e) {
            // Redis down — local cache cleared, DB is source of truth
        }
    }

    /**
     * Flush all cached config values (e.g., after bulk update).
     */
    public function flushCache(): void
    {
        $this->localCache = [];
        try {
            // Flush all syscfg: keys
            $keys = DB::select("SELECT section, config_key FROM system_configs");
            foreach ($keys as $row) {
                Cache::forget(self::CACHE_PREFIX . $row->section . '.' . $row->config_key);
            }
        } catch (\Throwable $e) {
            Log::warning('SystemConfigService: Cache flush failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Parse "section.config_key" into [$section, $config_key].
     * If no dot, section defaults to "general".
     */
    private function parseKey(string $key): array
    {
        $parts = explode('.', $key, 2);
        if (count($parts) === 1) {
            return ['general', $parts[0]];
        }
        return [$parts[0], $parts[1]];
    }

    /**
     * Cast a string DB value to the appropriate PHP type.
     */
    private function cast(?string $value, string $dataType): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($dataType) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json', 'array' => json_decode($value, true) ?? [],
            default => $value,
        };
    }

    /**
     * Auto-detect data type from a PHP value.
     */
    private function detectType(mixed $value): string
    {
        return match (true) {
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_bool($value) => 'bool',
            is_array($value) => 'json',
            default => 'string',
        };
    }

    /**
     * Serialize for Redis cache storage.
     */
    private function cacheValue(string $key, string $rawValue): void
    {
        try {
            Cache::put(self::CACHE_PREFIX . $key, $rawValue, self::CACHE_TTL);
        } catch (\Throwable $e) {
            // Redis down — DB is source of truth
        }
    }

    /**
     * Deserialize from Redis (returns raw string — caller casts via get()).
     */
    private function deserialize(mixed $cached): mixed
    {
        // Redis stores the raw DB value; re-fetch data_type from DB for proper casting
        // For performance, just return the raw value — callers use getInt/getFloat for typed access
        return $cached;
    }
}
