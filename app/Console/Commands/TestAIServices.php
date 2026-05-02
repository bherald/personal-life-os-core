<?php

namespace App\Console\Commands;

use App\Engine\AIRouter;
use App\Services\AIService;
use Illuminate\Console\Command;

/**
 * TestAIServices Command
 * E01 Phase 3: Enhanced with AIService health stats and resilience testing
 */
class TestAIServices extends Command
{
    protected $signature = 'ai:test {--service=auto : Which service to test (auto|local|claude)} {--resilient : Test via AIService with circuit breaker}';

    protected $description = 'Test AI service connectivity and performance';

    public function handle(): int
    {
        $this->info('Testing AI Services...');
        $this->newLine();

        $aiRouter = new AIRouter;
        $aiService = app(AIService::class);
        $service = $this->option('service');
        $useResilient = $this->option('resilient');

        // Check status
        $status = $aiRouter->getStatus();
        $health = $aiService->getHealthStats();

        $this->info('Current Configuration:');
        $this->table(
            ['Service', 'Available', 'Details'],
            [
                [
                    'Ollama',
                    ($status['ollama']['available'] ?? false) ? '✓ Yes' : '✗ No',
                    ($status['ollama']['url'] ?? 'N/A').' ('.($status['ollama']['model'] ?? 'N/A').')',
                ],
                [
                    'Claude Code CLI',
                    ($status['claude_cli']['available'] ?? $status['claude']['cli_available'] ?? false) ? '✓ Yes' : '✗ No',
                    ($status['claude_cli']['path'] ?? $status['claude']['cli_path'] ?? 'N/A').' (optional, operator-configured)',
                ],
            ]
        );

        $this->newLine();
        $this->info('Mode: '.$status['mode']);
        $this->newLine();

        // E01 Phase 3: Display AIService health stats
        $this->info('AIService Health (Circuit Breaker):');
        $healthRows = [];

        // Ollama instances
        foreach ($health['ollama_instances'] ?? [] as $id => $instance) {
            $circuitState = $instance['circuit_state'] ?? 'unknown';
            $stateIcon = match ($circuitState) {
                'closed' => '✓ closed',
                'open' => '✗ OPEN',
                'half-open' => '~ half-open',
                default => $circuitState,
            };
            $healthRows[] = [
                $instance['name'] ?? $id,
                $stateIcon,
                $instance['success_rate'] ?? 'N/A',
                ($instance['vram_used_gb'] ?? 0).' GB',
                $instance['available'] ? 'Yes' : 'No',
            ];
        }

        // Claude CLI
        $claudeHealth = $health['claude_cli'] ?? [];
        $claudeCircuit = $claudeHealth['circuit_state'] ?? 'unknown';
        $healthRows[] = [
            'Claude CLI',
            match ($claudeCircuit) {
                'closed' => '✓ closed',
                'open' => '✗ OPEN',
                'half-open' => '~ half-open',
                default => $claudeCircuit,
            },
            'N/A',
            'N/A',
            ($claudeHealth['available'] ?? false) ? 'Yes' : 'No',
        ];

        $this->table(['Provider', 'Circuit', 'Success Rate', 'VRAM', 'Available'], $healthRows);
        $this->newLine();

        // Test services
        if ($service === 'auto' || $service === 'local') {
            if ($useResilient) {
                $this->testResilient($aiService);
            } else {
                $this->testOllama($aiRouter, $status);
            }
        }

        if ($service === 'auto' || $service === 'claude') {
            $this->testClaude($aiRouter, $status);
        }

        return Command::SUCCESS;
    }

    private function testOllama(AIRouter $aiRouter, array $status): void
    {
        $this->info('Testing Ollama (direct)...');

        if (! $status['ollama']['available']) {
            $this->warn('Ollama is not available');
            $this->newLine();

            return;
        }

        try {
            $start = microtime(true);
            $response = $aiRouter->processWithAI('Say "OK"', ['ai_mode' => 'local', 'max_tokens' => 10]);
            $duration = round((microtime(true) - $start) * 1000);

            $this->info("✓ Ollama is working ({$duration}ms)");
            $this->line('  Response: '.substr($response, 0, 100));
            $this->newLine();
        } catch (\Exception $e) {
            $this->error('✗ Ollama test failed: '.$e->getMessage());
            $this->newLine();
        }
    }

    private function testResilient(AIService $aiService): void
    {
        $this->info('Testing AIService (with circuit breaker + retry)...');

        try {
            $start = microtime(true);
            $result = $aiService->process('Say "OK"', ['max_tokens' => 10]);
            $duration = round((microtime(true) - $start) * 1000);

            if ($result['success']) {
                $this->info("✓ AIService is working ({$duration}ms)");
                $this->line('  Provider: '.($result['provider'] ?? 'unknown'));
                $this->line('  Response: '.substr($result['response'] ?? '', 0, 100));
            } else {
                $this->error('✗ AIService failed: '.($result['error'] ?? 'Unknown'));
                if (! empty($result['attempts'])) {
                    $this->line('  Attempts: '.json_encode($result['attempts']));
                }
            }
            $this->newLine();
        } catch (\Exception $e) {
            $this->error('✗ AIService test failed: '.$e->getMessage());
            $this->newLine();
        }
    }

    private function testClaude(AIRouter $aiRouter, array $status): void
    {
        $this->info('Testing Claude Code CLI...');

        $cliAvailable = $status['claude_cli']['available'] ?? $status['claude']['cli_available'] ?? false;
        if (! $cliAvailable) {
            $this->warn('Claude Code CLI not available (install from https://claude.com/claude-code)');
            $this->newLine();

            return;
        }

        try {
            $start = microtime(true);
            $response = $aiRouter->processWithAI('Say "OK"', ['ai_mode' => 'claude', 'max_tokens' => 10]);
            $duration = round((microtime(true) - $start) * 1000);

            $this->info("✓ Claude Code CLI is working ({$duration}ms)");
            $this->line('  Response: '.substr($response, 0, 100));
            $this->newLine();
        } catch (\Exception $e) {
            $this->error('✗ Claude Code CLI test failed: '.$e->getMessage());
            $this->newLine();
        }
    }
}
