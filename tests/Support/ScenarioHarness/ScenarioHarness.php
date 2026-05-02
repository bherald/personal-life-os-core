<?php

declare(strict_types=1);

namespace Tests\Support\ScenarioHarness;

use App\Services\AgentLoopService;
use App\Services\AgentToolRegistryService;
use App\Services\AIService;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * Scripted integration harness for PLOS agent loops.
 *
 * Wires a {@see ScriptedAIService} into the container in place of the
 * real AIService and replays a scripted sequence of LLM turns against
 * a mini agent loop. Each turn is either:
 *
 *   - a tool call (scripted as a JSON block + a stubbed tool result)
 *   - a final text response (ends the loop)
 *
 * The mini loop reuses the real {@see AgentToolRegistryService::parseToolCall}
 * and {@see AgentLoopService::buildToolResultMessage} so harness fidelity
 * tracks production parsing/truncation/precompaction behavior. Tool
 * execution itself is NOT routed through the registry — tests supply a
 * callable that returns the stubbed result text. This keeps scenarios
 * hermetic (no DB rows required in agent_tool_registry).
 *
 * Framework B5 — scenario harness infrastructure (2026-04-22).
 *
 * Typical usage:
 *
 *     $h = new ScenarioHarness($this->app, agentId: 'test-agent');
 *     $h->scriptToolCall('log_scan_files', [], resultText: '{"files":[]}');
 *     $h->scriptFinalResponse('No log activity.');
 *     $h->run('Check logs');
 *     $h->assertToolCalled('log_scan_files');
 *     $h->assertFinalResponseContains('No log activity.');
 */
class ScenarioHarness
{
    private Application $app;

    private string $agentId;

    private ScriptedAIService $scriptedAI;

    /**
     * Tool definitions available to the mini loop, keyed by tool name.
     * Each entry: ['result' => callable(array $params): string, 'phase' => ?string].
     *
     * @var array<string, array{result: callable, phase: ?string}>
     */
    private array $tools = [];

    /**
     * Scripted turn plan. Each entry is either:
     *   ['kind' => 'tool', 'tool' => string, 'params' => array, 'result' => string]
     *   ['kind' => 'final', 'text' => string]
     *
     * @var array<int, array>
     */
    private array $plan = [];

    /**
     * Execution trace, populated by run().
     *
     * @var array<int, array>
     */
    private array $toolCallsMade = [];

    private string $finalResponse = '';

    /**
     * Phases touched by any executed tool, in first-seen order.
     *
     * @var array<int, string>
     */
    private array $phasesReached = [];

    private int $maxIterations;

    private bool $executed = false;

    public function __construct(Application $app, string $agentId = 'scenario-test-agent', int $maxIterations = 10)
    {
        $this->app = $app;
        $this->agentId = $agentId;
        $this->maxIterations = $maxIterations;
        $this->scriptedAI = new ScriptedAIService;

        // Install the scripted AI service as the bound singleton. Every
        // code path that resolves AIService via the container — including
        // AgentLoopService::getAIService() — now sees the stub.
        $this->app->instance(AIService::class, $this->scriptedAI);
    }

    /**
     * Register a tool the harness knows how to "execute". The callable
     * is invoked with the params block from the LLM tool-call JSON and
     * must return the result text the loop will feed back.
     *
     * @param  callable(array): string|null  $resultCallable
     */
    public function registerTool(string $name, ?callable $resultCallable = null, ?string $phase = null): self
    {
        $this->tools[$name] = [
            'result' => $resultCallable ?? fn (array $params) => 'OK',
            'phase' => $phase,
        ];

        return $this;
    }

    /**
     * Script one tool-call turn. The harness will:
     *   1. Serve an LLM response shaped as a ```json {"tool": ..., "params": ...}``` block
     *   2. Parse it with the real AgentToolRegistryService::parseToolCall
     *   3. Confirm the parsed tool matches the scripted tool (assertion fires otherwise)
     *   4. Produce $resultText as the tool result and feed it back via buildToolResultMessage
     *
     * Auto-registers the tool with a static result if it is not already registered.
     */
    public function scriptToolCall(string $toolName, array $params = [], string $resultText = 'OK', ?string $phase = null): self
    {
        if (! isset($this->tools[$toolName])) {
            $this->registerTool($toolName, fn (array $p) => $resultText, $phase);
        } elseif ($phase !== null && $this->tools[$toolName]['phase'] === null) {
            $this->tools[$toolName]['phase'] = $phase;
        }

        $this->plan[] = [
            'kind' => 'tool',
            'tool' => $toolName,
            'params' => $params,
            'result' => $resultText,
        ];

        // Queue a matching LLM response onto the scripted AI
        $jsonBlock = "```json\n".json_encode(['tool' => $toolName, 'params' => (object) $params], JSON_UNESCAPED_SLASHES)."\n```";
        $this->scriptedAI->pushTurn($jsonBlock);

        return $this;
    }

    /**
     * Script a final (non-tool) LLM response. This ends the loop.
     */
    public function scriptFinalResponse(string $text): self
    {
        $this->plan[] = ['kind' => 'final', 'text' => $text];
        $this->scriptedAI->pushTurn($text);

        return $this;
    }

    /**
     * Run the mini agent loop against the scripted plan.
     *
     * Returns the execution summary (tool calls made, final response).
     *
     * @return array{tool_calls: array<int, array>, final_response: string, iterations: int}
     */
    public function run(string $task = 'scenario-test-task'): array
    {
        if ($this->executed) {
            throw new RuntimeException('ScenarioHarness::run() already executed; construct a fresh harness per scenario.');
        }
        $this->executed = true;

        $registry = $this->app->make(AgentToolRegistryService::class);
        $loop = $this->app->make(AgentLoopService::class);

        $iterations = 0;
        $messages = [
            ['role' => 'user', 'content' => $task],
        ];

        while ($iterations < $this->maxIterations) {
            $iterations++;

            // Ask the scripted LLM for its next turn. The prompt content
            // does not matter for scripted playback, but we pass a
            // formatted conversation so call traces are readable in
            // failure messages.
            $response = $this->scriptedAI->process($this->formatPrompt($messages), ['agent_id' => $this->agentId]);
            $content = $response['content'];

            $parsed = $registry->parseToolCall($content);

            if (! $parsed['has_tool_call']) {
                // Final answer — loop terminates
                $this->finalResponse = $content;
                break;
            }

            $toolName = $parsed['tool'];
            $toolParams = $parsed['params'] ?? [];

            if (! isset($this->tools[$toolName])) {
                throw new RuntimeException(sprintf(
                    'ScenarioHarness: LLM requested tool "%s" but no handler is registered. '
                    .'Call $harness->registerTool("%s", ...) or use scriptToolCall() instead.',
                    $toolName,
                    $toolName
                ));
            }

            $resultCallable = $this->tools[$toolName]['result'];
            $resultText = (string) $resultCallable($toolParams);

            // Re-use the REAL buildToolResultMessage so any compaction /
            // truncation pipeline stays wired the way production runs it.
            $toolMessage = $loop->buildToolResultMessage($toolName, $resultText);

            $phase = $this->tools[$toolName]['phase'];
            if ($phase !== null && ! in_array($phase, $this->phasesReached, true)) {
                $this->phasesReached[] = $phase;
            }

            $this->toolCallsMade[] = [
                'tool' => $toolName,
                'params' => $toolParams,
                'result' => $resultText,
                'iteration' => $iterations,
                'phase' => $phase,
            ];

            $messages[] = ['role' => 'assistant', 'content' => $content];
            $messages[] = ['role' => 'user', 'content' => $toolMessage];
        }

        if ($this->scriptedAI->turnsRemaining() > 0) {
            throw new RuntimeException(sprintf(
                'ScenarioHarness: loop exited with %d scripted turn(s) still queued. '
                .'Either the scenario over-scripted or the loop terminated early.',
                $this->scriptedAI->turnsRemaining()
            ));
        }

        return [
            'tool_calls' => $this->toolCallsMade,
            'final_response' => $this->finalResponse,
            'iterations' => $iterations,
        ];
    }

    // ─── Assertions ─────────────────────────────────────────────────

    public function assertToolCalled(string $name, int $times = 1): self
    {
        $actual = 0;
        foreach ($this->toolCallsMade as $call) {
            if ($call['tool'] === $name) {
                $actual++;
            }
        }
        Assert::assertSame($times, $actual, sprintf(
            'Expected tool "%s" to be called %d time(s); got %d. Calls made: %s',
            $name,
            $times,
            $actual,
            implode(', ', array_column($this->toolCallsMade, 'tool')) ?: '(none)'
        ));

        return $this;
    }

    public function assertFinalResponseContains(string $substring): self
    {
        Assert::assertStringContainsString(
            $substring,
            $this->finalResponse,
            sprintf('Expected final response to contain "%s"; got: %s', $substring, $this->finalResponse ?: '(empty)')
        );

        return $this;
    }

    public function assertPhaseReached(string $phase): self
    {
        Assert::assertContains(
            $phase,
            $this->phasesReached,
            sprintf(
                'Expected phase "%s" to be reached; phases actually touched: %s',
                $phase,
                implode(', ', $this->phasesReached) ?: '(none)'
            )
        );

        return $this;
    }

    public function assertToolCallCount(int $expected): self
    {
        Assert::assertCount($expected, $this->toolCallsMade, sprintf(
            'Expected %d tool call(s); got %d.',
            $expected,
            count($this->toolCallsMade)
        ));

        return $this;
    }

    // ─── Introspection ──────────────────────────────────────────────

    /** @return array<int, array> */
    public function toolCalls(): array
    {
        return $this->toolCallsMade;
    }

    public function finalResponse(): string
    {
        return $this->finalResponse;
    }

    /** @return array<int, string> */
    public function phasesReached(): array
    {
        return $this->phasesReached;
    }

    public function scriptedAI(): ScriptedAIService
    {
        return $this->scriptedAI;
    }

    private function formatPrompt(array $messages): string
    {
        $out = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] === 'assistant' ? 'Assistant' : 'User';
            $out[] = "{$role}: {$msg['content']}";
        }

        return implode("\n\n", $out);
    }
}
