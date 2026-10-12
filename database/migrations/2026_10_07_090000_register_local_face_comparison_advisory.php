<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NAME = 'file_local_face_comparison_plan';

    private const TOOL = [
        'method' => 'planLocalFaceComparison',
        'description' => 'Dry-run source, region, vector, and producer-proof pins for two existing face embedding IDs. No inference or writes.',
        'parameters' => ['type' => 'object', 'properties' => [
            'source_embedding_id' => ['type' => 'integer', 'minimum' => 1],
            'candidate_embedding_id' => ['type' => 'integer', 'minimum' => 1],
        ], 'required' => ['source_embedding_id', 'candidate_embedding_id']],
        'returns' => 'Current source and vector pins, compatible model/checkpoint/dimension contract digest, and exact expected input digest.',
        'risk' => 'read', 'confirm' => false,
    ];

    private const EXECUTE = [
        'method' => 'executeLocalFaceComparison',
        'description' => 'Run one confirmed local-only advisory comparison for the exact two current embedding IDs and dry-run digest. Never identifies, links, or clusters.',
        'parameters' => ['type' => 'object', 'properties' => [
            'source_embedding_id' => ['type' => 'integer', 'minimum' => 1],
            'candidate_embedding_id' => ['type' => 'integer', 'minimum' => 1],
            'expected_input_digest' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
        ], 'required' => ['source_embedding_id', 'candidate_embedding_id', 'expected_input_digest']],
        'returns' => 'Durable technical advice receipt and route trace ID; operator review remains required.',
        'risk' => 'write', 'confirm' => true,
    ];

    public function up(): void
    {
        $this->validateFramework();
        DB::transaction(function (): void {
            $this->validateFramework(true);
            foreach ([self::NAME => self::TOOL, 'file_local_face_comparison_execute' => self::EXECUTE] as $name => $tool) {
                DB::table('agent_tool_registry')->insertOrIgnore($this->values($name, $tool));
                $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
                if (! $registered || ! $this->matches($registered, $name, $tool)) {
                    throw new RuntimeException('Conflicting registered service for '.$name.'.');
                }
                if ((int) $registered->enabled === 1) {
                    $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
                    $framework = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
                    $framework['tools'][$name] ??= ['target' => 'local', 'tree_scoped' => false, 'references' => []];
                    DB::table('system_configs')->where('id', $row->id)->update([
                        'config_value' => json_encode($framework, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    private function validateFramework(bool $lock = false): void
    {
        $query = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services');
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if (! $row) {
            throw new RuntimeException('Missing table-owned CLI framework service policy.');
        }
        $framework = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($framework) || ! is_array($framework['tools'] ?? null)) {
            throw new RuntimeException('Invalid CLI framework service policy.');
        }
        foreach ([self::NAME => self::TOOL, 'file_local_face_comparison_execute' => self::EXECUTE] as $name => $tool) {
            $existing = DB::table('agent_tool_registry')->where('name', $name)->first();
            if ($existing && ! $this->matches($existing, $name, $tool)) {
                throw new RuntimeException('Conflicting registered service for '.$name.'.');
            }
            $expected = ['target' => 'local', 'tree_scoped' => false, 'references' => []];
            if ((! $existing || (int) $existing->enabled === 1) && isset($framework['tools'][$name]) && $framework['tools'][$name] !== $expected) {
                throw new RuntimeException('Conflicting framework service policy for '.$name.'.');
            }
        }
    }

    private function values(string $name, array $tool): array
    {
        return ['name' => $name, 'service_class' => 'App\\Services\\PhotoFaceComparisonAdvisoryService',
            'method' => $tool['method'], 'description' => $tool['description'],
            'parameters' => json_encode($tool['parameters'], JSON_THROW_ON_ERROR), 'returns_description' => $tool['returns'],
            'permissions' => json_encode([$tool['risk'] === 'read' ? 'file:read' : 'file:write'], JSON_THROW_ON_ERROR),
            'enabled' => 1, 'risk_level' => $tool['risk'], 'category' => 'file',
            'requires_confirmation' => $tool['confirm'] ? 1 : 0, 'source' => 'config', 'created_at' => now(), 'updated_at' => now()];
    }

    private function matches(object $registered, string $name, array $tool): bool
    {
        $expected = $this->values($name, $tool);
        foreach ($expected as $key => $value) {
            if (in_array($key, ['enabled', 'created_at', 'updated_at'], true)) {
                continue;
            }
            if (in_array($key, ['parameters', 'permissions'], true)) {
                if (json_decode((string) ($registered->{$key} ?? '')) != json_decode((string) $value)) {
                    return false;
                }
            } elseif (($registered->{$key} ?? null) != $value) {
                return false;
            }
        }

        return true;
    }

    public function down(): void
    {
        foreach ([self::NAME => self::TOOL, 'file_local_face_comparison_execute' => self::EXECUTE] as $name => $tool) {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->first();
            if ($row) {
                $policy = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
                if (($policy['tools'][$name] ?? null) === ['target' => 'local', 'tree_scoped' => false, 'references' => []]) {
                    unset($policy['tools'][$name]);
                    DB::table('system_configs')->where('id', $row->id)->update([
                        'config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
                    ]);
                }
            }
            $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
            if ($registered && (int) $registered->enabled === 1 && $this->matches($registered, $name, $tool)) {
                DB::table('agent_tool_registry')->where('name', $name)->update(['enabled' => 0, 'updated_at' => now()]);
            }
        }
    }
};
