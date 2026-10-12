<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NAME = 'file_image_source_metadata_transition_provenance';

    private const TOOL = [
        'method' => 'metadataTransitionProvenance',
        'description' => 'Inspect a retained file-source finding against same-asset metadata-only transition receipts, exact private preimages, and a fresh physical pixel/display proof. Read-only; never changes hashes, identities, review receipts, or queue state.',
        'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'findingId' => ['type' => 'integer', 'minimum' => 1],
            'expectedFindingDigest' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            'sourceKind' => ['type' => 'string', 'enum' => ['registry', 'media']],
            'sourceId' => ['type' => 'integer', 'minimum' => 1],
            'treeId' => ['type' => 'integer', 'minimum' => 1],
        ], 'required' => ['findingId', 'expectedFindingDigest', 'sourceKind', 'sourceId']],
        'returns' => 'Bounded finding-bound provenance status, hashes and packet digests only. Private paths, raw metadata, identity decisions and write authority are excluded.',
        'risk' => 'read', 'confirm' => false,
    ];

    public function up(): void
    {
        $this->validate(true);
        DB::transaction(function (): void {
            $this->validate(true);
            DB::table('agent_tool_registry')->insertOrIgnore($this->values() + [
                'name' => self::NAME, 'created_at' => now(),
            ]);
            $registered = DB::table('agent_tool_registry')->where('name', self::NAME)->first();
            if ($registered === null || ! $this->matches($registered)) {
                throw new RuntimeException('Registered service changed during migration.');
            }
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            $framework = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            if ((int) $registered->enabled === 1) {
                $framework['tools'][self::NAME] ??= $this->executionRule();
            }
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($framework, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
            ]);
        });
    }

    private function validate(bool $lock = false): void
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
        $query = DB::table('agent_tool_registry')->where('name', self::NAME);
        if ($lock) {
            $query->lockForUpdate();
        }
        $existing = $query->first();
        if ($existing !== null && ! $this->matches($existing)) {
            throw new RuntimeException('Conflicting registered service for '.self::NAME.'.');
        }
        if ($existing === null && isset($framework['tools'][self::NAME])
            && $framework['tools'][self::NAME] !== $this->executionRule()) {
            throw new RuntimeException('Conflicting framework service policy for '.self::NAME.'.');
        }
    }

    private function values(): array
    {
        return ['service_class' => 'App\\Services\\FileRegistryImageSourceAuditService',
            'method' => self::TOOL['method'], 'description' => self::TOOL['description'],
            'parameters' => json_encode(self::TOOL['parameters'], JSON_THROW_ON_ERROR),
            'returns_description' => self::TOOL['returns'],
            'permissions' => json_encode(['file:read'], JSON_THROW_ON_ERROR),
            'enabled' => 1, 'risk_level' => 'read', 'category' => 'file',
            'requires_confirmation' => 0, 'source' => 'config', 'updated_at' => now()];
    }

    private function matches(object $existing): bool
    {
        foreach ($this->values() as $key => $value) {
            if (in_array($key, ['enabled', 'updated_at'], true)) {
                continue;
            }
            if ($key === 'parameters' || $key === 'permissions') {
                if (json_decode((string) ($existing->{$key} ?? '')) != json_decode((string) $value)) {
                    return false;
                }
            } elseif (($existing->{$key} ?? null) != $value) {
                return false;
            }
        }

        return true;
    }

    private function executionRule(): array
    {
        return ['target' => 'local', 'tree_scoped' => false, 'references' => [], 'timeout_ms' => 30000];
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($row !== null) {
                $framework = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
                $registered = DB::table('agent_tool_registry')->where('name', self::NAME)->lockForUpdate()->first();
                if ($registered !== null && $this->matches($registered)
                    && ($framework['tools'][self::NAME] ?? null) === $this->executionRule()) {
                    unset($framework['tools'][self::NAME]);
                    DB::table('system_configs')->where('id', $row->id)->update([
                        'config_value' => json_encode($framework, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
                    ]);
                }
            }
            $registered = DB::table('agent_tool_registry')->where('name', self::NAME)->lockForUpdate()->first();
            if ($registered !== null && $this->matches($registered) && (int) $registered->enabled === 1) {
                DB::table('agent_tool_registry')->where('name', self::NAME)->update(['enabled' => 0, 'updated_at' => now()]);
            }
        });
    }
};
