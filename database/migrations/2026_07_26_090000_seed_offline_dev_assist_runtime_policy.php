<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'ai', 'config_key' => 'offline_dev_assist_runtime_policy'],
            [
                'config_value' => json_encode([
                    'version' => 1,
                    'default_mode' => 'home_lan',
                    'model_role' => 'standard',
                    'modes' => [
                        'home_lan' => [
                            'model_role' => 'standard',
                        ],
                        'away' => [
                            'model_role' => 'standard',
                            'allowed_ollama_instance_ids' => ['ollama_host_only'],
                        ],
                    ],
                    'required_model_capability_groups' => [
                        ['tool_calling', 'tool_use', 'tools'],
                    ],
                ], JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Table-owned model role and capability gate for the local-only native offline development assistant.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'genealogy', 'config_key' => 'offline_gedcom_snapshot_policy'],
            [
                'config_value' => json_encode([
                    'version' => 1,
                    'trees' => [
                        '4' => [
                            'directory' => 'storage/app/imports/genealogy',
                            'selection' => 'single_gedcom_only',
                        ],
                    ],
                ], JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Table-owned, fail-closed local GEDCOM snapshot mapping for read-only off-grid Genea tools.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        Cache::forget('syscfg:ai.offline_dev_assist_runtime_policy');
        Cache::forget('syscfg:genealogy.offline_gedcom_snapshot_policy');
    }

    public function down(): void
    {
        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')
                ->where('section', 'ai')
                ->where('config_key', 'offline_dev_assist_runtime_policy')
                ->delete();
            DB::table('system_configs')
                ->where('section', 'genealogy')
                ->where('config_key', 'offline_gedcom_snapshot_policy')
                ->delete();
        }

        Cache::forget('syscfg:ai.offline_dev_assist_runtime_policy');
        Cache::forget('syscfg:genealogy.offline_gedcom_snapshot_policy');
    }
};
