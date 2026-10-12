<?php

use App\Services\SkillLoaderService;
use App\Services\SkillVersionService;
use App\Services\UnifiedCli\CliFrameworkServicePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const STATE_KEY = 'genealogy_media_path_retarget_file_ops_skill_20261009';

    private const PRIOR_STATE_KEY = 'incoming_face_readiness_file_ops_skill_20261009';

    private const SERVICE = 'App\\Services\\Genealogy\\GenealogyMediaPathRetargetService';

    private const TIMEOUT_MS = 600000;

    private function tools(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $hash = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];

        return [
            'genealogy_media_path_retarget_plan' => [
                'method' => 'plan', 'risk' => 'read', 'confirm' => false, 'permissions' => ['file:read'],
                'description' => 'Read-only plan for one exact same-byte genealogy media path retarget in the selected tree. Checks current source/target registry rows, physical hashes, current-face equivalence, protected references, and full preimage digest.',
                'returns' => 'One exact selector and source/target/preimage digests; no metadata, identity or file changes.',
                'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'tree_id' => $id, 'media_id' => $id, 'source_registry_id' => $id, 'target_registry_id' => $id,
                ], 'required' => ['tree_id', 'media_id', 'source_registry_id', 'target_registry_id']],
            ],
            'genealogy_media_path_retarget_execute' => [
                'method' => 'execute', 'risk' => 'write', 'confirm' => true, 'permissions' => ['file:write'],
                'description' => 'Execute only the exact approved same-byte genealogy media path plan. Retains a private full-row preimage and changes only the selected genealogy_media path/timestamp; preserves registry, face, vector, identity and history rows.',
                'returns' => 'Durable terminal receipt or held result. Does not copy/delete bytes, decide identity, or replay unknown outcomes.',
                'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'tree_id' => $id, 'media_id' => $id, 'source_registry_id' => $id, 'target_registry_id' => $id,
                    'expected_plan_sha256' => $hash, 'idempotency_key' => ['type' => 'string', 'format' => 'uuid'],
                ], 'required' => ['tree_id', 'media_id', 'source_registry_id', 'target_registry_id', 'expected_plan_sha256', 'idempotency_key']],
            ],
        ];
    }

    public function up(): void
    {
        DB::transaction(function (): void {
            $skill = app(SkillLoaderService::class)->loadSkillSnapshot('file-ops');
            if ($skill === null || ($skill['frontmatter']['name'] ?? null) !== 'file-ops'
                || ($skill['frontmatter']['version'] ?? null) !== '1.0.6'
                || ! in_array('file:read', $skill['frontmatter']['permissions'] ?? [], true)
                || ! in_array('file:write', $skill['frontmatter']['permissions'] ?? [], true)
                || ! is_string($skill['content_hash'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $skill['content_hash'])
                || ! is_string($skill['full_content'] ?? null)
                || hash('sha256', $skill['full_content']) !== $skill['content_hash']) {
                throw new RuntimeException('Reviewed file-ops 1.0.6 source is required for genealogy media path retarget access.');
            }
            $declared = SkillLoaderService::declaredToolNames($skill['frontmatter']);
            foreach (array_keys($this->tools()) as $name) {
                if (! in_array($name, $declared, true)) {
                    throw new RuntimeException('Reviewed file-ops source omits a path retarget action.');
                }
            }

            $frameworkRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($frameworkRow === null) {
                throw new RuntimeException('Genealogy media path retarget requires the existing framework service policy.');
            }
            $framework = json_decode((string) $frameworkRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            CliFrameworkServicePolicy::validate($framework);
            $stateRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', self::STATE_KEY)->lockForUpdate()->first();
            $savedState = $stateRow ? json_decode((string) $stateRow->config_value, true, flags: JSON_THROW_ON_ERROR) : null;
            $state = [];
            foreach ($this->tools() as $name => $tool) {
                $registered = DB::table('agent_tool_registry')->where('name', $name)->lockForUpdate()->first();
                $rule = $framework['tools'][$name] ?? null;
                if ($stateRow !== null) {
                    $savedTool = is_array($savedState) ? ($savedState['tools'][$name] ?? null) : null;
                    $expectedRule = is_array($savedTool) ? ($savedTool['prior_rule'] ?? null) : null;
                    if (! is_array($savedTool) || $registered === null || ! $this->registrationMatches($registered, $tool)) {
                        throw new RuntimeException('Genealogy media path retarget registration ownership changed: '.$name.'.');
                    }
                    if ($expectedRule === null && (int) $registered->enabled === 1) {
                        $expectedRule = ['target' => 'local', 'tree_scoped' => true, 'tree_argument' => 'tree_id', 'references' => []];
                        if (($savedTool['timeout_added'] ?? false) === true) {
                            $expectedRule['timeout_ms'] = self::TIMEOUT_MS;
                        }
                    } elseif (is_array($expectedRule) && ($savedTool['timeout_added'] ?? false) === true) {
                        $expectedRule['timeout_ms'] = self::TIMEOUT_MS;
                    }
                    if ($this->canonicalize($rule) !== $this->canonicalize($expectedRule)) {
                        throw new RuntimeException('Genealogy media path retarget framework ownership changed: '.$name.'.');
                    }

                    continue;
                }
                if ($registered !== null && ! $this->registrationMatches($registered, $tool)) {
                    throw new RuntimeException('Conflicting genealogy media path retarget registration: '.$name.'.');
                }
                if ($rule !== null && ! $this->validRule($rule)) {
                    throw new RuntimeException('Conflicting genealogy media path retarget framework rule: '.$name.'.');
                }
                $state[$name] = ['registration_present' => $registered !== null, 'rule_present' => $rule !== null,
                    'prior_rule' => $rule, 'timeout_added' => false];
                DB::table('agent_tool_registry')->insertOrIgnore($this->registrationValues($tool) + ['name' => $name, 'created_at' => now()]);
                $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
                if ($registered === null || ! $this->registrationMatches($registered, $tool)) {
                    throw new RuntimeException('Genealogy media path retarget registration changed during migration: '.$name.'.');
                }
                if ((int) $registered->enabled === 1) {
                    $framework['tools'][$name] ??= ['target' => 'local', 'tree_scoped' => true,
                        'tree_argument' => 'tree_id', 'references' => []];
                    if (! array_key_exists('timeout_ms', $framework['tools'][$name])) {
                        $framework['tools'][$name]['timeout_ms'] = self::TIMEOUT_MS;
                        $state[$name]['timeout_added'] = true;
                    }
                }
            }
            if ((int) DB::table('agent_tool_registry')->where('name', 'genealogy_media_path_retarget_execute')->value('enabled') === 1
                && (int) DB::table('agent_tool_registry')->where('name', 'genealogy_media_path_retarget_plan')->value('enabled') !== 1) {
                throw new RuntimeException('Genealogy media path retarget execute cannot be enabled without its plan.');
            }

            $active = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('is_active', 1)->lockForUpdate()->limit(2)->get();
            if ($active->count() !== 1) {
                throw new RuntimeException('Genealogy media path retarget requires one active file-ops version.');
            }
            if ($stateRow !== null) {
                $previous = is_array($savedState) ? DB::table('skill_versions')->where('skill_name', 'file-ops')
                    ->where('id', $savedState['previous_id'] ?? 0)->lockForUpdate()->first() : null;
                if (! is_array($savedState) || ($savedState['installed_content_hash'] ?? null) !== $skill['content_hash']
                    || (int) $active[0]->id !== (int) ($savedState['installed_id'] ?? 0)
                    || $active[0]->content_hash !== $skill['content_hash'] || ! $previous
                    || $previous->content_hash !== ($savedState['previous_content_hash'] ?? null)
                    || $active[0]->full_content !== $skill['full_content']
                    || hash('sha256', (string) $previous->full_content) !== $previous->content_hash
                    || ($savedState['installed_record_sha256'] ?? null) !== $this->skillRecordDigest($active[0])
                    || ($savedState['previous_record_sha256'] ?? null) !== $this->skillRecordDigest($previous)
                    || ! is_bool($savedState['activated_by_migration'] ?? null)) {
                    throw new RuntimeException('Genealogy media path retarget registration state conflicts with current source.');
                }
            } else {
                $priorRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', self::PRIOR_STATE_KEY)->lockForUpdate()->first();
                $prior = $priorRow ? json_decode((string) $priorRow->config_value, true, flags: JSON_THROW_ON_ERROR) : null;
                if (! is_array($prior) || (int) ($prior['installed_id'] ?? 0) !== (int) $active[0]->id
                    || ($prior['installed_content_hash'] ?? null) !== $active[0]->content_hash
                    || ($prior['installed_record_sha256'] ?? null) !== $this->skillRecordDigest($active[0])
                    || hash('sha256', (string) $active[0]->full_content) !== $active[0]->content_hash
                    || ($active[0]->version !== '1.0.5'
                        && ($active[0]->version !== '1.0.6' || $active[0]->content_hash !== $skill['content_hash']
                            || $active[0]->full_content !== $skill['full_content']))) {
                    throw new RuntimeException('Prior reviewed file-ops snapshot changed; preserve operator amendments.');
                }
                $previousId = (int) $active[0]->id;
                $previousHash = (string) $active[0]->content_hash;
                $activated = ! hash_equals($previousHash, $skill['content_hash']);
                if ($activated) {
                    app(SkillVersionService::class)->trackVersion('file-ops', $skill['frontmatter'], $skill['body'], $skill['full_content']);
                }
                $installed = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('is_active', 1)->lockForUpdate()->limit(2)->get();
                $previous = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('id', $previousId)->lockForUpdate()->first();
                if ($installed->count() !== 1 || $installed[0]->content_hash !== $skill['content_hash']
                    || $installed[0]->full_content !== $skill['full_content'] || ! $previous
                    || $previous->content_hash !== $previousHash
                    || hash('sha256', (string) $previous->full_content) !== $previousHash) {
                    throw new RuntimeException('Genealogy media path retarget file-ops snapshot was not activated exactly.');
                }
                DB::table('system_configs')->insert([
                    'section' => 'cli', 'config_key' => self::STATE_KEY,
                    'config_value' => json_encode(['previous_id' => $previousId, 'previous_content_hash' => $previousHash,
                        'previous_record_sha256' => $this->skillRecordDigest($previous),
                        'installed_id' => (int) $installed[0]->id, 'installed_content_hash' => $skill['content_hash'],
                        'installed_record_sha256' => $this->skillRecordDigest($installed[0]),
                        'activated_by_migration' => $activated, 'tools' => $state], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'data_type' => 'json', 'description' => 'Owned rollback state for exact genealogy media path retarget access.',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            CliFrameworkServicePolicy::validate($framework);
            DB::table('system_configs')->where('id', $frameworkRow->id)->update([
                'config_value' => json_encode($framework, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $stateRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', self::STATE_KEY)->lockForUpdate()->first();
            if ($stateRow === null) {
                return;
            }
            if (DB::table('review_desk_receipts')->where('action', 'genealogy_media_path_retarget')->exists()) {
                throw new RuntimeException('Retain genealogy media path retarget receipts and preimages during rollback.');
            }
            $state = json_decode((string) $stateRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($state) || ! is_bool($state['activated_by_migration'] ?? null) || ! is_array($state['tools'] ?? null)) {
                throw new RuntimeException('Genealogy media path retarget rollback state is invalid.');
            }
            $frameworkRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($frameworkRow === null) {
                throw new RuntimeException('Cannot roll back path retarget without the framework service policy.');
            }
            $framework = json_decode((string) $frameworkRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            CliFrameworkServicePolicy::validate($framework);
            foreach ($this->tools() as $name => $tool) {
                $saved = $state['tools'][$name] ?? null;
                if (! is_array($saved)) {
                    throw new RuntimeException('Genealogy media path retarget tool ownership state is missing.');
                }
                $rule = $framework['tools'][$name] ?? null;
                if (($saved['timeout_added'] ?? false) === true && is_array($rule) && ($rule['timeout_ms'] ?? null) === self::TIMEOUT_MS) {
                    unset($framework['tools'][$name]['timeout_ms']);
                    $rule = $framework['tools'][$name];
                }
                $expected = ['target' => 'local', 'tree_scoped' => true, 'tree_argument' => 'tree_id', 'references' => []];
                if (($saved['rule_present'] ?? false) !== true && $rule === $expected) {
                    unset($framework['tools'][$name]);
                } elseif (($saved['rule_present'] ?? false) !== true && $rule !== null) {
                    throw new RuntimeException('Operator adopted framework rule changed; retain path retarget access and reconcile rollback.');
                } elseif (($saved['rule_present'] ?? false) === true) {
                    $prior = $saved['prior_rule'] ?? null;
                    if ($rule !== $prior) {
                        throw new RuntimeException('Operator framework rule changed; retain path retarget access and reconcile rollback.');
                    }
                }
                if (($saved['registration_present'] ?? false) !== true) {
                    $registered = DB::table('agent_tool_registry')->where('name', $name)->lockForUpdate()->first();
                    if ($registered !== null && ! $this->registrationMatches($registered, $tool)) {
                        throw new RuntimeException('Operator tool registration changed; retain path retarget access and reconcile rollback.');
                    }
                    if ($registered !== null && (int) $registered->enabled === 1) {
                        DB::table('agent_tool_registry')->where('id', $registered->id)->update(['enabled' => 0, 'updated_at' => now()]);
                    }
                }
            }
            $active = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('is_active', 1)->lockForUpdate()->limit(2)->get();
            $previous = DB::table('skill_versions')->where('skill_name', 'file-ops')->where('id', $state['previous_id'] ?? 0)->lockForUpdate()->first();
            if ($active->count() !== 1 || (int) $active[0]->id !== (int) ($state['installed_id'] ?? 0)
                || $active[0]->content_hash !== ($state['installed_content_hash'] ?? null)
                || hash('sha256', (string) $active[0]->full_content) !== $active[0]->content_hash
                || ! $previous || $previous->content_hash !== ($state['previous_content_hash'] ?? null)
                || hash('sha256', (string) $previous->full_content) !== $previous->content_hash
                || ($state['installed_record_sha256'] ?? null) !== $this->skillRecordDigest($active[0])
                || ($state['previous_record_sha256'] ?? null) !== $this->skillRecordDigest($previous)) {
                throw new RuntimeException('file-ops changed; retain path retarget skill snapshot and reconcile rollback.');
            }
            if ($state['activated_by_migration']) {
                DB::table('skill_versions')->where('id', $active[0]->id)->update(['is_active' => 0]);
                DB::table('skill_versions')->where('id', $previous->id)->update(['is_active' => 1]);
            }
            CliFrameworkServicePolicy::validate($framework);
            DB::table('system_configs')->where('id', $frameworkRow->id)->update([
                'config_value' => json_encode($framework, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
            ]);
            DB::table('system_configs')->where('id', $stateRow->id)->delete();
        });
    }

    private function registrationValues(array $tool): array
    {
        return ['service_class' => self::SERVICE, 'method' => $tool['method'], 'description' => $tool['description'],
            'parameters' => json_encode($tool['parameters'], JSON_THROW_ON_ERROR), 'returns_description' => $tool['returns'],
            'permissions' => json_encode($tool['permissions'], JSON_THROW_ON_ERROR), 'enabled' => 1, 'risk_level' => $tool['risk'],
            'category' => 'file', 'requires_confirmation' => $tool['confirm'] ? 1 : 0,
            'source' => 'config', 'updated_at' => now()];
    }

    private function validRule(mixed $rule): bool
    {
        if (! is_array($rule) || array_diff(array_keys($rule), ['target', 'tree_scoped', 'tree_argument', 'references', 'timeout_ms']) !== []
            || ($rule['target'] ?? null) !== 'local' || ($rule['tree_scoped'] ?? null) !== true
            || ($rule['tree_argument'] ?? null) !== 'tree_id' || ($rule['references'] ?? null) !== []) {
            return false;
        }

        return ! array_key_exists('timeout_ms', $rule) || (is_int($rule['timeout_ms']) && $rule['timeout_ms'] > 0);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }

    private function skillRecordDigest(object $row): string
    {
        return hash('sha256', json_encode($this->canonicalize((array) $row), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function registrationMatches(object $existing, array $tool): bool
    {
        foreach ($this->registrationValues($tool) as $key => $value) {
            if (in_array($key, ['enabled', 'updated_at'], true)) {
                continue;
            }
            if (in_array($key, ['parameters', 'permissions'], true)) {
                if (json_decode((string) ($existing->{$key} ?? '')) != json_decode((string) $value)) {
                    return false;
                }
            } elseif (($existing->{$key} ?? null) != $value) {
                return false;
            }
        }

        return true;
    }
};
