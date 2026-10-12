<?php

use App\Services\Review\LegacyFaceRegionReviewStagingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const UUID = ['type' => 'string', 'format' => 'uuid'];

    private const SHA256 = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];

    private const REGION = ['type' => 'object', 'additionalProperties' => false, 'properties' => [
        'x' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
        'y' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
        'w' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 1],
        'h' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 1],
    ], 'required' => ['x', 'y', 'w', 'h']];

    private const SELECTORS = [
        'faceId' => ['type' => 'integer', 'minimum' => 1],
        'treeId' => ['type' => 'integer', 'minimum' => 1],
        'mediaId' => ['type' => 'integer', 'minimum' => 1],
        'personId' => ['type' => 'integer', 'minimum' => 1],
        'assetUuid' => self::UUID,
        'proposedRegion' => self::REGION,
    ];

    private const TOOLS = [
        'genealogy_operator_photo_region_plan' => [
            'method' => 'planOperatorSelectedRegion',
            'description' => 'Read source-bound evidence for one operator-selected current photo region tied to an exact tree/media/person context and unbound legacy AI face row. No identity decision or writes.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => self::SELECTORS,
                'required' => ['faceId', 'treeId', 'mediaId', 'personId', 'assetUuid', 'proposedRegion']],
            'returns' => 'Exact current source SHA/dimensions, face/media/history snapshots, proposed ROI, and a pending no-match region-review preview.',
            'risk' => 'read', 'confirm' => false,
        ],
        'genealogy_operator_photo_region_stage' => [
            'method' => 'stageOperatorSelectedRegion',
            'description' => 'Stage one explicitly selected current photo ROI as a pending no-match region-only Review Desk item. Requires exact current source, source dimensions, row/media/history bindings, idempotency key, and trusted CLI confirmation. Preserves legacy face/vector/person-media rows and changes no identity decision.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => self::SELECTORS + [
                'sourceSha256' => self::SHA256,
                'sourceDimensions' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 2,
                    'items' => ['type' => 'integer', 'minimum' => 1]],
                'faceSnapshotSha256' => self::SHA256,
                'regionInfoSha256' => self::SHA256,
                'reviewHistorySha256' => self::SHA256,
                'pathBindingSha256' => self::SHA256,
                'personMediaSnapshotSha256' => self::SHA256,
                'idempotencyKey' => self::UUID,
            ], 'required' => ['faceId', 'treeId', 'mediaId', 'personId', 'assetUuid', 'proposedRegion',
                'sourceSha256', 'sourceDimensions', 'faceSnapshotSha256', 'regionInfoSha256',
                'reviewHistorySha256', 'pathBindingSha256', 'personMediaSnapshotSha256', 'idempotencyKey']],
            'returns' => 'Pending region-only Review Desk reference and durable receipt; no person assignment or face identity decision.',
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
                    throw new RuntimeException('Conflicting operator photo region review registration.');
                }
            }
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            $framework = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            foreach (array_keys(self::TOOLS) as $tool) {
                if ((int) DB::table('agent_tool_registry')->where('name', $tool)->value('enabled') === 1) {
                    $framework['tools'][$tool] ??= self::POLICY;
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
            throw new RuntimeException('Invalid table-owned CLI framework services policy.');
        }
        foreach (self::TOOLS as $name => $tool) {
            $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
            if ($registered !== null && ! $this->registrationMatches($registered, $tool)) {
                throw new RuntimeException('Conflicting operator photo region review registration.');
            }
            if (($registered === null || (int) $registered->enabled === 1)
                && isset($framework['tools'][$name]) && $framework['tools'][$name] !== self::POLICY) {
                throw new RuntimeException('Conflicting framework policy for operator photo region review.');
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
        if (DB::table('review_desk_receipts')->where('action', LegacyFaceRegionReviewStagingService::SELECTED_REGION_ACTION)->exists()) {
            throw new RuntimeException('Retain selected photo region stage receipts; disable execution explicitly.');
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
