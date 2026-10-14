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
    /** Read an explicit routing binding; missing or unreadable policy never creates a route. */
    public function llmRoutingBinding(string $name): array
    {
        $bindings = $this->llmRoutingPolicy('routing', 'llm_bindings');
        $binding = is_array($bindings) ? ($bindings[$name] ?? null) : null;
        if (! is_array($binding) || $binding === []) {
            throw new \RuntimeException('Required table-owned LLM binding is unavailable: '.$name);
        }

        return $binding;
    }

    public function llmRoutingValue(string $name, string $field): string|int
    {
        $value = data_get($this->llmRoutingBinding($name), $field);
        if ($field === 'port'
            ? is_int($value) && $value > 0 && $value <= 65535
            : is_string($value) && trim($value) !== '' && strlen($value) <= 2048) {
            return $value;
        }

        throw new \RuntimeException('Required LLM binding field is unavailable: '.$name.'.'.$field);
    }

    /** Dedicated lifecycle guards use their existing policy, including when no K3 binding exists. */
    public function isDedicatedLlmTarget(string $target): bool
    {
        if ($target === '') {
            return false;
        }
        $policy = $this->llmRoutingPolicy('ai', 'model_host_controller_mhc08_k3_policy') ?? [];
        if (! is_array($policy)) {
            throw new \RuntimeException('Dedicated LLM target policy is invalid.');
        }
        $registered = $policy['target']['target_id'] ?? null;

        return is_string($registered) && $registered !== '' && $target === $registered;
    }

    /** Fresh reads for route authority, independent of legacy configuration cache behavior. */
    protected function llmRoutingPolicy(string $section, string $key): ?array
    {
        try {
            $row = DB::selectOne('SELECT config_value, data_type FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1', [$section, $key]);
            if ($row === null) {
                return null;
            }
            if (! in_array($row->data_type, ['json', 'array'], true)) {
                throw new \RuntimeException('LLM routing policy must be registered JSON.');
            }
            $value = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($value)) {
                throw new \RuntimeException('LLM routing policy must be an object.');
            }

            return $value;
        } catch (\Throwable $error) {
            throw new \RuntimeException('LLM routing policy lookup unavailable.', 0, $error);
        }
    }

    private const CACHE_PREFIX = 'syscfg:';

    private const CACHE_TTL = 300; // 5 minutes

    /**
     * In-memory cache for the current request to avoid repeated Redis/DB hits.
     */
    private array $localCache = [];

    /**
     * Process-local versions let one service instance invalidate other instances in the same worker.
     *
     * @var array<string, int>
     */
    private static array $cacheGenerations = [];

    /** @var array<string, int> */
    private array $localCacheGenerations = [];

    /**
     * Get a configuration value.
     *
     * Lookup order: local memory → Redis → MySQL → default.
     *
     * @param  string  $key  Dot-notation key: "section.config_key"
     * @param  mixed  $default  Fallback value if not found anywhere
     * @param  bool  $strict  Fresh security-policy read; defaults require proven row absence, lookup/decode failures throw
     * @return mixed Typed value (int/float/bool/string/array based on data_type column)
     */
    public function get(string $key, mixed $default = null, bool $strict = false): mixed
    {
        if ($strict) {
            try {
                $row = $this->lookupRow($key);

                return $row === null ? $default : $this->cast($row->config_value, $row->data_type, strict: true);
            } catch (\Throwable $error) {
                throw new \RuntimeException('System configuration lookup unavailable.', 0, $error);
            }
        }

        // 1. Local memory (zero-cost repeated reads in same request)
        if (
            array_key_exists($key, $this->localCache)
            && ($this->localCacheGenerations[$key] ?? -1) === $this->cacheGeneration($key)
        ) {
            return $this->localCache[$key];
        }
        unset($this->localCache[$key], $this->localCacheGenerations[$key]);

        // 2. Redis cache
        try {
            $cached = Cache::get(self::CACHE_PREFIX.$key);
            if ($cached !== null) {
                $value = $this->deserialize($cached);
                if ($value === null) {
                    Cache::forget(self::CACHE_PREFIX.$key);
                } else {
                    $this->localCache[$key] = $value;
                    $this->localCacheGenerations[$key] = $this->cacheGeneration($key);

                    return $value;
                }
            }
        } catch (\Throwable $e) {
            // Redis down — fall through to DB
        }

        // 3. MySQL
        try {
            $row = $this->lookupRow($key);

            if ($row !== null) {
                $value = $this->cast($row->config_value, $row->data_type);
                $this->localCache[$key] = $value;
                $this->localCacheGenerations[$key] = $this->cacheGeneration($key);
                $this->cacheValue($key, $row->config_value, $row->data_type);

                return $value;
            }
        } catch (\Throwable $e) {
            Log::warning('SystemConfigService: DB read failed', ['key' => $key, 'error' => $e->getMessage()]);
        }

        // 4. Default
        return $default;
    }

    private function lookupRow(string $key): ?object
    {
        [$section, $configKey] = $this->parseKey($key);

        return DB::selectOne(
            'SELECT config_value, data_type FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
            [$section, $configKey]
        );
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
     * @param  string  $key  Dot-notation key
     * @param  mixed  $value  New value
     * @param  string|null  $dataType  Override data_type (auto-detected if null)
     */
    public function set(string $key, mixed $value, ?string $dataType = null): void
    {
        [$section, $configKey] = $this->parseKey($key);
        $dataType = $dataType ?? $this->detectType($value);
        $serialized = is_array($value) ? json_encode($value) : (string) $value;

        $exists = DB::selectOne(
            'SELECT id FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
            [$section, $configKey]
        );

        if ($exists) {
            DB::update(
                'UPDATE system_configs SET config_value = ?, data_type = ?, updated_at = NOW() WHERE section = ? AND config_key = ?',
                [$serialized, $dataType, $section, $configKey]
            );
        } else {
            DB::insert(
                'INSERT INTO system_configs (section, config_key, config_value, data_type, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
                [$section, $configKey, $serialized, $dataType]
            );
        }

        $this->invalidateGeneration($key);
        $this->cacheValue($key, $serialized, $dataType);
        $this->localCache[$key] = $value;
        $this->localCacheGenerations[$key] = $this->cacheGeneration($key);
    }

    /**
     * Invalidate a cached config value.
     */
    public function forget(string $key): void
    {
        $this->invalidateGeneration($key);
        unset($this->localCache[$key], $this->localCacheGenerations[$key]);
        try {
            Cache::forget(self::CACHE_PREFIX.$key);
        } catch (\Throwable $e) {
            // Redis down — local cache cleared, DB is source of truth
        }
    }

    /**
     * Flush all cached config values (e.g., after bulk update).
     */
    public function flushCache(bool $strict = false): void
    {
        $this->localCache = [];
        $this->localCacheGenerations = [];
        try {
            // Flush all syscfg: keys
            $keys = DB::select('SELECT section, config_key FROM system_configs');
            foreach ($keys as $row) {
                $key = $row->section.'.'.$row->config_key;
                $this->invalidateGeneration($key);
                Cache::forget(self::CACHE_PREFIX.$key);
                if ($strict && Cache::has(self::CACHE_PREFIX.$key)) {
                    throw new \RuntimeException('Configuration cache remained present.');
                }
            }
        } catch (\Throwable $e) {
            if ($strict) {
                throw new \RuntimeException('Configuration cache invalidation unavailable.', 0, $e);
            }
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
    private function cast(?string $value, string $dataType, bool $strict = false): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($dataType) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json', 'array' => $strict ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : (json_decode($value, true) ?? []),
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
    private function cacheValue(string $key, string $rawValue, string $dataType): void
    {
        try {
            Cache::put(self::CACHE_PREFIX.$key, [
                '__syscfg_cache_v' => 1,
                'value' => $rawValue,
                'data_type' => $dataType,
            ], self::CACHE_TTL);
        } catch (\Throwable $e) {
            // Redis down — DB is source of truth
        }
    }

    /**
     * Deserialize from Redis. Null means legacy/untyped cache and forces DB refresh.
     */
    private function deserialize(mixed $cached): mixed
    {
        if (! is_array($cached) || ($cached['__syscfg_cache_v'] ?? null) !== 1) {
            return null;
        }

        $dataType = $cached['data_type'] ?? null;
        if (! is_string($dataType)) {
            return null;
        }

        return $this->cast(isset($cached['value']) ? (string) $cached['value'] : null, $dataType);
    }

    private function cacheGeneration(string $key): int
    {
        return self::$cacheGenerations[$key] ?? 0;
    }

    private function invalidateGeneration(string $key): void
    {
        self::$cacheGenerations[$key] = $this->cacheGeneration($key) + 1;
    }
}
