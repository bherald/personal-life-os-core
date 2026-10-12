<?php

namespace App\Console\Commands;

use App\Services\GortexSchemaExportService;
use Illuminate\Console\Command;
use Throwable;

final class GortexExportSchemasCommand extends Command
{
    protected $signature = 'gortex:export-schemas';

    protected $description = 'Refresh private metadata-only MySQL/PostgreSQL snapshots for local Gortex navigation';

    public function handle(GortexSchemaExportService $exporter): int
    {
        try {
            $receipt = $exporter->export(config('gortex.schema_connections'), config('gortex.schema_directory').'/migrations');
            $this->line(json_encode($receipt, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            // Connection exceptions can contain credentials. Keep console diagnostics generic.
            $this->error('Schema export failed; existing snapshots are retained. Error class: '.get_class($error));

            return self::FAILURE;
        }
    }
}
