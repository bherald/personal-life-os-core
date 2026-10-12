<?php

use App\Services\Genealogy\GenealogyService;
use App\Services\Genealogy\ResearchTaskService;
use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const METHODS = ['create_research_task' => 'createTask', 'get_open_research_tasks' => 'getOpenTasks',
        'log_research_search' => 'logSearch', 'assess_gps_compliance' => 'assessGPSCompliance'];

    public function up(): void
    {
        $this->change(true);
    }

    public function down(): void
    {
        // Remove only this wiring. Do not move/delete GPS data or reintroduce
        // incorrect queue routing/read classification. Code rollback must restore
        // its paired registry configuration using the normal release backup.
        $this->change(false);
    }

    private function rule(string $name): array
    {
        $person = ['table' => 'genealogy_persons', 'id_column' => 'id', 'tree_column' => 'tree_id'];
        $task = ['table' => 'gps_research_tasks', 'id_column' => 'id', 'tree_column' => 'tree_id'];
        $rule = ['target' => 'workflow', 'tree_scoped' => true, 'references' => []];
        if ($name === 'create_research_task') {
            $rule['references']['person_id'] = $person;
        } elseif ($name === 'get_open_research_tasks') {
            $rule['tree_argument'] = 'tree_id';
            $rule['references'] = ['person_id' => $person, 'filters.person_id' => $person];
        } else {
            $rule['references']['task_id'] = $task;
            if ($name === 'log_research_search') {
                $rule['references']['search_details.source_ids_found'] = ['table' => 'genealogy_sources', 'id_column' => 'id', 'tree_column' => 'tree_id'];
                $rule['references']['search_details.media_ids_found'] = ['table' => 'genealogy_media', 'id_column' => 'id', 'tree_column' => 'tree_id'];
            }
        }

        return $rule;
    }

    private function change(bool $install): void
    {
        $changed = DB::transaction(function () use ($install): bool {
            $config = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($config === null) {
                return false;
            }
            $policy = json_decode($config->config_value, true, flags: JSON_THROW_ON_ERROR);
            $before = $policy;
            foreach (self::METHODS as $name => $method) {
                $rule = $this->rule($name);
                if (! $install) {
                    if (($policy['tools'][$name] ?? null) === $rule) {
                        unset($policy['tools'][$name]);
                    }

                    continue;
                }
                $tool = DB::table('agent_tool_registry')->where('name', $name)->lockForUpdate()->first();
                if ($tool === null || ! $this->align($tool, $method)) {
                    continue;
                }
                if (! array_key_exists($name, $policy['tools'])) {
                    $policy['tools'][$name] = $rule;
                }
            }
            if ($policy === $before) {
                return false;
            }
            if (DB::table('system_configs')->where('id', $config->id)->where('config_value', $config->config_value)
                ->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('Research task bindings changed during migration.');
            }

            return true;
        });
        if ($changed) {
            app(SystemConfigService::class)->forget('cli.framework_services');
        }
    }

    private function align(object $tool, string $method): bool
    {
        $changes = [];
        $parameters = json_decode($tool->parameters, true, flags: JSON_THROW_ON_ERROR);
        if ($tool->name === 'create_research_task' && $tool->service_class === GenealogyService::class && $tool->method === 'createResearchTask') {
            // Correct only the known legacy contract; preserve custom extensions.
            $keys = array_keys($parameters);
            sort($keys);
            if ($keys !== ['person_id', 'priority', 'question', 'task_type']
                || ($parameters['priority']['type'] ?? null) !== 'integer'
                || array_key_exists('default', $parameters['priority'])) {
                return false;
            }
            $parameters['priority'] = ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical'],
                'default' => 'medium', 'description' => 'GPS research priority.'];
            $parameters['task_type'] = ['type' => 'string', 'enum' => array_keys(ResearchTaskService::TASK_TYPES),
                'default' => 'other', 'description' => 'GPS research question category.'];
            $parameters['options'] = $this->bundle(['priority']);
            $changes = ['service_class' => ResearchTaskService::class, 'method' => $method,
                'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
        } elseif ($tool->service_class !== ResearchTaskService::class || $tool->method !== $method) {
            return false;
        }
        if ($tool->name === 'get_open_research_tasks' && isset($parameters['person_id'])
            && ! isset($parameters['type']) && ! array_key_exists('filters', $parameters)) {
            $parameters['filters'] = $this->bundle(['person_id']);
            $changes['parameters'] = json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        if ($tool->name === 'assess_gps_compliance' && $tool->risk_level === 'read') {
            // assessGPSCompliance saves gps_assessments, even for an empty task.
            $permissions = json_decode($tool->permissions ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            $changes += ['risk_level' => 'write', 'requires_confirmation' => true,
                'permissions' => json_encode(array_values(array_unique([...$permissions, 'genealogy:write'])), JSON_THROW_ON_ERROR)];
        }
        if ($changes !== [] && DB::table('agent_tool_registry')->where('id', $tool->id)
            ->where('service_class', $tool->service_class)->where('method', $tool->method)
            ->whereRaw('CAST(parameters AS CHAR) = ?', [$tool->parameters])
            ->update($changes + ['updated_at' => now()]) !== 1) {
            throw new RuntimeException('Research task definition changed during migration.');
        }

        return true;
    }

    private function bundle(array $fields): array
    {
        return ['type' => 'object', 'x_context_only' => true, 'x_parameter_bundle' => $fields];
    }
};
