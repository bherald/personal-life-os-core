<?php

use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'os_tools')->where('config_key', 'definitions')->lockForUpdate()->first();
            if (! $row) {
                return;
            }
            $definitions = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            $defaults = json_decode(file_get_contents(__DIR__.'/../../resources/tldr-tools/os-definitions.json'), true, flags: JSON_THROW_ON_ERROR);
            $definitions += ['docker' => $defaults['docker']];
            foreach (['plos-archify' => 'node', 'plos-pdf' => 'java'] as $name => $dependency) {
                // Complete only our unchanged default wrapper; preserve operator replacements.
                if (($definitions[$name]['paths'] ?? null) === $defaults[$name]['paths']
                    && ($definitions[$name]['dependencies'] ?? null) === ['python3']) {
                    $definitions[$name]['dependencies'][] = $dependency;
                }
            }
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($definitions, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
        });
        app(SystemConfigService::class)->forget('os_tools.definitions');
    }

    public function down(): void
    {
        // Retain operator-owned discovery metadata; no execution authority was added.
    }
};
