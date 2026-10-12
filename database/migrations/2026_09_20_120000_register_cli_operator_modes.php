<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_TARGET_DESCRIPTION = 'Default data target for production-specific CLI workflows: prod (.252). Explicit session or saved cli.target overrides take precedence; local files do not require a data connection.';

    private const TARGET_DESCRIPTION = 'Default workflow data target is this PLOS installation (local). Explicit saved/session target choices remain authoritative; model hosts never choose the data target.';

    private const MODE_DESCRIPTION = 'CLI mode restrictions: chat, read-only, coding, operator or autonomous. Existing skill permissions, sandbox, action approvals and agent budgets remain enforced.';

    public function up(): void
    {
        DB::table('system_configs')->insertOrIgnore(['section' => 'cli', 'config_key' => 'operator_mode',
            'config_value' => 'operator', 'data_type' => 'string', 'description' => self::MODE_DESCRIPTION,
            'created_at' => now(), 'updated_at' => now()]);
        // Replace only our known historical seed. Preserve operator customization.
        DB::table('system_configs')->where('section', 'cli')->where('config_key', 'workflow_target')
            ->where('config_value', 'prod')->where('description', self::OLD_TARGET_DESCRIPTION)
            ->update(['config_value' => 'local', 'description' => self::TARGET_DESCRIPTION, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('system_configs')->where('section', 'cli')->where('config_key', 'operator_mode')
            ->where('config_value', 'operator')->where('description', self::MODE_DESCRIPTION)->delete();
        DB::table('system_configs')->where('section', 'cli')->where('config_key', 'workflow_target')
            ->where('config_value', 'local')->where('description', self::TARGET_DESCRIPTION)
            ->update(['config_value' => 'prod', 'description' => self::OLD_TARGET_DESCRIPTION, 'updated_at' => now()]);
    }
};
