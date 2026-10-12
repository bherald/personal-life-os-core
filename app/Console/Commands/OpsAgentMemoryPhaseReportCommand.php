<?php

namespace App\Console\Commands;

use App\Services\Ops\AgentMemoryPhaseReportService;
use Illuminate\Console\Command;

class OpsAgentMemoryPhaseReportCommand extends Command
{
    protected $signature = 'ops:agent-memory-phase-report
        {--hours=24 : Lookback window in hours}
        {--limit=1000 : Maximum receipts to aggregate}
        {--json : Emit machine-readable JSON}
        {--compact : Emit aggregate-only JSON; requires --json}
        {--fail-on-warnings : Return failure for unavailable, invalid, or failed phase evidence}';

    protected $description = 'Report content-free agent memory retrieval latency, inclusion size, and phase failures';

    public function handle(AgentMemoryPhaseReportService $report): int
    {
        if ($this->option('compact') && ! $this->option('json')) {
            $this->error('The --compact option requires --json.');

            return self::FAILURE;
        }

        $payload = $report->collect((int) $this->option('hours'), (int) $this->option('limit'));

        if ($this->option('json')) {
            $json = json_encode($payload, $this->option('compact') ? JSON_UNESCAPED_SLASHES : JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode agent memory phase report JSON.');

                return self::FAILURE;
            }
            $this->line($json);
        } else {
            $summary = $payload['summary'];
            $this->line(sprintf(
                'Agent memory phases: %s receipts=%d valid=%d failures=%d chars=%d avg_ms=%.3f p95_ms=%.3f',
                strtoupper($payload['status']),
                $summary['receipts'],
                $summary['valid_receipts'],
                $summary['phase_failures'],
                $summary['context_chars'],
                $summary['avg_retrieval_ms'],
                $summary['p95_retrieval_ms'],
            ));
        }

        return $this->option('fail-on-warnings') && ! in_array($payload['status'], ['observe_ok', 'no_data'], true)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
