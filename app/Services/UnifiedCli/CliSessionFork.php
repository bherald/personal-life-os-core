<?php

namespace App\Services\UnifiedCli;

use RuntimeException;

/** Copy conversation context, never running work or action authority. */
final class CliSessionFork
{
    public function __construct(private readonly CliStateStore $store) {}

    public function create(array $source, ?string $name = null): array
    {
        if ((new LosslessConversation($source['messages'] ?? []))->pendingToolCalls() !== []) {
            throw new RuntimeException('Resolve or cancel pending tool calls before forking. A fork cannot inherit or replay another session\'s actions.');
        }
        $turns = [];
        foreach ($source['turns'] ?? [] as $turn) {
            if (($turn['status'] ?? null) === 'inProgress') {
                $input = $turn['items'][0]['content'] ?? [];
                $command = count($input) === 1 ? ($input[0]['text'] ?? '') : '';
                if (! preg_match('/^\/fork(?:\s.*)?$/sD', $command)) {
                    throw new RuntimeException('Finish or recover the active turn before forking. Running work is never copied.');
                }

                continue;
            }
            $turns[] = $turn;
        }
        $copy = array_intersect_key($source, array_flip(['generation', 'overrides', 'messages',
            'attachments', 'pending_attachments', 'active_context_compaction', 'context_compactions']));
        $copy += ['messages' => [], 'overrides' => []];
        $copy['turns'] = $turns;
        $copy['created_at'] = time();
        $copy['name'] = $name ?? (($source['name'] ?? 'Session').' (fork)');
        $copy['session_start_source'] = 'fork';
        $copy['forked_from'] = ['session_id' => $source['id'], 'revision' => $source['revision'],
            'conversation_sha256' => (new LosslessConversation($source['messages'] ?? []))->hash()];

        return $this->store->createSession($copy);
    }
}
