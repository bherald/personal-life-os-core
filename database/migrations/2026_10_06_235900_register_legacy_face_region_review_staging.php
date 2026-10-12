<?php

use App\Services\Review\LegacyFaceRegionReviewStagingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const UUID = ['type' => 'string', 'format' => 'uuid'];

    private const SHA256 = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];

    private const SELECTORS = [
        'faceId' => ['type' => 'integer', 'minimum' => 1],
        'treeId' => ['type' => 'integer', 'minimum' => 1],
        'mediaId' => ['type' => 'integer', 'minimum' => 1],
        'personId' => ['type' => 'integer', 'minimum' => 1],
        'assetUuid' => self::UUID,
    ];

    private const TOOLS = [
        'genealogy_legacy_face_region_review_plan' => [
            'method' => 'plan',
            'description' => 'Read the exact current source, legacy face row, raw RegionInfo, and retained review-history bindings for one selected tree/media/person/asset. No writes or identity decisions.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => self::SELECTORS,
                'required' => ['faceId', 'treeId', 'mediaId', 'personId', 'assetUuid']],
            'returns' => 'Read-only source and snapshot hashes with a pending no-match region-review preview.',
            'risk' => 'read', 'confirm' => false,
        ],
        'genealogy_legacy_face_region_review_stage' => [
            'method' => 'stage',
            'description' => 'Stage one explicitly selected current legacy face as a pending no-match region-only Review Desk item. Requires exact fresh source/snapshot/history hashes and trusted CLI confirmation. Preserves the legacy row and changes no identity decision.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => self::SELECTORS + [
                'sourceSha256' => self::SHA256,
                'faceSnapshotSha256' => self::SHA256,
                'regionInfoSha256' => self::SHA256,
                'reviewHistorySha256' => self::SHA256,
                'pathBindingSha256' => self::SHA256,
                'idempotencyKey' => self::UUID,
            ], 'required' => ['faceId', 'treeId', 'mediaId', 'personId', 'assetUuid', 'sourceSha256',
                'faceSnapshotSha256', 'regionInfoSha256', 'reviewHistorySha256', 'pathBindingSha256', 'idempotencyKey']],
            'returns' => 'Exact pending region-review reference and durable receipt; no identity approval, rejection, or source write.',
            'risk' => 'write', 'confirm' => true,
        ],
    ];

    private const POLICY = ['target' => 'workflow', 'tree_scoped' => true, 'tree_argument' => 'treeId', 'references' => []];

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
                    throw new RuntimeException('Conflicting legacy face region review registration.');
                }
            }
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            $framework = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            foreach (array_keys(self::TOOLS) as $name) {
                if ((int) DB::table('agent_tool_registry')->where('name', $name)->value('enabled') === 1) {
                    $framework['tools'][$name] ??= self::POLICY;
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
        foreach (self::TOOLS as $name => $tool) {
            $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
            if ($registered !== null && ! $this->registrationMatches($registered, $tool)) {
                throw new RuntimeException('Conflicting legacy face region review registration.');
            }
            if (($registered === null || (int) $registered->enabled === 1)
                && isset($framework['tools'][$name]) && $framework['tools'][$name] !== self::POLICY) {
                throw new RuntimeException('Conflicting CLI framework policy for legacy face region review.');
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
        return ['service_class' => LegacyFaceRegionReviewStagingService::class, 'method' => $tool['method'],
            'description' => $tool['description'], 'parameters' => json_encode($tool['parameters'], JSON_THROW_ON_ERROR),
            'returns_description' => $tool['returns'],
            'permissions' => json_encode($tool['risk'] === 'read' ? ['genealogy:read'] : ['genealogy:read', 'genealogy:write'], JSON_THROW_ON_ERROR),
            'enabled' => 1, 'risk_level' => $tool['risk'], 'category' => 'genealogy',
            'requires_confirmation' => $tool['confirm'] ? 1 : 0, 'source' => 'config', 'updated_at' => now()];
    }

    public function down(): void
    {
        if (DB::table('review_desk_receipts')->where('action', LegacyFaceRegionReviewStagingService::ACTION)->exists()) {
            throw new RuntimeException('Retain legacy face region review receipts; disable execution explicitly.');
        }
        $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
        if ($row !== null) {
            $framework = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            foreach (array_keys(self::TOOLS) as $name) {
                if (($framework['tools'][$name] ?? null) === self::POLICY) {
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
