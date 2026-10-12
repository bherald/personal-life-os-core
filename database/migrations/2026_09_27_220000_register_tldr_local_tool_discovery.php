<?php

use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function definitions(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../resources/tldr-tools/os-definitions.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function up(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'os_tools')->where('config_key', 'definitions')->lockForUpdate()->first();
            $current = $row ? json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR) : [];
            // Discovery hints never replace operator definitions or grant execution authority.
            $merged = $current + $this->definitions();
            if ($row) {
                DB::table('system_configs')->where('id', $row->id)->update(['config_value' => json_encode($merged, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            } else {
                DB::table('system_configs')->insert(['section' => 'os_tools', 'config_key' => 'definitions', 'data_type' => 'json', 'config_value' => json_encode($merged, JSON_THROW_ON_ERROR), 'description' => 'Optional local tool discovery; no execution permissions.', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        app(SystemConfigService::class)->forget('os_tools.definitions');
    }

    public function down(): void
    {
        // Preserve discovered/edited definitions and installed tools on code rollback.
    }
};
