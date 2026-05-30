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
        {--json : Emit machine-readable JSON}
        {--compact : Emit aggregate-only trace counts without events, ids, actor ids, paths, or payload details}';

    protected $description = 'Read-only tail of sanitized PLOS dev-agent trace envelopes';

    public function handle(TraceEnvelopeService $traces): int
    {
        $filters = [
            'limit' => $this->option('limit'),
            'since' => $this->option('since'),
            'trace' => $this->option('trace'),
            'type' => $this->option('type'),
            'surface' => $this->option('surface'),
            'actor' => $this->option('actor'),
        ];
        $payload = $this->option('compact')
            ? $traces->compactTail($filters)
            : $traces->tail($filters);

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($this->option('compact')) {
            $this->line(sprintf(
                'Agent trace compact tail: events=%d warnings=%d window=%dh',
                (int) ($payload['event_count'] ?? 0),
                (int) ($payload['warning_count'] ?? 0),
                (int) ($payload['hours'] ?? 24)
            ));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Agent trace tail: %d event(s), window=%dh',
            count($payload['events'] ?? []),
            (int) ($payload['hours'] ?? 24)
        ));

        foreach ($payload['events'] ?? [] as $event) {
            if (! is_array($event)) {
                continue;
            }

            $this->line(sprintf(
                '[%s] %s trace=%s surface=%s actor=%s status=%s',
                (string) ($event['recorded_at'] ?? ''),
                (string) ($event['event_type'] ?? ''),
                $this->humanTraceLabel($event),
                (string) ($event['surface'] ?? ''),
                $this->humanActorLabel($event),
                (string) ($event['result']['status'] ?? 'n/a'),
            ));
        }

        foreach ($payload['warnings'] ?? [] as $warning) {
            if (! is_array($warning)) {
                continue;
            }

            $this->warn(sprintf(
                'warning: trace-file:%s %s',
                (string) ($warning['line'] ?? '-'),
                (string) ($warning['warning'] ?? 'unknown')
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function humanTraceLabel(array $event): string
    {
        return isset($event['trace_id']) && trim((string) $event['trace_id']) !== '' ? 'matched' : 'n/a';
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function humanActorLabel(array $event): string
    {
        $actor = is_array($event['actor'] ?? null) ? $event['actor'] : [];
        $type = trim((string) ($actor['type'] ?? ''));

        if ($type !== '') {
            return $type;
        }

        return isset($actor['id']) && trim((string) $actor['id']) !== '' ? 'present' : 'n/a';
    }
}
