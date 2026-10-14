<?php

namespace App\Services\UnifiedCli;

use App\Services\SystemConfigService;
use RuntimeException;

/** Read the selected CLI session through existing PLOS service contracts. */
final class CliSystemConfigService extends SystemConfigService
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
    ) {}

    public function get(string $key, mixed $default = null, bool $strict = false): mixed
    {
        $session = $this->store->session($this->sessionId);
        $effective = (new CliConfiguration($this->store))->effective($session);
        if ($effective['generation'] === null) {
            throw new RuntimeException('A validated local registry generation is required.');
        }

        // No shared Redis cache or application database: every policy check
        // sees the latest session revision and its exact pinned generation,
        // including named strict reads through the shared parent contract.
        $value = array_key_exists($key, $effective['values']) ? $effective['values'][$key] : $default;
        if ($key === config('offline_policy.active_profile_config_key', 'routing.active_profile')
            && (! is_string($value) || ! array_key_exists(strtolower(trim($value)), (array) config('offline_policy.profiles', [])))) {
            // The shared service normally treats an unknown name as its
            // permissive default. An invalid CLI override must fail closed.
            throw new RuntimeException('CLI routing profile is not defined in the PLOS policy.');
        }

        return $value;
    }

    protected function llmRoutingPolicy(string $section, string $key): ?array
    {
        $value = $this->get($section.'.'.$key, null, true);
        if ($value !== null && ! is_array($value)) {
            throw new RuntimeException('Pinned CLI routing policy must be an object.');
        }

        return $value;
    }

    public function set(string $key, mixed $value, ?string $dataType = null): void
    {
        throw new RuntimeException('Shared services cannot change CLI policy. Use the explicit CLI setting command.');
    }

    public function forget(string $key): void
    {
        // Nothing is cached. Do not invalidate another PLOS process's cache.
    }

    public function flushCache(bool $strict = false): void
    {
        // Nothing is cached. Never query or alter the shared database/cache.
    }
}
