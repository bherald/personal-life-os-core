<?php

use App\Services\OsToolDiscoveryService;
use App\Services\OsToolFixtureService;
use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function definitions(): array
    {
        $categories = array_values(array_unique(array_column((new OsToolDiscoveryService)->catalog(), 'category')));
        $tools = [
            'os_tools_inventory' => [OsToolDiscoveryService::class, 'inventory', 'Observe curated local executables and dependencies; does not authorize use.',
                ['category' => ['type' => 'string', 'enum' => $categories]], [], false, 'os_tools:read'],
            'os_tools_shellcheck_fixture' => [OsToolFixtureService::class, 'checkFixture', 'Run only a named synthetic ShellCheck fixture in an isolated network namespace. No source paths or target URLs accepted.',
                ['fixture' => ['type' => 'string', 'enum' => array_keys(OsToolFixtureService::FIXTURES)],
                    'expected_identity' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$']],
                ['fixture', 'expected_identity'], true, 'os_tools:qualify'],
        ];
        $rows = [];
        foreach ($tools as $name => [$class, $method, $description, $properties, $required, $confirm, $permission]) {
            $rows[] = ['name' => $name, 'service_class' => $class, 'method' => $method, 'description' => $description,
                'parameters' => json_encode(['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false], JSON_THROW_ON_ERROR),
                'returns_description' => 'Versioned observations or complete/failed synthetic fixture result, never a general security verdict.',
                'permissions' => json_encode([$permission], JSON_THROW_ON_ERROR), 'risk_level' => $confirm ? 'write' : 'read',
                'category' => 'os_tools', 'enabled' => true, 'requires_confirmation' => $confirm,
                'availability_status' => 'available', 'privacy_class' => 'internal_private', 'allows_private_data' => false,
                'source' => 'manual', 'notes' => 'os-tools-v1: availability is checked at call time; no skill permission or executable installation granted.'];
        }

        return $rows;
    }

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ($this->definitions() as $row) {
                DB::table('agent_tool_registry')->insertOrIgnore($row + ['created_at' => now(), 'updated_at' => now()]);
            }
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            foreach ($this->definitions() as $tool) {
                $current = DB::table('agent_tool_registry')->where('name', $tool['name'])->first();
                if ($current->service_class === $tool['service_class'] && $current->method === $tool['method']) {
                    $policy['tools'][$tool['name']] ??= ['target' => 'local', 'tree_scoped' => false, 'references' => []];
                }
            }
            DB::table('system_configs')->where('id', $row->id)->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        });
        app(SystemConfigService::class)->forget('cli.framework_services');
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $policyRow = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            $policy = $policyRow === null ? null : json_decode($policyRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            foreach ($this->definitions() as $definition) {
                $row = DB::table('agent_tool_registry')->where('name', $definition['name'])->lockForUpdate()->first();
                if ($row === null) {
                    continue;
                }
                foreach ($definition as $field => $value) {
                    $equal = in_array($field, ['parameters', 'permissions'], true)
                        ? json_decode($row->{$field}, true, flags: JSON_THROW_ON_ERROR) == json_decode($value, true, flags: JSON_THROW_ON_ERROR)
                        : (string) $row->{$field} === (string) (is_bool($value) ? (int) $value : $value);
                    if (! $equal) {
                        continue 2;
                    }
                }
                DB::table('agent_tool_registry')->where('id', $row->id)->delete();
                if (($policy['tools'][$definition['name']] ?? null) === ['target' => 'local', 'tree_scoped' => false, 'references' => []]) {
                    unset($policy['tools'][$definition['name']]);
                }
            }
            if ($policyRow !== null) {
                DB::table('system_configs')->where('id', $policyRow->id)->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
        });
        app(SystemConfigService::class)->forget('cli.framework_services');
    }
};
