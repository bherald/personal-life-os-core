<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const UUID = ['type' => 'string', 'format' => 'uuid'];

    private const SHA256 = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];

    private const TOOLS = [
        'file_exact_catalog_date_plan' => [
            'method' => 'planExactCatalogDate',
            'description' => 'Read one photo catalog preimage and current source hash for an operator supplied ISO original date with unknown capture time.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false,
                'properties' => ['assetUuid' => self::UUID, 'captureDate' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$']],
                'required' => ['assetUuid', 'captureDate']],
            'returns' => 'Current catalog preimage/hash and physical source hash; no writes.', 'risk' => 'read', 'confirm' => false,
        ],
        'file_exact_catalog_date_set' => [
            'method' => 'setExactCatalogDate',
            'description' => 'Preview by default or set one operator confirmed exact original date in catalog only. Unknown time uses documented midnight storage placeholder. Preserves immutable prior catalog receipt, scan context, source bytes and identity decisions. Trusted registered CLI approval required.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'assetUuid' => self::UUID, 'captureDate' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
                'expectedSourceHash' => self::SHA256, 'expectedCatalogPreimageHash' => self::SHA256,
                'idempotencyKey' => self::UUID, 'dryRun' => ['type' => 'boolean', 'default' => true],
            ], 'required' => ['assetUuid', 'captureDate', 'expectedSourceHash', 'expectedCatalogPreimageHash', 'idempotencyKey']],
            'returns' => 'Catalog-only date decision and receipt retaining prior fields. No physical metadata write or identity approval.', 'risk' => 'write', 'confirm' => true,
        ],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            $framework = $row ? json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR) : null;
            if (! is_array($framework['tools'] ?? null)) {
                throw new RuntimeException('Missing table-owned CLI framework services policy.');
            }
            foreach (self::TOOLS as $name => $tool) {
                $values = $this->values($tool);
                DB::table('agent_tool_registry')->insertOrIgnore($values + ['name' => $name, 'created_at' => now()]);
                $existing = DB::table('agent_tool_registry')->where('name', $name)->first();
                if (! $existing || ! $this->matches($existing, $tool)) {
                    throw new RuntimeException('Conflicting exact catalog date CLI registration.');
                }
                if ((int) $existing->enabled === 1) {
                    $framework['tools'][$name] ??= ['target' => 'local', 'tree_scoped' => false, 'references' => []];
                }
            }
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($framework, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
            ]);
        });
    }

    private function values(array $tool): array
    {
        return ['service_class' => 'App\\Services\\Files\\ScanDateEstimateReviewDecisionService',
            'method' => $tool['method'], 'description' => $tool['description'],
            'parameters' => json_encode($tool['parameters'], JSON_THROW_ON_ERROR),
            'returns_description' => $tool['returns'],
            'permissions' => json_encode([$tool['risk'] === 'read' ? 'file:read' : 'file:write'], JSON_THROW_ON_ERROR),
            'enabled' => 1, 'risk_level' => $tool['risk'], 'category' => 'file',
            'requires_confirmation' => $tool['confirm'] ? 1 : 0, 'source' => 'config', 'updated_at' => now()];
    }

    private function matches(object $row, array $tool): bool
    {
        foreach ($this->values($tool) as $key => $value) {
            if (in_array($key, ['enabled', 'updated_at'], true)) {
                continue;
            }
            if (in_array($key, ['parameters', 'permissions'], true)) {
                if (json_decode((string) $row->{$key}) != json_decode((string) $value)) {
                    return false;
                }
            } elseif (($row->{$key} ?? null) != $value) {
                return false;
            }
        }

        return true;
    }

    public function down(): void
    {
        if (DB::table('review_desk_receipts')->where('action', 'set_exact_catalog_date')->exists()) {
            throw new RuntimeException('Retain exact catalog date receipts and disable execution explicitly.');
        }
        DB::transaction(function (): void {
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
                if ($registered && (int) $registered->enabled === 1 && $this->matches($registered, $tool)) {
                    DB::table('agent_tool_registry')->where('name', $name)->update(['enabled' => 0, 'updated_at' => now()]);
                }
            }
        });
    }
};
