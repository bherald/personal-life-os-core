<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TOOLS = [
        'file_face_generation_resume' => [
            'method' => 'resumeAnonymousFaceGeneration',
            'description' => 'Resume an unchanged validated anonymous generation packet after registry revision drift, under fresh exact CLI approval. No detector or identity work; original provenance retained.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'asset_uuid' => ['type' => 'string', 'format' => 'uuid'],
                'source_sha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                'binding_sha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                'idempotency_key' => ['type' => 'string', 'format' => 'uuid'],
                'original_run_uuid' => ['type' => 'string', 'format' => 'uuid'],
            ], 'required' => ['asset_uuid', 'source_sha256', 'binding_sha256', 'idempotency_key', 'original_run_uuid']],
            'returns' => 'Original run completion receipt with reused immutable packet and anonymous rows; renewed approval evidence retained.',
            'risk' => 'write', 'confirm' => true,
        ],
    ];

    public function up(): void
    {
        $this->validateFrameworkAndRegistrations();
        if (! Schema::hasTable('file_registry_face_generation_runs')) {
            throw new RuntimeException('Missing anonymous generation custody ledger.');
        }
        if (! Schema::hasColumn('file_registry_face_generation_runs', 'resume_evidence_json')) {
            Schema::table('file_registry_face_generation_runs', function (Blueprint $table): void {
                $table->longText('resume_evidence_json')->nullable();
            });
        }

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
                    $framework['tools'][$tool] ??= ['target' => 'local', 'tree_scoped' => false, 'references' => []];
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
            $expectedPolicy = ['target' => 'local', 'tree_scoped' => false, 'references' => []];
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
        return ['service_class' => 'App\\Services\\FaceEmbeddingService', 'method' => $tool['method'],
            'description' => $tool['description'], 'parameters' => json_encode($tool['parameters'], JSON_THROW_ON_ERROR),
            'returns_description' => $tool['returns'],
            'permissions' => json_encode([$tool['risk'] === 'read' ? 'file:read' : 'file:write'], JSON_THROW_ON_ERROR),
            'enabled' => 1, 'risk_level' => $tool['risk'], 'category' => 'file',
            'requires_confirmation' => $tool['confirm'] ? 1 : 0, 'source' => 'config', 'updated_at' => now()];
    }

    public function down(): void
    {
        if (Schema::hasTable('file_registry_face_generation_runs')
            && DB::table('file_registry_face_generation_runs')->whereNotNull('resume_evidence_json')->exists()) {
            throw new RuntimeException('Retain anonymous face generation receipts and stage custody during rollback.');
        }
        if (DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->exists()) {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            $policy = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            foreach (array_keys(self::TOOLS) as $tool) {
                if (($policy['tools'][$tool] ?? null) === ['target' => 'local', 'tree_scoped' => false, 'references' => []]) {
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
        if (Schema::hasColumn('file_registry_face_generation_runs', 'resume_evidence_json')) {
            Schema::table('file_registry_face_generation_runs', fn (Blueprint $table) => $table->dropColumn('resume_evidence_json'));
        }
    }
};
