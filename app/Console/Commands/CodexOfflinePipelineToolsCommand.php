<?php

namespace App\Console\Commands;

use App\Services\OfflineAiPipelineInventoryService;
use Illuminate\Console\Command;

class CodexOfflinePipelineToolsCommand extends Command
{
    protected $signature = 'codex:offline-pipeline-tools
        {--json : Emit machine-readable JSON}
        {--compact : Omit long descriptions and optional inventories}
        {--task= : Filter configured ollama_pipeline tasks by name}';

    protected $description = 'List PLOS AI pipeline functions, tools, and options in execution order for offline agents';

    public function handle(OfflineAiPipelineInventoryService $inventory): int
    {
        $payload = $inventory->payload(
            task: is_string($this->option('task')) ? (string) $this->option('task') : null,
            compact: (bool) $this->option('compact')
        );

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('PLOS offline AI pipeline inventory');
        $this->line('Use this when a local model asks what PLOS AI pipeline tools/options exist.');
        $this->newLine();

        $this->line('Text generation order:');
        foreach ($payload['runtime_text_pipeline_order'] as $step) {
            $this->line(sprintf(
                '  %d. %s -> %s',
                (int) $step['order'],
                (string) $step['function'],
                (string) $step['tool_or_option'],
            ));
        }

        $this->newLine();
        $this->line('Configured ollama_pipeline tasks:');
        foreach ($payload['configured_ollama_pipeline_tasks'] as $task) {
            $stages = implode(' -> ', array_map(
                static fn (array $stage): string => (string) $stage['stage'],
                $task['stages_in_execution_order'] ?? []
            ));
            $this->line(sprintf(
                '  - %s [%s/%s]: %s',
                (string) $task['task'],
                (string) $task['route'],
                (string) $task['model_role'],
                $stages
            ));
        }

        $this->newLine();
        $this->line('Offline entrypoints: ./offline_dev.sh --pipeline-tools, /pipeline-tools, plos_offline_pipeline_tools');

        return self::SUCCESS;
    }
}
