<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TOOLS = [
        'file_image_source_finding_recheck' => [
            'method' => 'recheckFinding',
            'description' => 'Read one retained inspection finding with exact digest/source/tree bindings and a bounded fresh local source recheck. Advertise existing read-plan candidates only; no repairs, identities, historical hash backfill or queue decisions.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'findingId' => ['type' => 'integer', 'minimum' => 1],
                'expectedFindingDigest' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                'sourceKind' => ['type' => 'string', 'enum' => ['registry', 'media']],
                'sourceId' => ['type' => 'integer', 'minimum' => 1],
                'treeId' => ['type' => 'integer', 'minimum' => 1],
            ], 'required' => ['findingId', 'expectedFindingDigest', 'sourceKind', 'sourceId']],
            'returns' => 'Fresh redacted source observation and preliminary existing-plan eligibility. Ordinary views only expose admission; every result remains write_ready=false.',
            'risk' => 'read', 'confirm' => false,
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
                    $framework['tools'][$tool] ??= $this->executionRule($tool);
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
            $expectedPolicy = $this->executionRule($name);
            $registrationQuery = DB::table('agent_tool_registry')->where('name', $name);
            if ($lock) {
                $registrationQuery->lockForUpdate();
            }
            $existing = $registrationQuery->first();
            if ($existing !== null && ! $this->registrationMatches($existing, $tool)) {
                throw new RuntimeException('Conflicting registered service for '.$name.'.');
            }
            if ($existing === null
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
        return ['service_class' => 'App\\Services\\FileRegistryImageSourceAuditService', 'method' => $tool['method'],
            'description' => $tool['description'], 'parameters' => json_encode($tool['parameters'], JSON_THROW_ON_ERROR),
            'returns_description' => $tool['returns'],
            'permissions' => json_encode([$tool['risk'] === 'read' ? 'file:read' : 'file:write'], JSON_THROW_ON_ERROR),
            'enabled' => 1, 'risk_level' => $tool['risk'], 'category' => 'file',
            'requires_confirmation' => $tool['confirm'] ? 1 : 0, 'source' => 'config', 'updated_at' => now()];
    }

    private function executionRule(string $name): array
    {
        return ['target' => 'local', 'tree_scoped' => false, 'references' => [], 'timeout_ms' => 30000];
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($row !== null) {
                $policy = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
                foreach (self::TOOLS as $name => $tool) {
                    $registered = DB::table('agent_tool_registry')->where('name', $name)->lockForUpdate()->first();
                    if ($registered !== null && $this->registrationMatches($registered, $tool)
                        && ($policy['tools'][$name] ?? null) === $this->executionRule($name)) {
                        unset($policy['tools'][$name]);
                    }
                }
                DB::table('system_configs')->where('id', $row->id)->update([
                    'config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
                ]);
            }
            foreach (self::TOOLS as $name => $tool) {
                $registered = DB::table('agent_tool_registry')->where('name', $name)->lockForUpdate()->first();
                if ($registered !== null && $this->registrationMatches($registered, $tool) && (int) $registered->enabled === 1) {
                    DB::table('agent_tool_registry')->where('name', $name)->update(['enabled' => 0, 'updated_at' => now()]);
                }
            }
        });
    }
};
