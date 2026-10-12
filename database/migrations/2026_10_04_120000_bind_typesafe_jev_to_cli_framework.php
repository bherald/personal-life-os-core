<?php

use App\Services\SystemConfigService;
use App\Services\TypeSafeJevDecisionService;
use App\Services\UnifiedCli\CliFrameworkServicePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const RULE = ['target' => 'local', 'tree_scoped' => false, 'references' => []];

    public function up(): void
    {
        DB::transaction(function (): void {
            $tool = DB::table('agent_tool_registry')->where('name', 'typesafe_jev_decide')->first();
            if ($tool === null || $tool->service_class !== TypeSafeJevDecisionService::class
                || $tool->method !== 'evaluate') {
                throw new RuntimeException('The registered TypeSafe tool binding differs; no CLI route was added.');
            }
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')
                ->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('The CLI framework service configuration is unavailable.');
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            CliFrameworkServicePolicy::validate($policy);
            if (! isset($policy['targets']['local'])) {
                throw new RuntimeException('The local CLI framework target is unavailable.');
            }
            if (array_key_exists('typesafe_jev_decide', $policy['tools'])) {
                return; // Preserve an operator-owned execution rule.
            }
            $policy['tools']['typesafe_jev_decide'] = self::RULE;
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($policy, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
        });
        app(SystemConfigService::class)->forget('cli.framework_services');
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')
                ->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            if (($policy['tools']['typesafe_jev_decide'] ?? null) !== self::RULE) {
                return;
            }
            unset($policy['tools']['typesafe_jev_decide']);
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($policy, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
        });
        app(SystemConfigService::class)->forget('cli.framework_services');
    }
};
