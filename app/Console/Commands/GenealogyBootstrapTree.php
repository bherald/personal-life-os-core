<?php

namespace App\Console\Commands;

use App\Services\Genealogy\TreeManagementService;
use Illuminate\Console\Command;

class GenealogyBootstrapTree extends Command
{
    protected $signature = 'genealogy:bootstrap-tree
                            {name=Local Dev Tree : Name for the genealogy tree}
                            {--description=Bootstrap tree for local genealogy intake development. : Optional description}';

    protected $description = 'Create a minimal genealogy tree for local intake/review development when none exists';

    public function handle(TreeManagementService $trees): int
    {
        $name = trim((string) $this->argument('name'));
        $description = trim((string) $this->option('description'));

        if ($name === '') {
            $this->error('Tree name is required.');

            return Command::FAILURE;
        }

        $existingTrees = array_values($trees->listTrees());
        foreach ($existingTrees as $row) {
            if (strcasecmp((string) ($row->name ?? ''), $name) === 0) {
                $this->info('Genealogy tree already exists.');
                $this->table(['Tree ID', 'Name', 'Description'], [[
                    (int) ($row->id ?? 0),
                    (string) ($row->name ?? $name),
                    (string) ($row->description ?? ''),
                ]]);
                $this->line('Use `php artisan genealogy:ingest-documents --list-trees` to verify available trees.');

                return Command::SUCCESS;
            }
        }

        $treeId = $trees->createTree($name, $description !== '' ? $description : null);

        $this->info('Genealogy tree created.');
        $this->table(['Tree ID', 'Name', 'Description'], [[
            $treeId,
            $name,
            $description,
        ]]);
        $this->line('Next: `php artisan genealogy:ingest-documents --list-trees`');
        $defaultFolder = config('genealogy.ft_reference_root', '/Library/FamilyTree/__intake');
        $defaultFolder = dirname((string) $defaultFolder);
        $this->line('Then: `php artisan genealogy:ingest-documents --stage --save-run --tree='.$treeId.' --folder='.$defaultFolder.' --limit=10 --unprocessed-only`');

        return Command::SUCCESS;
    }
}
