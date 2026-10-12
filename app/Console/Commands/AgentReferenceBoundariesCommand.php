<?php

namespace App\Console\Commands;

use App\Services\AgentReferenceBoundaryService;
use Illuminate\Console\Command;

class AgentReferenceBoundariesCommand extends Command
{
    protected $signature = 'ops:agent-reference-boundaries
        {--reference= : Limit to one reference id, for example hermes_agent or claw_code}
        {--guidance : Emit reusable agent guidance text}
        {--compact : Emit compact JSON or text}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only license, attribution, and provenance report for external developer-agent sources';

    public function handle(AgentReferenceBoundaryService $boundaries): int
    {
        $referenceId = $this->option('reference') ? (string) $this->option('reference') : null;

        if ($this->option('guidance')) {
            $this->line($boundaries->guidanceBlock($referenceId));

            return self::SUCCESS;
        }

        $payload = $boundaries->report($referenceId);
        if ($this->option('compact')) {
            $payload = $this->compactPayload($payload);
        }

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode agent reference boundary JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        $this->renderText($payload, (bool) $this->option('compact'));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compactPayload(array $payload): array
    {
        return [
            'version' => $payload['version'] ?? AgentReferenceBoundaryService::VERSION,
            'generated_at' => $payload['generated_at'] ?? null,
            'status' => $payload['status'] ?? 'unknown',
            'summary' => $payload['summary'] ?? [],
            'references' => array_map(static fn (array $row): array => [
                'id' => $row['id'] ?? null,
                'name' => $row['name'] ?? null,
                'status' => $row['status'] ?? null,
                'posture' => $row['posture'] ?? null,
                'allowed_use' => $row['allowed_use'] ?? null,
                'high_caution' => (bool) ($row['high_caution'] ?? false),
                'refresh_required' => (bool) ($row['refresh_required'] ?? false),
                'snapshot_version' => $row['snapshot_version'] ?? null,
                'snapshot_release' => $row['snapshot_release'] ?? null,
                'snapshot_revision' => $row['snapshot_revision'] ?? null,
                'snapshot_refreshed_at' => $row['snapshot_refreshed_at'] ?? null,
                'todo_lane' => $row['todo_lane'] ?? null,
                'todo_state' => $row['todo_state'] ?? null,
            ], (array) ($payload['references'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderText(array $payload, bool $compact): void
    {
        $summary = (array) ($payload['summary'] ?? []);
        $this->line(sprintf(
            'Agent reference boundaries: %s total=%d high_caution=%d refresh_required=%d',
            strtoupper((string) ($payload['status'] ?? 'unknown')),
            (int) ($summary['total'] ?? 0),
            (int) ($summary['high_caution_count'] ?? 0),
            (int) ($summary['refresh_required_count'] ?? 0),
        ));

        foreach ((array) ($payload['references'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $this->line(sprintf(
                '- %s [%s] posture=%s todo=%s/%s refresh=%s',
                (string) ($row['name'] ?? $row['id'] ?? 'unknown'),
                (string) ($row['status'] ?? 'unknown'),
                (string) ($row['posture'] ?? 'unknown'),
                (string) ($row['todo_lane'] ?? 'n/a'),
                (string) ($row['todo_state'] ?? 'n/a'),
                (bool) ($row['refresh_required'] ?? false) ? 'required' : 'current',
            ));

            if ($compact) {
                continue;
            }

            $this->line('  allowed: '.(string) ($row['allowed_use'] ?? 'unknown'));
            $this->line('  prohibited: '.implode(', ', (array) ($row['prohibited_use'] ?? [])));
            $this->line('  targets: '.implode(', ', (array) ($row['plos_targets'] ?? [])));
        }
    }
}
