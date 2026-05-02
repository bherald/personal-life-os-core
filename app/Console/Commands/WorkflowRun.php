<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Engine\WorkflowEngine;
use Exception;

class WorkflowRun extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workflow:run
                            {name : The workflow name}
                            {--input= : JSON-encoded initial input for the workflow}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute a workflow';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $workflowName = $this->argument('name');
        $inputJson = $this->option('input');

        // Parse initial input if provided
        $initialInput = [];
        if ($inputJson) {
            $initialInput = json_decode($inputJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error("Invalid JSON provided for --input option");
                return 1;
            }
        }

        $this->info("Starting workflow: {$workflowName}");
        if (!empty($initialInput)) {
            $this->line("With initial input: " . json_encode($initialInput));
        }

        try {
            $engine = new WorkflowEngine();
            $result = $engine->executeWorkflow($workflowName, $initialInput);

            $this->info("Workflow completed successfully");
            $this->line("Run ID: {$result['run_id']}");

            if (!empty($result['output'])) {
                $this->displayOutput($result['output']);
            }

            return 0;

        } catch (\Throwable $e) {
            $this->error("Workflow execution failed: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Display workflow output in a user-friendly format
     */
    private function displayOutput(array $output): void
    {
        // Check if output has standardOutput structure (data/meta/error)
        if (isset($output['error']) && $output['error'] !== null) {
            $this->error("Output Error: {$output['error']}");
            return;
        }

        if (isset($output['data'])) {
            // Extract meaningful data
            $data = $output['data'];

            // Special formatting for PushoverNotify outputs
            if (isset($data['notification_sent'])) {
                $status = $data['notification_sent'] ? '✓' : '✗';
                $this->line("Notification: $status " . ($data['notification_sent'] ? 'Sent' : 'Failed'));

                if (isset($data['title'])) {
                    $this->line("  Title: {$data['title']}");
                }

                if (isset($data['total_parts']) && $data['total_parts'] > 1) {
                    $this->line("  Parts: {$data['parts_sent']}/{$data['total_parts']} sent");
                }

                if (isset($data['message_length'])) {
                    $this->line("  Message length: {$data['message_length']} chars");
                }

                return;
            }

            // Special formatting for indexed documents
            if (isset($data['documents_indexed'])) {
                $this->line("Documents indexed: {$data['documents_indexed']}");
                return;
            }

            // Special formatting for search results
            if (isset($data['results_found'])) {
                $this->line("Results found: {$data['results_found']}");
                if (isset($data['top_match_score'])) {
                    $this->line("Top match score: {$data['top_match_score']}");
                }
                return;
            }

            // Default: show data as readable format
            if (is_string($data)) {
                $this->line("Output: $data");
            } elseif (is_array($data) && count($data) <= 5) {
                // Small arrays - show key-value pairs
                foreach ($data as $key => $value) {
                    if (is_scalar($value) || is_null($value)) {
                        $this->line("  $key: " . json_encode($value));
                    }
                }
            } else {
                // Larger or complex data - show JSON
                $this->line("Output: " . json_encode($data, JSON_PRETTY_PRINT));
            }
        } else {
            // No standard structure - display as-is
            $this->line("Output: " . json_encode($output, JSON_PRETTY_PRINT));
        }
    }
}
