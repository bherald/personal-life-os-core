<?php

use App\Services\CyberWorkflowJobClientService;
use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function definitions(): array
    {
        $rows = [];
        foreach (['submit', 'status', 'cancel', 'resume'] as $method) {
            $mutating = $method !== 'status';
            $properties = [$method === 'submit' ? 'submission_id' : 'handle' => ['type' => 'string', 'format' => 'uuid']];
            if ($mutating) {
                $properties['execution_intent'] = ['type' => 'string', 'enum' => [$method.'_live']];
            }
            $rows[] = ['name' => 'cyber_job_'.$method, 'service_class' => CyberWorkflowJobClientService::class, 'method' => $method,
                'description' => 'CYBER durable job '.$method.'. Retain exact handle and qualified scope; unknown outcomes cannot be replayed. Model arguments never authorize execution.',
                'parameters' => json_encode(['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false], JSON_THROW_ON_ERROR),
                'returns_description' => 'Owner-bound task status and exact artifact fingerprint; completed checking does not close security findings.',
                'permissions' => json_encode(['cyber_jobs:'.$method]), 'enabled' => true, 'requires_confirmation' => $mutating, 'risk_level' => $mutating ? 'write' : 'read', 'category' => 'cyber_jobs',
                'availability_status' => 'available', 'privacy_class' => 'internal_private', 'allows_private_data' => false, 'source' => 'manual', 'notes' => 'Endpoint/client disabled until qualified; existing CLI approval and exact Passport scope both required.'];
        }

        return $rows;
    }

    public function up(): void
    {
        DB::transaction(function () {
            foreach ($this->definitions() as $row) {
                DB::table('agent_tool_registry')->insertOrIgnore($row + ['created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('system_configs')->insertOrIgnore(['section' => 'cyber', 'config_key' => 'workflow_job_client', 'data_type' => 'json', 'config_value' => json_encode(['enabled' => false, 'base_url' => null, 'bindings_path' => null, 'token_reference' => 'host:cyber:API_TOKEN']), 'description' => 'Scoped CYBER API client; token contents remain in private local bindings.', 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($row !== null) {
                $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
                foreach ($this->definitions() as $definition) {
                    $registered = DB::table('agent_tool_registry')->where('name', $definition['name'])->first();
                    if ($registered->service_class === $definition['service_class'] && $registered->method === $definition['method']) {
                        $policy['tools'][$definition['name']] ??= ['target' => 'local', 'tree_scoped' => false, 'references' => []];
                    }
                }
                DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
        });
        app(SystemConfigService::class)->forget('cli.framework_services');
        app(SystemConfigService::class)->forget('cyber.workflow_job_client');
    }

    public function down(): void
    {
        // Registration retirement must preserve operator edits and durable job history.
        foreach ($this->definitions() as $row) {
            DB::table('agent_tool_registry')->where('name', $row['name'])->where('service_class', $row['service_class'])->update(['enabled' => false]);
        }
    }
};
