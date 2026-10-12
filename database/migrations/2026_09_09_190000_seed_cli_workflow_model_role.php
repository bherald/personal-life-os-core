<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DESCRIPTION = 'Supporting workflow text-model role. Auto preserves each service choice; a registered role overrides nested AIService text calls for this operation only. Provider selection, admission and resource policies remain in the existing PLOS pipeline.';

    public function up(): void
    {
        DB::table('system_configs')->insertOrIgnore(['section' => 'cli', 'config_key' => 'workflow_model_role',
            'config_value' => 'auto', 'data_type' => 'string', 'description' => self::DESCRIPTION,
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('system_configs')->where('section', 'cli')->where('config_key', 'workflow_model_role')
            ->where('config_value', 'auto')->where('data_type', 'string')
            ->where('description', self::DESCRIPTION)->delete();
    }
};
