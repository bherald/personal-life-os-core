<?php

use App\Services\CyberWorkflowJobClientService;
use App\Services\SkillLoaderService;
use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

return new class extends Migration
{
    public function definitions(): array
    {
        $rows = [];
        foreach (['refresh', 'reconcile'] as $method) {
            $rows[] = ['name' => 'cyber_job_'.$method, 'service_class' => CyberWorkflowJobClientService::class, 'method' => $method,
                'description' => 'Explicit original native job '.$method.'. Uses only its saved owner-bound plan; no workflow resume or execution replay. Status alone does not contact the worker.',
                'parameters' => json_encode(['type' => 'object', 'properties' => ['handle' => ['type' => 'string', 'format' => 'uuid'], 'execution_intent' => ['type' => 'string', 'enum' => [$method.'_live']]], 'required' => ['handle', 'execution_intent'], 'additionalProperties' => false], JSON_THROW_ON_ERROR),
                'returns_description' => 'Saved status, verified original control evidence and deadline when known; unknown outcomes remain held.',
                'permissions' => json_encode(['cyber_jobs:'.$method]), 'enabled' => true, 'requires_confirmation' => true, 'risk_level' => 'write', 'category' => 'cyber_jobs',
                'availability_status' => 'available', 'privacy_class' => 'internal_private', 'allows_private_data' => false, 'source' => 'manual',
                'notes' => 'Opt-in skill; existing authenticated endpoint and original action gates required. No endpoint or workflow activation.'];
        }

        return $rows;
    }

    public function up(): void
    {
        DB::transaction(function () {
            foreach ($this->definitions() as $row) {
                DB::table('agent_tool_registry')->insertOrIgnore($row + ['created_at' => now(), 'updated_at' => now()]);
            }
            $config = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($config !== null) {
                $policy = json_decode($config->config_value, true, flags: JSON_THROW_ON_ERROR);
                foreach ($this->definitions() as $definition) {
                    $registered = DB::table('agent_tool_registry')->where('name', $definition['name'])->first();
                    if ($registered->service_class === $definition['service_class'] && $registered->method === $definition['method']
                        && json_decode($registered->parameters) == json_decode($definition['parameters'])
                        && (bool) $registered->requires_confirmation) {
                        $policy['tools'][$definition['name']] ??= ['target' => 'local', 'tree_scoped' => false, 'references' => []];
                    }
                }
                DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
            $content = file_get_contents(SkillLoaderService::configuredSkillsBasePath().'/cyber-native-job-control/SKILL.md');
            $parts = preg_split('/^---\s*$/m', $content, 3);
            $frontmatter = Yaml::parse($parts[1]);
            DB::table('skill_versions')->insertOrIgnore(['skill_name' => 'cyber-native-job-control', 'version' => $frontmatter['version'], 'content_hash' => hash('sha256', $content), 'frontmatter' => json_encode($frontmatter, JSON_THROW_ON_ERROR), 'body_text' => trim($parts[2]), 'full_content' => $content, 'change_summary' => 'Explicit original native job control; no replay or automatic status transport.', 'changed_by' => 'migration', 'is_active' => true, 'tools_count' => count($frontmatter['tools']), 'tool_phases_count' => 0, 'permissions' => json_encode($frontmatter['permissions'], JSON_THROW_ON_ERROR), 'created_at' => now()]);
        });
        app(SystemConfigService::class)->forget('cli.framework_services');
    }

    public function down(): void
    {
        foreach ($this->definitions() as $row) {
            $registered = DB::table('agent_tool_registry')->where('name', $row['name'])->first();
            if ($registered !== null && $registered->service_class === $row['service_class'] && $registered->method === $row['method']
                && json_decode($registered->parameters) == json_decode($row['parameters'])) {
                DB::table('agent_tool_registry')->where('name', $row['name'])->update(['enabled' => false]);
            }
        }
        DB::table('skill_versions')->where('skill_name', 'cyber-native-job-control')->where('content_hash', hash_file('sha256', SkillLoaderService::configuredSkillsBasePath().'/cyber-native-job-control/SKILL.md'))->update(['is_active' => false]);
        // Preserve operator rules, original plans, claims, cancellation and results.
        app(SystemConfigService::class)->forget('cli.framework_services');
    }
};
