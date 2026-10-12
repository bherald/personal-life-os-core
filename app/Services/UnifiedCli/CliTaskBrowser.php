<?php

namespace App\Services\UnifiedCli;

use App\Services\AgentToolRegistryService;
use Throwable;

/** Discovery from the same registry, skill contract and argument schemas used by execution. */
final class CliTaskBrowser
{
    public function __construct(private readonly CliConfiguration $configuration) {}

    public function browse(array $session, string $query = ''): array
    {
        $catalog = new CliToolCatalog($this->configuration);
        $definitions = $catalog->definitions($session);
        $registry = new AgentToolRegistryService(fn () => $definitions);
        $name = $this->configuration->selectedSkillName($session);
        $contract = ['tools' => [], 'unresolved_declared_tools' => []];
        $skillProblem = $name === null ? 'Select a skill with /skills and /skill NAME.' : null;
        if ($name !== null) {
            try {
                $selected = array_filter($this->configuration->skills($session),
                    fn ($skill) => $skill['name'] === $name && $skill['version_matches']);
                if (count($selected) !== 1) {
                    throw new \RuntimeException('Selected skill is inactive or lacks a matching bundle.');
                }
                $skill = (new CliSkillReader($this->configuration))->read($session, $name);
                $contract = $registry->resolveToolContractForAgent($name, $skill['configuration']);
            } catch (Throwable) {
                $skillProblem = 'Selected skill is unavailable; inspect /skills and select a matching bundle.';
            }
        }
        $mode = $this->configuration->effective($session)['values']['cli.operator_mode'];
        $tasks = [];
        foreach ($this->configuration->definitions($session, 'agent_tool_registry') as $row) {
            $tool = $row['name'];
            if ($query !== '' && mb_stripos(implode(' ', [$tool, $row['category'] ?? '', $row['description'] ?? '',
                $row['mcp_server'] ?? '', $row['mcp_tool'] ?? '']), $query) === false) {
                continue;
            }
            $definition = $definitions[$tool] ?? null;
            $status = $definition === null ? 'registry_unavailable'
                : (! isset($contract['tools'][$tool]) ? 'not_in_selected_skill'
                    : (! CliOperatorModes::allows($mode, $definition) ? 'blocked_by_mode' : 'available_to_skill'));
            $schema = null;
            if ($definition !== null) {
                try {
                    $schema = $catalog->schema($definition['parameters']);
                } catch (Throwable) {
                    $status = 'argument_schema_unavailable';
                }
            }
            $tasks[] = ['name' => $tool, 'category' => $row['category'] ?? 'uncategorized',
                'description' => $row['description'] ?? '', 'availability' => $status,
                'permissions' => $definition['permissions'] ?? CliSettingValue::decode($row['permissions'] ?? [], 'json'),
                'requires_confirmation' => $definition['requires_confirmation'] ?? false,
                'arguments' => $schema, 'command' => '/tool '.$tool.' JSON_OBJECT',
                'mcp_server' => $row['mcp_server'] ?? null];
        }
        usort($tasks, fn ($a, $b) => [$a['category'], $a['name']] <=> [$b['category'], $b['name']]);

        return ['skill' => $name, 'skill_problem' => $skillProblem,
            'unresolved_declared_tools' => $contract['unresolved_declared_tools'], 'tasks' => $tasks,
            'note' => 'Availability checks the registered skill contract and mode. Execution still checks policy, target bindings, arguments and required approvals. No task was executed.'];
    }
}
