<?php

namespace Tests\Unit\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CacheClearCustodyGuardTest extends TestCase
{
    public function test_default_global_clear_stops_before_resolving_or_flushing_a_store(): void
    {
        $this->assertClearHeld([]);
    }

    public function test_explicit_store_or_tag_cannot_bypass_the_global_clear_guard(): void
    {
        $this->assertClearHeld(['store' => 'array', '--tags' => 'synthetic']);
    }

    public function test_optimize_clear_cannot_invoke_global_flushing_indirectly(): void
    {
        $this->assertClearHeld(['--except' => 'config,compiled,events,routes,views'], 'optimize:clear');
    }

    private function assertClearHeld(array $arguments, string $command = 'cache:clear'): void
    {
        $store = Cache::store('array');
        $store->put('synthetic-custody', 'retained-owner', 60);
        Cache::shouldReceive('driver')->andReturn($store);
        Cache::shouldReceive('store')->never();
        Cache::shouldReceive('flush')->never();

        try {
            Artisan::call($command, $arguments);
            $this->fail('Global cache clearing must retain execution custody.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Global cache clearing is held to preserve execution custody. Use ops:release-cache-invalidate instead.', $error->getMessage());
        }

        $this->assertSame('retained-owner', $store->get('synthetic-custody'));
    }
}
