<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DESCRIPTION = 'Optional CLI connection restriction. Online adds no CLI destination restriction; existing PLOS rules govern model and tool access. Explicit table, session and saved operator restrictions remain authoritative.';

    public function up(): void
    {
        DB::table('system_configs')->insertOrIgnore(['section' => 'cli', 'config_key' => 'network',
            'config_value' => 'online', 'data_type' => 'string', 'description' => self::DESCRIPTION,
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('system_configs')->where('section', 'cli')->where('config_key', 'network')
            ->where('config_value', 'online')->where('data_type', 'string')
            ->where('description', self::DESCRIPTION)->delete();
    }
};
