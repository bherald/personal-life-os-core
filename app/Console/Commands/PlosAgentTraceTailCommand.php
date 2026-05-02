<?php

namespace App\Console\Commands;

use App\Services\DevAgent\TraceEnvelopeService;
use Illuminate\Console\Command;

class PlosAgentTraceTailCommand extends Command
{
    protected $signature = 'plos:agent-trace-tail
        {--limit=20 : Maximum envelopes to return, 1-200}
        {--since=24 : Scan window in hours}
        {--trace= : Filter by trace id}
        {--type= : Filter by event type}
        {--surface= : Filter by trace surface}
        {--actor= : Filter by actor id}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only tail of sanitized PLOS dev-agent trace envelopes';

    public function handle(TraceEnvelopeService $traces): int
    {
        $payload = $traces->tail([
            'limit' => $this->option('limit'),
            'since' => $this->option('since'),
            'trace' => $this->option('trace'),
            'type' => $this->option('type'),
            'surface' => $this->option('surface'),
            'actor' => $this->option('actor'),
        ]);

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Agent trace tail: %d event(s), window=%dh',
            count($payload['events'] ?? []),
            (int) ($payload['hours'] ?? 24)
        ));

        foreach ($payload['events'] ?? [] as $event) {
            $this->line(sprintf(
                '[%s] %s %s surface=%s actor=%s status=%s',
                (string) ($event['recorded_at'] ?? ''),
                (string) ($event['event_type'] ?? ''),
                (string) ($event['trace_id'] ?? ''),
                (string) ($event['surface'] ?? ''),
                (string) ($event['actor']['id'] ?? 'n/a'),
                (string) ($event['result']['status'] ?? 'n/a'),
            ));
        }

        foreach ($payload['warnings'] ?? [] as $warning) {
            $this->warn(sprintf(
                'warning: %s:%s %s',
                (string) ($warning['file'] ?? 'unknown'),
                (string) ($warning['line'] ?? '-'),
                (string) ($warning['warning'] ?? 'unknown')
            ));
        }

        return self::SUCCESS;
    }
}
