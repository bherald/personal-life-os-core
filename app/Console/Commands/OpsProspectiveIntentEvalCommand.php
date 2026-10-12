<?php

namespace App\Console\Commands;

use App\Services\Ops\ProspectiveIntentEvalService;
use Illuminate\Console\Command;

final class OpsProspectiveIntentEvalCommand extends Command
{
    protected $signature = 'ops:prospective-intent-eval
        {--json : Emit machine-readable JSON}
        {--compact : Omit passing scenario detail}
        {--fail-on-violations : Return failure when an evaluation contract fails}';

    protected $description = 'Evaluate deterministic deferred-intent due sets, updates, dependencies, and monitoring evidence';

    public function handle(ProspectiveIntentEvalService $service): int
    {
        $payload = $service->evaluate();
        $output = $this->option('compact') ? $service->compactPayload($payload) : $payload;

        if ($this->option('json')) {
            $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode prospective intent eval JSON.');

                return self::FAILURE;
            }
            $this->line($json);
        } else {
            $summary = (array) ($payload['summary'] ?? []);
            $this->line(sprintf(
                'Prospective intent eval: %s  scenarios=%d  passed=%d  failed=%d  checks=%d  violations=%d',
                strtoupper((string) ($payload['status'] ?? 'fail')),
                (int) ($summary['scenarios'] ?? 0),
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
