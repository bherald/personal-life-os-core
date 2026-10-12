<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['registry_discovery_policy' => config('file_registry.discovery.policy'),
            'registry_discovery_qualifications' => []] as $key => $value) {
            DB::table('system_configs')->insertOrIgnore([
                'section' => 'files', 'config_key' => $key,
                'config_value' => json_encode($value, JSON_THROW_ON_ERROR), 'data_type' => 'json',
                'description' => $key === 'registry_discovery_policy'
                    ? 'Bounded resumable registry discovery; existing operator policy retained.'
                    : 'Successful native fixture receipts bound to exact filesystem/runtime/helper; observations alone do not qualify.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Retain operator policy and runtime qualifications on rollback.
    }
};
