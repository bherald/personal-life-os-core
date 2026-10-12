<?php

namespace App\Console\Commands;

use App\Services\AIService;
use Illuminate\Console\Command;

/**
 * TestAIServices Command
 * E01 Phase 3: Enhanced with AIService health stats and resilience testing
 */
class TestAIServices extends Command
{
    protected $signature = 'ai:test {--instance= : Exact llm_instances.instance_id; omitted uses table routing}';

    protected $description = 'Test AI service connectivity and performance';

    public function handle(): int
    {
        $this->info('Testing AI Services...');
        $this->newLine();

        $aiService = app(AIService::class);
        $instanceId = trim((string) $this->option('instance'));

        $health = $aiService->getHealthStats();

        $this->info('Table-admitted AI provider health:');
        $healthRows = [];

        foreach ($health['providers'] ?? [] as $id => $instance) {
            $circuitState = $instance['circuit_state'] ?? 'unknown';
            $stateIcon = match ($circuitState) {
                'closed' => '✓ closed',
                'open' => '✗ OPEN',
                'half-open' => '~ half-open',
                default => $circuitState,
            };
            $healthRows[] = [
                $instance['name'] ?? $id,
                $instance['adapter'] ?? 'unspecified',
                $instance['transport'] ?? 'unspecified',
                $stateIcon,
                $instance['success_rate'] ?? 'N/A',
                $instance['available'] ? 'Yes' : 'No',
            ];
        }

        $this->table(['Provider', 'Adapter', 'Transport', 'Circuit', 'Success Rate', 'Available'], $healthRows);
        $this->newLine();

        $this->testGateway($aiService, $instanceId);

        return Command::SUCCESS;
    }

    private function testGateway(AIService $aiService, string $instanceId): void
    {
        $target = $instanceId !== '' ? $instanceId : 'table-selected provider';
        $this->info("Testing AIService provider gateway ({$target})...");

        try {
            $start = microtime(true);
            $config = ['max_tokens' => 10];
            if ($instanceId !== '') {
                $config['required_instance_id'] = $instanceId;
            }
            $result = $aiService->process('Say "OK"', $config);
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
}
