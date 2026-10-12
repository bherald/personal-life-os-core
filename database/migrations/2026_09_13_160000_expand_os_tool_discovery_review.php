<?php

use App\Services\OsToolDiscoveryService;
use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function definitions(): array
    {
        $tools = [
            'os_tools_discover' => [OsToolDiscoveryService::class, 'discover', 'Observe bounded current PATH and package metadata. Discovery grants no execution authority.',
                ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500], 'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000]], [], false, 'os_tools:read'],
            'os_tools_review' => [OsToolDiscoveryService::class, 'review', 'Read local manual/documentation evidence and prepare an OS-tool approval candidate; no installation, execution or enablement.',
                ['tool' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9][a-zA-Z0-9_.+-]{0,127}$']], ['tool'], false, 'os_tools:read'],
        ];
        $rows = [];
        foreach ($tools as $name => [$class, $method, $description, $properties, $required, $confirm, $permission]) {
            $rows[] = ['name' => $name, 'service_class' => $class, 'method' => $method, 'description' => $description,
                'parameters' => json_encode(['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false], JSON_THROW_ON_ERROR),
                'returns_description' => 'Bounded environment observations or documentation-backed review candidates; no installation, qualification or execution authority.',
                'permissions' => json_encode([$permission], JSON_THROW_ON_ERROR), 'risk_level' => $confirm ? 'write' : 'read',
                'category' => 'os_tools', 'enabled' => true, 'requires_confirmation' => $confirm,
                'availability_status' => 'available', 'privacy_class' => 'internal_private', 'allows_private_data' => false,
                'source' => 'manual', 'notes' => 'os-tools-v2: untrusted discovery/document evidence only; no skill permissions, installations or executable adapters granted.'];
        }

        return $rows;
    }

    public function configDefinition(): array
    {
        return ['section' => 'os_tools', 'config_key' => 'definitions', 'data_type' => 'json',
            'config_value' => json_encode(json_decode(file_get_contents(__DIR__.'/../../resources/os-tools/definitions.json'), true, flags: JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR),
            'description' => 'OS-tool enrichment hints; environment discovery is independent. These definitions grant no execution permissions.'];
    }

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('system_configs')->insertOrIgnore($this->configDefinition() + ['created_at' => now(), 'updated_at' => now()]);
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
        app(SystemConfigService::class)->forget('os_tools.definitions');
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $seed = $this->configDefinition();
            $configRow = DB::table('system_configs')->where('section', 'os_tools')->where('config_key', 'definitions')->lockForUpdate()->first();
            if ($configRow !== null && json_decode($configRow->config_value, true) === json_decode($seed['config_value'], true)
                && ($configRow->description ?? null) === $seed['description'] && ($configRow->data_type ?? null) === 'json') {
                DB::table('system_configs')->where('id', $configRow->id)->delete();
            }
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
        app(SystemConfigService::class)->forget('os_tools.definitions');
    }
};
