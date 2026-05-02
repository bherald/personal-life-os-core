<?php

declare(strict_types=1);

namespace Tests\Support\ScenarioHarness;

use App\Services\AIService;
use RuntimeException;

/**
 * Test-only AIService that replays a scripted sequence of LLM responses.
 *
 * Replaces network-bound LLM calls with an in-memory queue. Each call to
 * `process()` pops the next scripted response off the queue and returns
 * it in the shape AgentLoopService expects:
 *
 *   ['content' => '...', 'response' => '...', 'tokens' => int, 'model' => string]
 *
 * When the queue is empty, `process()` throws — this is deliberate so
 * tests see "ran out of scripted turns" as an explicit failure rather
 * than silently hanging or returning empty strings that get mistaken for
 * a real final response.
 *
 * Framework B5 — scenario harness infrastructure.
 */
class ScriptedAIService extends AIService
{
    /**
     * @var array<int, array{content: string, tokens?: int, model?: string}>
     */
    private array $scriptedTurns = [];

    /**
     * Index of the next turn to serve. Also the count of turns consumed.
     */
    private int $cursor = 0;

    /**
     * Full record of prompts passed to process(), one entry per call.
     *
     * @var array<int, array{prompt: string, config: array}>
     */
    private array $calls = [];

    /**
     * Construct WITHOUT invoking the real AIService constructor — the
     * real constructor pokes at DB-backed pool managers, circuit
     * breakers, and config. We want a pure in-memory stub.
     */
    public function __construct()
    {
        // Intentionally do NOT call parent::__construct().
        // The subset of AIService we override (process, setAgentModelRole,
        // isOfflineMode) does not require the constructor-wired deps.
    }

    /**
     * Push a scripted turn onto the queue. Turns are served FIFO.
     */
    public function pushTurn(string $content, int $tokens = 10, string $model = 'scripted-test-model'): void
    {
        $this->scriptedTurns[] = [
            'content' => $content,
            'tokens' => $tokens,
            'model' => $model,
        ];
    }

    /**
     * Replace the scripted response from AIService::process().
     *
     * Returns the shape AgentLoopService's tool loop expects:
     * - content / response: the raw text (tool-call JSON or final answer)
     * - tokens: consumed in budget accounting
     * - model: recorded in episodes
     */
    public function process(string $prompt, array $config = []): array
    {
        $this->calls[] = ['prompt' => $prompt, 'config' => $config];

        if ($this->cursor >= count($this->scriptedTurns)) {
            throw new RuntimeException(sprintf(
                'ScriptedAIService: ran out of scripted turns (requested %d, have %d). '
                .'The agent made more LLM calls than the scenario scripted — script more turns '
                .'or assert that the loop should have terminated earlier.',
                $this->cursor + 1,
                count($this->scriptedTurns)
            ));
        }

        $turn = $this->scriptedTurns[$this->cursor];
        $this->cursor++;

        return [
            'content' => $turn['content'],
            'response' => $turn['content'],
            'tokens' => $turn['tokens'] ?? 10,
            'model' => $turn['model'] ?? 'scripted-test-model',
            'provider' => 'scripted',
        ];
    }

    /**
     * Offline-mode gates in some code paths query AIService first. The
     * harness forces OFFLINE semantics so tests cannot accidentally
     * trigger network-bound fallbacks.
     *
     * Note: setAgentModelRole is static on the parent and is inherited
     * as-is. The parent's implementation is safe (just writes a static
     * property) and does not require constructor-wired deps.
     */
    public function isOfflineMode(): bool
    {
        return true;
    }

    /**
     * Count of turns the agent has actually consumed from the script.
     */
    public function turnsConsumed(): int
    {
        return $this->cursor;
    }

    /**
     * Count of turns still queued but not yet served.
     */
    public function turnsRemaining(): int
    {
        return count($this->scriptedTurns) - $this->cursor;
    }

    /**
     * Full record of LLM prompts that have been issued against this stub.
     *
     * @return array<int, array{prompt: string, config: array}>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}
