<?php

use App\Services\UnifiedCli\CliFrameworkServicePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const STATE_KEY = 'face_coverage_readiness_registration_20261008';

    private function tools(): array
    {
        return ['file_face_coverage_readiness_list' => ['method' => 'listIncomingCoverageReadiness',
            'description' => 'Read bounded private incoming face coverage readiness observations. Retained measurements are advisory; obtain a fresh existing native read plan before separately approved execution. Never performs detection, writes or replay.',
            'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'afterObservationId' => ['type' => 'integer', 'minimum' => 0],
                'limit' => ['type' => 'integer', 'minimum' => 1],
            ]],
            'returns' => 'Private observation/source/engine/ledger readiness pins and existing read-plan arguments; write_ready and automatic_replay_allowed remain false.',
            'risk' => 'read', 'confirm' => false]];
    }

    public function up(): void
    {
        DB::transaction(function (): void {
            $frameworkRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($frameworkRow === null) {
                throw new RuntimeException('Face coverage readiness requires the existing framework service policy.');
            }
            $framework = json_decode((string) $frameworkRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            CliFrameworkServicePolicy::validate($framework);
            $priorStateRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', self::STATE_KEY)->first();
            if ($priorStateRow !== null) {
                foreach ($this->tools() as $name => $tool) {
                    $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
                    if ($registered === null || ! $this->registrationMatches($registered, $tool)) {
                        throw new RuntimeException('Face coverage readiness registration changed after migration.');
                    }
                }

                return;
            }
            $priorState = [];
            foreach (array_keys($this->tools()) as $name) {
                $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
                $priorState[$name] = ['registration_present' => $registered !== null,
                    'framework_rule_present' => array_key_exists($name, $framework['tools']),
                    'framework_rule' => $framework['tools'][$name] ?? null];
            }
            foreach ($this->tools() as $name => $tool) {
                $existing = DB::table('agent_tool_registry')->where('name', $name)->first();
                if ($existing !== null && ! $this->registrationMatches($existing, $tool)) {
                    throw new RuntimeException('Conflicting face coverage readiness registration.');
                }
                $rule = $framework['tools'][$name] ?? null;
                $expected = ['target' => 'local', 'tree_scoped' => false, 'references' => []];
                if ($rule !== null && array_diff_key($rule, ['timeout_ms' => true, 'durable_receipts' => true]) !== $expected) {
                    throw new RuntimeException('Conflicting face coverage readiness framework policy.');
                }
                DB::table('agent_tool_registry')->insertOrIgnore($this->registrationValues($tool) + ['name' => $name, 'created_at' => now()]);
                $registered = DB::table('agent_tool_registry')->where('name', $name)->first();
                if ($registered === null || ! $this->registrationMatches($registered, $tool)) {
                    throw new RuntimeException('Registered face coverage readiness service changed during migration.');
                }
                if ((int) $registered->enabled === 1) {
                    $framework['tools'][$name] ??= $expected;
                }
            }
            DB::table('system_configs')->insert([
                'section' => 'cli', 'config_key' => self::STATE_KEY,
                'config_value' => json_encode($priorState, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'data_type' => 'json', 'description' => 'Migration rollback ownership for incoming face coverage readiness registration.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('system_configs')->where('id', $frameworkRow->id)->update([
                'config_value' => json_encode($framework, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
            ]);
        });
    }

    private function registrationValues(array $tool): array
    {
        return ['service_class' => 'App\\Services\\FileRegistryFaceCoverageService', 'method' => $tool['method'],
            'description' => $tool['description'], 'parameters' => json_encode($tool['parameters'], JSON_THROW_ON_ERROR),
            'returns_description' => $tool['returns'], 'permissions' => json_encode([$tool['risk'] === 'read' ? 'file:read' : 'file:write'], JSON_THROW_ON_ERROR),
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
            $stateRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', self::STATE_KEY)->lockForUpdate()->first();
            if ($stateRow === null) {
                return;
            }
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('Cannot roll back face coverage readiness without the existing framework service policy.');
            }
            $priorState = json_decode((string) $stateRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $framework = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            CliFrameworkServicePolicy::validate($framework);

            foreach ($this->tools() as $name => $tool) {
                if (($priorState[$name]['registration_present'] ?? null) === true) {
                    continue;
                }
                $registered = DB::table('agent_tool_registry')->where('name', $name)->lockForUpdate()->first();
                if ($registered !== null && $this->registrationMatches($registered, $tool) && (int) $registered->enabled === 1) {
                    DB::table('agent_tool_registry')->where('id', $registered->id)->update(['enabled' => 0, 'updated_at' => now()]);
                }
            }
            foreach (array_keys($this->tools()) as $name) {
                if (($priorState[$name]['framework_rule_present'] ?? null) === true) {
                    continue;
                }
                $rule = $framework['tools'][$name] ?? null;
                if ($rule === ['target' => 'local', 'tree_scoped' => false, 'references' => []]) {
                    unset($framework['tools'][$name]);
                }
            }
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($framework, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
            ]);
            DB::table('system_configs')->where('id', $stateRow->id)->delete();
        });
    }
};
