<?php

namespace App\Console\Commands;

use App\Services\OperatorEvidenceService;
use Illuminate\Console\Command;

class OperatorEvidenceCommand extends Command
{
    protected $signature = 'ops:operator-evidence {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only operator evidence snapshot for queue, backlog, degraded state, and agent health';

    public function handle(OperatorEvidenceService $evidence): int
    {
        $payload = $evidence->collect();

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode operator evidence JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Operator evidence: %s sampled=%s',
            $payload['status'] ?? 'unknown',
            $payload['captured_at'] ?? '-'
        ));

        foreach (($payload['sections'] ?? []) as $name => $section) {
            $this->line(sprintf(
                '%s: %s',
                str_replace('_', '-', (string) $name),
                (string) ($section['status'] ?? 'unknown')
            ));

            if (isset($section['next_action'])) {
                $this->line('  next: '.$section['next_action']);
            }
        }

        return self::SUCCESS;
    }
}
