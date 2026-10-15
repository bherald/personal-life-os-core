<?php

namespace Tests\Unit\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ColibriTaskLogonRegistrationTest extends TestCase
{
    private string $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = DB::getDefaultConnection();
        config(['database.connections.colibri_logon_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('colibri_logon_test');
        Schema::create('system_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('section');
            $table->string('config_key');
            $table->text('config_value');
        });
    }

    protected function tearDown(): void
    {
        DB::purge('colibri_logon_test');
        DB::setDefaultConnection($this->previous);
        parent::tearDown();
    }

    public function test_registration_only_declares_previous_interactive_guard_and_preserves_choices(): void
    {
        $before = ['enabled' => true, 'targets' => [
            'legacy' => ['lifecycle_adapter' => ['adapter_type' => 'colibri_gateway'], 'operator_metadata' => 'retain'],
            'declared' => ['lifecycle_adapter' => ['adapter_type' => 'colibri_gateway', 'task_logon_type' => 'S4U']],
            'other' => ['lifecycle_adapter' => ['adapter_type' => 'pinned_llama']],
        ]];
        DB::table('system_configs')->insert(['section' => 'ai', 'config_key' => 'model_host_controller_policy',
            'config_value' => json_encode($before)]);
        $migration = require base_path('database/migrations/2026_10_14_235000_register_colibri_task_logon_binding.php');
        $migration->up();
        $expected = $before;
        $expected['targets']['legacy']['lifecycle_adapter']['task_logon_type'] = 'Interactive';
        $this->assertSame($expected, json_decode(DB::table('system_configs')->value('config_value'), true));
        $migration->up();
        $migration->down();
        $this->assertSame($expected, json_decode(DB::table('system_configs')->value('config_value'), true));
    }

    public function test_unconfigured_public_install_creates_no_host_or_policy(): void
    {
        (require base_path('database/migrations/2026_10_14_235000_register_colibri_task_logon_binding.php'))->up();
        $this->assertSame(0, DB::table('system_configs')->count());
    }
}
