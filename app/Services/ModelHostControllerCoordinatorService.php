<?php

namespace App\Services;

use RuntimeException;

class ModelHostControllerCoordinatorService
{
    public function __construct(
        private readonly ModelHostControllerPolicyService $policies,
        private readonly ModelHostControllerClient $controller
    ) {}

    /** @return array<string,mixed> */
    public function selectForRole(string $role, ?string $preferredTarget = null): array
    {
        $role = trim($role);
        if (preg_match('/\A[a-z][a-z0-9_]{1,47}\z/', $role) !== 1) {
            throw new RuntimeException('Model-host role is invalid.');
        }
        $policy = $this->policies->required();
        if ($preferredTarget !== null) {
            $target = $policy['targets'][$preferredTarget] ?? null;
            if (! is_array($target)
                || ! ($target['activation_allowed'] ?? false)
                || ! in_array($role, $target['roles'] ?? [], true)) {
                throw new RuntimeException('Preferred model-host target is not admitted for this role.');
            }

            return $this->selection($preferredTarget, $target, $role, $policy);
        }

        $eligible = [];
        foreach ($policy['targets'] as $targetId => $target) {
            if (($target['activation_allowed'] ?? false)
                && ($target['state'] ?? null) === 'production'
                && in_array($role, $target['roles'] ?? [], true)) {
                $eligible[$targetId] = $target;
            }
        }
        if ($eligible === []) {
            throw new RuntimeException('No production model-host target is admitted for this role.');
        }
        $targetId = isset($eligible[$policy['default_target']])
            ? $policy['default_target']
            : (string) array_key_first($eligible);

        return $this->selection($targetId, $eligible[$targetId], $role, $policy);
    }

    /** @return array<string,mixed> */
    public function planRole(string $role, string $requestId, ?string $preferredTarget = null): array
    {
        $selection = $this->selectForRole($role, $preferredTarget);
        if (! $selection['controller_enabled']) {
            return $selection + [
                'controller_plan' => null,
                'result' => 'controller_disabled',
            ];
        }

        return $selection + [
            'controller_plan' => $this->controller->plan($selection['target'], $requestId),
            'result' => 'planned',
        ];
    }

    /** @return array<string,mixed> */
    public function activateRole(
        string $role,
        string $requestId,
        string $leaseId,
        ?string $preferredTarget = null
    ): array {
        $selection = $this->selectForRole($role, $preferredTarget);
        if (! $selection['controller_enabled'] || ! $selection['automatic_activation_enabled']) {
            throw new RuntimeException('Automatic model-host activation is disabled by PLOS policy.');
        }
        if ($selection['requires_operator_override']) {
            throw new RuntimeException('Model-host target is excluded from automatic activation and requires the operator command path.');
        }

        return $selection + [
            'controller_activation' => $this->controller->activate($selection['target'], $requestId, $leaseId),
            'result' => 'activation_requested',
        ];
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $policy @return array<string,mixed> */
    private function selection(string $targetId, array $target, string $role, array $policy): array
    {
        return [
            'schema' => 'plos.model_host_controller.selection.v1',
            'host_id' => $policy['host_id'],
            'role' => $role,
            'target' => $targetId,
            'model' => $target['model'],
            'engine' => $target['engine'],
            'resource_group' => $target['resource_group'],
            'requires_operator_override' => $target['requires_operator_override'],
            'controller_enabled' => $policy['enabled'],
            'automatic_activation_enabled' => $policy['automatic_activation_enabled'],
            'execution_mode' => $policy['execution_mode'],
            'contains_prompt_or_response_content' => false,
        ];
    }
}
