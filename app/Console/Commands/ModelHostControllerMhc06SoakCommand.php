<?php

namespace App\Console\Commands;

use App\Services\ModelHostControllerMhc06SoakSamplerService;
use Illuminate\Console\Command;
use RuntimeException;

class ModelHostControllerMhc06SoakCommand extends Command
{
    protected $signature = 'model-host:mhc06-soak
                            {action : sample, aggregate, or provenance}
                            {--sample-id= : Content-free sample identity for sample action}
                            {--custody= : Exact predeploy MHC-06 custody receipt for v2 provenance}';

    protected $description = 'Capture read-only, content-free MHC-06 soak evidence';

    public function handle(ModelHostControllerMhc06SoakSamplerService $sampler): int
    {
        $action = trim((string) $this->argument('action'));
        $custody = $this->option('custody');
        try {
            $receipt = match ($action) {
                'sample' => $sampler->sample((string) ($this->option('sample-id') ?: 'mhc06-sample-'.date('YmdHis'))),
                'aggregate' => $sampler->aggregate(),
                'provenance' => $custody === null ? $sampler->provenance() : $sampler->provenance(null, (string) $custody),
                default => throw new RuntimeException('MHC-06 soak action must be sample, aggregate, or provenance.'),
            };
            $this->line(json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return in_array($receipt['result'] ?? null, ['green', 'insufficient'], true)
                ? self::SUCCESS
                : self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
