<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SHA256 = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];

    private const IDENTITY = ['type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]{7,118}$'];

    private const TOOLS = [
        'model_host_technical_fence_plan' => [
            'method' => 'planTechnicalFence',
            'description' => 'Read one terminal undispatched controller reservation and fresh exact host quiescence. Return its owner, request, lease, target, custody digest and host generation for operator review. No lifecycle action or inference.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
            'returns' => 'Exact technical reservation and host generation; original activation outcome remains unknown.',
            'risk' => 'read', 'confirm' => false,
        ],
        'model_host_technical_fence_apply' => [
            'method' => 'fenceActivation',
            'description' => 'Preview or explicitly fence one exact terminal undispatched controller activation reservation. Applying requires trusted CLI approval of every argument and fresh clear-host generation proof; no model is activated, stopped, loaded or replayed. Retain the original activation as unknown and preserve its permanent replay fence.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'owner' => ['type' => 'string', 'format' => 'uuid'],
                'requestId' => self::IDENTITY, 'leaseId' => self::IDENTITY,
                'target' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9_.:-]{2,63}$'],
                'custodyDigest' => self::SHA256, 'hostGeneration' => self::SHA256,
                'idempotencyKey' => ['type' => 'string', 'format' => 'uuid'],
                'dryRun' => ['type' => 'boolean', 'default' => true],
            ], 'required' => ['owner', 'requestId', 'leaseId', 'target', 'custodyDigest', 'hostGeneration', 'idempotencyKey']],
            'returns' => 'Distinct durable technical fence readback and exact reservation reconciliation; uncertain outcomes remain held.',
            'risk' => 'write', 'confirm' => true,
        ],
    ];

    public function up(): void
    {
        $this->validateExisting();
        DB::transaction(function (): void {
            $this->validateExisting(true);
            foreach (self::TOOLS as $name => $tool) {
                DB::table('agent_tool_registry')->insertOrIgnore($this->registrationValues($tool) + [
                    'name' => $name, 'created_at' => now(),
                ]);
                $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
                if ($registered === null || ! $this->registrationMatches($registered, $tool)) {
                    throw new RuntimeException('Conflicting controller technical fence tool registration.');
                }
            }
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            $framework = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            foreach (array_keys(self::TOOLS) as $name) {
                if ((int) DB::table('agent_tool_registry')->where('name', $name)->value('enabled') === 1) {
                    $framework['tools'][$name] ??= ['target' => 'local', 'tree_scoped' => false, 'references' => []];
                }
            }
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($framework, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
            ]);
        });
    }

    private function validateExisting(bool $lock = false): void
    {
        $query = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services');
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if ($row === null) {
            throw new RuntimeException('Missing table-owned CLI framework services policy.');
        }
        $framework = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($framework) || ! is_array($framework['tools'] ?? null)) {
            throw new RuntimeException('Invalid CLI framework services policy.');
        }
        $expectedPolicy = ['target' => 'local', 'tree_scoped' => false, 'references' => []];
        foreach (self::TOOLS as $name => $tool) {
            $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
            if ($registered !== null && ! $this->registrationMatches($registered, $tool)) {
                throw new RuntimeException('Conflicting controller technical fence tool registration.');
            }
            if (($registered === null || (int) $registered->enabled === 1)
                && isset($framework['tools'][$name]) && $framework['tools'][$name] !== $expectedPolicy) {
                throw new RuntimeException('Conflicting CLI framework policy for controller technical fence.');
            }
        }
    }

    private function registrationMatches(object $row, array $tool): bool
    {
        foreach ($this->registrationValues($tool) as $column => $expected) {
            if (in_array($column, ['enabled', 'updated_at'], true)) {
                continue;
            }
            if (in_array($column, ['parameters', 'permissions'], true)) {
                if (json_decode((string) ($row->{$column} ?? '')) != json_decode((string) $expected)) {
                    return false;
                }
            } elseif (($row->{$column} ?? null) != $expected) {
                return false;
            }
        }

        return true;
    }

    private function registrationValues(array $tool): array
    {
        return ['service_class' => 'App\\Services\\ModelHostControllerAutomaticSelectionService',
            'method' => $tool['method'], 'description' => $tool['description'],
            'parameters' => json_encode($tool['parameters']['properties'] === [] ? array_replace($tool['parameters'], ['properties' => (object) []]) : $tool['parameters'], JSON_THROW_ON_ERROR),
            'returns_description' => $tool['returns'],
            'permissions' => json_encode([$tool['risk'] === 'read' ? 'system:read' : 'system:write'], JSON_THROW_ON_ERROR),
            'enabled' => 1, 'risk_level' => $tool['risk'], 'category' => 'system',
            'requires_confirmation' => $tool['confirm'] ? 1 : 0, 'source' => 'config', 'updated_at' => now()];
    }

    public function down(): void
    {
        if (DB::table('distributed_tasks')->where('result->reconciliation->evidence', 'operator_conditional_technical_fence')->exists()) {
            throw new RuntimeException('Permanent controller fences and original unknown actions must survive rollback; disable execution explicitly.');
        }
        $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
        if ($row !== null) {
            $framework = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            foreach (array_keys(self::TOOLS) as $name) {
                if (($framework['tools'][$name] ?? null) === ['target' => 'local', 'tree_scoped' => false, 'references' => []]) {
                    unset($framework['tools'][$name]);
                }
            }
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($framework, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
            ]);
        }
        foreach (self::TOOLS as $name => $tool) {
            $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
            if ($registered !== null && (int) $registered->enabled === 1 && $this->registrationMatches($registered, $tool)) {
                DB::table('agent_tool_registry')->where('name', $name)->update(['enabled' => 0, 'updated_at' => now()]);
            }
        }
    }
};
