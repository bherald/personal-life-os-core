<?php

use App\Services\UnifiedCli\CliFrameworkServicePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PLAN = 'file_confirmed_face_vector_refresh_plan';

    private const EXECUTE = 'file_confirmed_face_vector_refresh_execute';

    private const STATE_KEY = 'confirmed_face_vector_refresh_registration_20261009';

    private const TIMEOUT_MS = 600000;

    private function tools(): array
    {
        return [
            self::PLAN => ['method' => 'planConfirmedFaceVectorRefresh',
                'description' => 'Dry-run one already operator-confirmed face vector against its exact current source, retained face region, approval receipt, and current embedding engine. Never runs detection or changes records.',
                'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'face_embedding_id' => ['type' => 'integer', 'minimum' => 1],
                ], 'required' => ['face_embedding_id']],
                'returns' => 'Bounded source, face-region, old-vector, identity-receipt, engine, and timeout pins. No names, vector values, private paths, or identity decisions.',
                'risk' => 'read', 'confirm' => false],
            self::EXECUTE => ['method' => 'executeConfirmedFaceVectorRefresh',
                'description' => 'Rebuild one already operator-confirmed face vector from its exact source and retained region under the current registered engine. Preserves MySQL identity records and old PostgreSQL vector history; never changes names, identity links, clusters, or source files.',
                'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'face_embedding_id' => ['type' => 'integer', 'minimum' => 1],
                    'expected_input_digest' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                    'idempotency_key' => ['type' => 'string', 'format' => 'uuid'],
                ], 'required' => ['face_embedding_id', 'expected_input_digest', 'idempotency_key']],
                'returns' => 'Durable per-action terminal or held receipt. Unknown outcomes never replay detection automatically.',
                'risk' => 'write', 'confirm' => true],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('file_registry_face_vector_refresh_runs')) {
            Schema::create('file_registry_face_vector_refresh_runs', function (Blueprint $table): void {
                $table->id();
                $table->uuid('run_uuid')->unique('fr_face_vector_refresh_uuid');
                $table->uuid('idempotency_key')->unique('fr_face_vector_refresh_idem');
                $table->unsignedBigInteger('file_registry_id');
                $table->uuid('asset_uuid');
                $table->unsignedBigInteger('file_registry_face_id');
                $table->unsignedBigInteger('face_embedding_id');
                $table->unsignedBigInteger('new_embedding_id')->nullable();
                $table->char('source_sha256', 64);
                $table->char('old_vector_sha256', 64);
                $table->char('input_digest', 64);
                $table->char('identity_proof_sha256', 64);
                $table->char('engine_provenance_sha256', 64);
                $table->text('engine_provenance_json');
                $table->char('output_embedding_sha256', 64)->nullable();
                $table->char('provenance_sha256', 64)->nullable();
                $table->char('caller_binding_sha256', 64);
                $table->string('stage', 24)->default('detector_running');
                $table->string('failure_code', 80)->nullable();
                $table->unsignedSmallInteger('attempts')->default(1);
                $table->dateTime('completed_at', 6)->nullable();
                $table->timestamps(6);
                $table->index(['file_registry_face_id', 'stage'], 'fr_face_vector_refresh_face_stage');
            });
        }
        if (! Schema::hasColumns('file_registry_face_vector_refresh_runs', [
            'run_uuid', 'idempotency_key', 'file_registry_id', 'asset_uuid', 'file_registry_face_id', 'face_embedding_id',
            'new_embedding_id', 'source_sha256', 'old_vector_sha256', 'input_digest', 'identity_proof_sha256',
            'engine_provenance_sha256', 'engine_provenance_json', 'output_embedding_sha256', 'provenance_sha256',
            'caller_binding_sha256', 'stage', 'failure_code', 'attempts', 'completed_at',
        ])) {
            throw new RuntimeException('Confirmed face vector refresh ledger exists with an incomplete schema.');
        }

        DB::transaction(function (): void {
            $frameworkRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')
                ->lockForUpdate()->first();
            if ($frameworkRow === null) {
                throw new RuntimeException('Confirmed face vector refresh requires the existing framework service policy.');
            }
            $framework = json_decode((string) $frameworkRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            CliFrameworkServicePolicy::validate($framework);
            if (DB::table('system_configs')->where('section', 'cli')->where('config_key', self::STATE_KEY)->exists()) {
                return;
            }

            $priorState = [];
            foreach ($this->tools() as $name => $tool) {
                $registered = DB::table('agent_tool_registry')->where('name', $name)->lockForUpdate()->first();
                if ($registered !== null && ! $this->registrationMatches($registered, $tool)) {
                    throw new RuntimeException('Conflicting confirmed face vector refresh registration.');
                }
                $priorRule = $framework['tools'][$name] ?? null;
                $baseRule = is_array($priorRule) ? array_diff_key($priorRule, ['timeout_ms' => true, 'durable_receipts' => true]) : [];
                if ($priorRule !== null && (count($baseRule) !== 3 || ($baseRule['target'] ?? null) !== 'local'
                    || ($baseRule['tree_scoped'] ?? null) !== false || ($baseRule['references'] ?? null) !== [])) {
                    throw new RuntimeException('Conflicting confirmed face vector refresh framework policy.');
                }
                $priorState[$name] = ['registration_present' => $registered !== null,
                    'rule_present' => $priorRule !== null, 'timeout_added' => false];
                DB::table('agent_tool_registry')->insertOrIgnore($this->registrationValues($tool) + ['name' => $name, 'created_at' => now()]);
                $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
                if ($registered === null || ! $this->registrationMatches($registered, $tool)) {
                    throw new RuntimeException('Registered confirmed face vector refresh service changed during migration.');
                }
                if ((int) $registered->enabled === 1) {
                    $framework['tools'][$name] ??= ['target' => 'local', 'tree_scoped' => false, 'references' => []];
                    if (! array_key_exists('timeout_ms', $framework['tools'][$name])) {
                        $framework['tools'][$name]['timeout_ms'] = self::TIMEOUT_MS;
                        $priorState[$name]['timeout_added'] = true;
                    }
                }
            }
            DB::table('system_configs')->insert([
                'section' => 'cli', 'config_key' => self::STATE_KEY,
                'config_value' => json_encode($priorState, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'data_type' => 'json', 'description' => 'Rollback ownership for confirmed face vector refresh native registration.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            CliFrameworkServicePolicy::validate($framework);
            DB::table('system_configs')->where('id', $frameworkRow->id)->update([
                'config_value' => json_encode($framework, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
            ]);
        });
    }

    private function registrationValues(array $tool): array
    {
        return ['service_class' => 'App\\Services\\FaceProtectedVectorRefreshService', 'method' => $tool['method'],
            'description' => $tool['description'], 'parameters' => json_encode($tool['parameters'], JSON_THROW_ON_ERROR),
            'returns_description' => $tool['returns'],
            'permissions' => json_encode([$tool['risk'] === 'read' ? 'file:read' : 'file:write'], JSON_THROW_ON_ERROR),
            'enabled' => 1, 'risk_level' => $tool['risk'], 'category' => 'file',
            'requires_confirmation' => $tool['confirm'] ? 1 : 0, 'source' => 'config', 'updated_at' => now()];
    }

    private function registrationMatches(object $existing, array $tool): bool
    {
        foreach ($this->registrationValues($tool) as $key => $value) {
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

    public function down(): void
    {
        DB::transaction(function (): void {
            $stateRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', self::STATE_KEY)
                ->lockForUpdate()->first();
            if ($stateRow === null) {
                return;
            }
            if (DB::table('file_registry_face_vector_refresh_runs')->exists()) {
                throw new RuntimeException('Retain confirmed face vector refresh receipts and stage custody during rollback.');
            }
            $frameworkRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')
                ->lockForUpdate()->first();
            if ($frameworkRow === null) {
                throw new RuntimeException('Cannot roll back native vector refresh without the framework service policy.');
            }
            $state = json_decode((string) $stateRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $framework = json_decode((string) $frameworkRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            CliFrameworkServicePolicy::validate($framework);
            $changed = false;
            foreach ($this->tools() as $name => $tool) {
                $prior = $state[$name] ?? [];
                $rule = $framework['tools'][$name] ?? null;
                if (($prior['timeout_added'] ?? false) === true && is_array($rule)
                    && ($rule['timeout_ms'] ?? null) === self::TIMEOUT_MS) {
                    unset($framework['tools'][$name]['timeout_ms']);
                    $rule = $framework['tools'][$name];
                    $changed = true;
                }
                if (($prior['rule_present'] ?? false) !== true && $rule === ['target' => 'local', 'tree_scoped' => false, 'references' => []]) {
                    unset($framework['tools'][$name]);
                    $changed = true;
                }
                if (($prior['registration_present'] ?? false) !== true) {
                    $registered = DB::table('agent_tool_registry')->where('name', $name)->lockForUpdate()->first();
                    if ($registered !== null && $this->registrationMatches($registered, $tool) && (int) $registered->enabled === 1) {
                        DB::table('agent_tool_registry')->where('id', $registered->id)->update(['enabled' => 0, 'updated_at' => now()]);
                    }
                }
            }
            if ($changed) {
                CliFrameworkServicePolicy::validate($framework);
                DB::table('system_configs')->where('id', $frameworkRow->id)->update([
                    'config_value' => json_encode($framework, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
                ]);
            }
            DB::table('system_configs')->where('id', $stateRow->id)->delete();
        });
    }
};
