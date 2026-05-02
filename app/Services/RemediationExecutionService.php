<?php

namespace App\Services;

class RemediationExecutionService
{
    public function __construct(
        private readonly RemediationRegistryService $registry,
        private readonly AutoHealService $executor
    ) {
    }

    /**
     * Execute the registered remediation for a finding type.
     *
     * @return array{success: bool, status_code: int, message?: string, error?: string, detail?: string, action?: array}
     */
    public function executeFindingType(string $findingType, bool $confirmed = false): array
    {
        $action = $this->registry->getActionForFinding($findingType);

        if (!$action) {
            return [
                'success' => false,
                'status_code' => 404,
                'message' => "No remediation action registered for '{$findingType}'",
                'error' => "No remediation action registered for '{$findingType}'",
            ];
        }

        if ($action['risk_level'] === 'destructive') {
            return [
                'success' => false,
                'status_code' => 403,
                'message' => 'Destructive actions cannot be executed via the UI. Escalate to Claude Code.',
                'error' => 'Destructive actions cannot be executed via the UI. Escalate to Claude Code.',
                'action' => $action,
            ];
        }

        if (!empty($action['requires_confirmation']) && !$confirmed) {
            return [
                'success' => false,
                'status_code' => 403,
                'message' => 'This remediation requires explicit confirmation before execution.',
                'error' => 'This remediation requires explicit confirmation before execution.',
                'action' => $action,
            ];
        }

        if ($this->registry->isInCooldown($action)) {
            return [
                'success' => false,
                'status_code' => 429,
                'message' => 'Action is in cooldown. Try again later.',
                'error' => 'Action is in cooldown. Try again later.',
                'action' => $action,
            ];
        }

        $result = $this->executor->execute($action);
        $this->registry->recordExecution($action['id'], $result['success']);

        if ($result['success']) {
            return [
                'success' => true,
                'status_code' => 200,
                'message' => "Remediation executed: {$action['description']}",
                'detail' => $result['detail'] ?? '',
                'action' => $action,
            ];
        }

        return [
            'success' => false,
            'status_code' => 500,
            'message' => $result['error'] ?? 'Remediation failed',
            'error' => $result['error'] ?? 'Remediation failed',
            'action' => $action,
        ];
    }
}
