<?php

namespace App\Services\UnifiedCli;

use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Explicit CLI context projection; original messages, actions and results remain authoritative. */
final class CliCompaction
{
    public function __construct(private readonly CliStateStore $store) {}

    public static function validatePolicy(mixed $policy): array
    {
        if (! is_array($policy) || ! is_bool($policy['enabled'] ?? null)
            || ! is_string($policy['prompt'] ?? null) || trim($policy['prompt']) === ''
            || ! is_int($policy['confirmation_ttl_seconds'] ?? null)
            || $policy['confirmation_ttl_seconds'] < 1 || $policy['confirmation_ttl_seconds'] > 86400
            || (isset($policy['warning_percent']) && (! is_int($policy['warning_percent']) || $policy['warning_percent'] < 1 || $policy['warning_percent'] > 100))
            || (array_key_exists('max_requests', $policy) && (! is_int($policy['max_requests']) || $policy['max_requests'] < 1))
            || array_diff(array_keys($policy), ['enabled', 'prompt', 'confirmation_ttl_seconds', 'warning_percent', 'max_requests']) !== []) {
            throw new InvalidArgumentException('CLI compaction policy requires enabled, a summary prompt and confirmation_ttl_seconds (1–86400). Automatic compaction is not an option.');
        }

        return $policy;
    }

    public function prepare(array $session): array
    {
        $policy = $this->policy($session);
        $this->requireResolvedHistory($session);
        if (($session['messages'] ?? []) === []) {
            throw new RuntimeException('There is no model conversation to compact. Prior session records remain intact.');
        }
        if (($session['compaction_request']['state'] ?? null) === 'running') {
            throw new RuntimeException('An interrupted compaction needs /compact cancel before a new request. It will not be replayed.');
        }
        $session['compaction_request'] = ['id' => (string) Str::uuid(), 'state' => 'pending',
            'binding' => $this->binding($session), 'expires_at' => time() + $policy['confirmation_ttl_seconds']];

        return $this->store->saveSession($session);
    }

    public function cancel(array $session): array
    {
        if (isset($session['compaction_request'])) {
            $session['compaction_request']['state'] = 'cancelled';
            $session = $this->store->saveSession($session);
        }

        return $session;
    }

    public function restore(array $session): array
    {
        unset($session['active_context_compaction']);
        if (isset($session['compaction_request'])) {
            $session['compaction_request']['state'] = 'cancelled';
        }

        return $this->store->saveSession($session);
    }

    public function begin(array $session, string $id): array
    {
        $this->policy($session);
        $this->requireResolvedHistory($session);
        $pending = $session['compaction_request'] ?? [];
        if (($pending['id'] ?? null) !== $id || ($pending['state'] ?? null) !== 'pending'
            || ($pending['expires_at'] ?? 0) <= time() || ($pending['binding'] ?? null) !== $this->binding($session)) {
            throw new RuntimeException('Compaction confirmation is missing, expired, used or stale. Use /compact to review the current session.');
        }
        $session['compaction_request']['state'] = 'running';
        $session['compaction_request']['operator_turn'] = $session['turns'][array_key_last($session['turns'])]['id'];

        return $this->store->saveSession($session);
    }

    public function finish(array $session, string $summary, array $route): array
    {
        if (($session['compaction_request']['state'] ?? null) !== 'running' || trim($summary) === '') {
            throw new RuntimeException('An explicitly confirmed compaction with a nonempty summary is required.');
        }
        $projection = ['id' => $session['compaction_request']['id'], 'source_count' => count($session['messages']),
            'source_sha256' => self::hash($session['messages']), 'summary' => $summary,
            'operator_turn' => $session['compaction_request']['operator_turn'],
            'instance_id' => $route['instance_id'], 'model' => $route['model']];
        $session['context_compactions'][] = $projection;
        $session['active_context_compaction'] = $projection;
        $session['compaction_request']['state'] = 'completed';

        return $this->store->saveSession($session);
    }

    public function policy(array $session): array
    {
        $policy = self::validatePolicy((new CliConfiguration($this->store))->effective($session)['values']['cli.compaction_policy'] ?? null);
        if (! $policy['enabled']) {
            throw new RuntimeException('Manual compaction is disabled by the effective CLI table settings. Use /model or /clear.');
        }

        return $policy;
    }

    private function binding(array $session): string
    {
        return self::hash(['messages' => $session['messages'] ?? [],
            'projection' => $session['active_context_compaction'] ?? null,
            'configuration' => (new CliConfiguration($this->store))->effective($session)]);
    }

    private function requireResolvedHistory(array $session): void
    {
        if ((new LosslessConversation($session['messages'] ?? []))->pendingToolCalls() !== []) {
            throw new RuntimeException('Resolve or explicitly cancel pending tool actions before compaction. No action was replayed.');
        }
    }

    public static function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
