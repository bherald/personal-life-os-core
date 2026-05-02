<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Exception;

class WorkflowCreate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workflow:create
                            {name : The workflow name}
                            {--description= : Workflow description}
                            {--schedule= : Cron schedule expression}
                            {--error-handling=stop : Error handling strategy (stop|continue)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new workflow';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = $this->argument('name');
        $description = $this->option('description');
        $schedule = $this->option('schedule');
        $errorHandling = $this->option('error-handling');

        try {
            // Check if workflow already exists using raw SQL
            $sql = "SELECT COUNT(*) as count FROM workflows WHERE name = ?";
            $exists = (DB::select($sql, [$name])[0]->count ?? 0) > 0;
            if ($exists) {
                $this->error("Workflow already exists: {$name}");
                return 1;
            }

            // Create workflow using raw SQL
            $sql = "INSERT INTO workflows (name, description, schedule, error_handling, active, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            DB::insert($sql, [$name, $description, $schedule, $errorHandling, true, now(), now()]);
            $workflowId = DB::getPdo()->lastInsertId();

            $this->info("Workflow created successfully: {$name} (ID: {$workflowId})");
            $this->line("Next steps:");
            $this->line("  1. Add nodes to your workflow using database or PHP script");
            $this->line("  2. Configure node settings in workflow_node_configs table");
            $this->line("  3. Run with: php artisan workflow:run {$name}");

            return 0;

        } catch (Exception $e) {
            $this->error("Failed to create workflow: " . $e->getMessage());
            return 1;
        }
    }
}
