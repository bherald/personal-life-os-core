<?php

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
            'section' => 'guardrails',
            'config_key' => 'confirmation_ttl_seconds',
            'config_value' => '300',
            'data_type' => 'int',
            'description' => 'Lifetime of a pending guardrail confirmation; approvals are single-use and expire closed.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', 'guardrails')
            ->where('config_key', 'confirmation_ttl_seconds')
            ->where('config_value', '300')
            ->delete();
    }
};
