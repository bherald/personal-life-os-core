<?php

use App\Services\UnifiedCli\CliFrameworkServicePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const UUID = ['type' => 'string', 'format' => 'uuid'];

    private const SHA256 = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];

    private const TOOLS = [
        'file_reviewed_face_metadata_append_plan' => [
            'method' => 'planAppend',
            'description' => 'Read exact source and raw RegionInfo proof for a single-face append. Reports whether the current RegionList is empty; no writes or identity decisions.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false,
                'properties' => ['assetUuid' => self::UUID], 'required' => ['assetUuid']],
            'returns' => 'Current asset UUID, source SHA256, RegionInfo SHA256, encoded dimensions, RegionList and append eligibility.',
            'risk' => 'read', 'confirm' => false,
        ],
        'file_reviewed_face_metadata_append' => [
            'method' => 'append',
            'description' => 'Preview or append exactly one approved current global face to an exact empty RegionList. Name and encoded-pixel ROI come only from the selected face and its current approval receipt. Physical write requires trusted CLI approval of the complete arguments.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'assetUuid' => self::UUID,
                'expectedSourceHash' => self::SHA256,
                'expectedRegionInfoHash' => self::SHA256,
                'approvedFaceId' => ['type' => 'integer', 'minimum' => 1],
                'approvalReceiptId' => self::UUID,
                'idempotencyKey' => self::UUID,
                'dryRun' => ['type' => 'boolean', 'default' => true],
            ], 'required' => ['assetUuid', 'expectedSourceHash', 'expectedRegionInfoHash',
                'approvedFaceId', 'approvalReceiptId', 'idempotencyKey']],
            'returns' => 'Exact preview or durable technical append receipt; unresolved physical outcomes are held and never retried automatically.',
            'risk' => 'write', 'confirm' => true,
        ],
    ];

    private const APPEND_ACTION = 'append_reviewed_face_metadata';

    private const APPEND_TIMEOUT_MS = 120000;

    public function up(): void
    {
        if (strlen(self::APPEND_ACTION) > 30) {
            throw new RuntimeException('Reviewed append receipt action exceeds the deployed receipt column width.');
        }
        $this->validateExisting();
        DB::transaction(function (): void {
            $this->validateExisting(true);
            foreach (self::TOOLS as $name => $tool) {
                DB::table('agent_tool_registry')->insertOrIgnore($this->registrationValues($tool) + [
                    'name' => $name, 'created_at' => now(),
                ]);
                $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
                if ($registered === null || ! $this->registrationMatches($registered, $tool)) {
                    throw new RuntimeException('Conflicting reviewed face metadata append registration.');
                }
            }
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            $framework = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            CliFrameworkServicePolicy::validate($framework);
            foreach (array_keys(self::TOOLS) as $name) {
                if ((int) DB::table('agent_tool_registry')->where('name', $name)->value('enabled') === 1) {
                    $framework['tools'][$name] ??= ['target' => 'local', 'tree_scoped' => false, 'references' => []];
                    if ($name === 'file_reviewed_face_metadata_append') {
                        $framework['tools'][$name]['timeout_ms'] ??= self::APPEND_TIMEOUT_MS;
                    }
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
        CliFrameworkServicePolicy::validate($framework);
        foreach (self::TOOLS as $name => $tool) {
            $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
            if ($registered !== null && ! $this->registrationMatches($registered, $tool)) {
                throw new RuntimeException('Conflicting reviewed face metadata append registration.');
            }
            if (($registered === null || (int) $registered->enabled === 1) && isset($framework['tools'][$name])) {
                $rule = $framework['tools'][$name];
                if (($rule['target'] ?? null) !== 'local' || ($rule['tree_scoped'] ?? null) !== false
                    || ($name === 'file_reviewed_face_metadata_append'
                        && (! is_int($rule['timeout_ms'] ?? null) || $rule['timeout_ms'] < 50 || $rule['timeout_ms'] > 3600000))) {
                    throw new RuntimeException('Conflicting CLI framework policy for reviewed face metadata append.');
                }
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
        if (DB::table('review_desk_receipts')->where('action', self::APPEND_ACTION)->exists()) {
            throw new RuntimeException('Retain reviewed face append receipts; disable execution explicitly.');
        }
        $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
        if ($row !== null) {
            $framework = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            foreach (array_keys(self::TOOLS) as $name) {
                $rule = $framework['tools'][$name] ?? null;
                if ($name === 'file_reviewed_face_metadata_append' && is_array($rule)
                    && ($rule['target'] ?? null) === 'local' && ($rule['tree_scoped'] ?? null) === false
                    && ($rule['references'] ?? null) === [] && ($rule['timeout_ms'] ?? null) === self::APPEND_TIMEOUT_MS
                    && count($rule) === 4) {
                    unset($framework['tools'][$name]);
                } elseif ($name === 'file_reviewed_face_metadata_append' && is_array($rule)
                    && ($rule['timeout_ms'] ?? null) === self::APPEND_TIMEOUT_MS) {
                    unset($framework['tools'][$name]['timeout_ms']);
                } elseif ($name !== 'file_reviewed_face_metadata_append'
                    && $rule === ['target' => 'local', 'tree_scoped' => false, 'references' => []]) {
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
