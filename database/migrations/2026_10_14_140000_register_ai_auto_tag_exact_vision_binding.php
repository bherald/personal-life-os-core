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
            'section' => 'files', 'config_key' => 'ai_auto_tag_vision_model_selection',
            'config_value' => '', 'data_type' => 'string',
            'description' => 'Exact instance::model binding for local AI auto-tag vision. Empty holds work until operator configuration; no automatic or alternate route.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Retain operator-owned bindings and fail-closed registration on rollback.
    }
};
