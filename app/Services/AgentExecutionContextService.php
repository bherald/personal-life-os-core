<?php

namespace App\Services;

class AgentExecutionContextService
{
    /**
     * Build the bounded runtime context shared by tool policy, execution, and audit.
     *
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $delegationGovernance
     * @return array<string,mixed>
     */
    public function build(
        string $agentId,
        string $sessionId,
        array $options,
        array $delegationGovernance
    ): array {
        $context = is_array($options['context'] ?? null) ? $options['context'] : [];
        $metadata = is_array($context['metadata'] ?? null) ? $context['metadata'] : [];

        $runtime = [
            'session_id' => $sessionId,
            'agent_id' => $agentId,
            'tree_id' => $options['tree_id'] ?? null,
            'delegation_governance' => $delegationGovernance,
            'ds_profile' => $options['ds_profile'] ?? $delegationGovernance['ds_profile'] ?? null,
            'sidecar' => $options['sidecar'] ?? $delegationGovernance['sidecar'] ?? false,
            'delegation_depth' => $options['delegation_depth'] ?? $delegationGovernance['depth'] ?? 0,
            'ds_confirmed' => $options['ds_confirmed'] ?? $delegationGovernance['operator_confirmed'] ?? false,
        ];

        foreach (['parent_session_id', 'handoff_id', 'scheduled_job_name', 'distributed_task_id', 'workflow_id'] as $key) {
            $value = $options[$key] ?? $context[$key] ?? $metadata[$key] ?? null;
            if (is_string($value) || is_int($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $runtime[$key] = $value;
                }
            }
        }

        $runtime['execution_origin'] = $this->executionOrigin($options, $runtime);

        return $runtime;
    }

    /** @param array<string,mixed> $options @param array<string,mixed> $runtime */
    private function executionOrigin(array $options, array $runtime): string
    {
        $explicit = $options['execution_origin'] ?? null;
        if (is_string($explicit) && preg_match('/^[a-z][a-z0-9_-]{1,40}$/', $explicit) === 1) {
            return $explicit;
        }

        if (isset($runtime['handoff_id'])) {
            return 'handoff';
        }
        if (isset($runtime['distributed_task_id'])) {
            return 'distributed_task';
        }
        if (isset($runtime['scheduled_job_name'])) {
            return 'scheduled_job';
        }

        return isset($options['session_id']) && filled($options['session_id'])
            ? 'resumed_session'
            : 'direct';
    }
}
