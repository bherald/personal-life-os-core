<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cyber_workflow_jobs') && ! Schema::hasColumn('cyber_workflow_jobs', 'capacity_wait_state')) {
            Schema::table('cyber_workflow_jobs', fn (Blueprint $table) => $table->text('capacity_wait_state')->nullable());
        }
        DB::table('system_configs')->insertOrIgnore([
            'section' => 'ai', 'config_key' => 'model_host_controller_capacity_wait_policy', 'data_type' => 'json',
            'config_value' => $this->value(),
            'description' => 'Disabled pre-workflow capacity waiting for exact managed model jobs. No inference retry or fallback.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_capacity_wait_policy')
            ->where('config_value', $this->value())->delete();
        // Keep original wait/dispatch evidence. An explicit retirement migration owns removal.
    }

    private function value(): string
    {
        return json_encode(['schema' => 'plos.model_host_controller.capacity_wait.v1', 'revision' => 'hybrid-capacity-wait-stage-1',
            'enabled' => false, 'maximum_wait_seconds' => 900, 'maximum_checks' => 10,
            'retry_delays_seconds' => [30, 60, 120]], JSON_THROW_ON_ERROR);
    }
};
