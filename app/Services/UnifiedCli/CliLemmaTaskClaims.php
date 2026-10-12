<?php

namespace App\Services\UnifiedCli;

use RuntimeException;

/** Create-only task ownership in the shared pod; one active row per task key. */
final class CliLemmaTaskClaims
{
    public function __construct(
        private readonly string $sessionId,
        private readonly array $definition,
        private readonly string $root,
        private readonly string $node,
        private readonly int $timeoutMs,
    ) {}

    public function claim(string $key, string $summary): array
    {
        $this->validateKey($key);
        if (trim($summary) === '' || mb_strlen($summary) > 300) {
            throw new RuntimeException('Task claim needs a short summary.');
        }
        $existing = $this->byKey($key);
        if ($existing !== null) {
            return $this->owned($existing, true);
        }
        try {
            $this->mcp('lemma_pod_write_record', ['action' => 'create', 'table_name' => 'agent_task_claims',
                'data' => ['task_key' => $key, 'owner_session' => $this->sessionId, 'summary' => trim($summary)]]);
        } catch (\Throwable) {
            $existing = $this->byKey($key);
            if ($existing === null) {
                throw new CliToolOutcomeUnknown('Task claim outcome is unknown; inspect the task key before retrying.');
            }
        }
        $existing = $this->byKey($key);
        if ($existing === null) {
            throw new CliToolOutcomeUnknown('Task claim has no verified read-back.');
        }

        return $this->owned($existing, false);
    }

    public function release(string $key): array
    {
        $this->validateKey($key);
        $claim = $this->byKey($key);
        if ($claim === null || ($claim['owner_session'] ?? null) !== $this->sessionId) {
            throw new RuntimeException('This session does not own that task claim.');
        }
        try {
            $this->mcp('lemma_pod_write_record', ['action' => 'delete', 'table_name' => 'agent_task_claims',
                'record_id' => $claim['id']]);
        } catch (\Throwable) {
            if ($this->byKey($key) !== null) {
                throw new CliToolOutcomeUnknown('Task release outcome is unknown; inspect the claim before retrying.');
            }
        }
        if ($this->byKey($key) !== null) {
            throw new CliToolOutcomeUnknown('Task release has no verified read-back.');
        }

        return ['released' => true, 'task_key' => $key, 'owner_session' => $this->sessionId];
    }

    public function listing(): array
    {
        $result = $this->mcp('lemma_pod_get_records', ['table_name' => 'agent_task_claims',
            'sorts' => [['column' => 'created_at', 'direction' => 'desc']], 'limit' => 200]);
        $records = $result['records'] ?? null;
        if (! is_array($records)) {
            throw new RuntimeException('Task claim list is incomplete.');
        }

        return ['claims' => $records, 'count' => count($records)];
    }

    private function byKey(string $key): ?array
    {
        $result = $this->mcp('lemma_pod_get_records', ['table_name' => 'agent_task_claims',
            'filters' => [['column' => 'task_key', 'op' => 'eq', 'value' => $key]], 'limit' => 2]);
        $records = $result['records'] ?? null;
        if (! is_array($records) || count($records) > 1) {
            throw new RuntimeException('Task claim key lookup is invalid.');
        }

        return $records[0] ?? null;
    }

    private function owned(array $claim, bool $existing): array
    {
        if (($claim['owner_session'] ?? null) !== $this->sessionId) {
            return ['claimed' => false, 'task_key' => $claim['task_key'],
                'owner_session' => $claim['owner_session'] ?? null, 'reason' => 'task_already_claimed'];
        }

        return ['claimed' => true, 'claim_id' => $claim['id'], 'task_key' => $claim['task_key'],
            'owner_session' => $this->sessionId, 'already_owned' => $existing];
    }

    private function validateKey(string $key): void
    {
        if (! preg_match('~^[A-Za-z0-9][A-Za-z0-9._:/-]{0,159}$~D', $key)) {
            throw new RuntimeException('Task key must be a stable 1 to 160 character name.');
        }
    }

    private function mcp(string $tool, array $arguments): array
    {
        return CliLemmaPodMcpService::result($tool, $arguments, $this->definition,
            $this->root, $this->node, $this->timeoutMs);
    }
}
