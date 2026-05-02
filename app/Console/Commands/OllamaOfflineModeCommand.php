<?php

namespace App\Console\Commands;

use App\Services\SystemConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Toggle the PLOS offline kill switch.
 *
 * INTERNET offline, not LAN offline. When enabled, external LLM providers
 * are blocked:
 *   - Claude CLI (--print)
 *   - SambaNova, Cerebras, Groq, OpenRouter, Gemini, Mistral, DeepInfra
 *
 * Everything on the local network stays online:
 *   - Nextcloud at /mnt/llm-storage (local storage + WebDAV)
 *   - MySQL / PostgreSQL / Redis
 *   - Local Ollama instances configured through OLLAMA_API_URL / OLLAMA_SECONDARY_URLS
 *   - LAN MCP servers (Thunderbird, SearXNG, etc.)
 *   - Web UI, API, queue workers, scheduled jobs
 *
 * PLOS remains fully functional for review, planning, local file work, and
 * local LLM inference. Only the cloud escape hatch closes.
 *
 * Fail-closed: AIService and AIRouter treat any lookup error as "enabled"
 * so personal data never leaks through a transient config fault.
 *
 * Values in system_configs (`routing.offline_mode`):
 *   - 'disabled' → cloud fallback active (default)
 *   - 'enabled'  → block all external + Claude CLI
 *
 * Usage:
 *   php artisan ollama:offline-mode status
 *   php artisan ollama:offline-mode enable
 *   php artisan ollama:offline-mode disable
 */
class OllamaOfflineModeCommand extends Command
{
    protected $signature = 'ollama:offline-mode {action : enable|disable|status}';

    protected $description = 'Toggle the PLOS offline kill switch (blocks cloud LLM providers when enabled).';

    private const KEY = 'routing.offline_mode';

    public function handle(SystemConfigService $config): int
    {
        $action = strtolower(trim((string) $this->argument('action')));

        return match ($action) {
            'status' => $this->showStatus($config),
            'enable' => $this->setMode($config, 'enabled'),
            'disable' => $this->setMode($config, 'disabled'),
            default => $this->failWith("Unknown action '{$action}'. Use: enable | disable | status"),
        };
    }

    private function showStatus(SystemConfigService $config): int
    {
        $value = $config->get(self::KEY, 'disabled');
        $value = is_string($value) ? strtolower(trim($value)) : 'enabled';

        if ($value === 'disabled') {
            $this->line('offline_mode: <fg=green>disabled</> — cloud fallback active (Claude CLI + external APIs allowed)');
        } else {
            $this->line('offline_mode: <fg=yellow>enabled</> — cloud fallback BLOCKED, local Ollama only');
        }

        return self::SUCCESS;
    }

    private function setMode(SystemConfigService $config, string $target): int
    {
        $before = $config->get(self::KEY, 'disabled');
        $before = is_string($before) ? strtolower(trim($before)) : 'enabled';

        if ($before === $target) {
            $this->line("offline_mode already {$target} — no change");

            return self::SUCCESS;
        }

        $config->set(self::KEY, $target, 'string');

        Log::info('AIService: offline_mode changed', [
            'from' => $before,
            'to' => $target,
            'source' => 'ollama:offline-mode',
        ]);

        $verb = $target === 'enabled' ? 'ENABLED' : 'DISABLED';
        $this->line("offline_mode {$verb} (was: {$before})");

        if ($target === 'enabled') {
            $this->warn('Cloud LLM providers (Claude CLI, SambaNova, Cerebras, Groq, OpenRouter, Gemini, Mistral) are now blocked.');
            $this->warn('Local network stays online: Nextcloud, DB, Redis, configured Ollama hosts, MCP, queue workers.');
            $this->warn('Only the cloud escape hatch is closed. Verify both Ollama hosts are healthy before relying on offline mode.');
        }

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
