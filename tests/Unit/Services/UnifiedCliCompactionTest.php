<?php

namespace Tests\Unit\Services;

use App\Services\UnifiedCli\CliCompaction;
use App\Services\UnifiedCli\CliConfiguration;
use App\Services\UnifiedCli\CliPrimaryAgent;
use App\Services\UnifiedCli\CliStateStore;
use App\Services\UnifiedCli\LosslessConversation;
use App\Services\UnifiedCli\RegistrySnapshotValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class UnifiedCliCompactionTest extends TestCase
{
    private function fixture(): array
    {
        $store = new CliStateStore(':memory:');
        $policy = ['enabled' => true, 'prompt' => 'Produce only a summary. Do not execute tools.', 'confirmation_ttl_seconds' => 900];
        $tables = array_fill_keys(RegistrySnapshotValidator::REQUIRED_TABLES, []);
        $tables['system_configs'] = [['section' => 'cli', 'config_key' => 'compaction_policy', 'data_type' => 'json', 'config_value' => json_encode($policy)]];
        $generation = $store->activate(['schema' => RegistrySnapshotValidator::SCHEMA, 'source' => 'fixture', 'exported_at' => gmdate('c'), 'tables' => $tables]);
        $session = $store->createSession(['generation' => $generation, 'overrides' => [],
            'messages' => [['role' => 'system', 'content' => "  complete guards\r\n"], ['role' => 'user', 'content' => str_repeat("José\r\n", 3000)]],
            'turns' => [['id' => 'operator-confirm', 'status' => 'inProgress']]]);
        $route = ['instance_id' => 'fixture', 'model' => 'fixture', 'agent_admitted' => true,
            'context_window' => 8192, 'max_output_tokens' => 256, 'max_iterations' => 2, 'max_tool_calls' => 1];

        return [$store, $session, $route, $policy];
    }

    private function agent(CliStateStore $store, array $route, \Closure $transport, ?\Closure $counter = null): CliPrimaryAgent
    {
        return new CliPrimaryAgent($store, fn () => $route, fn () => [], $transport, $counter ?? fn () => 10,
            fn () => ['allowed' => true], fn () => $this->fail('Compaction executed a tool.'));
    }

    public function test_projection_retains_exact_instructions_original_media_and_later_messages(): void
    {
        [$store, $session, $route] = $this->fixture();
        $session['messages'][] = ['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,ZmFrZQ==']]]];
        $session = $store->saveSession($session);
        $original = $session['messages'];
        $session = (new CliCompaction($store))->prepare($session);
        $summary = "  Summary José\r\n  ";
        $agent = $this->agent($store, $route, function ($selected, $messages, $tools) use ($original, $summary, $route): array {
            $this->assertSame($original, array_slice($messages, 0, count($original)));
            $this->assertSame([], $tools);

            return $route + ['completed' => true, 'content' => $summary, 'tool_calls' => []];
        });
        [$saved] = $agent->compact($session, $session['compaction_request']['id']);
        $this->assertSame($original, $saved['messages']);
        $conversation = new LosslessConversation($saved['messages'], $saved['active_context_compaction']);
        $conversation->append(['role' => 'user', 'content' => "  next input\r\n"]);
        $this->assertSame($original[0], $conversation->requestMessages()[0]);
        $this->assertStringEndsWith($summary, $conversation->requestMessages()[1]['content']);
        $this->assertSame("  next input\r\n", $conversation->requestMessages()[2]['content']);
        $this->assertSame($original, array_slice($conversation->messages(), 0, count($original)));
        $tampered = $saved['messages'];
        $tampered[1]['content'] .= ' changed';
        $this->expectExceptionMessage('does not match');
        (new LosslessConversation($tampered, $saved['active_context_compaction']))->requestMessages();
    }

    public function test_stale_expired_cancelled_and_foreign_confirmations_never_call_model(): void
    {
        foreach (['stale', 'expired', 'cancelled', 'foreign'] as $case) {
            [$store, $session, $route] = $this->fixture();
            $control = new CliCompaction($store);
            $session = $control->prepare($session);
            $id = $session['compaction_request']['id'];
            if ($case === 'stale') {
                $session['overrides']['cli.network'] = 'lan';
            } elseif ($case === 'expired') {
                $session['compaction_request']['expires_at'] = time() - 1;
            } elseif ($case === 'cancelled') {
                $session = $control->cancel($session);
            } else {
                $id = 'foreign';
            }
            $session = $store->saveSession($session);
            try {
                $this->agent($store, $route, fn () => $this->fail('Unconfirmed generation.'))->compact($session, $id);
                $this->fail('Invalid confirmation accepted: '.$case);
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('confirmation', $error->getMessage());
                $this->assertSame($session, $store->session($session['id']));
            }
        }
    }

    public function test_failed_incomplete_wrong_model_or_tool_output_leaves_context_unchanged_and_cannot_replay(): void
    {
        foreach (['interrupted', 'incomplete', 'wrong_model', 'tool_call', 'overflow'] as $case) {
            [$store, $session, $route] = $this->fixture();
            $original = $session['messages'];
            $session = (new CliCompaction($store))->prepare($session);
            $id = $session['compaction_request']['id'];
            $calls = 0;
            $agent = $this->agent($store, $route, function () use (&$calls, $case, $route): array {
                $calls++;
                if ($case === 'interrupted') {
                    throw new RuntimeException('Fixture connection lost.');
                }

                return array_replace($route, ['completed' => $case !== 'incomplete', 'content' => 'summary',
                    'model' => $case === 'wrong_model' ? 'different' : $route['model'], 'tool_calls' => $case === 'tool_call' ? [['id' => 'forbidden']] : []]);
            }, fn () => $case === 'overflow' ? 9000 : 10);
            try {
                $agent->compact($session, $id);
                $this->fail('Invalid compaction accepted.');
            } catch (RuntimeException $error) {
                $saved = $store->session($session['id']);
                $this->assertSame($original, $saved['messages']);
                $this->assertArrayNotHasKey('active_context_compaction', $saved);
                $this->assertSame('failed', $saved['compaction_request']['state']);
            }
            $this->assertSame($case === 'overflow' ? 0 : 1, $calls);
            try {
                $agent->compact($store->session($session['id']), $id);
                $this->fail('Failed generation replayed.');
            } catch (RuntimeException) {
                $this->assertSame($case === 'overflow' ? 0 : 1, $calls);
            }
        }
    }

    public function test_saved_policy_never_saves_confirmation_or_enables_automatic_compaction(): void
    {
        [$store, $session, , $policy] = $this->fixture();
        $configuration = new CliConfiguration($store);
        $policy['prompt'] = 'Operator custom summary prompt';
        $session = $configuration->set($session, 'cli.compaction_policy', $policy, true);
        $this->assertSame($policy, $store->defaults()['cli.compaction_policy']);
        $this->assertArrayNotHasKey('compaction_request', $session);
        $this->assertFalse($configuration->effective($session)['input_integrity']['compaction']);
        $this->expectException(\InvalidArgumentException::class);
        $configuration->set($session, 'cli.compaction_policy', $policy + ['automatic' => true], true);
    }

    public function test_pending_actions_cannot_be_hidden_by_compaction(): void
    {
        [$store, $session] = $this->fixture();
        $session['messages'][] = ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
            'id' => 'pending', 'type' => 'function', 'function' => ['name' => 'fixture', 'arguments' => '{}'],
        ]]];
        $session = $store->saveSession($session);
        try {
            (new CliCompaction($store))->prepare($session);
            $this->fail('Compaction hid a pending action.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('pending tool actions', $error->getMessage());
            $this->assertSame($session, $store->session($session['id']));
        }
    }

    public static function rejectingTransports(): array
    {
        return ['Ollama' => ['ollama_chat'], 'Chat Completions' => ['chat_completions']];
    }

    #[DataProvider('rejectingTransports')]
    public function test_native_ollama_summary_uses_complete_input_without_a_local_tokenizer(string $wireApi): void
    {
        [$store, $session, $route] = $this->fixture();
        $route += ['wire_api' => $wireApi, 'context_handling' => 'provider_reject'];
        $original = $session['messages'];
        $session = (new CliCompaction($store))->prepare($session);
        $agent = $this->agent($store, $route, function ($selected, $messages, $tools) use ($original, $route): array {
            $this->assertSame($original, array_slice($messages, 0, count($original)));
            $this->assertSame([], $tools);

            return $route + ['completed' => true, 'content' => 'native summary'];
        }, fn () => $this->fail('Admitted provider required a local tokenizer.'));
        [$saved] = $agent->compact($session, $session['compaction_request']['id']);
        $this->assertSame('native summary', $saved['active_context_compaction']['summary']);
        $this->assertSame($original, $saved['messages']);
    }

    public function test_oversized_history_keeps_order_and_complete_instructions_across_summary_requests(): void
    {
        [$store, $session, $route, $policy] = $this->fixture();
        $route['max_iterations'] = 8;
        $policy['max_requests'] = 8;
        $session['messages'] = [$session['messages'][0]];
        foreach (range(1, 6) as $index) {
            $session['messages'][] = ['role' => 'user', 'content' => $index.str_repeat('x', 2000)];
        }
        $session = $store->saveSession($session);
        $session = (new CliConfiguration($store))->set($session, 'cli.compaction_policy', $policy);
        $original = $session['messages'];
        $session = (new CliCompaction($store))->prepare($session);
        $requests = [];
        $agent = $this->agent($store, $route, function ($selected, $messages, $tools) use (&$requests, $route, $original): array {
            $requests[] = $messages;
            $this->assertSame($original[0], $messages[0]);
            $this->assertSame([], $tools);

            return $route + ['completed' => true, 'content' => 'summary '.count($requests)];
        }, fn ($selected, $messages) => strlen(json_encode($messages)));
        [$saved] = $agent->compact($session, $session['compaction_request']['id']);
        $this->assertCount(3, $requests);
        $this->assertSame(array_slice($original, 1, 3), array_slice($requests[0], 1, -1));
        $this->assertSame(array_slice($original, 4, 3), array_slice($requests[1], 1, -1));
        $this->assertStringContainsString('["summary 1","summary 2"]', $requests[2][1]['content']);
        $this->assertSame($original, $saved['messages']);
        $this->assertSame('summary 3', $saved['active_context_compaction']['summary']);
        $this->assertSame(3, $saved['compaction_request']['model_requests_started']);
    }

    public function test_native_overflow_splits_only_confirmed_work_and_retains_complete_tool_groups(): void
    {
        [$store, $session, $route, $policy] = $this->fixture();
        $route = array_replace($route, ['wire_api' => 'ollama_chat', 'context_handling' => 'provider_reject', 'max_iterations' => 8]);
        $policy['max_requests'] = 8;
        $session['messages'] = [$session['messages'][0], ['role' => 'user', 'content' => 'Original request'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'one', 'type' => 'function', 'function' => ['name' => 'fixture', 'arguments' => '{}']],
                ['id' => 'two', 'type' => 'function', 'function' => ['name' => 'fixture', 'arguments' => '{}']],
            ]], ['role' => 'tool', 'tool_call_id' => 'two', 'content' => "  second José\r\n"],
            ['role' => 'tool', 'tool_call_id' => 'one', 'content' => 'first']];
        $session = $store->saveSession($session);
        $session = (new CliConfiguration($store))->set($session, 'cli.compaction_policy', $policy);
        $original = $session['messages'];
        $session = (new CliCompaction($store))->prepare($session);
        $requests = [];
        $agent = $this->agent($store, $route, function ($selected, $messages, $tools) use (&$requests, $route): array {
            $requests[] = $messages;
            $this->assertSame([], $tools);
            if (count($requests) === 1) {
                throw new \OverflowException('Fixture provider context rejection.');
            }

            return $route + ['completed' => true, 'content' => 'summary '.count($requests)];
        }, fn () => $this->fail('Native Ollama required a local tokenizer.'));
        [$saved] = $agent->compact($session, $session['compaction_request']['id']);
        $this->assertCount(4, $requests);
        $this->assertSame(array_slice($original, 2), array_slice($requests[2], 1, -1));
        $this->assertSame($original, $saved['messages']);
        $this->assertSame(4, $saved['compaction_request']['model_requests_started']);
    }

    public function test_oversized_atomic_media_keeps_originals_and_does_not_generate_text_fragments(): void
    {
        [$store, $session, $route, $policy] = $this->fixture();
        $route['max_iterations'] = 8;
        $policy['max_requests'] = 8;
        $session['messages'][1]['content'] = [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,ZmFrZQ==']]];
        $session = $store->saveSession($session);
        $session = (new CliConfiguration($store))->set($session, 'cli.compaction_policy', $policy);
        $original = $session['messages'];
        $session = (new CliCompaction($store))->prepare($session);
        try {
            $this->agent($store, $route, fn () => $this->fail('Oversized media generated a summary.'), fn () => 9000)
                ->compact($session, $session['compaction_request']['id']);
            $this->fail('Oversized media was split.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('media message', $error->getMessage());
            $saved = $store->session($session['id']);
            $this->assertSame($original, $saved['messages']);
            $this->assertArrayNotHasKey('active_context_compaction', $saved);
            $this->assertSame('failed', $saved['compaction_request']['state']);
        }
    }

    public function test_large_single_record_fragments_preserve_every_original_utf8_byte_and_obey_budget(): void
    {
        foreach ([2, 8] as $limit) {
            [$store, $session, $route, $policy] = $this->fixture();
            $route['context_window'] = 4096;
            $route['max_iterations'] = 8;
            $policy['max_requests'] = $limit;
            $session['messages'][1]['content'] = str_repeat('José', 1200);
            $session = $store->saveSession($session);
            $session = (new CliConfiguration($store))->set($session, 'cli.compaction_policy', $policy);
            $original = $session['messages'];
            $session = (new CliCompaction($store))->prepare($session);
            $requests = [];
            $agent = $this->agent($store, $route, function ($selected, $messages) use (&$requests, $route): array {
                $requests[] = $messages;

                return $route + ['completed' => true, 'content' => 'short summary'];
            }, fn ($selected, $messages) => strlen(json_encode($messages, JSON_UNESCAPED_UNICODE)));
            try {
                [$saved] = $agent->compact($session, $session['compaction_request']['id']);
                $this->assertSame(8, $limit, 'Exceeded the confirmed request budget.');
                $this->assertCount(3, $requests);
                $this->assertSame('short summary', $saved['active_context_compaction']['summary']);
            } catch (RuntimeException $error) {
                $this->assertSame(2, $limit, $error->getMessage());
                $this->assertStringContainsString('request limit', $error->getMessage());
                $this->assertCount(2, $requests);
                $saved = $store->session($session['id']);
                $this->assertArrayNotHasKey('active_context_compaction', $saved);
                $this->assertSame('failed', $saved['compaction_request']['state']);
            }
            $parts = array_map(fn ($request) => substr($request[1]['content'], strpos($request[1]['content'], "\n") + 1), array_slice($requests, 0, 2));
            $this->assertSame(json_encode([$original[1]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), implode('', $parts));
            $this->assertSame($original, $saved['messages']);
        }
    }
}
