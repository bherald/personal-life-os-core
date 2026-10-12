<?php

namespace App\Services;

use App\Services\UnifiedCli\CliLocalBindings;
use App\Services\UnifiedCli\CliStateStore;
use RuntimeException;

/** Private cross-process cancellation/progress bridge; never a model tool or credential container. */
final class CyberManagedModelJobContext
{
    private CliStateStore $store;

    private array $context;

    public function __construct(string $path, string $sha256)
    {
        $bytes = CliLocalBindings::readPrivateFile($path);
        if (! hash_equals($sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('Durable job context changed.');
        }
        $this->context = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $c = $this->context;
        if (($c['schema'] ?? null) !== 'plos.cyber.managed-job-context.v1'
            || array_diff(array_keys($c), ['schema', 'state_path', 'session_id', 'turn_id', 'owner', 'scope_sha256', 'artifact_sha256', 'connection_sha256', 'artifact_directory']) !== []) {
            throw new RuntimeException('Unsupported durable model job context.');
        }
        foreach (['state_path', 'session_id', 'turn_id', 'owner', 'scope_sha256', 'artifact_sha256', 'connection_sha256', 'artifact_directory'] as $key) {
            if (! is_string($c[$key] ?? null) || $c[$key] === '') {
                throw new RuntimeException('Incomplete durable job context.');
            }
        }
        CliLocalBindings::readPrivateFile($c['state_path']);
        $this->store = new CliStateStore($c['state_path']);
        $receipt = $this->store->actionReceipts($c['session_id'], $c['turn_id'])['managed-model-suite'] ?? null;
        $arguments = $receipt['document']['action']['arguments'] ?? [];
        $expected = array_intersect_key($c, array_flip(['turn_id', 'owner', 'scope_sha256', 'artifact_sha256', 'connection_sha256']));
        if (($receipt['state'] ?? null) !== 'started' || ($receipt['document']['action']['tool'] ?? null) !== 'cyber_managed_model_suite' || $arguments !== $expected) {
            throw new RuntimeException('Job context does not match its exact claimed action.');
        }
        $this->assertActive();
    }

    public function assertActive(): void
    {
        $this->store->assertTurnNotCancelled($this->context['session_id'], $this->context['turn_id']);
    }

    public function artifactDirectory(): string
    {
        return $this->context['artifact_directory'];
    }

    public function progress(string $phase): void
    {
        if (! in_array($phase, ['model_request', 'model_response', 'tool_action', 'suite_started', 'suite_finished'], true)) {
            throw new RuntimeException('Unsupported model job progress event.');
        }
        $this->store->recordEvent($this->context['session_id'], 'cyber_managed_model_progress', ['phase' => $phase]);
    }
}
