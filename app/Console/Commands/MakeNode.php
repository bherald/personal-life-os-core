<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MakeNode extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:node {name : The name of the node class}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new workflow node';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = $this->argument('name');

        // Validate name
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
            $this->error('Node name must be in PascalCase (e.g., WeatherAPI)');
            return 1;
        }

        $filePath = app_path("Nodes/{$name}.php");

        if (file_exists($filePath)) {
            $this->error("Node already exists: {$name}");
            return 1;
        }

        // Generate node template
        $template = $this->getNodeTemplate($name);

        // Create directory if it doesn't exist
        if (!is_dir(app_path('Nodes'))) {
            mkdir(app_path('Nodes'), 0755, true);
        }

        // Write file
        file_put_contents($filePath, $template);

        $this->info("Node created successfully: {$filePath}");
        $this->info("Next steps:");
        $this->line("  1. Implement the execute() method in your node");
        $this->line("  2. Add any required configuration to your workflow");
        $this->line("  3. Test with: php artisan workflow:run <workflow-name>");

        return 0;
    }

    private function getNodeTemplate(string $name): string
    {
        return <<<PHP
<?php

namespace App\Nodes;

use Exception;

class {$name} extends BaseNode
{
    public function execute(array \$input): array
    {
        try {
            // TODO: Implement your node logic here

            // Example: Get configuration values
            // \$someConfig = \$this->getConfigValue('config_key', 'default_value');

            // Example: Process input data
            // \$data = \$input['data'] ?? \$input;

            // Example: Return output
            return \$this->standardOutput([
                'result' => 'Your processed data here',
                'original_input' => \$input
            ]);

        } catch (Exception \$e) {
            return \$this->standardOutput(null, [], \$e->getMessage());
        }
    }
}

PHP;
    }
}
