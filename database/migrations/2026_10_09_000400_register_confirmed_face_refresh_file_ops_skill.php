<?php

use App\Services\SkillLoaderService;
use App\Services\SkillVersionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const STATE_KEY = 'confirmed_face_refresh_file_ops_skill_20261009';

    private const TOOLS = [
        'file_confirmed_face_vector_refresh_plan' => ['risk_level' => 'read', 'requires_confirmation' => 0, 'permissions' => ['file:read']],
        'file_confirmed_face_vector_refresh_execute' => ['risk_level' => 'write', 'requires_confirmation' => 1, 'permissions' => ['file:write']],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->assertRegisteredTools();
            $skill = app(SkillLoaderService::class)->loadSkillSnapshot('file-ops');
            if ($skill === null || ($skill['frontmatter']['name'] ?? null) !== 'file-ops'
                || ! in_array($skill['frontmatter']['version'] ?? null, ['1.0.1', '1.0.2', '1.0.3', '1.0.4', '1.0.5', '1.0.6'], true)
                || ! in_array('file:read', $skill['frontmatter']['permissions'] ?? [], true)
                || ! in_array('file:write', $skill['frontmatter']['permissions'] ?? [], true)
                || array_diff(array_keys(self::TOOLS), SkillLoaderService::declaredToolNames($skill['frontmatter'])) !== []) {
                throw new RuntimeException('Reviewed file-ops skill does not expose the confirmed face refresh contract.');
            }
            $stateRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', self::STATE_KEY)
                ->lockForUpdate()->first();
            if ($stateRow !== null) {
                $state = json_decode((string) $stateRow->config_value, true, flags: JSON_THROW_ON_ERROR);
                $active = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('is_active', 1)
                    ->lockForUpdate()->limit(2)->get(['id', 'content_hash']);
                if (! is_array($state) || ($state['installed_content_hash'] ?? null) !== $skill['content_hash']
                    || $active->count() !== 1 || (int) $active[0]->id !== (int) ($state['installed_id'] ?? 0)
                    || $active[0]->content_hash !== ($state['installed_content_hash'] ?? null)) {
                    throw new RuntimeException('Confirmed face refresh skill registration state conflicts with the current source.');
                }

                return;
            }
            $active = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('is_active', 1)
                ->lockForUpdate()->limit(2)->get(['id', 'content_hash']);
            if ($active->count() !== 1) {
                throw new RuntimeException('Confirmed face refresh requires one active prior file-ops skill version.');
            }
            app(SkillVersionService::class)->trackVersion('file-ops', $skill['frontmatter'], $skill['body'], $skill['full_content']);
            $installed = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('is_active', 1)
                ->limit(2)->get(['id', 'content_hash']);
            if ($installed->count() !== 1 || $installed[0]->content_hash !== $skill['content_hash']) {
                throw new RuntimeException('Confirmed face refresh skill snapshot was not activated exactly.');
            }
            DB::table('system_configs')->insert([
                'section' => 'cli', 'config_key' => self::STATE_KEY,
                'config_value' => json_encode(['previous_id' => (int) $active[0]->id,
                    'previous_content_hash' => $active[0]->content_hash,
                    'installed_id' => (int) $installed[0]->id, 'installed_content_hash' => $skill['content_hash']],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'data_type' => 'json', 'description' => 'Rollback ownership for confirmed face refresh file-ops skill access.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $stateRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', self::STATE_KEY)
                ->lockForUpdate()->first();
            if ($stateRow === null) {
                return;
            }
            $state = json_decode((string) $stateRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($state)) {
                throw new RuntimeException('file-ops refresh skill rollback state is invalid.');
            }
            $active = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('is_active', 1)
                ->lockForUpdate()->limit(2)->get(['id', 'content_hash']);
            $previous = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('id', $state['previous_id'] ?? 0)
                ->lockForUpdate()->first(['id', 'content_hash']);
            if ($active->count() !== 1
                || (int) $active[0]->id !== (int) ($state['installed_id'] ?? 0)
                || $active[0]->content_hash !== ($state['installed_content_hash'] ?? null)
                || ! $previous || $previous->content_hash !== ($state['previous_content_hash'] ?? null)) {
                throw new RuntimeException('file-ops skill changed since refresh registration; retain tool access and reconcile rollback.');
            }
            DB::table('skill_versions')->where('skill_name', 'file-ops')->where('is_active', 1)->update(['is_active' => 0]);
            DB::table('skill_versions')->where('id', $previous->id)->where('skill_name', 'file-ops')
                ->where('content_hash', $previous->content_hash)->update(['is_active' => 1]);
            DB::table('system_configs')->where('id', $stateRow->id)->delete();
        });
    }

    private function assertRegisteredTools(): void
    {
        foreach (self::TOOLS as $name => $expected) {
            $row = DB::table('agent_tool_registry')->where('name', $name)->first();
            if ($row === null || (int) $row->enabled !== 1 || $row->risk_level !== $expected['risk_level']
                || (int) $row->requires_confirmation !== $expected['requires_confirmation']
                || json_decode((string) $row->permissions, true, flags: JSON_THROW_ON_ERROR) !== $expected['permissions']) {
                throw new RuntimeException('Confirmed face refresh tool registration is missing or changed: '.$name);
            }
        }
    }
};
