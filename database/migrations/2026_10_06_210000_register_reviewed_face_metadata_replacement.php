<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ASSET_UUID = ['type' => 'string', 'format' => 'uuid'];

    private const SHA256 = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];

    private const REPLACEMENTS = ['type' => 'array', 'minItems' => 1, 'maxItems' => 32, 'items' => [
        'type' => 'object', 'additionalProperties' => false,
        'properties' => [
            'region_index' => ['type' => 'integer', 'minimum' => 0],
            'approved_face_id' => ['type' => 'integer', 'minimum' => 1],
            'approval_receipt_id' => ['type' => 'string', 'format' => 'uuid'],
        ], 'required' => ['region_index', 'approved_face_id', 'approval_receipt_id'],
    ]];

    private const TOOLS = [
        'file_reviewed_face_metadata_plan' => [
            'method' => 'plan',
            'description' => 'Read current face-region metadata for one selected asset UUID and return source and raw RegionInfo hashes plus region indices for a reviewed replacement plan. No writes or identity decisions.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false,
                'properties' => ['assetUuid' => self::ASSET_UUID], 'required' => ['assetUuid']],
            'returns' => 'Current asset UUID, source SHA256, RegionInfo SHA256, dimensions and complete region list.',
            'risk' => 'read', 'confirm' => false,
        ],
        'file_reviewed_face_metadata_replace' => [
            'method' => 'replace',
            'description' => 'Preview or replace the complete MWG face region list for one exact asset. Every RegionList index must map to a currently approved face and its exact identity approval receipt. Physical writes require trusted CLI approval of this full argument set; no identity, queue or genealogy decision is changed.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'assetUuid' => self::ASSET_UUID,
                'expectedSourceHash' => self::SHA256,
                'expectedRegionInfoHash' => self::SHA256,
                'replacements' => self::REPLACEMENTS,
                'idempotencyKey' => ['type' => 'string', 'format' => 'uuid'],
                'dryRun' => ['type' => 'boolean', 'default' => true],
            ], 'required' => ['assetUuid', 'expectedSourceHash', 'expectedRegionInfoHash', 'replacements', 'idempotencyKey']],
            'returns' => 'Exact preview or durable technical write receipt; unresolved physical outcomes are held and never retried automatically.',
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
                    throw new RuntimeException('Conflicting reviewed face metadata tool registration.');
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
                throw new RuntimeException('Conflicting reviewed face metadata tool registration.');
            }
            if (($registered === null || (int) $registered->enabled === 1)
                && isset($framework['tools'][$name]) && $framework['tools'][$name] !== $expectedPolicy) {
                throw new RuntimeException('Conflicting CLI framework policy for reviewed face metadata.');
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
        return ['service_class' => 'App\\Services\\Files\\ReviewedFaceMetadataReplacementService',
            'method' => $tool['method'], 'description' => $tool['description'],
            'parameters' => json_encode($tool['parameters'], JSON_THROW_ON_ERROR),
            'returns_description' => $tool['returns'],
            'permissions' => json_encode([$tool['risk'] === 'read' ? 'file:read' : 'file:write'], JSON_THROW_ON_ERROR),
            'enabled' => 1, 'risk_level' => $tool['risk'], 'category' => 'file',
            'requires_confirmation' => $tool['confirm'] ? 1 : 0, 'source' => 'config', 'updated_at' => now()];
    }

    public function down(): void
    {
        if (DB::table('review_desk_receipts')->where('action', 'replace_reviewed_face_metadata')->exists()) {
            throw new RuntimeException('Retain reviewed face metadata receipts; disable execution explicitly.');
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
