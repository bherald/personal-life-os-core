<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Engine\DatabaseLayer;

class WorkflowList extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workflow:list {--active : Show only active workflows} {--schedule : Show only scheduled workflows}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all workflows';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $db = new DatabaseLayer();

        if ($this->option('active')) {
            $workflows = $db->getActiveWorkflows();
        } else {
            $workflows = $db->getAllWorkflows();
        }

        if (empty($workflows)) {
            $this->info('No workflows found');
            return 0;
        }

        // Filter by schedule if requested
        if ($this->option('schedule')) {
            $workflows = array_filter($workflows, fn($w) => !empty($w->schedule));
        }

        $tableData = [];
        foreach ($workflows as $workflow) {
            $tableData[] = [
                $workflow->id,
                $workflow->name,
                $workflow->description ?? '-',
                $workflow->schedule ?? '-',
                $workflow->active ? 'Yes' : 'No',
                $workflow->error_handling ?? 'stop',
            ];
        }

        $this->table(
            ['ID', 'Name', 'Description', 'Schedule', 'Active', 'Error Handling'],
            $tableData
        );

        return 0;
    }
}
