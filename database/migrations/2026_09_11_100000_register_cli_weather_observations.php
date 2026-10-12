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
            DB::table('agent_tool_registry')->insertOrIgnore([
                'name' => 'mcp_weather_current', 'service_class' => 'MCP_BRIDGE', 'method' => 'callTool',
                'description' => 'Get observed US weather from NWS for latitude and longitude, with station, source URL, timestamp and measurement units. No forecast or provider fallback.',
                'parameters' => json_encode(['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['latitude' => ['type' => 'number', 'minimum' => -90, 'maximum' => 90],
                        'longitude' => ['type' => 'number', 'minimum' => -180, 'maximum' => 180]],
                    'required' => ['latitude', 'longitude']], JSON_THROW_ON_ERROR),
                'permissions' => '["system:read"]', 'risk_level' => 'read', 'category' => 'search',
                'requires_confirmation' => false, 'enabled' => true, 'source' => 'manual',
                'mcp_server' => 'web-research', 'mcp_tool' => 'weather_current',
                'privacy_class' => 'external_no_private', 'allows_private_data' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'workflow_services')->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('Existing CLI workflow service bindings are required.');
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            if (! array_key_exists('web-research', $policy['servers'] ?? [])) {
                $policy['servers']['web-research'] = ['exclude_tools' => ['web_search', 'web_search_parallel',
                    'discover_sources', 'get_engine_status', 'scrape_page', 'add_source'], 'tree_scoped' => false];
                DB::table('system_configs')->where('id', $row->id)->update([
                    'config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
                ]);
            }
            $skill = app(SkillLoaderService::class)->loadSkillSnapshot('offline-weather');
            if ($skill === null) {
                throw new RuntimeException('The deployed weather skill is unavailable.');
            }
            app(SkillVersionService::class)->trackVersion('offline-weather', $skill['frontmatter'], $skill['body'], $skill['full_content']);
        });
        app(SystemConfigService::class)->forget('cli.workflow_services');
    }

    public function down(): void
    {
        // Retain operator registrations and skill history. Rollback selects the
        // previous CLI skill and disables this tool explicitly before code rollback.
    }
};
