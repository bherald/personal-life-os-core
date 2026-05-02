<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class WorkflowDryRunService
{
    public function dryRun(int $workflowId, ?array $inputData = null): array
    {
        $workflow = DB::selectOne("SELECT * FROM workflows WHERE id = ?", [$workflowId]);
        if (!$workflow) {
            return ['success' => false, 'error' => 'Workflow not found'];
        }

        $nodes = DB::select(
            "SELECT * FROM workflow_nodes WHERE workflow_id = ? ORDER BY node_order ASC",
            [$workflowId]
        );

        $results = [
            'success' => true,
            'workflow' => $workflow->name,
            'node_count' => count($nodes),
            'nodes' => [],
            'warnings' => [],
        ];

        $currentInput = $inputData ?? [];

        foreach ($nodes as $node) {
            $nodeResult = $this->simulateNode($node, $currentInput);
            $results['nodes'][] = $nodeResult;

            if (!empty($nodeResult['warnings'])) {
                $results['warnings'] = array_merge($results['warnings'], $nodeResult['warnings']);
            }

            // Pass output as input to next node
            $currentInput = $nodeResult['expected_output'] ?? $currentInput;
        }

        return $results;
    }

    public function simulateNode(object $nodeConfig, array $inputData): array
    {
        $config = json_decode($nodeConfig->node_config ?? '{}', true) ?? [];
        $nodeType = $nodeConfig->node_type ?? 'unknown';
        $warnings = [];

        // Validate required fields based on node type
        $expectedOutput = [];
        switch ($nodeType) {
            case 'AI':
                if (empty($config['prompt']) && empty($config['system'])) {
                    $warnings[] = "AI node missing prompt configuration";
                }
                $expectedOutput = ['content' => '[AI response placeholder]', 'success' => true];
                break;

            case 'Pushover':
                if (empty($config['title']) && empty($inputData['title'])) {
                    $warnings[] = "Pushover node: no title configured or in input data";
                }
                $expectedOutput = ['sent' => true, 'notification_id' => 'dry_run'];
                break;

            case 'Conditional':
                if (empty($config['conditions'])) {
                    $warnings[] = "Conditional node has no conditions defined";
                }
                $expectedOutput = ['condition_met' => true, 'branch' => 'true'];
                break;

            case 'ConditionalBranch':
                $branches = $config['branches'] ?? [];
                if (empty($branches)) {
                    $warnings[] = "ConditionalBranch node has no branches defined";
                } else {
                    $service = new \App\Services\ConditionalBranchService();
                    $validation = $service->validateBranches($branches);
                    if (!$validation['valid']) {
                        foreach ($validation['errors'] as $err) {
                            $warnings[] = "ConditionalBranch: {$err}";
                        }
                    }
                }
                $expectedOutput = ['branch' => $branches[0]['name'] ?? 'default', 'original_data' => $inputData];
                break;

            case 'RSS':
                if (empty($config['url'])) {
                    $warnings[] = "RSS node missing feed URL";
                }
                $expectedOutput = ['items' => [], 'feed_title' => '[RSS feed]'];
                break;

            case 'ContentExtraction':
                $expectedOutput = ['content' => '[Extracted content]', 'content_type' => 'text'];
                break;

            case 'JoplinWrite':
            case 'JoplinSync':
                $expectedOutput = ['note_id' => 'dry_run', 'success' => true];
                break;

            case 'Weather':
                $expectedOutput = ['temperature' => 72, 'conditions' => 'Sunny', 'humidity' => 45];
                break;

            default:
                $expectedOutput = ['output' => '[simulated]'];
        }

        return [
            'node_type' => $nodeType,
            'node_order' => $nodeConfig->node_order ?? 0,
            'config_valid' => empty($warnings),
            'expected_output' => $expectedOutput,
            'warnings' => $warnings,
            'dry_run' => true,
        ];
    }

    public function validateWorkflow(int $workflowId): array
    {
        $workflow = DB::selectOne("SELECT * FROM workflows WHERE id = ?", [$workflowId]);
        if (!$workflow) {
            return ['valid' => false, 'errors' => ['Workflow not found']];
        }

        $nodes = DB::select(
            "SELECT * FROM workflow_nodes WHERE workflow_id = ? ORDER BY node_order ASC",
            [$workflowId]
        );

        $errors = [];
        $warnings = [];

        if (empty($nodes)) {
            $errors[] = 'Workflow has no nodes';
        }

        // Check for duplicate node orders
        $nodeOrders = array_map(fn($n) => $n->node_order, $nodes);
        if (count($nodeOrders) !== count(array_unique($nodeOrders))) {
            $warnings[] = 'Duplicate node orders detected';
        }

        // Check connections (if stored)
        $connections = [];
        try {
            $connections = DB::select(
                "SELECT * FROM workflow_connections WHERE workflow_id = ?",
                [$workflowId]
            );
        } catch (\Exception $e) {
            // workflow_connections table may not exist
        }

        if (count($nodes) > 1 && empty($connections)) {
            $warnings[] = 'Multi-node workflow with no connections defined';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'node_count' => count($nodes),
            'connection_count' => count($connections),
        ];
    }
}
