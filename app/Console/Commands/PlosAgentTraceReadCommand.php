<?php

namespace App\Console\Commands;

use App\Services\DevAgent\TraceEnvelopeService;
use Illuminate\Console\Command;

class PlosAgentTraceReadCommand extends Command
{
    protected $signature = 'plos:agent-trace-read
        {trace_id? : Trace id to read}
        {--event= : Read one event id instead of a full trace}
        {--since=168 : Scan window in hours}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read one sanitized PLOS dev-agent trace or event';

    public function handle(TraceEnvelopeService $traces): int
    {
        $eventId = trim((string) ($this->option('event') ?? ''));
        $traceId = trim((string) ($this->argument('trace_id') ?? ''));

        if ($eventId === '' && $traceId === '') {
            $this->error('Provide a trace_id argument or --event=evt_id.');

            return self::FAILURE;
        }

        if ($eventId !== '') {
            $payload = $traces->readEventById($eventId, [
                'since' => $this->option('since'),
            ]);
        } else {
            $payload = $traces->readByTraceId($traceId, [
                'since' => $this->option('since'),
            ]);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (($payload['result'] ?? null) !== 'ok') {
            $this->warn('Trace envelope not found.');
            $this->writeWarnings($payload['warnings'] ?? []);

            return self::SUCCESS;
        }

        $events = isset($payload['event']) && is_array($payload['event'])
            ? [$payload['event']]
            : (array) ($payload['events'] ?? []);

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $this->line(sprintf(
                '[%s] %s trace=%s surface=%s status=%s',
                (string) ($event['recorded_at'] ?? ''),
                (string) ($event['event_type'] ?? ''),
                $this->humanTraceLabel($event),
                (string) ($event['surface'] ?? ''),
                (string) ($event['result']['status'] ?? 'n/a'),
            ));
        }

        $this->writeWarnings($payload['warnings'] ?? []);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, mixed>  $warnings
     */
    private function writeWarnings(array $warnings): void
    {
        foreach ($warnings as $warning) {
            if (! is_array($warning)) {
                continue;
            }

            $this->warn(sprintf(
                'warning: trace-file:%s %s',
                (string) ($warning['line'] ?? '-'),
                (string) ($warning['warning'] ?? 'unknown')
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function humanTraceLabel(array $event): string
    {
        return isset($event['trace_id']) && trim((string) $event['trace_id']) !== '' ? 'matched' : 'n/a';
    }
}
