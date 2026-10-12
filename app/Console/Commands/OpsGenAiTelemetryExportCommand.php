<?php

namespace App\Console\Commands;

use App\Services\Ops\GenAiTelemetryExportService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use UnexpectedValueException;

class OpsGenAiTelemetryExportCommand extends Command
{
    protected $signature = 'ops:gen-ai-telemetry-export
        {--hours=24 : Lookback window in hours, capped at 31 days}
        {--limit=500 : Maximum rows per selected source, capped at 5000}
        {--source=all : all, models, agents, retrievals, or workflows}
        {--json : Emit the PLOS export envelope with OTLP payload}
        {--otlp-json : Emit strict OTLP JSON only}
        {--fail-on-empty : Return failure when no spans are available}';

    protected $description = 'Export content-free OpenTelemetry GenAI and OpenInference model, agent, retrieval, and workflow telemetry';

    public function handle(GenAiTelemetryExportService $service): int
    {
        try {
            $payload = $service->export(
                (int) $this->option('hours'),
                (int) $this->option('limit'),
                (string) $this->option('source'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (UnexpectedValueException) {
            $this->error('OTLP trace JSON validation failed; no payload was emitted.');

            return self::FAILURE;
        }

        $spans = (int) ($payload['summary']['spans'] ?? 0);

        if ($this->option('otlp-json')) {
            $this->line($this->encode((array) ($payload['otlp'] ?? [])));
        } elseif ($this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $summary = (array) ($payload['summary'] ?? []);
            $this->line(sprintf(
                'GenAI telemetry export: %s spans=%d models=%d agents=%d retrievals=%d workflows=%d workflow_nodes=%d tools=%d tool_ids=%d errors=%d openinference=%d content_capture=off',
                strtoupper((string) ($payload['status'] ?? 'unknown')),
                $spans,
                (int) ($summary['model_spans'] ?? 0),
                (int) ($summary['agent_spans'] ?? 0),
                (int) ($summary['retrieval_spans'] ?? 0),
                (int) ($summary['workflow_spans'] ?? 0),
                (int) ($summary['workflow_node_spans'] ?? 0),
                (int) ($summary['tool_spans'] ?? 0),
                (int) ($summary['tool_spans_with_call_id'] ?? 0),
                (int) ($summary['error_spans'] ?? 0),
                (int) ($summary['openinference_spans'] ?? 0),
            ));
        }

        return $this->option('fail-on-empty') && $spans === 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Unable to encode telemetry export.');
        }

        return $json;
    }
}
