<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Temporarily opens the already-qualified K3 provider for one explicit,
 * deep-offline, no-fallback inference callback. The exact provider and
 * profile snapshots are restored even when inference fails.
 */
class ModelHostControllerMhc08K3AdmissionService
{
    private const JOURNAL_SECTION = 'ai';

    private const JOURNAL_KEY = 'model_host_controller_mhc08_k3_admission_journal';

    private const JOURNAL_SCHEMA = 'plos.model_host_controller.mhc08_k3_admission_journal.v1';

    private const INSTANCE_ID = 'kimi_k3_87';

    private const PROFILE = 'deep_offline_kimi_k3';

    public function __construct(
        private readonly ?ModelHostControllerMhc08K3AdmissionGuardService $guard = null,
        private readonly ?ModelHostControllerMhc08K3StateContractService $stateContract = null,
    ) {}

    /** @var list<string> */
    private const SAFE_TELEMETRY_COLUMNS = [
        'avg_response_ms',
        'p95_response_ms',
        'total_requests',
        'total_failures',
        'consecutive_failures',
        'success_rate',
        'last_success_at',
        'last_failure_at',
    ];

    /**
     * @template T
     *
     * @param  callable(string):T  $callback
     * @return T
     */
    public function run(string $requestId, callable $callback): mixed
    {
        $requestId = trim($requestId);
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{7,110}\z/', $requestId) !== 1) {
            throw new RuntimeException('MHC-08 K3 admission request identity is invalid.');
        }

        $guard = $this->guard ?? app(ModelHostControllerMhc08K3AdmissionGuardService::class);
        $token = $guard->issue($requestId);
        try {
            $this->open($requestId);
            $this->clearCaches();

            return $callback($token);
        } finally {
            $this->recoverInterruptedWithGuard($requestId, $guard);
        }
    }

    /** @return array<string,mixed> */
    public function recoverInterrupted(string $requestId): array
    {
        $requestId = $this->requestId($requestId);

        return $this->recoverInterruptedWithGuard(
            $requestId,
            $this->guard ?? app(ModelHostControllerMhc08K3AdmissionGuardService::class),
        );
    }

    /** @return array{provider:array<string,mixed>,profile:array<string,mixed>,provider_sha256:string,profile_sha256:string} */
    private function open(string $requestId): array
    {
        return DB::transaction(function () use ($requestId): array {
            $this->requireTables();
            if ($this->journalRow(true) !== null) {
                throw new RuntimeException('MHC-08 K3 admission journal is already active.');
            }
            $provider = $this->providerRow(true);
            $profile = $this->profileRow(true);
            $config = $this->decodeConfig($provider['config'] ?? null);

            $this->assertClosedProvider($provider, $config);
            $this->assertProfileGated($profile);

            $journal = [
                'schema' => self::JOURNAL_SCHEMA,
                'request_id' => $requestId,
                'provider' => $provider,
                'profile' => $profile,
                'provider_sha256' => $this->hashSnapshot($provider),
                'profile_sha256' => $this->hashSnapshot($profile),
                'opened_provider' => null,
                'opened_profile' => null,
                'opened_provider_sha256' => null,
                'opened_profile_sha256' => null,
            ];
            $this->insertJournal($journal);

            $route = is_array($config['route_policy'] ?? null) ? $config['route_policy'] : [];
            $route['allowed_model_roles'] = ['deep_offline'];
            $route['require_model_role'] = true;
            $route['require_explicit_instance'] = true;
            $route['allow_generic_fallback'] = false;
            $route['tools_enabled'] = false;
            $config['route_policy'] = $route;

            $affected = $this->updateProvider($provider['id'], [
                'is_active' => 1,
                'is_healthy' => 1,
                'health_score' => 80,
                'routability' => 'allowed',
                'max_concurrent' => 1,
                'rate_limit_rpm' => 1,
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'last_health_check' => now(),
                'updated_at' => now(),
            ]);
            if ($affected !== 1) {
                throw new RuntimeException('MHC-08 K3 admission provider update was not exact.');
            }

            $openedProvider = $this->providerRow(true);
            $openedProfile = $this->profileRow(true);

            $journal['opened_provider'] = $openedProvider;
            $journal['opened_profile'] = $openedProfile;
            $journal['opened_provider_sha256'] = $this->hashSnapshot($openedProvider);
            $journal['opened_profile_sha256'] = $this->hashSnapshot($openedProfile);
            $this->updateJournal($journal);

            return $journal;
        });
    }

    /** @return array<string,mixed> */
    private function recoverInterruptedWithGuard(
        string $requestId,
        ModelHostControllerMhc08K3AdmissionGuardService $guard,
    ): array {
        $requestId = $this->requestId($requestId);
        try {
            $restored = DB::transaction(function () use ($requestId): bool {
                $this->requireTables();
                $journalRow = $this->journalRow(true);
                $provider = $this->providerRow(true);
                $profile = $this->profileRow(true);
                if ($journalRow === null) {
                    $this->assertClosedProvider($provider, $this->decodeConfig($provider['config'] ?? null));
                    $this->assertProfileGated($profile);

                    return false;
                }
                $snapshot = $this->decodeJournal($journalRow['config_value'] ?? null);
                if (($snapshot['request_id'] ?? null) !== $requestId) {
                    throw new RuntimeException('MHC-08 K3 admission recovery request does not own the journal.');
                }
                if ((int) ($provider['id'] ?? 0) !== (int) ($snapshot['opened_provider']['id'] ?? 0)
                    || (int) ($profile['id'] ?? 0) !== (int) ($snapshot['opened_profile']['id'] ?? 0)
                    || (string) ($provider['instance_id'] ?? '') !== self::INSTANCE_ID) {
                    throw new RuntimeException('MHC-08 K3 cleanup found a replaced provider or profile row.');
                }
                $providerIsOpened = $this->openedStateUnchanged($provider, $snapshot['opened_provider']);
                $profileIsOpened = $this->openedStateUnchanged($profile, $snapshot['opened_profile']);
                $providerIsClosed = $this->openedStateUnchanged($provider, $snapshot['provider']);
                $profileIsClosed = $this->openedStateUnchanged($profile, $snapshot['profile']);
                if (! $providerIsOpened && ! $providerIsClosed) {
                    throw new RuntimeException('MHC-08 K3 cleanup detected unexpected provider state drift.');
                }
                if (! $profileIsOpened && ! $profileIsClosed) {
                    throw new RuntimeException('MHC-08 K3 cleanup detected unexpected profile state drift.');
                }

                $closedProvider = $snapshot['provider'];
                foreach (self::SAFE_TELEMETRY_COLUMNS as $column) {
                    if (array_key_exists($column, $provider)) {
                        $closedProvider[$column] = $provider[$column];
                    }
                }
                $snapshot['restored_provider_sha256'] = $this->hashSnapshot($closedProvider);
                if ($providerIsOpened) {
                    $this->restoreRow('llm_instances', (int) $snapshot['provider']['id'], $closedProvider);
                }
                if ($profileIsOpened) {
                    $this->restoreRow('llm_model_profiles', (int) $snapshot['profile']['id'], $snapshot['profile']);
                }

                $restoredProvider = $this->providerRow(true);
                $restoredProfile = $this->profileRow(true);
                if ($this->hashSnapshot($restoredProvider) !== $snapshot['restored_provider_sha256']
                    || $this->hashSnapshot($restoredProfile) !== $snapshot['profile_sha256']) {
                    throw new RuntimeException('MHC-08 K3 cleanup did not restore the exact closed provider and profile snapshots.');
                }

                return true;
            });
        } catch (\Throwable $restoreException) {
            try {
                $guard->revoke($requestId);
            } catch (\Throwable) {
                // The durable journal remains the authoritative unresolved-cleanup marker.
            }
            throw $restoreException;
        }

        $guard->revoke($requestId);
        DB::transaction(function () use ($requestId): void {
            $journal = $this->journalRow(true);
            if ($journal === null) {
                return;
            }
            $snapshot = $this->decodeJournal($journal['config_value'] ?? null);
            if (($snapshot['request_id'] ?? null) !== $requestId) {
                throw new RuntimeException('MHC-08 K3 admission journal ownership changed before cleanup.');
            }
            $deleted = DB::table('system_configs')
                ->where('id', (int) $journal['id'])
                ->where('section', self::JOURNAL_SECTION)
                ->where('config_key', self::JOURNAL_KEY)
                ->delete();
            if ($deleted !== 1) {
                throw new RuntimeException('MHC-08 K3 admission journal removal was not exact.');
            }
        });
        $this->clearCaches();

        return [
            'schema' => 'plos.model_host_controller.mhc08_k3_admission_recovery.v1',
            'request_id' => $requestId,
            'result' => $restored ? 'restored' : 'not_required',
            'provider_closed' => true,
            'profile_gated' => true,
            'guard_revoked' => true,
            'journal_removed' => true,
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $config */
    private function assertClosedProvider(array $row, array $config): void
    {
        // MHC-08 candidate execution is State-qualified only.  The older
        // Mapped identity is intentionally not an admission fallback.
        $admission = is_array($config['admission'] ?? null) ? $config['admission'] : [];
        if (($admission['runtime_profile'] ?? null) !== 'State') {
            throw new RuntimeException('MHC-08 K3 admission requires finalized State evidence; Mapped is closed.');
        }
        ($this->stateContract ?? app(ModelHostControllerMhc08K3StateContractService::class))
            ->assertClosedProvider($row, $config);
    }

    /** @param array<string,mixed> $profile */
    private function assertProfileGated(array $profile): void
    {
        if ((string) ($profile['profile_name'] ?? '') !== self::PROFILE
            || (int) ($profile['enabled'] ?? 1) !== 0
            || (string) ($profile['status'] ?? '') !== 'staged'
            || (int) ($profile['requires_override'] ?? 0) !== 1) {
            throw new RuntimeException('MHC-08 K3 profile must remain staged, disabled, and override-required.');
        }
        ($this->stateContract ?? app(ModelHostControllerMhc08K3StateContractService::class))
            ->assertClosedProfile($profile);
    }

    /** @return array<string,mixed> */
    private function providerRow(bool $lock = false): array
    {
        $query = DB::table('llm_instances')->where('instance_id', self::INSTANCE_ID);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if ($row === null) {
            throw new RuntimeException('MHC-08 K3 provider row is missing.');
        }

        return (array) $row;
    }

    /** @return array<string,mixed> */
    private function profileRow(bool $lock = false): array
    {
        $query = DB::table('llm_model_profiles')->where('profile_name', self::PROFILE);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if ($row === null) {
            throw new RuntimeException('MHC-08 K3 model profile is missing.');
        }

        return (array) $row;
    }

    private function requireTables(): void
    {
        if (! Schema::hasTable('llm_instances')
            || ! Schema::hasTable('llm_model_profiles')
            || ! Schema::hasTable('system_configs')) {
            throw new RuntimeException('MHC-08 K3 admission tables are unavailable.');
        }
    }

    /** @return array<string,mixed>|null */
    private function journalRow(bool $lock = false): ?array
    {
        $query = DB::table('system_configs')
            ->where('section', self::JOURNAL_SECTION)
            ->where('config_key', self::JOURNAL_KEY);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row === null ? null : (array) $row;
    }

    /** @param array<string,mixed> $journal */
    private function insertJournal(array $journal): void
    {
        $inserted = DB::table('system_configs')->insert($this->filterColumns('system_configs', [
            'section' => self::JOURNAL_SECTION,
            'config_key' => self::JOURNAL_KEY,
            'config_value' => json_encode($journal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'data_type' => 'json',
            'description' => 'Active exact MHC-08 K3 admission recovery journal; removal requires proved provider closure and guard revocation.',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        if (! $inserted) {
            throw new RuntimeException('MHC-08 K3 admission journal creation failed.');
        }
    }

    /** @param array<string,mixed> $journal */
    private function updateJournal(array $journal): void
    {
        $affected = DB::table('system_configs')
            ->where('section', self::JOURNAL_SECTION)
            ->where('config_key', self::JOURNAL_KEY)
            ->update($this->filterColumns('system_configs', [
                'config_value' => json_encode($journal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]));
        if ($affected !== 1) {
            throw new RuntimeException('MHC-08 K3 admission journal finalization was not exact.');
        }
    }

    /** @return array<string,mixed> */
    private function decodeJournal(mixed $value): array
    {
        $journal = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        $keys = [
            'schema', 'request_id', 'provider', 'profile', 'provider_sha256', 'profile_sha256',
            'opened_provider', 'opened_profile', 'opened_provider_sha256', 'opened_profile_sha256',
        ];
        if (! is_array($journal) || array_keys($journal) !== $keys
            || ($journal['schema'] ?? null) !== self::JOURNAL_SCHEMA
            || ! is_array($journal['provider'] ?? null)
            || ! is_array($journal['profile'] ?? null)
            || ! is_array($journal['opened_provider'] ?? null)
            || ! is_array($journal['opened_profile'] ?? null)
            || ($journal['provider_sha256'] ?? null) !== $this->hashSnapshot($journal['provider'])
            || ($journal['profile_sha256'] ?? null) !== $this->hashSnapshot($journal['profile'])
            || ($journal['opened_provider_sha256'] ?? null) !== $this->hashSnapshot($journal['opened_provider'])
            || ($journal['opened_profile_sha256'] ?? null) !== $this->hashSnapshot($journal['opened_profile'])) {
            throw new RuntimeException('MHC-08 K3 admission journal is invalid or drifted.');
        }
        $this->requestId((string) ($journal['request_id'] ?? ''));
        $this->assertClosedProvider($journal['provider'], $this->decodeConfig($journal['provider']['config'] ?? null));
        $this->assertProfileGated($journal['profile']);

        return $journal;
    }

    private function requestId(string $requestId): string
    {
        $requestId = trim($requestId);
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{7,110}\z/', $requestId) !== 1) {
            throw new RuntimeException('MHC-08 K3 admission request identity is invalid.');
        }

        return $requestId;
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private function decodeConfig(mixed $config): array
    {
        $decoded = json_decode((string) $config, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<string> */
    private function jsonList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_map('strval', $decoded));
    }

    /** @param array<string,mixed> $config */
    private function exactHealthEndpoint(array $config): bool
    {
        $health = parse_url((string) ($config['health_base_url'] ?? ''));
        if (! is_array($health)
            || ($health['scheme'] ?? null) !== 'http'
            || ($health['host'] ?? null) !== (string) config('model_host_controller.reviewed_runtime_host', '')
            || (int) ($health['port'] ?? 0) !== 8001
            || isset($health['path']) && $health['path'] !== ''
            || isset($health['query'])
            || isset($health['fragment'])) {
            return false;
        }

        return ($config['health_path'] ?? null) === '/health';
    }

    /** @param array<string,mixed> $values */
    private function updateProvider(int $id, array $values): int
    {
        return DB::table('llm_instances')->where('id', $id)->update($this->filterColumns('llm_instances', $values));
    }

    /** @param array<string,mixed> $row */
    private function restoreRow(string $table, int $id, array $row): void
    {
        unset($row['id']);
        $affected = DB::table($table)->where('id', $id)->update($this->filterColumns($table, $row));
        if ($affected !== 1) {
            throw new RuntimeException("MHC-08 K3 cleanup did not update exactly one {$table} row.");
        }
    }

    /** @param array<string,mixed> $actual @param array<string,mixed> $expected */
    private function assertOpenedStateUnchanged(array $actual, array $expected, string $kind): void
    {
        if (! $this->openedStateUnchanged($actual, $expected)) {
            throw new RuntimeException("MHC-08 K3 cleanup detected unexpected {$kind} state drift.");
        }
    }

    /** @param array<string,mixed> $actual @param array<string,mixed> $expected */
    private function openedStateUnchanged(array $actual, array $expected): bool
    {
        $actualComparable = array_diff_key($actual, array_flip(self::SAFE_TELEMETRY_COLUMNS));
        $expectedComparable = array_diff_key($expected, array_flip(self::SAFE_TELEMETRY_COLUMNS));

        return $this->hashSnapshot($actualComparable) === $this->hashSnapshot($expectedComparable);
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private function filterColumns(string $table, array $values): array
    {
        $columns = array_flip(Schema::getColumnListing($table));
        unset($values['id']);

        return array_intersect_key($values, $columns);
    }

    /** @param array<string,mixed> $snapshot */
    private function hashSnapshot(array $snapshot): string
    {
        ksort($snapshot);

        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function clearCaches(): void
    {
        foreach (['llm_instances_all', 'llm_instances_healthy', 'external_api_providers'] as $key) {
            Cache::forget($key);
        }
    }
}
