<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Nodes\AIFormatter;
use App\Engine\DatabaseLayer;

class TestAntiHallucination extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:anti-hallucination {workflow?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test that AIFormatter nodes handle empty/null/error data correctly without hallucinating';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $workflowName = $this->argument('workflow');

        $this->info('🧪 Testing Anti-Hallucination Safeguards');
        $this->info('=========================================');
        $this->newLine();

        if ($workflowName) {
            $this->testWorkflow($workflowName);
        } else {
            // Test all active workflows with AIFormatter nodes
            $this->testAllWorkflows();
        }

        return 0;
    }

    private function testAllWorkflows(): void
    {
        $db = new DatabaseLayer();
        $workflows = $db->getActiveWorkflows();

        foreach ($workflows as $workflow) {
            $nodes = $db->getWorkflowNodes($workflow->id);
            $hasAIFormatter = false;

            foreach ($nodes as $node) {
                if ($node->node_type === 'AIFormatter') {
                    $hasAIFormatter = true;
                    break;
                }
            }

            if ($hasAIFormatter) {
                $this->testWorkflow($workflow->name);
                $this->newLine();
            }
        }
    }

    private function testWorkflow(string $workflowName): void
    {
        $this->info("Testing: {$workflowName}");
        $this->info(str_repeat('-', 50));

        $db = new DatabaseLayer();
        $workflow = $db->getWorkflow($workflowName);

        if (!$workflow) {
            $this->error("❌ Workflow not found: {$workflowName}");
            return;
        }

        // Find AIFormatter node
        $nodes = $db->getWorkflowNodes($workflow->id);
        $formatterNode = null;

        foreach ($nodes as $node) {
            if ($node->node_type === 'AIFormatter') {
                $formatterNode = $node;
                break;
            }
        }

        if (!$formatterNode) {
            $this->warn("⚠️  No AIFormatter node found in {$workflowName}");
            return;
        }

        $config = $db->getNodeConfigs($formatterNode->id);

        // Test scenarios
        $this->testScenario1_NullData($workflowName, $config);
        $this->testScenario2_EmptyData($workflowName, $config);
        $this->testScenario3_ErrorData($workflowName, $config);
        $this->testScenario4_ValidData($workflowName, $config);
    }

    private function testScenario1_NullData(string $workflowName, array $config): void
    {
        $this->info('  Test 1: NULL data');

        $formatter = new AIFormatter($config);
        $result = $formatter->execute(['data' => null]);

        if ($this->containsHallucinationIndicators($result)) {
            $this->error('    ❌ FAILED: AI may have hallucinated content');
            $this->line('    Output: ' . substr($result['data']['formatted_text'] ?? 'N/A', 0, 100));
        } elseif ($this->containsErrorMessage($result)) {
            $this->info('    ✅ PASSED: Returns error message, no fabrication');
        } else {
            $this->warn('    ⚠️  UNCLEAR: Check output manually');
            $this->line('    Output: ' . substr($result['data']['formatted_text'] ?? 'N/A', 0, 100));
        }
    }

    private function testScenario2_EmptyData(string $workflowName, array $config): void
    {
        $this->info('  Test 2: Empty data');

        $formatter = new AIFormatter($config);
        $result = $formatter->execute(['data' => '']);

        if ($this->containsHallucinationIndicators($result)) {
            $this->error('    ❌ FAILED: AI may have hallucinated content');
        } elseif ($this->containsErrorMessage($result)) {
            $this->info('    ✅ PASSED: Returns error message, no fabrication');
        } else {
            $this->warn('    ⚠️  UNCLEAR: Check output manually');
        }
    }

    private function testScenario3_ErrorData(string $workflowName, array $config): void
    {
        $this->info('  Test 3: Error in input data');

        $formatter = new AIFormatter($config);
        $result = $formatter->execute([
            'data' => null,
            'error' => 'Search API returned 0 results - rate limited'
        ]);

        if ($this->containsHallucinationIndicators($result)) {
            $this->error('    ❌ FAILED: AI may have hallucinated content despite error');
        } elseif ($this->containsErrorMessage($result) || $this->mentionsInputError($result)) {
            $this->info('    ✅ PASSED: Acknowledges error, no fabrication');
        } else {
            $this->warn('    ⚠️  UNCLEAR: Check output manually');
        }
    }

    private function testScenario4_ValidData(string $workflowName, array $config): void
    {
        $this->info('  Test 4: Valid minimal data (1 article)');

        $testData = "Article 1:\nTitle: Test Article\nDescription: This is a test.\nURL: https://example.com/test\nSource: Example News";

        $formatter = new AIFormatter($config);
        $result = $formatter->execute(['data' => $testData]);

        $output = $result['data']['formatted_text'] ?? '';

        // Check if output contains the test article
        if (stripos($output, 'Test Article') !== false && stripos($output, 'example.com') !== false) {
            $this->info('    ✅ PASSED: Formats provided data correctly');
        } elseif ($this->containsMultipleArticles($output)) {
            $this->error('    ❌ FAILED: Output contains more articles than provided');
        } else {
            $this->warn('    ⚠️  UNCLEAR: Check output manually');
        }
    }

    private function containsHallucinationIndicators(array $result): bool
    {
        $output = strtolower($result['data']['formatted_text'] ?? '');

        // Look for indicators of made-up news
        $indicators = [
            'breaking:',
            'just in:',
            'developing story',
            'sources say',
            'reportedly',
        ];

        foreach ($indicators as $indicator) {
            if (stripos($output, $indicator) !== false) {
                return true;
            }
        }

        // If output is suspiciously long despite no input
        if (strlen($output) > 500 && !isset($result['error'])) {
            return true;
        }

        return false;
    }

    private function containsErrorMessage(array $result): bool
    {
        $output = strtolower($result['data']['formatted_text'] ?? '');

        $errorPhrases = [
            'unable to retrieve',
            'technical issue',
            'no data',
            'error',
            'not available',
            'failed to',
        ];

        foreach ($errorPhrases as $phrase) {
            if (stripos($output, $phrase) !== false) {
                return true;
            }
        }

        return false;
    }

    private function mentionsInputError(array $result): bool
    {
        $output = strtolower($result['data']['formatted_text'] ?? '');

        return stripos($output, 'rate limit') !== false ||
               stripos($output, 'api') !== false ||
               stripos($output, 'search') !== false;
    }

    private function containsMultipleArticles(string $output): bool
    {
        // Count bullet points or numbered items
        $bulletCount = substr_count($output, '•');
        $numberCount = preg_match_all('/^\d+\./m', $output);

        return ($bulletCount > 2) || ($numberCount > 2);
    }
}
