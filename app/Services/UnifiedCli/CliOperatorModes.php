<?php

namespace App\Services\UnifiedCli;

use App\Services\AgentToolRegistryService;
use InvalidArgumentException;
use RuntimeException;

/** Session restrictions over existing skill contracts, never additional authority. */
final class CliOperatorModes
{
    public const MODES = ['chat', 'read-only', 'coding', 'operator', 'autonomous'];

    public static function validate(mixed $mode): void
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('Choose chat, read-only, coding, operator or autonomous. Modes never bypass skill permissions or required approvals.');
        }
    }

    public static function allows(string $mode, array $definition): bool
    {
        self::validate($mode);
        if ($mode === 'chat') {
            return false;
        }
        if ($mode !== 'read-only') {
            return true;
        }
        // Unknown or mixed permissions cannot truthfully promise read-only work.
        if (($definition['risk_level'] ?? null) !== 'read') {
            return false;
        }
        foreach ($definition['permissions'] ?? [] as $permission) {
            if (! is_string($permission) || ! str_ends_with($permission, ':read')) {
                return false;
            }
        }

        return true;
    }

    public static function requireTool(string $mode, array $definition): void
    {
        if (! self::allows($mode, $definition)) {
            throw new RuntimeException('The current '.$mode.' mode does not permit this tool. Inspect /permissions; explicitly select /mode operator or /mode coding to use the existing skill permissions.');
        }
    }

    public static function inspect(CliConfiguration $configuration, array $session): array
    {
        $effective = $configuration->effective($session);
        $values = $effective['values'];
        $mode = $values['cli.operator_mode'];
        self::validate($mode);
        $skillName = $configuration->selectedSkillName($session);
        $permissions = [];
        $available = [];
        $blocked = [];
        $unresolved = [];
        $reason = null;
        if ($skillName !== null) {
            try {
                $skill = (new CliSkillReader($configuration))->read($session, $skillName);
                $permissions = $skill['configuration']['permissions'] ?? [];
                $catalog = new CliToolCatalog($configuration);
                $registry = new AgentToolRegistryService(fn () => $catalog->definitions($session));
                $contract = $registry->resolveToolContractForAgent($skillName, $skill['configuration']);
                $unresolved = $contract['unresolved_declared_tools'];
                foreach ($contract['tools'] as $name => $definition) {
                    if (self::allows($mode, $definition)) {
                        $available[] = $name;
                    } else {
                        $blocked[] = $name;
                    }
                }
            } catch (\Throwable) {
                $reason = 'Selected skill is unavailable or its complete contract cannot be inspected. Use /skills and select a complete /skill.';
            }
        }
        $key = array_key_exists('cli.target', $values) ? 'cli.target' : 'cli.workflow_target';

        return ['mode' => $mode, 'mode_origin' => $effective['origins']['cli.operator_mode'],
            'available_modes' => self::MODES, 'skill' => $skillName, 'skill_permissions' => $permissions,
            'available_tools' => $available, 'mode_blocked_tools' => $blocked,
            'unresolved_tools' => $unresolved, 'skill_problem' => $reason,
            'operation_selection' => $configuration->operationSelection($session, workflow: true),
            'target_origin' => $effective['origins'][$key] ?? 'default',
            'network' => $values['cli.network'], 'native_permissions' => $values['cli.native_permissions'] ?? null,
            'policy_profile' => $values['routing.active_profile'] ?? null,
            'runtime_admission_checked' => false,
            'message' => match ($mode) {
                'chat' => 'Chat only: tool calls by the model or operator are blocked. Configuration, inspection and host lifecycle controls remain operator commands.',
                'read-only' => 'Only registered read tools with read permissions are allowed. Arbitrary shell commands and unknown or write tools are blocked; the registered fixed workspace diff uses a read-only sandbox. Host lifecycle controls remain explicit operator commands.',
                'coding' => 'Coding uses the selected skill and configured native sandbox. Select /skill offline-assistant for registered command tools; inspect /permissions before execution.',
                'autonomous' => 'The existing bounded agent loop runs within the selected skill and configured iteration/tool budgets. Required approvals still stop execution; /continue resumes a reviewed pending action.',
                default => 'Operator work uses the selected skill, tool rules and configured sandbox. Required approvals remain exact-action approvals.',
            }];
    }
}
