<?php

use App\Services\SkillLoaderService;
use App\Services\SkillVersionService;
use App\Services\UnifiedCli\CliFrameworkServicePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const STATE_KEY = 'incoming_face_readiness_file_ops_skill_20261009';

    private const PRIOR_STATE_KEY = 'anonymous_face_restoration_file_ops_skill_20261009';

    private function tools(): array
    {
        return [
            'file_face_coverage_readiness_list' => ['service' => 'App\\Services\\FileRegistryFaceCoverageService',
                'method' => 'listIncomingCoverageReadiness', 'parameters' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['afterObservationId' => ['type' => 'integer', 'minimum' => 0],
                        'limit' => ['type' => 'integer', 'minimum' => 1]]]],
            'file_face_coverage_preview_plan' => ['service' => 'App\\Services\\FaceEmbeddingService',
                'method' => 'planAnonymousCoveragePreview', 'parameters' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['asset_uuid' => ['type' => 'string', 'format' => 'uuid']], 'required' => ['asset_uuid']]],
        ];
    }

    public function up(): void
    {
        DB::transaction(function (): void {
            $skill = app(SkillLoaderService::class)->loadSkillSnapshot('file-ops');
            if ($skill === null || ($skill['frontmatter']['name'] ?? null) !== 'file-ops'
                || ! in_array($skill['frontmatter']['version'] ?? null, ['1.0.5', '1.0.6'], true)
                || ! in_array('file:read', $skill['frontmatter']['permissions'] ?? [], true)) {
                throw new RuntimeException('Reviewed file-ops 1.0.5 source is required for incoming readiness discovery.');
            }
            $declared = SkillLoaderService::declaredToolNames($skill['frontmatter']);
            foreach (array_keys($this->tools()) as $name) {
                if (! in_array($name, $declared, true)) {
                    throw new RuntimeException('Reviewed file-ops source omits its existing readiness read tools.');
                }
            }
            if (in_array('file_face_coverage_preview_execute', $declared, true)) {
                throw new RuntimeException('Incoming readiness access cannot add preview execution.');
            }
            $this->assertExistingBindings();
            $stateRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', self::STATE_KEY)->lockForUpdate()->first();
            $active = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('is_active', 1)->lockForUpdate()->limit(2)->get();
            if ($active->count() !== 1 || ! is_string($skill['content_hash'] ?? null)
                || ! preg_match('/\A[a-f0-9]{64}\z/', $skill['content_hash'])) {
                throw new RuntimeException('Incoming readiness requires one exact active file-ops snapshot.');
            }
            if ($stateRow !== null) {
                $state = json_decode((string) $stateRow->config_value, true, flags: JSON_THROW_ON_ERROR);
                $this->assertOwnedState($state, $active[0]);
                if ($state['installed_content_hash'] !== $skill['content_hash']) {
                    throw new RuntimeException('Incoming readiness skill source changed after registration.');
                }

                return;
            }
            $priorReceipt = DB::table('system_configs')->where('section', 'cli')->where('config_key', self::PRIOR_STATE_KEY)->lockForUpdate()->first();
            $prior = $priorReceipt ? json_decode((string) $priorReceipt->config_value, true, flags: JSON_THROW_ON_ERROR) : null;
            if (! is_array($prior) || (int) ($prior['installed_id'] ?? 0) !== (int) $active[0]->id
                || ($prior['installed_content_hash'] ?? null) !== $active[0]->content_hash
                || hash('sha256', (string) $active[0]->full_content) !== $active[0]->content_hash
                || ! in_array($active[0]->version, ['1.0.4', '1.0.5', '1.0.6'], true)) {
                throw new RuntimeException('Prior file-ops snapshot changed; preserve operator amendments.');
            }
            $previousId = (int) $active[0]->id;
            $previousHash = $active[0]->content_hash;
            $activated = $previousHash !== $skill['content_hash'];
            if ($activated) {
                app(SkillVersionService::class)->trackVersion('file-ops', $skill['frontmatter'], $skill['body'], $skill['full_content']);
            }
            $installed = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('is_active', 1)->lockForUpdate()->limit(2)->get();
            $previous = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('id', $previousId)->lockForUpdate()->first();
            if ($installed->count() !== 1 || $installed[0]->content_hash !== $skill['content_hash'] || ! $previous
                || $previous->content_hash !== $previousHash) {
                throw new RuntimeException('Incoming readiness file-ops snapshot was not activated exactly.');
            }
            DB::table('system_configs')->insert([
                'section' => 'cli', 'config_key' => self::STATE_KEY,
                'config_value' => json_encode(['previous_id' => $previousId, 'previous_content_hash' => $previousHash,
                    'previous_record_sha256' => $this->digest($previous), 'installed_id' => (int) $installed[0]->id,
                    'installed_content_hash' => $skill['content_hash'], 'installed_record_sha256' => $this->digest($installed[0]),
                    'activated_by_migration' => $activated], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'data_type' => 'json', 'description' => 'Owned rollback pins for existing read-only incoming readiness file-ops access.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', self::STATE_KEY)->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $state = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            $active = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('is_active', 1)->lockForUpdate()->limit(2)->get();
            if ($active->count() !== 1) {
                throw new RuntimeException('Incoming readiness rollback requires one active file-ops snapshot.');
            }
            $this->assertOwnedState($state, $active[0]);
            if ($state['activated_by_migration']) {
                DB::table('skill_versions')->where('id', $active[0]->id)->update(['is_active' => 0]);
                DB::table('skill_versions')->where('id', $state['previous_id'])->where('content_hash', $state['previous_content_hash'])->update(['is_active' => 1]);
            }
            DB::table('system_configs')->where('id', $row->id)->delete();
        });
    }

    private function assertOwnedState(mixed $state, object $active): void
    {
        $previous = is_array($state) ? DB::table('skill_versions')->where('skill_name', 'file-ops')
            ->where('id', $state['previous_id'] ?? 0)->lockForUpdate()->first() : null;
        if (! is_array($state) || ! is_bool($state['activated_by_migration'] ?? null) || ! $previous
            || (int) ($state['installed_id'] ?? 0) !== (int) $active->id
            || ($state['installed_content_hash'] ?? null) !== $active->content_hash
            || ($state['previous_content_hash'] ?? null) !== $previous->content_hash
            || ($state['installed_record_sha256'] ?? null) !== $this->digest($active)
            || ($state['previous_record_sha256'] ?? null) !== $this->digest($previous)
            || $state['activated_by_migration'] !== ((int) $previous->id !== (int) $active->id)) {
            throw new RuntimeException('file-ops skill rows changed; preserve amendments and reconcile readiness rollback.');
        }
    }

    private function assertExistingBindings(): void
    {
        $policyRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
        if ($policyRow === null) {
            throw new RuntimeException('Incoming readiness requires the existing local framework policy.');
        }
        $policy = json_decode((string) $policyRow->config_value, true, flags: JSON_THROW_ON_ERROR);
        CliFrameworkServicePolicy::validate($policy);
        if (($policy['targets']['local'] ?? null) !== ['transport' => 'local']) {
            throw new RuntimeException('Incoming readiness read tools must stay local.');
        }
        foreach ($this->tools() as $name => $expected) {
            $row = DB::table('agent_tool_registry')->where('name', $name)->lockForUpdate()->first();
            if ($row === null || ! in_array((int) $row->enabled, [0, 1], true)
                || $row->service_class !== $expected['service'] || $row->method !== $expected['method']
                || $row->risk_level !== 'read' || (int) $row->requires_confirmation !== 0
                || $this->normalize(json_decode((string) $row->permissions, true, flags: JSON_THROW_ON_ERROR)) !== ['file:read']
                || $this->normalize(json_decode((string) $row->parameters, true, flags: JSON_THROW_ON_ERROR)) !== $this->normalize($expected['parameters'])) {
                throw new RuntimeException('Existing incoming readiness read contract changed: '.$name.'.');
            }
            if ((int) $row->enabled === 0) {
                continue;
            }
            $rule = $policy['tools'][$name] ?? null;
            if (! is_array($rule) || ($rule['target'] ?? null) !== 'local'
                || ($rule['tree_scoped'] ?? null) !== false || ($rule['references'] ?? null) !== []
                || array_diff(array_keys($rule), ['target', 'tree_scoped', 'references', 'timeout_ms', 'durable_receipts']) !== []) {
                throw new RuntimeException('Existing incoming readiness read route changed: '.$name.'.');
            }
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

    private function digest(object $row): string
    {
        return hash('sha256', json_encode($this->normalize((array) $row), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
};
