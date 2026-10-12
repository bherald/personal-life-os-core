<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('system_configs')->where('section', 'routing')->where('config_key', 'internet_access')->exists()) {
            DB::table('system_configs')->insert(['section' => 'routing', 'config_key' => 'internet_access',
                'config_value' => 'enabled', 'data_type' => 'string',
                'description' => 'Internet tool/MCP access: enabled or disabled. Independent from routing.offline_mode cloud inference switch; profile, network, and approval rules still apply.',
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Keep operator-owned routing preferences on code rollback.
    }
};
