<?php

namespace App\Console\Commands;

use App\Services\AIService;
use Illuminate\Console\Command;

class McpDecomposeCommand extends Command
{
    protected $signature = 'ops:mcp-decompose
                            {--payload= : Base64-encoded JSON payload with prompt/config}';

    protected $description = 'Run the MCP decompose AI flow without using artisan tinker';

    public function handle(): int
    {
        $payload = $this->option('payload');
        if (!is_string($payload) || trim($payload) === '') {
            $this->error('Missing --payload');
            return self::FAILURE;
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            $this->error('Invalid base64 payload');
            return self::FAILURE;
        }

        $data = json_decode($decoded, true);
        if (!is_array($data) || !is_string($data['prompt'] ?? null) || $data['prompt'] === '') {
            $this->error('Invalid payload JSON');
            return self::FAILURE;
        }

        /** @var AIService $ai */
        $ai = app(AIService::class);
        $result = $ai->process($data['prompt'], [
            'model_role' => $data['model_role'] ?? 'standard',
            'max_tokens' => (int) ($data['max_tokens'] ?? 2000),
            'temperature' => (float) ($data['temperature'] ?? 0.3),
            'task_type' => 'mcp_decompose',
        ]);

        $this->line(json_encode([
            'success' => $result['success'] ?? false,
            'response' => $result['response'] ?? ($result['error'] ?? 'No response'),
            'provider' => $result['provider'] ?? 'unknown',
            'model' => $result['model'] ?? 'unknown',
            'from_cache' => $result['from_cache'] ?? false,
            'rlm_auto_decompose' => $result['rlm_auto_decompose'] ?? false,
            'rlm_chunks' => $result['rlm_chunks'] ?? 0,
            'rlm_sub_tokens_avg' => $result['rlm_sub_tokens_avg'] ?? 0,
            'duration_ms' => $result['duration_ms'] ?? 0,
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
