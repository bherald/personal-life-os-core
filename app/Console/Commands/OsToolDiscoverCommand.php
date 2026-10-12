<?php

namespace App\Console\Commands;

use App\Services\OsToolDiscoveryService;
use Illuminate\Console\Command;

class OsToolDiscoverCommand extends Command
{
    protected $signature = 'os-tools:discover {--limit=100} {--offset=0} {--json}';

    protected $description = 'Discover current-environment executable names and package metadata without running discovered programs';

    public function handle(OsToolDiscoveryService $service): int
    {
        try {
            foreach (['limit', 'offset'] as $option) {
                if (! ctype_digit((string) $this->option($option))) {
                    throw new \RuntimeException('Limit and offset must be nonnegative integers.');
                }
            }
            $result = $service->discover((int) $this->option('limit'), (int) $this->option('offset'));
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } else {
                $this->table(['Names', 'Category', 'Next step'], array_map(fn ($row) => [implode(', ', $row['names']), $row['category'], $row['next_step']], $result['tools']));
                $this->line("{$result['executables_total']} resolved executable observations; {$result['installed_packages_total']} installed packages. No execution granted.");
                $this->line('Next offset: '.($result['next_offset'] ?? 'end').'; bounded scan truncated: '.json_encode($result['truncated']));
            }

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
