<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SELECTORS = ['type' => 'array', 'minItems' => 1, 'items' => [
        'type' => 'object', 'additionalProperties' => false,
        'properties' => ['type' => ['type' => 'string', 'enum' => ['person', 'name']],
            'value' => ['oneOf' => [['type' => 'integer', 'minimum' => 1], ['type' => 'string', 'minLength' => 1]]]],
        'required' => ['type', 'value'],
    ]];

    private const TOOLS = [
        'file_face_profiles_plan' => [
            'method' => 'plan', 'description' => 'Read an explicit derived appearance-profile refresh scope and exact plan/state hashes; no identity approval or writes.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['selectors' => self::SELECTORS], 'required' => ['selectors']],
            'returns' => 'Plan/state SHA256 and source/projection counts.', 'risk' => 'read', 'confirm' => false,
        ],
        'file_face_profiles_refresh' => [
            'method' => 'refresh', 'description' => 'Refresh only explicitly reviewed derived appearance profiles with verified private preimages and exact hashes under real CLI approval. No identities, tree links or review decisions change.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'selectors' => self::SELECTORS,
                'expectedPlanSha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                'expectedStateSha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                'idempotencyKey' => ['type' => 'string', 'format' => 'uuid']],
                'required' => ['selectors', 'expectedPlanSha256', 'expectedStateSha256', 'idempotencyKey']],
            'returns' => 'Technical receipt, verified private backup and exact projection counts; unknown outcomes held.',
            'risk' => 'write', 'confirm' => true,
        ],
    ];

    public function up(): void
    {
        $this->validateFrameworkAndRegistrations();
        DB::transaction(function (): void {
            $this->validateFrameworkAndRegistrations(true);
            foreach (self::TOOLS as $name => $tool) {
                DB::table('agent_tool_registry')->insertOrIgnore($this->registrationValues($tool) + [
                    'name' => $name, 'created_at' => now(),
                ]);
                $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
                if ($registered === null || ! $this->registrationMatches($registered, $tool)) {
                    throw new RuntimeException('Registered service changed during migration for '.$name.'.');
                }
            }
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            $framework = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            foreach (array_keys(self::TOOLS) as $tool) {
                if ((int) DB::table('agent_tool_registry')->where('name', $tool)->value('enabled') === 1) {
                    $framework['tools'][$tool] ??= ['target' => 'local', 'tree_scoped' => false, 'references' => []];
                }
            }
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($framework, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
            ]);
        });
    }

    private function validateFrameworkAndRegistrations(bool $lock = false): void
    {
        $query = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services');
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if ($row === null) {
            throw new RuntimeException('Missing table-owned CLI framework service policy.');
        }
        $framework = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($framework) || ! is_array($framework['tools'] ?? null)) {
            throw new RuntimeException('Invalid CLI framework service policy.');
        }
        foreach (self::TOOLS as $name => $tool) {
            $expectedPolicy = ['target' => 'local', 'tree_scoped' => false, 'references' => []];
            $existing = DB::table('agent_tool_registry')->where('name', $name)->first();
            if ($existing !== null && ! $this->registrationMatches($existing, $tool)) {
                throw new RuntimeException('Conflicting registered service for '.$name.'.');
            }
            if (($existing === null || (int) $existing->enabled === 1)
                && isset($framework['tools'][$name]) && $framework['tools'][$name] !== $expectedPolicy) {
                throw new RuntimeException('Conflicting framework service policy for '.$name.'.');
            }
        }
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

    private function registrationValues(array $tool): array
    {
        return ['service_class' => 'App\\Services\\Genealogy\\AppearanceProfileRefreshService', 'method' => $tool['method'],
            'description' => $tool['description'], 'parameters' => json_encode($tool['parameters'], JSON_THROW_ON_ERROR),
            'returns_description' => $tool['returns'],
            'permissions' => json_encode([$tool['risk'] === 'read' ? 'file:read' : 'file:write'], JSON_THROW_ON_ERROR),
            'enabled' => 1, 'risk_level' => $tool['risk'], 'category' => 'file',
            'requires_confirmation' => $tool['confirm'] ? 1 : 0, 'source' => 'config', 'updated_at' => now()];
    }

    public function down(): void
    {
        if (DB::table('review_desk_receipts')->where('action', 'refresh_face_profiles')->exists()) {
            throw new RuntimeException('Retain populated technical refresh receipts; disable execution explicitly.');
        }
        if (DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->exists()) {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            $policy = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            foreach (array_keys(self::TOOLS) as $tool) {
                if (($policy['tools'][$tool] ?? null) === ['target' => 'local', 'tree_scoped' => false, 'references' => []]) {
                    unset($policy['tools'][$tool]);
                }
            }
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
            ]);
        }
        foreach (self::TOOLS as $name => $tool) {
            $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
            if ($registered !== null && $this->registrationMatches($registered, $tool) && (int) $registered->enabled === 1) {
                DB::table('agent_tool_registry')->where('name', $name)->update(['enabled' => 0, 'updated_at' => now()]);
            }
        }
    }
};
