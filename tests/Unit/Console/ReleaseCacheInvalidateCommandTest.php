<?php

namespace Tests\Unit\Console;

use App\Services\SystemConfigService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReleaseCacheInvalidateCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_actual_invalidation_preserves_custody_response_and_circuit_state(): void
    {
        Cache::setDefaultDriver('array');
        $this->app->instance(SystemConfigService::class, new SystemConfigService);
        DB::shouldReceive('select')->once()->with('SELECT section, config_key FROM system_configs')->andReturn([(object) ['section' => 'routing', 'config_key' => 'offline_mode']]);
        $stale = ['syscfg:routing.offline_mode', 'llm_instances_all', 'llm_instances_healthy', 'compute_instances_all', 'compute_instances_healthy', 'external_api_providers'];
        $retained = ['ollama_busy_lock', 'whisper_gpu_lock', 'compute_gpu_lock:fixture', 'compute_gpu_lock:fixture:owned', 'ai_circuit_stats_fixture', 'private_response_fixture', 'llm_slots:fixture', 'llm_busy_lock:fixture', 'llm_slots:fixture_lock'];
        foreach (array_merge($stale, $retained) as $key) {
            Cache::put($key, ['retained' => $key], 60);
        }
        $mutex = Cache::lock('fixture_atomic_custody', 60);
        $this->assertTrue($mutex->get());
        $this->assertSame(0, Artisan::call('ops:release-cache-invalidate', ['--json' => true]));
        $receipt = json_decode(Artisan::output(), true);
        $this->assertSame(['schema' => 'plos.release_cache_invalidation.v1', 'success' => true, 'reason_code' => null], $receipt);
        foreach ($stale as $key) {
            $this->assertFalse(Cache::has($key), $key);
        }
        foreach ($retained as $key) {
            $this->assertSame(['retained' => $key], Cache::get($key), $key);
        }
        $this->assertTrue($mutex->isOwnedByCurrentProcess());
        $this->assertFalse(Cache::lock('fixture_atomic_custody', 60, 'foreign-owner')->get());
        $mutex->release();
    }

    public static function retainedInventoryKeys(): array
    {
        return array_map(static fn ($key) => [$key], ['syscfg:fixture.policy', 'llm_instances_all',
            'llm_instances_healthy', 'compute_instances_all', 'compute_instances_healthy', 'external_api_providers']);
    }

    #[DataProvider('retainedInventoryKeys')]
    public function test_silently_retained_inventory_is_failure_and_keeps_other_owners(string $retainedKey): void
    {
        $store = new class($retainedKey) extends ArrayStore
        {
            public function __construct(private string $retainedKey)
            {
                parent::__construct();
            }

            public function forget($key)
            {
                return $key === $this->retainedKey ? false : parent::forget($key);
            }
        };
        $repository = new Repository($store);
        $repository->put($retainedKey, ['stale' => true], 60);
        $repository->put('llm_slots:fixture', ['request' => ['owner' => 'original-owner', 'status' => 'unknown']], 60);
        $repository->put('semantic_cache_exact_fixture', 'retained-response', 60);
        config(['cache.stores.fault_fixture' => ['driver' => 'fault_fixture']]);
        Cache::extend('fault_fixture', fn () => $repository);
        Cache::setDefaultDriver('fault_fixture');
        $this->app->instance(SystemConfigService::class, new SystemConfigService);
        DB::shouldReceive('select')->once()->with('SELECT section, config_key FROM system_configs')
            ->andReturn([(object) ['section' => 'fixture', 'config_key' => 'policy']]);
        $this->assertSame(1, Artisan::call('ops:release-cache-invalidate', ['--json' => true]));
        $this->assertSame(['schema' => 'plos.release_cache_invalidation.v1', 'success' => false,
            'reason_code' => 'cache_invalidation_unavailable'], json_decode(Artisan::output(), true));
        $this->assertSame(['stale' => true], $repository->get($retainedKey));
        $this->assertSame(['request' => ['owner' => 'original-owner', 'status' => 'unknown']], $repository->get('llm_slots:fixture'));
        $this->assertSame('retained-response', $repository->get('semantic_cache_exact_fixture'));
    }

    public function test_database_read_uncertainty_is_fixed_failure_not_success(): void
    {
        $this->app->instance(SystemConfigService::class, new SystemConfigService);
        DB::shouldReceive('select')->with('SELECT section, config_key FROM system_configs')->once()->andThrow(new \RuntimeException('private database connection failure'));
        DB::shouldReceive('select')->andReturn([]);
        $this->assertSame(1, Artisan::call('ops:release-cache-invalidate', ['--json' => true]));
        $this->assertSame(['schema' => 'plos.release_cache_invalidation.v1', 'success' => false, 'reason_code' => 'cache_invalidation_unavailable'], json_decode(Artisan::output(), true));
        $this->assertStringNotContainsString('private database', Artisan::output());
    }

    public function test_cache_uncertainty_propagates_from_existing_configuration_helper(): void
    {
        DB::shouldReceive('select')->once()->andReturn([(object) ['section' => 'routing', 'config_key' => 'offline_mode']]);
        Cache::shouldReceive('forget')->once()->andThrow(new \RuntimeException('private redis failure'));
        Cache::shouldReceive('flush')->never();
        try {
            (new SystemConfigService)->flushCache(true);
            $this->fail('Strict invalidation swallowed cache failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Configuration cache invalidation unavailable.', $error->getMessage());
        }
    }

    public function test_nested_inventory_key_failure_cannot_report_success(): void
    {
        Cache::setDefaultDriver('array');
        $this->app->instance(SystemConfigService::class, new SystemConfigService);
        DB::shouldReceive('select')->once()->with('SELECT section, config_key FROM system_configs')->andReturn([]);
        $repository = new Repository(new ArrayStore);
        Cache::shouldReceive('driver')->andReturn($repository);
        Cache::shouldReceive('store')->andReturn($repository);
        Cache::shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('put')->andReturn(true);
        DB::shouldReceive('select')->andReturn([]);
        Cache::shouldReceive('forget')->once()->with('llm_instances_all')->andThrow(new \RuntimeException('private backend failure'));
        Cache::shouldReceive('flush')->never();
        $this->assertSame(1, Artisan::call('ops:release-cache-invalidate', ['--json' => true]));
        $this->assertSame(['schema' => 'plos.release_cache_invalidation.v1', 'success' => false, 'reason_code' => 'cache_invalidation_unavailable'], json_decode(Artisan::output(), true));
        $this->assertStringNotContainsString('private backend', Artisan::output());
    }

    public function test_existing_best_effort_configuration_invalidation_still_tolerates_failure(): void
    {
        DB::shouldReceive('select')->once()->andThrow(new \RuntimeException('synthetic unavailable'));
        (new SystemConfigService)->flushCache();
        $this->assertTrue(true);
    }
}
