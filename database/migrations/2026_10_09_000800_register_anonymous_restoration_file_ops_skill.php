<?php

use App\Services\SkillLoaderService;
use App\Services\SkillVersionService;
use App\Services\UnifiedCli\CliFrameworkServicePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const STATE_KEY = 'anonymous_face_restoration_file_ops_skill_20261009';

    private const SERVICE = 'App\\Services\\FaceGenerationRestorationBatchService';

    private const PLAN = 'file_face_restoration_batch_plan';

    private const EXECUTE = 'file_face_restoration_batch_execute';

    private function tools(): array
    {
        $hash = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];
        $itemHash = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];
        $item = ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'asset_uuid' => ['type' => 'string', 'format' => 'uuid'],
            'file_registry_id' => ['type' => 'integer', 'minimum' => 1],
            'source_sha256' => $itemHash, 'binding_sha256' => $itemHash, 'generation_key' => $itemHash,
            'detector_version' => $itemHash, 'coverage_preview_detector_version' => $itemHash,
            'manifest_sha256' => $itemHash,
            'old_face_count' => ['type' => 'integer', 'minimum' => 1],
            'old_vector_count' => ['type' => 'integer', 'minimum' => 0],
            'preview_face_count' => ['type' => 'integer', 'minimum' => 1],
            'preflight_budget_seconds' => ['type' => 'integer', 'minimum' => 1],
            'retirement_budget_seconds' => ['type' => 'integer', 'minimum' => 1],
            'generation_budget_seconds' => ['type' => 'integer', 'minimum' => 1],
            'write_budget_seconds' => ['type' => 'integer', 'minimum' => 1],
            'total_budget_seconds' => ['type' => 'integer', 'minimum' => 1],
            'reconciliation_idempotency_key' => ['type' => 'string', 'format' => 'uuid'],
            'generation_idempotency_key' => ['type' => 'string', 'format' => 'uuid'],
        ], 'required' => ['asset_uuid', 'file_registry_id', 'source_sha256', 'binding_sha256', 'generation_key',
            'detector_version', 'coverage_preview_detector_version', 'manifest_sha256', 'old_face_count',
            'old_vector_count', 'preview_face_count', 'preflight_budget_seconds', 'retirement_budget_seconds',
            'generation_budget_seconds', 'write_budget_seconds', 'total_budget_seconds',
            'reconciliation_idempotency_key', 'generation_idempotency_key']];

        return [
            self::PLAN => [
                'method' => 'plan', 'risk_level' => 'read', 'requires_confirmation' => 0,
                'permissions' => ['file:read'],
                'parameters' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['asset_uuids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 4,
                        'items' => ['type' => 'string', 'format' => 'uuid']]], 'required' => ['asset_uuids']],
            ],
            self::EXECUTE => [
                'method' => 'execute', 'risk_level' => 'write', 'requires_confirmation' => 1,
                'permissions' => ['file:write'],
                'parameters' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['plan' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                        'schema' => ['type' => 'string', 'const' => 'plos.anonymous_face_restoration_batch.v1'],
                        'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 4, 'items' => $item],
                        'max_sources' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
                        'success' => ['type' => 'boolean', 'const' => true],
                        'eligible' => ['type' => 'boolean', 'const' => true],
                        'mode' => ['type' => 'string', 'const' => 'dry_run'],
                        'detector_run' => ['type' => 'boolean', 'const' => false],
                        'source_originals_mutated' => ['type' => 'boolean', 'const' => false],
                        'batch_plan_sha256' => $hash,
                    ], 'required' => ['schema', 'items', 'max_sources', 'success', 'eligible', 'mode',
                        'detector_run', 'source_originals_mutated', 'batch_plan_sha256']],
                    ], 'required' => ['plan']],
            ],
        ];
    }

    public function up(): void
    {
        DB::transaction(function (): void {
            $skill = app(SkillLoaderService::class)->loadSkillSnapshot('file-ops');
            if ($skill === null || ($skill['frontmatter']['name'] ?? null) !== 'file-ops'
                || ! in_array($skill['frontmatter']['version'] ?? null, ['1.0.4', '1.0.5', '1.0.6'], true)
                || ! in_array('file:read', $skill['frontmatter']['permissions'] ?? [], true)
                || ! in_array('file:write', $skill['frontmatter']['permissions'] ?? [], true)) {
                throw new RuntimeException('Reviewed file-ops successor is required for anonymous restoration access.');
            }
            $declared = SkillLoaderService::declaredToolNames($skill['frontmatter']);
            foreach (array_keys($this->tools()) as $name) {
                if (! in_array($name, $declared, true)) {
                    throw new RuntimeException('Reviewed file-ops skill does not declare the anonymous restoration actions.');
                }
            }
            $this->assertRegisteredTools();
            $this->assertFrameworkBindings();

            $stateQuery = DB::table('system_configs')->where('section', 'cli')->where('config_key', self::STATE_KEY)->lockForUpdate();
            $stateRow = $stateQuery->first();
            $active = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('is_active', 1)
                ->lockForUpdate()->limit(2)->get(['id', 'content_hash']);
            if ($active->count() !== 1 || ! is_string($skill['content_hash'] ?? null)
                || ! preg_match('/\A[a-f0-9]{64}\z/', $skill['content_hash'])) {
                throw new RuntimeException('Anonymous restoration requires one valid active file-ops snapshot.');
            }
            if ($stateRow !== null) {
                $state = json_decode((string) $stateRow->config_value, true, flags: JSON_THROW_ON_ERROR);
                $previous = is_array($state) ? DB::table('skill_versions')->where('skill_name', 'file-ops')
                    ->where('id', $state['previous_id'] ?? 0)->lockForUpdate()->first(['id', 'content_hash']) : null;
                if (! is_array($state) || ($state['installed_content_hash'] ?? null) !== $skill['content_hash']
                    || (int) $active[0]->id !== (int) ($state['installed_id'] ?? 0)
                    || $active[0]->content_hash !== $skill['content_hash'] || ! $previous
                    || $previous->content_hash !== ($state['previous_content_hash'] ?? null)
                    || ! is_bool($state['activated_by_migration'] ?? null)) {
                    throw new RuntimeException('Anonymous restoration skill state conflicts with the current source.');
                }

                return;
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
                throw new RuntimeException('Anonymous restoration file-ops snapshot was not activated exactly.');
            }
            DB::table('system_configs')->insert([
                'section' => 'cli', 'config_key' => self::STATE_KEY,
                'config_value' => json_encode([
                    'previous_id' => $previousId, 'previous_content_hash' => $previousHash,
                    'installed_id' => (int) $installed[0]->id, 'installed_content_hash' => $skill['content_hash'],
                    'activated_by_migration' => $activatedByMigration,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'data_type' => 'json', 'description' => 'Rollback ownership for anonymous restoration file-ops skill access.',
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
                throw new RuntimeException('Anonymous restoration file-ops rollback state is invalid.');
            }
            $active = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('is_active', 1)
                ->lockForUpdate()->limit(2)->get(['id', 'content_hash']);
            $previous = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('id', $state['previous_id'] ?? 0)
                ->lockForUpdate()->first(['id', 'content_hash']);
            if ($active->count() !== 1 || (int) $active[0]->id !== (int) ($state['installed_id'] ?? 0)
                || $active[0]->content_hash !== ($state['installed_content_hash'] ?? null)
                || ! $previous || $previous->content_hash !== ($state['previous_content_hash'] ?? null)
                || ($state['activated_by_migration'] !== ((int) $previous->id !== (int) $active[0]->id))) {
                throw new RuntimeException('file-ops skill changed since anonymous restoration registration; retain access and reconcile rollback.');
            }
            if ($state['activated_by_migration']) {
                DB::table('skill_versions')->where('skill_name', 'file-ops')->where('is_active', 1)->update(['is_active' => 0]);
                DB::table('skill_versions')->where('id', $previous->id)->where('skill_name', 'file-ops')
                    ->where('content_hash', $previous->content_hash)->update(['is_active' => 1]);
            }
            DB::table('system_configs')->where('id', $stateRow->id)->delete();
        });
    }

    private function assertRegisteredTools(): void
    {
        foreach ($this->tools() as $name => $expected) {
            $row = DB::table('agent_tool_registry')->where('name', $name)->first();
            if ($row === null || ! in_array((int) $row->enabled, [0, 1], true)
                || $row->service_class !== self::SERVICE || $row->method !== $expected['method']
                || $row->risk_level !== $expected['risk_level']
                || (int) $row->requires_confirmation !== $expected['requires_confirmation']
                || $this->normalize(json_decode((string) $row->permissions, true, flags: JSON_THROW_ON_ERROR)) !== $this->normalize($expected['permissions'])
                || $this->normalize(json_decode((string) $row->parameters, true, flags: JSON_THROW_ON_ERROR)) !== $this->normalize($expected['parameters'])) {
                throw new RuntimeException('Anonymous restoration tool registration is missing or changed: '.$name.'.');
            }
        }
    }

    private function assertFrameworkBindings(): void
    {
        $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')
            ->lockForUpdate()->first();
        if ($row === null) {
            throw new RuntimeException('Anonymous restoration requires the existing CLI framework service policy.');
        }
        $policy = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($policy)) {
            throw new RuntimeException('Anonymous restoration framework service policy is invalid.');
        }
        CliFrameworkServicePolicy::validate($policy);
        if (($policy['targets']['local'] ?? null) !== ['transport' => 'local']) {
            throw new RuntimeException('Anonymous restoration requires the existing local-only framework target.');
        }
        $enabled = [];
        foreach ($this->tools() as $name => $_) {
            $registration = DB::table('agent_tool_registry')->where('name', $name)->first(['enabled']);
            $enabled[$name] = (int) $registration->enabled === 1;
            if (! $enabled[$name]) {
                continue;
            }
            $rule = $policy['tools'][$name] ?? null;
            $allowed = ['target', 'tree_scoped', 'references', 'timeout_ms', 'durable_receipts'];
            if (! is_array($rule) || ($rule['target'] ?? null) !== 'local'
                || ($rule['tree_scoped'] ?? null) !== false || ($rule['references'] ?? null) !== []
                || array_diff(array_keys($rule), $allowed) !== []) {
                throw new RuntimeException('Anonymous restoration requires its exact local framework binding: '.$name.'.');
            }
        }
        if ($enabled[self::EXECUTE] && ! $enabled[self::PLAN]) {
            throw new RuntimeException('Anonymous restoration execution cannot be enabled without the plan action.');
        }
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->normalize($child);
        }

        return $value;
    }
};
