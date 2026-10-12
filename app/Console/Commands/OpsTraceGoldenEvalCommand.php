<?php

namespace App\Console\Commands;

use App\Services\Ops\TraceGoldenEvalService;
use Illuminate\Console\Command;

class OpsTraceGoldenEvalCommand extends Command
{
    protected $signature = 'ops:trace-golden-eval
        {--json : Emit machine-readable JSON}
        {--compact : Omit passing case detail}
        {--fail-on-violations : Return failure when a golden contract fails}';

    protected $description = 'Run deterministic privacy-safe golden contracts against the production trace envelope builder';

    public function handle(TraceGoldenEvalService $service): int
    {
        $payload = $service->evaluate();
        $output = $this->option('compact') ? $service->compactPayload($payload) : $payload;

        if ($this->option('json')) {
            $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode trace golden eval JSON.');

                return self::FAILURE;
            }

            $this->line($json);
        } else {
            $summary = (array) ($payload['summary'] ?? []);
            $this->line(sprintf(
                'Trace golden eval: %s  cases=%d  passed=%d  failed=%d  checks=%d  violations=%d',
                strtoupper((string) ($payload['status'] ?? 'fail')),
                (int) ($summary['cases'] ?? 0),
                (int) ($summary['passed'] ?? 0),
                (int) ($summary['failed'] ?? 0),
                (int) ($summary['checks'] ?? 0),
                (int) ($summary['violations'] ?? 0),
            ));
        }

        return $this->option('fail-on-violations') && ($payload['status'] ?? 'fail') !== 'pass'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
