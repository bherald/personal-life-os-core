<?php

use App\Services\UpstreamPrivatePreparationAdmissionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }
        DB::table('system_configs')->insertOrIgnore([
            'section' => 'ops', 'config_key' => UpstreamPrivatePreparationAdmissionService::POLICY_KEY,
            'config_value' => json_encode([
                'schema' => UpstreamPrivatePreparationAdmissionService::POLICY_SCHEMA,
                'enabled' => false,
                // These are preparation caps, not model/context qualification or route defaults.
                'budget_caps' => ['wall_seconds' => 900, 'input_tokens' => 32768,
                    'output_tokens' => 2048, 'changed_files' => 20, 'artifact_bytes' => 4194304],
            ], JSON_THROW_ON_ERROR),
            'data_type' => 'json', 'description' => 'Disabled upstream private admission; no worker, grants, schedules or execution authority.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Preserve owner grants and all attempt evidence. Code rollback must not
        // remove a no-replay receipt or re-enable preparation through defaults.
    }
};
