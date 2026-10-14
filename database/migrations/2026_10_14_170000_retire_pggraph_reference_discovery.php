<?php

use App\Services\SkillLoaderService;
use App\Services\SkillVersionService;
use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'os_tools')->where('config_key', 'definitions')->lockForUpdate()->first();
            if ($row) {
                $definitions = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
                unset($definitions['plos-pggraph-query']);
                if (($definitions['docker']['purpose'] ?? null) === 'Observe the installed container client used by the optional local pgGraph query wrapper. Discovery grants no container execution permissions.') {
                    $definitions['docker']['purpose'] = 'Observe the installed container client. Discovery grants no container execution permissions.';
                }
                DB::table('system_configs')->where('id', $row->id)->update([
                    'config_value' => json_encode($definitions, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            }
            $skill = app(SkillLoaderService::class)->loadSkillSnapshot('offline-assistant');
            if ($skill === null) {
                throw new RuntimeException('Missing offline-assistant skill snapshot');
            }
            app(SkillVersionService::class)->trackVersion('offline-assistant', $skill['frontmatter'], $skill['body'], $skill['full_content']);
        });
        app(SystemConfigService::class)->forget('os_tools.definitions');
    }

    public function down(): void
    {
        // Preserve operator retirement; rollback never recreates the container or current references.
    }
};
