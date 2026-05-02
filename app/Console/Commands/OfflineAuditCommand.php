<?php

namespace App\Console\Commands;

use App\Services\OfflineAuditService;
use Illuminate\Console\Command;

class OfflineAuditCommand extends Command
{
    protected $signature = 'offline:audit
        {--hours=24 : Lookback window in hours}
        {--limit=20 : Number of recent events to show}
        {--tail : Show recent events}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Summarize offline/hybrid policy audit decisions';

    public function handle(OfflineAuditService $audit): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $limit = max(1, min(100, (int) $this->option('limit')));
        $summary = $audit->summarizeWindow($hours);
        $events = (bool) $this->option('tail') ? $audit->recentEvents($limit, $hours) : [];

        if ($this->option('json')) {
            $this->line(json_encode([
                'summary' => $summary,
                'events' => $events,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (($summary['result'] ?? 'ok') !== 'ok') {
            $this->warn('offline audit unavailable: '.($summary['result'] ?? 'unknown'));
            if (isset($summary['error'])) {
                $this->line((string) $summary['error']);
            }

            return self::SUCCESS;
        }

        $this->info("Offline audit window: {$hours}h");
        $this->line('Total decisions   : '.number_format((int) $summary['total']));
        $this->line('Denied decisions  : '.number_format((int) $summary['denied']).' ('.$summary['denied_per_hour'].'/h)');
        $this->line('Allowed decisions : '.number_format((int) $summary['allowed']));
        $this->line('Confirmations     : '.number_format((int) $summary['confirmations']));
        $this->line('Mode changes      : '.number_format((int) $summary['mode_changes']));

        if (! empty($summary['top_denials'])) {
            $this->newLine();
            $this->table(['Operation', 'Class', 'Profile', 'Count'], array_map(static fn (array $row) => [
                $row['operation'],
                $row['tool_class'],
                $row['profile'],
                $row['count'],
            ], $summary['top_denials']));
        }

        if ($events !== []) {
            $this->newLine();
            $this->table(['ID', 'Event', 'Profile', 'Operation', 'Class', 'Actor', 'Created'], array_map(static fn (array $row) => [
                $row['id'],
                $row['event_type'],
                $row['profile'] ?? '',
                $row['operation'] ?? '',
                $row['tool_class'] ?? $row['path_class'] ?? $row['provider_class'] ?? '',
                $row['actor'] ?? '',
                $row['created_at'],
            ], $events));
        }

        return self::SUCCESS;
    }
}
