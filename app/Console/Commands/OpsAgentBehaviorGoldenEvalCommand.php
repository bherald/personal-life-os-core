<?php

namespace App\Console\Commands;

use App\Services\Ops\AgentBehaviorGoldenEvalService;
use Illuminate\Console\Command;

class OpsAgentBehaviorGoldenEvalCommand extends Command
{
    protected $signature = 'ops:agent-behavior-golden-eval
        {--json : Emit machine-readable JSON}
        {--compact : Omit passing case detail}
        {--fail-on-violations : Return failure when a golden contract fails}';

    protected $description = 'Run deterministic production contracts for agent security, tools, output, evidence, and memory retrieval';

    public function handle(AgentBehaviorGoldenEvalService $service): int
    {
        $payload = $service->evaluate();
        $output = $this->option('compact') ? $service->compactPayload($payload) : $payload;

        if ($this->option('json')) {
            $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode agent behavior golden eval JSON.');

                return self::FAILURE;
            }

            $this->line($json);
        } else {
            $summary = (array) ($payload['summary'] ?? []);
            $this->line(sprintf(
                'Agent behavior golden eval: %s  cases=%d  passed=%d  failed=%d  competencies=%d  checks=%d  violations=%d',
                strtoupper((string) ($payload['status'] ?? 'fail')),
                (int) ($summary['cases'] ?? 0),
                (int) ($summary['passed'] ?? 0),
                (int) ($summary['failed'] ?? 0),
                (int) ($summary['competencies'] ?? 0),
                (int) ($summary['checks'] ?? 0),
                (int) ($summary['violations'] ?? 0),
            ));
        }

        return $this->option('fail-on-violations') && ($payload['status'] ?? 'fail') !== 'pass'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
