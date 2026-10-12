<?php

use App\Services\UnifiedCli\CliFrameworkServicePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PLAN = 'file_face_coverage_preview_plan';

    private const EXECUTE = 'file_face_coverage_preview_execute';

    private const STATE_KEY = 'native_face_preview_registration_20261008';

    private function planSchema(): array
    {
        $hash = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];
        $engine = ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'schema' => ['type' => 'string', 'const' => 'plos.face_generation_engine.v2'],
            'python' => ['type' => 'string'], 'detector' => ['type' => 'string', 'const' => 'hog'],
            'embedding_model' => ['type' => 'string', 'const' => 'dlib_face_recognition_resnet_model_v1'],
            'versions' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            'checkpoints' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'descriptor_sha256' => $hash, 'landmarks_small_sha256' => $hash, 'detector_binary_sha256' => $hash,
            ], 'required' => ['descriptor_sha256', 'landmarks_small_sha256', 'detector_binary_sha256']],
            'script_sha256' => $hash,
        ], 'required' => ['schema', 'python', 'detector', 'embedding_model', 'versions', 'checkpoints', 'script_sha256']];

        return ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'schema' => ['type' => 'string', 'const' => 'plos.anonymous_face_native_coverage_preview.v1'],
            'asset_uuid' => ['type' => 'string', 'format' => 'uuid'],
            'file_registry_id' => ['type' => 'integer', 'minimum' => 1],
            'source_sha256' => $hash, 'binding_sha256' => $hash, 'generation_key' => $hash,
            'detector_version' => $hash, 'coverage_preview_detector_version' => $hash, 'engine_provenance' => $engine,
            'success' => ['type' => 'boolean', 'const' => true], 'eligible' => ['type' => 'boolean', 'const' => true],
            'mode' => ['type' => 'string', 'const' => 'dry_run'], 'detector_run' => ['type' => 'boolean', 'const' => false],
            'source_originals_mutated' => ['type' => 'boolean', 'const' => false], 'identity_writes' => ['type' => 'boolean', 'const' => false],
            'plan_sha256' => $hash,
        ], 'required' => ['schema', 'asset_uuid', 'file_registry_id', 'source_sha256', 'binding_sha256', 'generation_key',
            'detector_version', 'coverage_preview_detector_version', 'engine_provenance', 'success', 'eligible', 'mode',
            'detector_run', 'source_originals_mutated', 'identity_writes', 'plan_sha256']];
    }

    private function tools(): array
    {
        return [
            self::PLAN => ['method' => 'planAnonymousCoveragePreview',
                'description' => 'Dry-run exact current source and detector engine for one anonymous preview. No face detection or writes.',
                'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'asset_uuid' => ['type' => 'string', 'format' => 'uuid'],
                ], 'required' => ['asset_uuid']],
                'returns' => 'Exact registry/source/engine preview plan; protected identity records remain held.',
                'risk' => 'read', 'confirm' => false],
            self::EXECUTE => ['method' => 'prepareAnonymousCoveragePreview',
                'description' => 'Prepare one exact native CLI-approved source/engine-bound anonymous detector preview. Writes only a private packet and anonymous preview index; never writes face rows, vectors, names, links, clusters, or identity decisions.',
                'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'plan' => $this->planSchema(),
                ], 'required' => ['plan']],
                'returns' => 'Sanitized preview receipt with exact source and engine binding; no face rows, vectors, or identity writes.',
                'risk' => 'write', 'confirm' => true],
        ];
    }

    public function up(): void
    {
        DB::transaction(function (): void {
            $frameworkRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($frameworkRow === null) {
                throw new RuntimeException('Native face preview requires the existing framework service policy.');
            }
            $framework = json_decode((string) $frameworkRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            CliFrameworkServicePolicy::validate($framework);
            $priorStateRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', self::STATE_KEY)->first();
            if ($priorStateRow !== null) {
                foreach ($this->tools() as $name => $tool) {
                    $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
                    if ($registered === null || ! $this->registrationMatches($registered, $tool)) {
                        throw new RuntimeException('Native face preview registration changed after migration.');
                    }
                }

                return;
            }
            $priorState = [];
            foreach (array_keys($this->tools()) as $name) {
                $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
                $priorState[$name] = ['registration_present' => $registered !== null,
                    'framework_rule_present' => array_key_exists($name, $framework['tools']),
                    'framework_rule' => $framework['tools'][$name] ?? null];
            }
            foreach ($this->tools() as $name => $tool) {
                $existing = DB::table('agent_tool_registry')->where('name', $name)->first();
                if ($existing !== null && ! $this->registrationMatches($existing, $tool)) {
                    throw new RuntimeException('Conflicting native face preview registration.');
                }
                $rule = $framework['tools'][$name] ?? null;
                $expected = ['target' => 'local', 'tree_scoped' => false, 'references' => []];
                if ($rule !== null && array_diff_key($rule, ['timeout_ms' => true, 'durable_receipts' => true]) !== $expected) {
                    throw new RuntimeException('Conflicting native face preview framework policy.');
                }
                DB::table('agent_tool_registry')->insertOrIgnore($this->registrationValues($tool) + ['name' => $name, 'created_at' => now()]);
                $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
                if ($registered === null || ! $this->registrationMatches($registered, $tool)) {
                    throw new RuntimeException('Registered native face preview service changed during migration.');
                }
                if ((int) $registered->enabled === 1) {
                    $framework['tools'][$name] ??= $expected;
                }
            }
            DB::table('system_configs')->insert([
                'section' => 'cli', 'config_key' => self::STATE_KEY,
                'config_value' => json_encode($priorState, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'data_type' => 'json', 'description' => 'Migration rollback ownership for native anonymous face preview registration.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('system_configs')->where('id', $frameworkRow->id)->update([
                'config_value' => json_encode($framework, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
            ]);
        });
    }

    private function registrationValues(array $tool): array
    {
        return ['service_class' => 'App\\Services\\FaceEmbeddingService', 'method' => $tool['method'],
            'description' => $tool['description'], 'parameters' => json_encode($tool['parameters'], JSON_THROW_ON_ERROR),
            'returns_description' => $tool['returns'], 'permissions' => json_encode([$tool['risk'] === 'read' ? 'file:read' : 'file:write'], JSON_THROW_ON_ERROR),
            'enabled' => 1, 'risk_level' => $tool['risk'], 'category' => 'file',
            'requires_confirmation' => $tool['confirm'] ? 1 : 0, 'source' => 'config', 'updated_at' => now()];
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

    public function down(): void
    {
        DB::transaction(function (): void {
            $stateRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', self::STATE_KEY)->lockForUpdate()->first();
            if ($stateRow === null) {
                return;
            }
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('Cannot roll back native face preview without the existing framework service policy.');
            }
            $priorState = json_decode((string) $stateRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $framework = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            CliFrameworkServicePolicy::validate($framework);

            foreach ($this->tools() as $name => $tool) {
                if (($priorState[$name]['registration_present'] ?? null) === true) {
                    continue;
                }
                $registered = DB::table('agent_tool_registry')->where('name', $name)->lockForUpdate()->first();
                if ($registered !== null && $this->registrationMatches($registered, $tool) && (int) $registered->enabled === 1) {
                    DB::table('agent_tool_registry')->where('id', $registered->id)->update(['enabled' => 0, 'updated_at' => now()]);
                }
            }
            foreach (array_keys($this->tools()) as $name) {
                if (($priorState[$name]['framework_rule_present'] ?? null) === true) {
                    continue;
                }
                $rule = $framework['tools'][$name] ?? null;
                if ($rule === ['target' => 'local', 'tree_scoped' => false, 'references' => []]) {
                    unset($framework['tools'][$name]);
                }
            }
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($framework, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
            ]);
            DB::table('system_configs')->where('id', $stateRow->id)->delete();
        });
    }
};
