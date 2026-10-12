<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const METHODS = ['file_face_reconciliation_plan' => 'plan', 'file_face_reconciliation_execute' => 'execute'];

    private function intentSchema(): array
    {
        $ids = ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'uniqueItems' => true];

        return ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'face_ids' => $ids + ['minItems' => 1], 'vector_ids' => $ids, 'preserved_cluster_ids' => $ids,
            'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
        ], 'required' => ['face_ids', 'vector_ids', 'preserved_cluster_ids', 'reason']];
    }

    public function up(): void
    {
        $this->change(true);
    }

    public function down(): void
    {
        if (DB::table('file_registry_face_reconciliation_runs')->where('manifest_json', 'like', '%operator_reviewed_legacy_retirement_v1%')->exists()) {
            throw new RuntimeException('Retain reviewed legacy retirement registrations and receipt custody.');
        }
        $this->change(false);
    }

    private function change(bool $add): void
    {
        DB::transaction(function () use ($add): void {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            $policy = $row ? json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR) : null;
            if (! is_array($policy['tools'] ?? null)) {
                throw new RuntimeException('Missing table-owned reconciliation framework policy.');
            }
            foreach (self::METHODS as $name => $method) {
                $tool = DB::table('agent_tool_registry')->where('name', $name)->lockForUpdate()->first();
                $risk = $method === 'plan' ? 'read' : 'write';
                if (! $tool || $tool->service_class !== 'App\\Services\\FaceGenerationReconciliationService'
                    || $tool->method !== $method || $tool->risk_level !== $risk
                    || (int) $tool->requires_confirmation !== ($method === 'execute' ? 1 : 0)
                    || ((int) $tool->enabled === 1 && ($policy['tools'][$name] ?? null) !== ['target' => 'local', 'tree_scoped' => false, 'references' => []])) {
                    throw new RuntimeException('Conflicting reconciliation registration or framework policy.');
                }
                $parameters = json_decode($tool->parameters, true, flags: JSON_THROW_ON_ERROR);
                $optional = $parameters['properties']['legacy_retirement_intent'] ?? null;
                if (! is_array($parameters['properties'] ?? null) || ! is_array($parameters['required'] ?? null)
                    || in_array('legacy_retirement_intent', $parameters['required'], true)
                    || ($optional !== null && $optional !== $this->intentSchema())) {
                    throw new RuntimeException('Conflicting reconciliation intent parameter.');
                }
                if ($add) {
                    $parameters['properties']['legacy_retirement_intent'] = $this->intentSchema();
                } else {
                    unset($parameters['properties']['legacy_retirement_intent']);
                }
                // Routing, enablement and operator-owned policy remain authoritative.
                DB::table('agent_tool_registry')->where('id', $tool->id)->update([
                    'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
                ]);
            }
        });
    }
};
