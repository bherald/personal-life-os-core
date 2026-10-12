<?php

use App\Services\Genealogy\GenealogyMCPService;
use App\Services\SkillLoaderService;
use App\Services\SkillVersionService;
use App\Services\SystemConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function definitions(): array
    {
        $tree = ['tree_id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]];
        $task = $tree + ['task_id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]];
        $run = $task + [
            'dry_run' => ['type' => 'boolean', 'default' => true],
            'max_iterations' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12, 'default' => 8],
            'timeout_minutes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'default' => 20],
        ];
        foreach (['use_current_cli_settings', 'save_cli_settings', 'confirm_non_face', 'confirm_review_write',
            'confirm_person_creation', 'confirm_canonical_facts', 'confirm_downloads', 'confirm_writeback',
            'confirm_scheduled_enablement', 'confirm_unanchored'] as $flag) {
            $run[$flag] = ['type' => 'boolean', 'default' => false];
        }
        $run['use_current_cli_settings']['description'] = 'Explicitly use this CLI session instead of the task saved settings.';
        $run['save_cli_settings']['description'] = 'Persist that explicit settings override only during execution; requires use_current_cli_settings.';
        $run['options'] = ['type' => 'object', 'x_context_only' => true,
            'x_parameter_bundle' => array_values(array_diff(array_keys($run), ['tree_id', 'task_id']))];
        $definitions = [];
        foreach ([
            ['research_task_queue', 'List the existing Genea research task queue on the selected workflow target and tree, preserving complete text in CLI execution.',
                $tree + ['status' => ['type' => 'string', 'default' => 'active'],
                    'priority' => ['type' => 'string'], 'task_type' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50]], false],
            ['research_task_profile', 'Read one existing Genea research task, full evidence, results and saved CLI execution settings; task must belong to the selected tree.', $task, false],
            ['research_task_run', 'Preview or explicitly execute one existing Genea task through genealogy:research-task-run. Defaults to dry run; execution requires separate confirmations. Saved settings apply unless explicitly overridden, with optional saving.', $run, true],
        ] as [$name, $description, $parameters, $write]) {
            $definitions[] = ['name' => $name, 'service_class' => GenealogyMCPService::class, 'method' => $name,
                'description' => $description, 'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'returns_description' => 'Existing task data. Runner returns complete command output, parsed result and explicit success or failure; no automatic retry.',
                'permissions' => json_encode($write ? ['genealogy:read', 'genealogy:write'] : ['genealogy:read'], JSON_THROW_ON_ERROR),
                'risk_level' => $write ? 'write' : 'read', 'requires_confirmation' => $write,
                'category' => 'genealogy', 'enabled' => true, 'source' => 'manual',
                'notes' => 'CLI Genea task registration v1; existing task services and selected-tree execution rule.'];
        }

        return $definitions;
    }

    private function rule(string $name): array
    {
        return ['target' => 'workflow', 'tree_scoped' => true, 'tree_argument' => 'tree_id',
            'references' => $name === 'research_task_queue' ? [] : ['task_id' => [
                'table' => 'genealogy_research_tasks', 'id_column' => 'id', 'tree_column' => 'tree_id']]];
    }

    public function up(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('Install the framework service configuration before registering task tools.');
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            foreach ($this->definitions() as $definition) {
                DB::table('agent_tool_registry')->insertOrIgnore($definition + ['created_at' => now(), 'updated_at' => now()]);
                $registered = DB::table('agent_tool_registry')->where('name', $definition['name'])
                    ->where('service_class', $definition['service_class'])->where('method', $definition['method'])->exists();
                if ($registered && ! array_key_exists($definition['name'], $policy['tools'])) {
                    $policy['tools'][$definition['name']] = $this->rule($definition['name']);
                }
            }
            $this->savePolicy($row, $policy);
            $skill = app(SkillLoaderService::class)->loadSkillSnapshot('genealogy-researcher');
            if ($skill === null) {
                throw new RuntimeException('The deployed genealogy-researcher skill source is unavailable.');
            }
            app(SkillVersionService::class)->trackVersion('genealogy-researcher', $skill['frontmatter'], $skill['body'], $skill['full_content']);
        });
        app(SystemConfigService::class)->forget('cli.framework_services');
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            $policy = $row === null ? null : json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            foreach ($this->definitions() as $definition) {
                $tool = DB::table('agent_tool_registry')->where('name', $definition['name'])->lockForUpdate()->first();
                if ($tool === null) {
                    continue;
                }
                foreach ($definition as $field => $value) {
                    $actual = $tool->{$field};
                    $equal = in_array($field, ['parameters', 'permissions'], true)
                        ? json_decode($actual, true, flags: JSON_THROW_ON_ERROR) == json_decode($value, true, flags: JSON_THROW_ON_ERROR)
                        : (string) $actual === (string) (is_bool($value) ? (int) $value : $value);
                    if (! $equal) {
                        continue 2;
                    }
                }
                if ($policy !== null && ($policy['tools'][$definition['name']] ?? null) !== $this->rule($definition['name'])) {
                    continue;
                }
                DB::table('agent_tool_registry')->where('id', $tool->id)->delete();
                if ($policy !== null) {
                    unset($policy['tools'][$definition['name']]);
                }
            }
            if ($row !== null) {
                $this->savePolicy($row, $policy);
            }
            // Retain skill version history; restoring source uses normal framework version tracking.
        });
        app(SystemConfigService::class)->forget('cli.framework_services');
    }

    private function savePolicy(object $row, array $policy): void
    {
        $encoded = json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if ($encoded !== $row->config_value && DB::table('system_configs')->where('id', $row->id)
            ->where('config_value', $row->config_value)->update(['config_value' => $encoded, 'updated_at' => now()]) !== 1) {
            throw new RuntimeException('Framework configuration changed while registering task tools.');
        }
    }
};
