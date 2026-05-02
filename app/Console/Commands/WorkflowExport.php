<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Exception;

class WorkflowExport extends Command
{
    protected $signature = 'workflow:export {name : The workflow name} {--path= : Custom export path}';
    protected $description = 'Export a workflow to JSON file';

    public function handle()
    {
        $workflowName = $this->argument('name');
        $customPath = $this->option('path');

        try {
            // Get workflow using raw SQL
            $sql = "SELECT * FROM workflows WHERE name = ? LIMIT 1";
            $workflows = DB::select($sql, [$workflowName]);
            $workflow = $workflows[0] ?? null;

            if (!$workflow) {
                $this->error("Workflow not found: {$workflowName}");
                return 1;
            }

            // Get retry config using raw SQL
            $sql = "SELECT * FROM retry_configs WHERE workflow_id = ? LIMIT 1";
            $retryConfigs = DB::select($sql, [$workflow->id]);
            $retryConfig = $retryConfigs[0] ?? null;
            $backoffIntervals = [];

            if ($retryConfig) {
                $sql = "SELECT * FROM retry_backoff_intervals WHERE retry_config_id = ? ORDER BY attempt_number";
                $backoffIntervals = DB::select($sql, [$retryConfig->id]);
            }

            // Get workflow defaults using raw SQL
            $sql = "SELECT * FROM workflow_defaults WHERE workflow_id = ?";
            $defaults = DB::select($sql, [$workflow->id]);

            // Get nodes using raw SQL
            $sql = "SELECT * FROM workflow_nodes WHERE workflow_id = ? ORDER BY node_order";
            $nodes = DB::select($sql, [$workflow->id]);

            $nodesArray = [];
            foreach ($nodes as $node) {
                $sql = "SELECT * FROM workflow_node_configs WHERE workflow_node_id = ?";
                $configs = DB::select($sql, [$node->id]);

                $nodesArray[] = [
                    'node_type' => $node->node_type,
                    'node_order' => $node->node_order,
                    'configs' => $configs
                ];
            }

            // Build export data
            $exportData = [
                'workflow' => [
                    'name' => $workflow->name,
                    'description' => $workflow->description,
                    'schedule' => $workflow->schedule,
                    'active' => $workflow->active,
                    'error_handling' => $workflow->error_handling
                ],
                'retry_config' => $retryConfig ? [
                    'max_attempts' => $retryConfig->max_attempts,
                    'notify_on_failure' => $retryConfig->notify_on_failure,
                    'backoff_intervals' => $backoffIntervals
                ] : null,
                'defaults' => $defaults,
                'nodes' => $nodesArray,
                'exported_at' => now()->toIso8601String(),
                'version' => '1.0'
            ];

            // Determine path
            $filename = "{$workflowName}_" . now()->format('Y-m-d_His') . ".json";

            if ($customPath) {
                $filePath = $customPath;
            } else {
                // Use storage/workflows directory
                $directory = storage_path('workflows');
                if (!is_dir($directory)) {
                    mkdir($directory, 0755, true);
                }
                $filePath = $directory . '/' . $filename;
            }

            // Write file
            file_put_contents($filePath, json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->info("Workflow exported successfully!");
            $this->line("File: {$filePath}");
            $this->line("Nodes exported: " . count($nodesArray));

            return 0;

        } catch (Exception $e) {
            $this->error("Export failed: " . $e->getMessage());
            return 1;
        }
    }
}
