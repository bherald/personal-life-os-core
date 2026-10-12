<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DESCRIPTION = 'Supporting workflow vision model. Auto uses existing PLOS vision routing; instance::model selects an exact provider/model under current target rules without substitution. Independent of the text model; session overrides may optionally be saved locally.';

    public function up(): void
    {
        DB::table('system_configs')->insertOrIgnore(['section' => 'cli', 'config_key' => 'workflow_vision_model',
            'config_value' => 'auto', 'data_type' => 'string', 'description' => self::DESCRIPTION,
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('system_configs')->where('section', 'cli')->where('config_key', 'workflow_vision_model')
            ->where('config_value', 'auto')->where('data_type', 'string')
            ->where('description', self::DESCRIPTION)->delete();
    }
};
