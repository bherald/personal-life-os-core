<?php

use App\Services\UnifiedCli\CliFrameworkServicePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Local vision permits 180 seconds; scoped comparison has taken 198 seconds. Keep bounded headroom for worker startup and receipt publication.
    private const TOOL = 'file_local_face_comparison_execute';

    private const TIMEOUT_MS = 300000;

    public function up(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('Local face comparison deadline requires the existing framework service policy.');
            }
            $policy = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            CliFrameworkServicePolicy::validate($policy);
            $registration = DB::table('agent_tool_registry')->where('name', self::TOOL)->first();
            if ($registration === null) {
                throw new RuntimeException('Local face comparison deadline requires its registered tool.');
            }
            if ((int) $registration->enabled === 1) {
                $rule = $policy['tools'][self::TOOL] ?? null;
                if (! is_array($rule) || ($rule['target'] ?? null) !== 'local'
                    || ($rule['tree_scoped'] ?? null) !== false || ($rule['references'] ?? null) !== []) {
                    throw new RuntimeException('Local face comparison deadline requires its exact registered local execution rule.');
                }
                // Preserve an existing operator-owned timeout.
                $policy['tools'][self::TOOL]['timeout_ms'] ??= self::TIMEOUT_MS;
                DB::table('system_configs')->where('id', $row->id)->update([
                    'config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $policy = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            CliFrameworkServicePolicy::validate($policy);
            $rule = $policy['tools'][self::TOOL] ?? null;
            if (is_array($rule) && ($rule['target'] ?? null) === 'local'
                && ($rule['tree_scoped'] ?? null) === false && ($rule['references'] ?? null) === []
                && ($rule['timeout_ms'] ?? null) === self::TIMEOUT_MS) {
                unset($policy['tools'][self::TOOL]['timeout_ms']);
                DB::table('system_configs')->where('id', $row->id)->update([
                    'config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
                ]);
            }
        });
    }
};
