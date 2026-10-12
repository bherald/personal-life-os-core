<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DESCRIPTION = 'Workflow compute host. Auto uses existing compute routing; an exact instance selection uses current target tables, capabilities, health and capacity without host substitution. Session overrides may optionally be saved locally.';

    public function up(): void
    {
        DB::table('system_configs')->insertOrIgnore(['section' => 'cli', 'config_key' => 'compute_instance',
            'config_value' => 'auto', 'data_type' => 'string', 'description' => self::DESCRIPTION,
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('system_configs')->where('section', 'cli')->where('config_key', 'compute_instance')
            ->where('config_value', 'auto')->where('data_type', 'string')
            ->where('description', self::DESCRIPTION)->delete();
    }
};
