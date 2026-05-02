<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Exception;

class WorkflowImport extends Command
{
    protected $signature = 'workflow:import {file : Path to JSON workflow file} {--overwrite : Overwrite existing workflow}';
    protected $description = 'Import a workflow from JSON file';

    public function handle()
    {
        $filePath = $this->argument('file');
        $overwrite = $this->option('overwrite');

        try {
            // Validate file exists
            if (!file_exists($filePath)) {
                $this->error("File not found: {$filePath}");
                return 1;
            }

            // Read and parse JSON
            $json = file_get_contents($filePath);
            $data = json_decode($json, true);

            if (!$data) {
                $this->error("Invalid JSON file");
                return 1;
            }

            // Validate structure
            if (!isset($data['workflow']) || !isset($data['nodes'])) {
                $this->error("Invalid workflow structure");
                return 1;
            }

            $workflowData = $data['workflow'];
            $workflowName = $workflowData['name'];

            // Check if workflow exists using raw SQL
            $sql = "SELECT * FROM workflows WHERE name = ? LIMIT 1";
            $workflows = DB::select($sql, [$workflowName]);
            $existingWorkflow = $workflows[0] ?? null;

            if ($existingWorkflow && !$overwrite) {
                $this->error("Workflow already exists: {$workflowName}. Use --overwrite to replace.");
                return 1;
            }

            DB::beginTransaction();

            try {
                // Delete existing workflow if overwriting
                if ($existingWorkflow) {
                    $this->info("Deleting existing workflow...");
                    $sql = "DELETE FROM workflows WHERE id = ?";
                    DB::delete($sql, [$existingWorkflow->id]);
                }

                // Create workflow using raw SQL
                $sql = "INSERT INTO workflows (name, description, schedule, active, error_handling, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                DB::insert($sql, [
                    $workflowData['name'],
                    $workflowData['description'] ?? null,
                    $workflowData['schedule'] ?? null,
                    $workflowData['active'] ?? true,
                    $workflowData['error_handling'] ?? 'stop',
                    now(),
                    now()
                ]);
                $workflowId = DB::getPdo()->lastInsertId();

                // Create retry config if exists
                if (!empty($data['retry_config'])) {
                    $retryConfig = $data['retry_config'];
                    $sql = "INSERT INTO retry_configs (workflow_id, max_attempts, notify_on_failure)
                            VALUES (?, ?, ?)";
                    DB::insert($sql, [
                        $workflowId,
                        $retryConfig['max_attempts'],
                        $retryConfig['notify_on_failure'] ?? 'pushover'
                    ]);
                    $retryConfigId = DB::getPdo()->lastInsertId();

                    // Create backoff intervals using raw SQL
                    if (!empty($retryConfig['backoff_intervals'])) {
                        $sql = "INSERT INTO retry_backoff_intervals (retry_config_id, attempt_number, backoff_seconds)
                                VALUES (?, ?, ?)";
                        foreach ($retryConfig['backoff_intervals'] as $interval) {
                            DB::insert($sql, [
                                $retryConfigId,
                                $interval->attempt_number,
                                $interval->backoff_seconds
                            ]);
                        }
                    }
                }

                // Create workflow defaults using raw SQL
                if (!empty($data['defaults'])) {
                    $sql = "INSERT INTO workflow_defaults (workflow_id, config_key, config_value)
                            VALUES (?, ?, ?)";
                    foreach ($data['defaults'] as $default) {
                        DB::insert($sql, [
                            $workflowId,
                            $default->config_key,
                            $default->config_value
                        ]);
                    }
                }

                // Create nodes using raw SQL
                foreach ($data['nodes'] as $nodeData) {
                    $sql = "INSERT INTO workflow_nodes (workflow_id, node_type, node_order, created_at)
                            VALUES (?, ?, ?, ?)";
                    DB::insert($sql, [
                        $workflowId,
                        $nodeData['node_type'],
                        $nodeData['node_order'],
                        now()
                    ]);
                    $nodeId = DB::getPdo()->lastInsertId();

                    // Create node configs using raw SQL
                    if (!empty($nodeData['configs'])) {
                        $sql = "INSERT INTO workflow_node_configs (workflow_node_id, config_key, config_value)
                                VALUES (?, ?, ?)";
                        foreach ($nodeData['configs'] as $config) {
                            DB::insert($sql, [
                                $nodeId,
                                $config->config_key,
                                $config->config_value
                            ]);
                        }
                    }
                }

                DB::commit();

                $this->info("Workflow imported successfully!");
                $this->line("Name: {$workflowName}");
                $this->line("ID: {$workflowId}");
                $this->line("Nodes: " . count($data['nodes']));
                $this->line("Run with: php artisan workflow:run {$workflowName}");

                return 0;

            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            $this->error("Import failed: " . $e->getMessage());
            return 1;
        }
    }
}
