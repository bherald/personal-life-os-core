<?php

namespace Tests\Support;

use App\Services\LLMPoolManagerService;
use App\Support\AI\PoolExecutionReceipt;

/** Explicit synthetic transport seams for tests whose subject is routing or cache identity. */
trait FakesPoolWireCheckpoints
{
    private function fakePoolWireReceipts(LLMPoolManagerService $pool, string $baseUrl): void
    {
        $pool->shouldReceive('executionReceipt')->andReturnUsing(fn ($id, $request, $model) => new PoolExecutionReceipt($model,
            ['ollama_generate' => hash('sha256', rtrim($baseUrl, '/').'/api/generate'),
                'ollama_chat' => hash('sha256', rtrim($baseUrl, '/').'/api/chat'),
                'openai_chat_completions' => hash('sha256', rtrim($baseUrl, '/').'/chat/completions')],
            static function () {}, static function () {}));
    }

    private function completePoolWireFixture(array $config, string $response): string
    {
        $receipt = PoolExecutionReceipt::fromConfig($config);
        $base = $config['instance_url'] ?? $config['ollama_url'];
        $model = $config['instance_model'] ?? $config['ollama_model'];
        $receipt->beforeSend('ollama_generate', rtrim($base, '/').'/api/generate', $model);
        $receipt->ollamaTerminal(['done' => true, 'response' => $response]);

        return $response;
    }
}
