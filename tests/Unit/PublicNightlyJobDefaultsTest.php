<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicNightlyJobDefaultsTest extends TestCase
{
    public function test_new_nightly_job_requires_operator_activation_without_updating_existing_jobs(): void
    {
        DB::shouldReceive('insert')->once()->withArgs(function (string $sql, array $bindings): bool {
            $this->assertStringContainsString('INSERT INTO scheduled_jobs', $sql);
            $this->assertSame('ops:nightly', $bindings[1]);
            $this->assertSame(0, $bindings[4], 'Public installs must not activate notification jobs.');

            return true;
        })->andReturnTrue();
        DB::shouldNotReceive('update');

        $migration = require base_path('database/migrations/2026_02_28_160002_add_nightly_ops_job.php');
        $migration->up();
    }
}
