<?php

namespace App\Console\Commands;

use App\Services\AgentHandoffService;
use App\Services\Ops\AgentRoutingGoldenEvalService;
use Illuminate\Console\Command;

final class OpsAgentRoutingGoldenEvalCommand extends Command
{
    protected $signature = 'ops:agent-routing-golden-eval
        {--json : Emit machine-readable JSON}
        {--compact : Omit passing case detail}
        {--fail-on-regression : Return failure when a routing fixture fails}';

    protected $description = 'Run deterministic live-catalog and synthetic-challenger shadow agent-routing fixtures';

    public function handle(AgentHandoffService $handoffs, AgentRoutingGoldenEvalService $service): int
    {
        $payload = $service->evaluate($handoffs->getAgents());
        $output = $this->option('compact') ? $service->compactPayload($payload) : $payload;

        if ($this->option('json')) {
            $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode agent-routing golden eval JSON.');

                return self::FAILURE;
            }
            $this->line($json);
        } else {
            $this->line(sprintf(
                'Agent routing golden eval: %s  cases=%d  passed=%d  failed=%d  top1=%.4f  mrr=%.4f',
                strtoupper((string) ($payload['status'] ?? 'fail')),
                (int) data_get($payload, 'summary.cases', 0),
                (int) data_get($payload, 'summary.passed', 0),
                (int) data_get($payload, 'summary.failed', 0),
                (float) data_get($payload, 'metrics.top_1_accuracy', 0),
                (float) data_get($payload, 'metrics.mrr', 0),
            ));
        }

        return $this->option('fail-on-regression') && ($payload['status'] ?? 'fail') !== 'pass'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
