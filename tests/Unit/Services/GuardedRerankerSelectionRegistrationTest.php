<?php

namespace Tests\Unit\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GuardedRerankerSelectionRegistrationTest extends TestCase
{
    private string $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = DB::getDefaultConnection();
        config(['database.connections.guarded_reranker_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'cache.default' => 'array']);
        DB::setDefaultConnection('guarded_reranker_test');
        Schema::create('system_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('section');
            $table->string('config_key');
            $table->string('data_type');
            $table->text('config_value');
            $table->text('description');
            $table->timestamps();
            $table->unique(['section', 'config_key']);
        });
    }

    protected function tearDown(): void
    {
        DB::purge('guarded_reranker_test');
        DB::setDefaultConnection($this->previous);
        parent::tearDown();
    }

    public function test_public_registration_has_no_model_or_host_choice_and_stays_disabled(): void
    {
        $migration = require base_path('database/migrations/2026_10_14_210000_register_guarded_reranker_selection.php');
        $migration->up();
        $policy = json_decode(DB::table('system_configs')->value('config_value'), true);
        $this->assertFalse($policy['enabled']);
        $this->assertSame('', $policy['instance_id']);
        $this->assertSame('', $policy['model']);
    }

    public function test_registration_preserves_an_existing_operator_choice(): void
    {
        $migration = require base_path('database/migrations/2026_10_14_210000_register_guarded_reranker_selection.php');
        $migration->up();
        $choice = ['schema' => 'plos.reranker_controller_policy.v1', 'enabled' => true,
            'instance_id' => 'fixture-provider', 'model' => 'fixture-model'];
        DB::table('system_configs')->update(['config_value' => json_encode($choice)]);
        $migration->up();
        $this->assertSame($choice, json_decode(DB::table('system_configs')->value('config_value'), true));
        $this->assertSame(1, DB::table('system_configs')->count());
    }
}
