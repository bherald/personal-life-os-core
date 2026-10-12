<?php

namespace App\Console\Commands;

use App\Services\OllamaProgressClient;
use App\Services\OpenAICompatibleProgressClient;
use Illuminate\Console\Command;

class OpsLlmActiveCommand extends Command
{
    protected $signature = 'ops:llm-active {--json : Emit machine-readable JSON} {--compact : Compact JSON; requires --json}';

    protected $description = 'Show content-free live progress for monitored local LLM generations';

    public function handle(OllamaProgressClient $progress, OpenAICompatibleProgressClient $compatibleProgress): int
    {
        if ($this->option('compact') && ! $this->option('json')) {
            $this->error('Use --compact with --json.');

            return self::INVALID;
        }

        $snapshot = $progress->progressSnapshot();
        $compatibleSnapshot = $compatibleProgress->progressSnapshot();
        $active = array_merge($snapshot['active_generations'], $compatibleSnapshot['active_generations']);
        $monitorAvailable = $snapshot['available'] && $compatibleSnapshot['available'];
        $payload = [
            'status' => ! $monitorAvailable ? 'unavailable' : ($active === [] ? 'idle' : 'processing'),
            'monitor_store_available' => $monitorAvailable,
            'active_count' => count($active),
            'active_generations' => $active,
            'content_included' => false,
            'generated_at' => now()->toIso8601String(),
        ];

        if ($this->option('json')) {
            $flags = JSON_UNESCAPED_SLASHES | ($this->option('compact') ? 0 : JSON_PRETTY_PRINT);
            $this->line((string) json_encode($payload, $flags));

            return self::SUCCESS;
        }

        if (! $monitorAvailable) {
            $this->warn('A local LLM progress heartbeat store is unavailable; generation and fallback are unaffected.');

            return self::SUCCESS;
        }

        $this->line($active === [] ? 'No monitored local LLM generations are active.' : count($active).' monitored local LLM generation(s) active.');
        foreach ($active as $entry) {
            $this->line(sprintf(
                '%s %s@%s elapsed=%ss bytes=%s queue_wait=%sms idle_budget=%ss',
                $entry['state'] ?? 'unknown',
                $entry['model'] ?? 'unknown',
                $entry['host'] ?? 'unknown',
                $entry['elapsed_seconds'] ?? 0,
                $entry['downloaded_bytes'] ?? 0,
                $entry['queue_wait_ms'] ?? 0,
                $entry['idle_timeout_seconds'] ?? 0
            ));
        }

        return self::SUCCESS;
    }
}
