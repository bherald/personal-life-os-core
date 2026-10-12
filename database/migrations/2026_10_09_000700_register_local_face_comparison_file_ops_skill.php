<?php

use App\Services\SkillLoaderService;
use App\Services\SkillVersionService;
use App\Services\UnifiedCli\CliFrameworkServicePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const STATE_KEY = 'local_face_comparison_file_ops_skill_20261009';

    private const SERVICE = 'App\\Services\\PhotoFaceComparisonAdvisoryService';

    private const TOOLS = [
        'file_local_face_comparison_plan' => [
            'method' => 'planLocalFaceComparison', 'risk_level' => 'read',
            'requires_confirmation' => 0, 'permissions' => ['file:read'],
        ],
        'file_local_face_comparison_execute' => [
            'method' => 'executeLocalFaceComparison', 'risk_level' => 'write',
            'requires_confirmation' => 1, 'permissions' => ['file:write'],
        ],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $skill = app(SkillLoaderService::class)->loadSkillSnapshot('file-ops');
            if ($skill === null || ($skill['frontmatter']['name'] ?? null) !== 'file-ops'
                || ! in_array($skill['frontmatter']['version'] ?? null, ['1.0.3', '1.0.4', '1.0.5', '1.0.6'], true)
                || ! in_array('file:read', $skill['frontmatter']['permissions'] ?? [], true)
                || ! in_array('file:write', $skill['frontmatter']['permissions'] ?? [], true)) {
                throw new RuntimeException('Reviewed file-ops successor does not expose the local comparison contract.');
            }
            $declared = SkillLoaderService::declaredToolNames($skill['frontmatter']);
            $comparisonTools = array_values(array_filter($declared, fn (string $name): bool => str_starts_with($name, 'file_local_face_comparison_')));
            $expectedTools = array_keys(self::TOOLS);
            sort($comparisonTools);
            sort($expectedTools);
            if ($comparisonTools !== $expectedTools || ! is_string($skill['content_hash'] ?? null)
                || ! preg_match('/\A[a-f0-9]{64}\z/', $skill['content_hash'])) {
                throw new RuntimeException('Reviewed file-ops skill does not declare exactly the existing local comparison actions.');
            }

            $registrations = $this->assertRegisteredTools();
            $this->assertFrameworkBindings($registrations);

            $stateQuery = DB::table('system_configs')->where('section', 'cli')->where('config_key', self::STATE_KEY)->lockForUpdate();
            $stateRow = $stateQuery->first();
            $active = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('is_active', 1)
                ->lockForUpdate()->limit(2)->get(['id', 'content_hash']);
            if ($active->count() > 1) {
                throw new RuntimeException('Local comparison skill requires one active file-ops snapshot.');
            }
            if ($stateRow !== null) {
                $state = json_decode((string) $stateRow->config_value, true, flags: JSON_THROW_ON_ERROR);
                $previous = is_array($state) ? DB::table('skill_versions')->where('skill_name', 'file-ops')
                    ->where('id', $state['previous_id'] ?? 0)->lockForUpdate()->first(['id', 'content_hash']) : null;
                if (! is_array($state) || ($state['installed_content_hash'] ?? null) !== $skill['content_hash']
                    || $active->count() !== 1 || (int) $active[0]->id !== (int) ($state['installed_id'] ?? 0)
                    || $active[0]->content_hash !== $skill['content_hash'] || ! $previous
                    || $previous->content_hash !== ($state['previous_content_hash'] ?? null)
                    || ! is_bool($state['activated_by_migration'] ?? null)
                    || ($state['activated_by_migration'] !== ((int) $previous->id !== (int) $active[0]->id))) {
                    throw new RuntimeException('Local comparison skill registration state conflicts with current source.');
                }

                return;
            }
            if ($active->count() !== 1) {
                throw new RuntimeException('Local comparison skill requires one active prior file-ops snapshot.');
            }

            $previousId = (int) $active[0]->id;
            $previousHash = (string) $active[0]->content_hash;
            $activatedByMigration = ! hash_equals($previousHash, $skill['content_hash']);
            if ($activatedByMigration) {
                app(SkillVersionService::class)->trackVersion('file-ops', $skill['frontmatter'], $skill['body'], $skill['full_content']);
            }
            $installed = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('is_active', 1)
                ->lockForUpdate()->limit(2)->get(['id', 'content_hash']);
            if ($installed->count() !== 1 || $installed[0]->content_hash !== $skill['content_hash']) {
                throw new RuntimeException('Local comparison file-ops skill snapshot was not activated exactly.');
            }

            DB::table('system_configs')->insert([
                'section' => 'cli', 'config_key' => self::STATE_KEY,
                'config_value' => json_encode([
                    'previous_id' => $previousId, 'previous_content_hash' => $previousHash,
                    'installed_id' => (int) $installed[0]->id, 'installed_content_hash' => $skill['content_hash'],
                    'activated_by_migration' => $activatedByMigration,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'data_type' => 'json', 'description' => 'Rollback ownership for local face comparison file-ops skill access.',
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
            if (! is_array($state) || ! is_bool($state['activated_by_migration'] ?? null)) {
                throw new RuntimeException('Local comparison file-ops skill rollback state is invalid.');
            }
            $active = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('is_active', 1)
                ->lockForUpdate()->limit(2)->get(['id', 'content_hash']);
            $previous = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('id', $state['previous_id'] ?? 0)
                ->lockForUpdate()->first(['id', 'content_hash']);
            if ($active->count() !== 1 || (int) $active[0]->id !== (int) ($state['installed_id'] ?? 0)
                || $active[0]->content_hash !== ($state['installed_content_hash'] ?? null)
                || ! $previous || $previous->content_hash !== ($state['previous_content_hash'] ?? null)
                || ($state['activated_by_migration'] !== ((int) $previous->id !== (int) ($active[0]->id ?? 0)))) {
                throw new RuntimeException('file-ops skill changed since local comparison registration; retain access and reconcile rollback.');
            }
            if ($state['activated_by_migration']) {
                DB::table('skill_versions')->where('skill_name', 'file-ops')->where('is_active', 1)->update(['is_active' => 0]);
                DB::table('skill_versions')->where('id', $previous->id)->where('skill_name', 'file-ops')
                    ->where('content_hash', $previous->content_hash)->update(['is_active' => 1]);
            }
            DB::table('system_configs')->where('id', $stateRow->id)->delete();
        });
    }

    private function assertRegisteredTools(): array
    {
        $rows = [];
        foreach (self::TOOLS as $name => $expected) {
            $row = DB::table('agent_tool_registry')->where('name', $name)->first();
            if ($row === null || ! in_array((int) $row->enabled, [0, 1], true)
                || $row->service_class !== self::SERVICE || $row->method !== $expected['method']
                || $row->risk_level !== $expected['risk_level']
                || (int) $row->requires_confirmation !== $expected['requires_confirmation']
                || json_decode((string) $row->permissions, true) !== $expected['permissions']) {
                throw new RuntimeException('Local face comparison tool registration is missing or changed: '.$name.'.');
            }
            $rows[$name] = $row;
        }

        return $rows;
    }

    private function assertFrameworkBindings(array $registrations): void
    {
        $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')
            ->lockForUpdate()->first();
        if ($row === null) {
            throw new RuntimeException('Local face comparison requires the existing CLI framework service policy.');
        }
        $policy = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($policy)) {
            throw new RuntimeException('Local face comparison framework service policy is invalid.');
        }
        CliFrameworkServicePolicy::validate($policy);
        if (($policy['targets']['local'] ?? null) !== ['transport' => 'local']) {
            throw new RuntimeException('Local face comparison requires the existing local-only framework target.');
        }
        foreach ($registrations as $name => $registration) {
            if ((int) $registration->enabled !== 1) {
                continue;
            }
            $rule = $policy['tools'][$name] ?? null;
            if (! is_array($rule) || ($rule['target'] ?? null) !== 'local'
                || ($rule['tree_scoped'] ?? null) !== false || ($rule['references'] ?? null) !== []) {
                throw new RuntimeException('Local face comparison requires its exact local framework binding: '.$name.'.');
            }
            $allowedKeys = ['target', 'tree_scoped', 'references', 'timeout_ms'];
            if (array_diff(array_keys($rule), $allowedKeys) !== []) {
                throw new RuntimeException('Local face comparison framework binding contains an unsupported scope: '.$name.'.');
            }
            if ($name === 'file_local_face_comparison_execute' && ! isset($rule['timeout_ms'])) {
                throw new RuntimeException('Local face comparison execution deadline is missing.');
            }
        }
    }
};
