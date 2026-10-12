<?php

use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('production')
            || DB::table('migrations')->where('migration', pathinfo(__FILE__, PATHINFO_FILENAME))->exists()) {
            return;
        }
        DB::transaction(function (): void {
            $active = DB::table('system_configs')->where('section', 'routing')->where('config_key', 'active_profile')->lockForUpdate()->first();
            $row = DB::table('system_configs')->where('section', 'routing')->where('config_key', 'profile.resilient_local_first')->lockForUpdate()->first();
            $activeName = $active ? (json_decode($active->config_value, true) ?? $active->config_value) : null;
            if ($activeName !== 'resilient_local_first' || ! $row) {
                throw new RuntimeException('The approved active local routing profile differs or is missing.');
            }
            $profile = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            $afterCapabilities = ['text', 'tools', 'vision', 'embedding'];
            if (($profile['allowed_capabilities'] ?? null) === $afterCapabilities) {
                return;
            }
            if (($profile['allowed_capabilities'] ?? null) !== ['text', 'tools']
                || ($profile['allowed_instance_types'] ?? null) !== ['ollama', 'local_llm', 'codex_cli']
                || ($profile['allowed_provider_classes'] ?? null) !== ['local_llm', 'cloud_sensitive_safe']
                || ($profile['llm_routing']['allowed_external_fallback_instance_ids'] ?? null) !== ['codex_exec']) {
                throw new RuntimeException('The approved local profile predecessor or provider privacy gates differ.');
            }
            $profile['allowed_capabilities'] = $afterCapabilities;
            $updated = DB::table('system_configs')->where('id', $row->id)->where('config_value', $row->config_value)->update([
                'config_value' => json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                throw new RuntimeException('The local routing profile changed during registration.');
            }
        });
        app(SystemConfigService::class)->forget('routing.profile.resilient_local_first');
    }

    public function down(): void
    {
        // Preserve later operator decisions. Rollback needs an exactly reviewed current profile, never a stale overwrite.
    }
};
