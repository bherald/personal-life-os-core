<?php

use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TOOL = 'transcribe_media_handwriting';

    private function rule(): array
    {
        return ['target' => 'workflow', 'tree_scoped' => true,
            'references' => ['media_id' => ['table' => 'genealogy_media', 'id_column' => 'id', 'tree_column' => 'tree_id']]];
    }

    public function up(): void
    {
        $changed = DB::transaction(function (): bool {
            $tool = DB::table('agent_tool_registry')->where('name', self::TOOL)
                ->where('service_class', 'App\\Services\\Genealogy\\HtrTranscriptionService')
                ->where('method', 'transcribeGenealogyMedia')->first();
            if ($tool === null) {
                return false;
            }
            // This existing method persists transcription/status to genealogy_media.
            // Correct its legacy read classification without weakening custom rules.
            if ($tool->risk_level === 'read') {
                $permissions = json_decode($tool->permissions ?? '[]', true, flags: JSON_THROW_ON_ERROR);
                $permissions = array_values(array_unique([...$permissions, 'genealogy:write']));
                $updated = DB::table('agent_tool_registry')->where('name', self::TOOL)
                    ->where('service_class', $tool->service_class)->where('method', $tool->method)
                    ->where('risk_level', 'read')->where('requires_confirmation', $tool->requires_confirmation)
                    ->when($tool->permissions === null, fn ($query) => $query->whereNull('permissions'),
                        fn ($query) => $query->whereRaw('CAST(permissions AS CHAR) = ?', [$tool->permissions]))
                    ->update(['risk_level' => 'write', 'requires_confirmation' => true,
                        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                if ($updated !== 1) {
                    throw new RuntimeException('Media tool registration changed during migration; no execution binding was installed.');
                }
            }
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->first();
            if ($row === null) {
                return false;
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            if (array_key_exists(self::TOOL, $policy['tools'])) {
                return false;
            }
            $policy['tools'][self::TOOL] = $this->rule();

            return DB::table('system_configs')->where('id', $row->id)->where('config_value', $row->config_value)
                ->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]) === 1;
        });
        if ($changed) {
            app(SystemConfigService::class)->forget('cli.framework_services');
        }
    }

    public function down(): void
    {
        // Retain the correct write classification: rollback must not relabel
        // a known database write as a read. Remove only this default binding.
        $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->first();
        if ($row === null) {
            return;
        }
        $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
        if (($policy['tools'][self::TOOL] ?? null) !== $this->rule()) {
            return;
        }
        unset($policy['tools'][self::TOOL]);
        if (DB::table('system_configs')->where('id', $row->id)->where('config_value', $row->config_value)
            ->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]) === 1) {
            app(SystemConfigService::class)->forget('cli.framework_services');
        }
    }
};
