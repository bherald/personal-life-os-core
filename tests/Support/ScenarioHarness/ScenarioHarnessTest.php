<?php

declare(strict_types=1);

namespace Tests\Support\ScenarioHarness;

use App\Services\AIService;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Framework B5 — unit coverage for the scenario harness itself.
 *
 * A later refactor of the harness should break these tests if it
 * regresses any of the contracts. Covered surfaces:
 *
 *   - Scripted response queue (FIFO, bounded, drained)
 *   - Tool-call matching against scripted JSON blocks
 *   - "Ran out of scripted turns" failure mode
 *   - Container binding so AgentLoopService resolves the stub
 *   - Assertion helpers (positive + negative paths)
 */
class ScenarioHarnessTest extends TestCase
{
    #[Test]
    public function scripted_turns_are_served_fifo(): void
    {
        $stub = new ScriptedAIService;
        $stub->pushTurn('first');
        $stub->pushTurn('second');
        $stub->pushTurn('third');

        $this->assertSame('first', $stub->process('')['content']);
        $this->assertSame('second', $stub->process('')['content']);
        $this->assertSame('third', $stub->process('')['content']);
        $this->assertSame(3, $stub->turnsConsumed());
        $this->assertSame(0, $stub->turnsRemaining());
    }

    #[Test]
    public function running_out_of_scripted_turns_throws_with_diagnostic_message(): void
    {
        $stub = new ScriptedAIService;
        $stub->pushTurn('only-one');
        $stub->process('');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ran out of scripted turns/i');
        $stub->process('');
    }

    #[Test]
    public function process_response_shape_matches_agent_loop_expectations(): void
    {
        $stub = new ScriptedAIService;
        $stub->pushTurn('payload', tokens: 42, model: 'fake-model');

        $resp = $stub->process('prompt', ['foo' => 'bar']);

        // AgentLoopService reads both `content` and `response` as fallbacks;
        // harness must populate both so either path works.
        $this->assertSame('payload', $resp['content']);
        $this->assertSame('payload', $resp['response']);
        $this->assertSame(42, $resp['tokens']);
        $this->assertSame('fake-model', $resp['model']);
    }

    #[Test]
    public function process_records_each_prompt_for_later_inspection(): void
    {
        $stub = new ScriptedAIService;
        $stub->pushTurn('a');
        $stub->pushTurn('b');

        $stub->process('first-prompt', ['x' => 1]);
        $stub->process('second-prompt', ['y' => 2]);

        $calls = $stub->calls();
        $this->assertCount(2, $calls);
        $this->assertSame('first-prompt', $calls[0]['prompt']);
        $this->assertSame(1, $calls[0]['config']['x']);
        $this->assertSame('second-prompt', $calls[1]['prompt']);
    }

    #[Test]
    public function harness_binds_scripted_service_into_container(): void
    {
        $harness = new ScenarioHarness($this->app, 'test-agent');

        $resolved = $this->app->make(AIService::class);
        $this->assertInstanceOf(ScriptedAIService::class, $resolved);
        $this->assertSame($harness->scriptedAI(), $resolved);
    }

    #[Test]
    public function tool_call_json_blocks_are_parsed_and_handler_invoked(): void
    {
        $harness = new ScenarioHarness($this->app, 'test-agent');
        $captured = null;

        $harness->registerTool('echo_tool', function (array $params) use (&$captured): string {
            $captured = $params;

            return 'echo-result';
        });

        $harness->scriptToolCall('echo_tool', ['message' => 'hello']);
        $harness->scriptFinalResponse('done');

        $result = $harness->run('task');

        $this->assertSame(['message' => 'hello'], $captured);
        $this->assertSame('done', $result['final_response']);
        $this->assertCount(1, $result['tool_calls']);
        $this->assertSame('echo_tool', $result['tool_calls'][0]['tool']);
        $this->assertSame('echo-result', $result['tool_calls'][0]['result']);
    }

    #[Test]
    public function multi_tool_sequence_runs_all_steps_in_order(): void
    {
        $harness = new ScenarioHarness($this->app, 'test-agent');

        $harness
            ->scriptToolCall('step_one', resultText: 'r1')
            ->scriptToolCall('step_two', resultText: 'r2')
            ->scriptToolCall('step_three', resultText: 'r3')
            ->scriptFinalResponse('all done');

        $harness->run();

        $harness
            ->assertToolCallCount(3)
            ->assertToolCalled('step_one')
            ->assertToolCalled('step_two')
            ->assertToolCalled('step_three')
            ->assertFinalResponseContains('all done');

        $order = array_column($harness->toolCalls(), 'tool');
        $this->assertSame(['step_one', 'step_two', 'step_three'], $order);
    }

    #[Test]
    public function unregistered_tool_request_throws(): void
    {
        $harness = new ScenarioHarness($this->app, 'test-agent');

        // Script a raw turn that requests a tool we never registered.
        // (scriptToolCall would auto-register, so we push directly.)
        $harness->scriptedAI()->pushTurn("```json\n".json_encode(['tool' => 'ghost', 'params' => []])."\n```");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no handler is registered/i');
        $harness->run();
    }

    #[Test]
    public function phase_reached_is_tracked_per_tool_phase(): void
    {
        $harness = new ScenarioHarness($this->app, 'test-agent');

        $harness
            ->scriptToolCall('log_scan_files', resultText: '{}', phase: 'scan')
            ->scriptToolCall('log_parse_errors', resultText: '{}', phase: 'scan')
            ->scriptToolCall('log_error_timeline', resultText: '{}', phase: 'analyze')
            ->scriptToolCall('log_save_snapshot', resultText: 'saved', phase: 'report')
            ->scriptFinalResponse('complete');

        $harness->run();

        $harness->assertPhaseReached('scan');
        $harness->assertPhaseReached('analyze');
        $harness->assertPhaseReached('report');
        $this->assertSame(['scan', 'analyze', 'report'], $harness->phasesReached());
    }

    #[Test]
    public function assert_tool_called_fails_when_tool_absent(): void
    {
        $harness = new ScenarioHarness($this->app, 'test-agent');
        $harness->scriptFinalResponse('empty run');
        $harness->run();

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $harness->assertToolCalled('never_called');
    }

    #[Test]
    public function assert_final_response_contains_fails_on_mismatch(): void
    {
        $harness = new ScenarioHarness($this->app, 'test-agent');
        $harness->scriptFinalResponse('hello world');
        $harness->run();

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $harness->assertFinalResponseContains('goodbye');
    }

    #[Test]
    public function scenario_cannot_be_run_twice(): void
    {
        $harness = new ScenarioHarness($this->app, 'test-agent');
        $harness->scriptFinalResponse('done');
        $harness->run();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already executed/i');
        $harness->run();
    }

    #[Test]
    public function over_scripted_turns_cause_run_to_fail_loudly(): void
    {
        // Final response terminates the loop; the extra turn queued after
        // is a scripting bug the harness must surface.
        $harness = new ScenarioHarness($this->app, 'test-agent');
        $harness->scriptFinalResponse('done');
        $harness->scriptedAI()->pushTurn('leftover-turn');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/still queued/i');
        $harness->run();
    }

    #[Test]
    public function max_iterations_caps_loop_length(): void
    {
        // Script far more tool calls than the iteration cap. Even though
        // the final turn is scripted, the cap must stop us first.
        $harness = new ScenarioHarness($this->app, 'test-agent', maxIterations: 3);

        for ($i = 0; $i < 5; $i++) {
            $harness->scriptToolCall('spin', resultText: "result-{$i}");
        }
        $harness->scriptFinalResponse('would-be-final');

        try {
            $harness->run();
            $this->fail('Expected harness to raise when extra turns remained after iteration cap');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('still queued', $e->getMessage());
        }

        // Cap respected: exactly 3 tool calls executed.
        $this->assertCount(3, $harness->toolCalls());
    }

    #[Test]
    public function tool_result_is_fed_back_through_real_build_tool_result_message(): void
    {
        // Sanity check that buildToolResultMessage is invoked for tool
        // results — we assert by observing the prompt that reaches the
        // SECOND scripted turn contains the "Tool result for X:" prefix.
        $harness = new ScenarioHarness($this->app, 'test-agent');
        $harness->scriptToolCall('my_tool', resultText: 'raw-tool-output-here');
        $harness->scriptFinalResponse('ok');

        $harness->run();

        $calls = $harness->scriptedAI()->calls();
        $this->assertGreaterThanOrEqual(2, count($calls));
        $this->assertStringContainsString('Tool result for my_tool', $calls[1]['prompt']);
        $this->assertStringContainsString('raw-tool-output-here', $calls[1]['prompt']);
        $this->assertStringContainsString('source_type: agent_tool_result', $calls[1]['prompt']);
    }
}
