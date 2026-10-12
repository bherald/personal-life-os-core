<?php

namespace App\Console\Commands;

use App\Services\OsToolDiscoveryService;
use Illuminate\Console\Command;

class OsToolInventoryCommand extends Command
{
    protected $signature = 'os-tools:inventory {--category=} {--json : Emit machine-readable observations}';

    protected $description = 'Observe known local tool availability without enabling, installing or scanning';

    public function handle(OsToolDiscoveryService $service): int
    {
        try {
            $result = $service->inventory($this->option('category'));
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Tool', 'Category', 'Executable', 'Missing dependencies', 'Qualification'], array_map(fn ($row) => [
                $row['id'], $row['category'], $row['executable'] ? 'present' : 'not found in known paths',
                implode(', ', $row['dependency_missing']), $row['qualification'],
            ], $result['tools']));
            $this->line('Curated observations only; no execution authority or complete machine inventory implied.');
        }

        return self::SUCCESS;
    }
}
