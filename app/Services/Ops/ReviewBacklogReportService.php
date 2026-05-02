<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReviewBacklogReportService
{
    public function collect(int $staleDays = 7, int $highPriorityThreshold = 8, bool $dryRun = false): array
    {
        $staleDays = max(1, $staleDays);
        $highPriorityThreshold = max(1, $highPriorityThreshold);

        $payload = [
            'version' => 1,
            'mode' => 'observe',
            'dry_run' => $dryRun,
            'stale_days' => $staleDays,
            'high_priority_threshold' => $highPriorityThreshold,
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'sources' => ['agent_review_queue'],
            'summary' => [],
            'pending_by_type' => [],
            'pending_by_agent' => [],
            'status_counts' => [],
            'recommendations' => [],
        ];

        if ($dryRun) {
            $payload['status'] = 'observe_ok';
            $payload['summary'] = [
                'pending_total' => 0,
                'stale_pending' => 0,
                'high_priority_pending' => 0,
                'oldest_pending_at' => null,
                'newest_pending_at' => null,
            ];
            $payload['recommendations'] = ['Dry run only; no review backlog queries executed.'];

            return $payload;
        }

        if (! Schema::hasTable('agent_review_queue')) {
            $payload['status'] = 'blocked';
            $payload['summary'] = [
                'pending_total' => 0,
                'stale_pending' => 0,
                'high_priority_pending' => 0,
                'oldest_pending_at' => null,
                'newest_pending_at' => null,
            ];
            $payload['recommendations'] = ['agent_review_queue table is missing; run migrations before reviewing backlog.'];

            return $payload;
        }

        $payload['summary'] = $this->summary($staleDays, $highPriorityThreshold);
        $payload['pending_by_type'] = $this->pendingByType($highPriorityThreshold);
        $payload['pending_by_agent'] = $this->pendingByAgent($highPriorityThreshold);
        $payload['status_counts'] = $this->statusCounts();
        $payload['status'] = $this->status($payload['summary']);
        $payload['recommendations'] = $this->recommendations($payload);

        return $payload;
    }

    public function toMarkdown(array $payload): string
    {
        $summary = $payload['summary'] ?? [];
        $lines = [
            '# Review Backlog Report',
            '',
            '- Mode: `'.($payload['mode'] ?? 'observe').'`',
            '- Status: `'.($payload['status'] ?? 'unknown').'`',
            '- Captured: `'.($payload['captured_at'] ?? 'unknown').'`',
            '- Dry run: `'.(($payload['dry_run'] ?? false) ? 'true' : 'false').'`',
            '- Pending total: `'.($summary['pending_total'] ?? 0).'`',
            '- Stale pending: `'.($summary['stale_pending'] ?? 0).'`',
            '- High-priority pending: `'.($summary['high_priority_pending'] ?? 0).'`',
            '',
            '## Pending By Type',
            '',
        ];

        foreach (($payload['pending_by_type'] ?? []) as $row) {
            $lines[] = sprintf(
                '- `%s` / `%s`: `%d` pending, oldest `%s`',
                (string) ($row['review_type'] ?? 'unknown'),
                (string) ($row['finding_type'] ?? 'none'),
                (int) ($row['pending'] ?? 0),
                (string) ($row['oldest_pending_at'] ?? 'none')
            );
        }
        if (($payload['pending_by_type'] ?? []) === []) {
            $lines[] = '- None.';
        }

        $lines[] = '';
        $lines[] = '## Recommendations';
        $lines[] = '';
        foreach (($payload['recommendations'] ?? []) as $recommendation) {
            $lines[] = '- '.$recommendation;
        }
        if (($payload['recommendations'] ?? []) === []) {
            $lines[] = '- No human action recommended from this observe-only sample.';
        }

        return implode("\n", $lines)."\n";
    }

    private function summary(int $staleDays, int $highPriorityThreshold): array
    {
        $staleCutoff = now()->subDays($staleDays)->toDateTimeString();

        $row = DB::selectOne(
            'SELECT
                COUNT(*) AS pending_total,
                SUM(CASE WHEN created_at < ? THEN 1 ELSE 0 END) AS stale_pending,
                SUM(CASE WHEN priority >= ? THEN 1 ELSE 0 END) AS high_priority_pending,
                MIN(created_at) AS oldest_pending_at,
                MAX(created_at) AS newest_pending_at
             FROM agent_review_queue
             WHERE status = ?',
            [$staleCutoff, $highPriorityThreshold, 'pending']
        );

        return [
            'pending_total' => (int) ($row->pending_total ?? 0),
            'stale_pending' => (int) ($row->stale_pending ?? 0),
            'high_priority_pending' => (int) ($row->high_priority_pending ?? 0),
            'oldest_pending_at' => $this->nullableString($row->oldest_pending_at ?? null),
            'newest_pending_at' => $this->nullableString($row->newest_pending_at ?? null),
        ];
    }

    private function pendingByType(int $highPriorityThreshold): array
    {
        $rows = DB::select(
            'SELECT
                review_type,
                finding_type,
                COUNT(*) AS pending,
                SUM(CASE WHEN priority >= ? THEN 1 ELSE 0 END) AS high_priority_pending,
                MIN(created_at) AS oldest_pending_at,
                MAX(created_at) AS newest_pending_at
             FROM agent_review_queue
             WHERE status = ?
             GROUP BY review_type, finding_type
             ORDER BY pending DESC, oldest_pending_at ASC
             LIMIT 50',
            [$highPriorityThreshold, 'pending']
        );

        return array_map(fn (object $row): array => [
            'review_type' => (string) ($row->review_type ?? 'unknown'),
            'finding_type' => $this->nullableString($row->finding_type ?? null),
            'pending' => (int) ($row->pending ?? 0),
            'high_priority_pending' => (int) ($row->high_priority_pending ?? 0),
            'oldest_pending_at' => $this->nullableString($row->oldest_pending_at ?? null),
            'newest_pending_at' => $this->nullableString($row->newest_pending_at ?? null),
        ], $rows);
    }

    private function pendingByAgent(int $highPriorityThreshold): array
    {
        $rows = DB::select(
            'SELECT
                agent_id,
                COUNT(*) AS pending,
                SUM(CASE WHEN priority >= ? THEN 1 ELSE 0 END) AS high_priority_pending,
                MIN(created_at) AS oldest_pending_at,
                MAX(created_at) AS newest_pending_at
             FROM agent_review_queue
             WHERE status = ?
             GROUP BY agent_id
             ORDER BY pending DESC, oldest_pending_at ASC
             LIMIT 50',
            [$highPriorityThreshold, 'pending']
        );

        return array_map(fn (object $row): array => [
            'agent_id' => $this->nullableString($row->agent_id ?? null) ?? 'unknown',
            'pending' => (int) ($row->pending ?? 0),
            'high_priority_pending' => (int) ($row->high_priority_pending ?? 0),
            'oldest_pending_at' => $this->nullableString($row->oldest_pending_at ?? null),
            'newest_pending_at' => $this->nullableString($row->newest_pending_at ?? null),
        ], $rows);
    }

    private function statusCounts(): array
    {
        $rows = DB::select(
            'SELECT status, COUNT(*) AS rows_count
             FROM agent_review_queue
             GROUP BY status
             ORDER BY rows_count DESC'
        );

        return array_map(fn (object $row): array => [
            'status' => (string) ($row->status ?? 'unknown'),
            'rows' => (int) ($row->rows_count ?? 0),
        ], $rows);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function status(array $summary): string
    {
        if ((int) ($summary['high_priority_pending'] ?? 0) > 0 || (int) ($summary['stale_pending'] ?? 0) > 0) {
            return 'review_required';
        }

        return (int) ($summary['pending_total'] ?? 0) > 0 ? 'observe_warning' : 'observe_ok';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function recommendations(array $payload): array
    {
        $summary = $payload['summary'] ?? [];
        $recommendations = [];

        if ((int) ($summary['high_priority_pending'] ?? 0) > 0) {
            $recommendations[] = 'Review high-priority pending rows one at a time before clearing Agent Doctor critical status.';
        }

        if ((int) ($summary['stale_pending'] ?? 0) > 0) {
            $recommendations[] = 'Classify stale pending rows as actionable, obsolete, or typed-preview-needed; do not bulk approve or reject.';
        }

        foreach (($payload['pending_by_type'] ?? []) as $row) {
            if (($row['review_type'] ?? null) === 'genealogy_finding') {
                $recommendations[] = 'For genealogy_finding rows, prefer source-backed packets and typed remediation previews before any canonical data changes.';
                break;
            }
        }

        if ($recommendations === [] && (int) ($summary['pending_total'] ?? 0) > 0) {
            $recommendations[] = 'Pending rows exist but are not stale or high priority; keep routine operator review cadence.';
        }

        return $recommendations;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
