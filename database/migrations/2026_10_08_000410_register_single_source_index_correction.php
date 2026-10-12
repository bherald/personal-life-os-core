<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const HASH = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];

    private const UUID = ['type' => 'string', 'format' => 'uuid'];

    private const TOOLS = [
        'file_single_source_index_plan' => [
            'method' => 'singleSourceIndexPlan', 'description' => 'Read a lone legacy lowercase path-index correction snapshot and fresh current source hash. No identities or writes.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'assetUuid' => self::UUID],
                'required' => ['assetUuid']],
            'returns' => 'Exact snapshot/current-source SHA256 and old/new path-index values; historical content hash remains unchanged.',
            'risk' => 'read', 'confirm' => false,
        ],
        'file_single_source_index_correct' => [
            'method' => 'correctReviewedSingleSourceIndex', 'description' => 'Correct only a reviewed lone lowercase path index with fresh exact CLI authority, source/snapshot proof and private preimage. Preserve asset UUID, content hashes, identities, rows, faces, vectors and relations. Dry-run first; ambiguous outcomes remain held.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'assetUuid' => self::UUID,
                'expectedSnapshotSha256' => self::HASH, 'expectedSourceSha256' => self::HASH,
                'idempotencyKey' => self::UUID, 'dryRun' => ['type' => 'boolean', 'default' => true]],
                'required' => ['assetUuid', 'expectedSnapshotSha256', 'expectedSourceSha256', 'idempotencyKey']],
            'returns' => 'Immutable technical index-only correction receipt with preserved historical source evidence and exact readback.',
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
        return ['service_class' => 'App\\Services\\FileRegistryService', 'method' => $tool['method'],
            'description' => $tool['description'], 'parameters' => json_encode($tool['parameters'], JSON_THROW_ON_ERROR),
            'returns_description' => $tool['returns'],
            'permissions' => json_encode([$tool['risk'] === 'read' ? 'file:read' : 'file:write'], JSON_THROW_ON_ERROR),
            'enabled' => 1, 'risk_level' => $tool['risk'], 'category' => 'file',
            'requires_confirmation' => $tool['confirm'] ? 1 : 0, 'source' => 'config', 'updated_at' => now()];
    }

    private function executionRule(string $name): array
    {
        $rule = ['target' => 'local', 'tree_scoped' => false, 'references' => []];
        if ($name === 'file_single_source_index_correct') {
            $rule['timeout_ms'] = 120000;
        }

        return $rule;
    }

    public function down(): void
    {
        if (DB::table('review_desk_receipts')->where('action', 'source_index_correct')->exists()) {
            throw new RuntimeException('Retain populated technical refresh receipts; disable execution explicitly.');
        }
        if (DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->exists()) {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            $policy = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            foreach (array_keys(self::TOOLS) as $tool) {
                if (($policy['tools'][$tool] ?? null) === $this->executionRule($tool)) {
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
