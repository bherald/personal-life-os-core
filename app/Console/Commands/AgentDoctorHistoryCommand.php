<?php

namespace App\Console\Commands;

use App\Services\Ops\AgentDoctorReadinessHistoryService;
use Illuminate\Console\Command;

class AgentDoctorHistoryCommand extends Command
{
    protected $signature = 'ops:agent-doctor-history
        {--days=7 : History window in days, 1-90}
        {--limit=30 : Maximum snapshots to return, 1-100}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only history report for persisted Agent Doctor readiness snapshots';

    public function handle(AgentDoctorReadinessHistoryService $history): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if (! is_int($days) || $days < 1 || $days > 90) {
            $this->error('Days must be an integer from 1 to 90.');

            return self::INVALID;
        }

        if (! is_int($limit) || $limit < 1 || $limit > 100) {
            $this->error('Limit must be an integer from 1 to 100.');

            return self::INVALID;
        }

        $payload = $history->collect($days, $limit);

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode agent-doctor history JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        $summary = $payload['summary'] ?? [];
        $this->line(sprintf(
            'agent doctor history: latest=%s trend=%s snapshots=%d warning_delta=%s critical_delta=%s',
            (string) ($summary['latest_status'] ?? 'none'),
            (string) ($summary['trend'] ?? 'unknown'),
            (int) ($summary['snapshot_count'] ?? 0),
            $this->signed($summary['warning_delta'] ?? null),
            $this->signed($summary['critical_delta'] ?? null),
        ));

        foreach (($payload['snapshots'] ?? []) as $snapshot) {
            $this->line(sprintf(
                '[%s] %s agents=%d warnings=%d critical=%d trace=%s recursion=%s',
                (string) ($snapshot['captured_at'] ?? '-'),
                (string) ($snapshot['overall_status'] ?? 'unknown'),
                (int) ($snapshot['agent_count'] ?? 0),
                (int) ($snapshot['warning_count'] ?? 0),
                (int) ($snapshot['critical_count'] ?? 0),
                (string) ($snapshot['trace_status'] ?? 'unknown'),
                (string) ($snapshot['recursion_status'] ?? 'unknown'),
            ));
        }

        return self::SUCCESS;
    }

    private function signed(mixed $value): string
    {
        return is_numeric($value) ? sprintf('%+d', (int) $value) : 'n/a';
    }
}
