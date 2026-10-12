<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DESCRIPTION = 'Default data target for production-specific CLI workflows: prod (.252). Explicit session or saved cli.target overrides take precedence; local files do not require a data connection.';

    public function up(): void
    {
        DB::table('system_configs')->insertOrIgnore(['section' => 'cli', 'config_key' => 'workflow_target',
            'config_value' => 'prod', 'data_type' => 'string', 'description' => self::DESCRIPTION,
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('system_configs')->where('section', 'cli')->where('config_key', 'workflow_target')
            ->where('config_value', 'prod')->where('data_type', 'string')
            ->where('description', self::DESCRIPTION)->delete();
    }
};
