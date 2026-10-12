<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const API = ['driver' => 'plos_mcp_api', 'base_url' => 'http://127.0.0.1:8000'];

    public function up(): void
    {
        $this->replaceDefault(['driver' => 'local'], self::API);
    }

    public function down(): void
    {
        $this->replaceDefault(self::API, ['driver' => 'local']);
    }

    private function replaceDefault(array $expected, array $replacement): void
    {
        $query = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'workflow_services')->where('data_type', 'json');
        $row = (clone $query)->first();
        if ($row === null) {
            return;
        }
        $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
        // Preserve custom targets, authentication references, server rules and
        // all other configuration. An explicit local driver remains supported
        // through the ordinary CLI session/saved override mechanism.
        if (($policy['targets']['local'] ?? null) != $expected) {
            return;
        }
        $policy['targets']['local'] = $replacement;
        $query->where('config_value', $row->config_value)->update([
            'config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
        ]);
    }
};
