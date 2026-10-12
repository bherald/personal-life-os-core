<?php

use App\Services\UnifiedCli\CliFrameworkServicePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PLAN = 'file_face_restoration_batch_plan';

    private const EXECUTE = 'file_face_restoration_batch_execute';

    private const TIMEOUT_MS = 600000;

    private function itemSchema(): array
    {
        $hash = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];

        return ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'asset_uuid' => ['type' => 'string', 'format' => 'uuid'],
            'file_registry_id' => ['type' => 'integer', 'minimum' => 1],
            'source_sha256' => $hash, 'binding_sha256' => $hash, 'generation_key' => $hash, 'detector_version' => $hash,
            'coverage_preview_detector_version' => $hash, 'manifest_sha256' => $hash,
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
        ], 'required' => ['asset_uuid', 'file_registry_id', 'source_sha256', 'binding_sha256', 'generation_key', 'detector_version',
            'coverage_preview_detector_version', 'manifest_sha256', 'old_face_count', 'old_vector_count',
            'preview_face_count', 'preflight_budget_seconds', 'retirement_budget_seconds', 'generation_budget_seconds',
            'write_budget_seconds', 'total_budget_seconds', 'reconciliation_idempotency_key', 'generation_idempotency_key']];
    }

    private function tools(): array
    {
        $hash = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];

        return [
            self::PLAN => ['method' => 'plan', 'description' => 'Dry-run a bounded source-bound anonymous face restoration batch. Requires a same-source detector preview with no face-count drop. No detector or writes.',
                'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'asset_uuids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 4, 'items' => ['type' => 'string', 'format' => 'uuid']],
                ], 'required' => ['asset_uuids']], 'returns' => 'Exact source, registry, old face/vector manifest, coverage preview and stable child idempotency plan for up to four assets.',
                'risk' => 'read', 'confirm' => false],
            self::EXECUTE => ['method' => 'execute', 'description' => 'Execute the exact approved anonymous face restoration batch. Retains old cohorts, requires verified source and row preimages, stops on drift, protected evidence, incomplete receipts or face-count drops. Never names, links or clusters faces.',
                'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'plan' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                        'schema' => ['type' => 'string', 'const' => 'plos.anonymous_face_restoration_batch.v1'],
                        'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 4, 'items' => $this->itemSchema()],
                        'max_sources' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4],
                        'success' => ['type' => 'boolean', 'const' => true],
                        'eligible' => ['type' => 'boolean', 'const' => true],
                        'mode' => ['type' => 'string', 'const' => 'dry_run'],
                        'detector_run' => ['type' => 'boolean', 'const' => false],
                        'source_originals_mutated' => ['type' => 'boolean', 'const' => false],
                        'batch_plan_sha256' => $hash,
                    ], 'required' => ['schema', 'items', 'max_sources', 'success', 'eligible', 'mode', 'detector_run',
                        'source_originals_mutated', 'batch_plan_sha256']],
                ], 'required' => ['plan']], 'returns' => 'Sanitized per-source terminal state; incomplete/unknown children stop the batch and are never automatically replayed.',
                'risk' => 'write', 'confirm' => true],
        ];
    }

    public function up(): void
    {
        DB::transaction(function (): void {
            $frameworkRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($frameworkRow === null) {
                throw new RuntimeException('Native face batch requires the existing framework service policy.');
            }
            $framework = json_decode((string) $frameworkRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            CliFrameworkServicePolicy::validate($framework);
            foreach ($this->tools() as $name => $tool) {
                $existing = DB::table('agent_tool_registry')->where('name', $name)->first();
                if ($existing !== null && ! $this->registrationMatches($existing, $tool)) {
                    throw new RuntimeException('Conflicting registered native face batch service.');
                }
                $rule = $framework['tools'][$name] ?? null;
                $expected = ['target' => 'local', 'tree_scoped' => false, 'references' => []];
                if ($rule !== null && array_diff_key($rule, ['timeout_ms' => true, 'durable_receipts' => true]) !== $expected) {
                    throw new RuntimeException('Conflicting native face batch framework policy.');
                }
                $framework['tools'][$name] ??= $expected;
                $framework['tools'][$name]['timeout_ms'] ??= self::TIMEOUT_MS;
                DB::table('agent_tool_registry')->insertOrIgnore($this->registrationValues($tool) + ['name' => $name, 'created_at' => now()]);
                $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
                if ($registered === null || ! $this->registrationMatches($registered, $tool)) {
                    throw new RuntimeException('Registered native face batch service changed during migration.');
                }
            }
            DB::table('system_configs')->where('id', $frameworkRow->id)->update([
                'config_value' => json_encode($framework, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
            ]);
        });
    }

    private function registrationValues(array $tool): array
    {
        return ['service_class' => 'App\\Services\\FaceGenerationRestorationBatchService', 'method' => $tool['method'],
            'description' => $tool['description'], 'parameters' => json_encode($tool['parameters'], JSON_THROW_ON_ERROR),
            'returns_description' => $tool['returns'], 'permissions' => json_encode([$tool['risk'] === 'read' ? 'file:read' : 'file:write'], JSON_THROW_ON_ERROR),
            'enabled' => 1, 'risk_level' => $tool['risk'], 'category' => 'file',
            'requires_confirmation' => $tool['confirm'] ? 1 : 0, 'source' => 'config', 'updated_at' => now()];
    }

    private function registrationMatches(object $existing, array $tool): bool
    {
        $expected = $this->registrationValues($tool);
        foreach ($expected as $key => $value) {
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
        foreach ($this->tools() as $name => $tool) {
            $existing = DB::table('agent_tool_registry')->where('name', $name)->first();
            if ($existing !== null && $this->registrationMatches($existing, $tool) && (int) $existing->enabled === 1) {
                DB::table('agent_tool_registry')->where('id', $existing->id)->update(['enabled' => 0, 'updated_at' => now()]);
            }
        }
        $frameworkRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
        if ($frameworkRow !== null) {
            $framework = json_decode((string) $frameworkRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            foreach ([self::PLAN, self::EXECUTE] as $name) {
                $rule = $framework['tools'][$name] ?? null;
                if (is_array($rule) && ($rule['target'] ?? null) === 'local' && ($rule['tree_scoped'] ?? null) === false
                    && ($rule['references'] ?? null) === [] && ($rule['timeout_ms'] ?? null) === self::TIMEOUT_MS
                    && ! array_key_exists('durable_receipts', $rule)) {
                    unset($framework['tools'][$name]);
                }
            }
            DB::table('system_configs')->where('id', $frameworkRow->id)->update([
                'config_value' => json_encode($framework, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
            ]);
        }
    }
};
