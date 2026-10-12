<?php

namespace Tests\Unit\Services;

use App\Services\OfflinePolicyService;
use App\Services\SystemConfigService;
use App\Services\UnifiedCli\CliConfiguration;
use App\Services\UnifiedCli\CliStateStore;
use App\Services\UnifiedCli\CliSystemConfigService;
use App\Services\UnifiedCli\RegistrySnapshotValidator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class UnifiedCliSystemConfigTest extends TestCase
{
    public function test_existing_offline_policy_observes_cli_changes_without_shared_database_or_cache(): void
    {
        DB::shouldReceive('select', 'selectOne', 'table', 'insert', 'update')->never();
        Cache::shouldReceive('get', 'put', 'forget', 'flush')->never();
        $store = new CliStateStore(':memory:');
        $generation = $store->activate($this->snapshot());
        $session = $store->createSession(['generation' => $generation, 'overrides' => []]);
        $adapter = new CliSystemConfigService($store, $session['id']);
        $this->app->instance(SystemConfigService::class, $adapter);
        $policy = new OfflinePolicyService;
        $this->assertSame('offline_review', $policy->activeProfile());
        $this->assertTrue($policy->evaluateOperation('file_read', ['_audit' => false])->allowed);
        $this->assertFalse($policy->evaluateOperation('file_edit', ['_audit' => false])->allowed);

        $session = (new CliConfiguration($store))->set($session, 'routing.active_profile', 'offline_dev_assist');
        $this->assertSame('offline_dev_assist', $policy->activeProfile());
        $this->assertTrue($policy->evaluateOperation('file_edit', ['_audit' => false])->allowed);
        $session = (new CliConfiguration($store))->set($session, 'routing.active_profile', 'offline_review');
        $this->assertFalse($policy->evaluateOperation('file_edit', ['_audit' => false])->allowed);
        $adapter->forget('routing.active_profile');
        $adapter->flushCache();
        $adapter->flushCache(strict: true);
        $adapter->flushCache(strict: false);
        $this->assertSame('offline_review', $adapter->get('routing.active_profile'));
    }

    public function test_saved_defaults_and_explicit_null_keep_their_normal_precedence(): void
    {
        $store = new CliStateStore(':memory:');
        $generation = $store->activate($this->snapshot());
        $store->saveDefault('routing.active_profile', 'offline_dev_assist');
        $session = $store->createSession(['generation' => $generation, 'overrides' => ['fixture.optional' => null]]);
        $adapter = new CliSystemConfigService($store, $session['id']);
        $this->assertSame('offline_dev_assist', $adapter->get('routing.active_profile'));
        $this->assertNull($adapter->get('fixture.optional', 'replacement'));
        $this->assertSame(42, $adapter->getInt('fixture.missing', 42));
        $this->expectExceptionMessage('cannot change CLI policy');
        $adapter->set('routing.active_profile', 'default');
    }

    public function test_missing_generation_cannot_fall_through_to_production_settings(): void
    {
        $store = new CliStateStore(':memory:');
        $session = $store->createSession([]);
        $this->expectException(RuntimeException::class);
        (new CliSystemConfigService($store, $session['id']))->get('routing.active_profile', 'default');
    }

    public function test_unknown_cli_profile_is_rejected_before_the_shared_permissive_default(): void
    {
        $store = new CliStateStore(':memory:');
        $generation = $store->activate($this->snapshot());
        $session = $store->createSession(['generation' => $generation,
            'overrides' => ['routing.active_profile' => 'unknown-profile']]);
        $this->expectExceptionMessage('not defined in the PLOS policy');
        (new CliSystemConfigService($store, $session['id']))->get('routing.active_profile');
    }

    private function snapshot(): array
    {
        $tables = array_fill_keys(RegistrySnapshotValidator::REQUIRED_TABLES, []);
        $tables['system_configs'] = [['section' => 'routing', 'config_key' => 'active_profile',
            'config_value' => 'offline_review', 'data_type' => 'string']];

        return ['schema' => RegistrySnapshotValidator::SCHEMA, 'source' => 'fixture',
            'exported_at' => '2026-09-07T00:00:00Z', 'tables' => $tables];
    }
}
