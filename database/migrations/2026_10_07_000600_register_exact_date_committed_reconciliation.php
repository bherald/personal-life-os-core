<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const UUID = ['type' => 'string', 'format' => 'uuid'];

    private const SHA256 = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];

    private const TOOLS = [
        'file_exact_capture_date_reconcile_committed' => [
            'method' => 'reconcile', 'description' => 'Record a distinct technical completion for a verified committed writer. Original UNKNOWN and operator decision remain immutable; no physical rewrite.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'originalReceiptId' => self::UUID, 'expectedPlanHash' => self::SHA256, 'expectedStateHash' => self::SHA256,
                'idempotencyKey' => self::UUID, 'dryRun' => ['type' => 'boolean', 'default' => true],
            ], 'required' => ['originalReceiptId', 'expectedPlanHash', 'expectedStateHash', 'idempotencyKey']],
            'returns' => 'Distinct completion receipt or held changed/partial evidence.', 'risk' => 'write', 'confirm' => true,
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
                    throw new RuntimeException('Conflicting exact capture-date CLI registration.');
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
        return ['service_class' => 'App\\Services\\Files\\ExactCaptureDateReconciliationService',
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
        if (DB::table('review_desk_receipts')->where('action', 'exact_date_commit_reconciled')->exists()) {
            throw new RuntimeException('Retain exact capture-date receipts and disable execution explicitly.');
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
