<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScheduledOutputAuditCommand extends Command
{
    protected $signature = 'ops:scheduled-output-audit
        {--json : Emit machine-readable JSON}
        {--compact : With --json, emit aggregate-only output without per-job rows}
        {--fail-on-violations : Return failure when forbidden keys or invalid JSON are found}';

    protected $description = 'Read-only audit of retained scheduled JSON output for raw identifiers, rows, samples, paths, and URLs';

    private const FORBIDDEN_KEYS = [
        'agent_id',
        'check_id',
        'check_ids',
        'command_output',
        'entity_id',
        'issue_id',
        'items',
        'media_id',
        'memory_id',
        'name',
        'nextcloud_path',
        'original_path',
        'path',
        'paths',
        'person_id',
        'proposal_id',
        'review_target',
        'rows',
        'samples',
        'snapshot_id',
        'source_id',
        'title',
        'tree_id',
        'url',
        'urls',
    ];

    public function handle(): int
    {
        if ($this->option('compact') && ! $this->option('json')) {
            $this->error('The --compact option is only supported with --json.');

            return self::FAILURE;
        }

        $payload = $this->audit();

        if ($this->option('compact')) {
            $payload = $this->compactPayload($payload);
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $summary = $payload['summary'] ?? [];
            $this->line(sprintf(
                'Scheduled output audit: jobs=%d retained=%d violations=%d invalid_json=%d',
                (int) ($summary['json_scheduled_jobs'] ?? 0),
                (int) ($summary['retained_outputs'] ?? 0),
                (int) ($summary['violating_jobs'] ?? 0),
                (int) ($summary['invalid_json_jobs'] ?? 0)
            ));
        }

        $summary = $payload['summary'] ?? [];
        if ((bool) $this->option('fail-on-violations')
            && ((int) ($summary['violating_jobs'] ?? 0) > 0 || (int) ($summary['invalid_json_jobs'] ?? 0) > 0)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function audit(): array
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return [
                'schema' => 'scheduled_output_audit.v1',
                'mode' => 'observe',
                'read_only' => true,
                'status' => 'schema_missing',
                'generated_at' => now()->toIso8601String(),
                'forbidden_keys' => self::FORBIDDEN_KEYS,
                'summary' => [
                    'json_scheduled_jobs' => 0,
                    'retained_outputs' => 0,
                    'invalid_json_jobs' => 0,
                    'violating_jobs' => 0,
                    'forbidden_key_hits' => 0,
                ],
                'jobs' => [],
            ];
        }

        $rows = DB::table('scheduled_jobs')
            ->select('name', 'command', 'last_run_output')
            ->where('command', 'like', '%--json%')
            ->orderBy('name')
            ->get();

        $jobs = [];
        $keyCounts = [];
        $retained = 0;
        $invalid = 0;
        $violating = 0;
        $hitTotal = 0;

        foreach ($rows as $row) {
            $output = (string) ($row->last_run_output ?? '');
            if ($output === '') {
                continue;
            }

            $retained++;
            $decoded = json_decode($output, true);
            if (! is_array($decoded)) {
                $invalid++;
                $violating++;
                $jobs[] = [
                    'name' => (string) $row->name,
                    'status' => 'invalid_json',
                    'forbidden_keys' => [],
                    'forbidden_key_hits' => 0,
                ];

                continue;
            }

            $hits = $this->forbiddenKeyHits($decoded);
            $hitKeys = array_values(array_unique($hits));
            sort($hitKeys);

            foreach ($hits as $key) {
                $keyCounts[$key] = ($keyCounts[$key] ?? 0) + 1;
                $hitTotal++;
            }

            if ($hitKeys !== []) {
                $violating++;
            }

            $jobs[] = [
                'name' => (string) $row->name,
                'status' => $hitKeys === [] ? 'ok' : 'forbidden_keys',
                'forbidden_keys' => $hitKeys,
                'forbidden_key_hits' => count($hits),
            ];
        }

        ksort($keyCounts);

        return [
            'schema' => 'scheduled_output_audit.v1',
            'mode' => 'observe',
            'read_only' => true,
            'status' => $violating > 0 ? 'violations_found' : 'ok',
            'generated_at' => now()->toIso8601String(),
            'forbidden_keys' => self::FORBIDDEN_KEYS,
            'summary' => [
                'json_scheduled_jobs' => $rows->count(),
                'retained_outputs' => $retained,
                'invalid_json_jobs' => $invalid,
                'violating_jobs' => $violating,
                'forbidden_key_hits' => $hitTotal,
            ],
            'forbidden_key_counts' => $keyCounts,
            'jobs' => $jobs,
        ];
    }

    private function compactPayload(array $payload): array
    {
        return [
            'schema' => (string) ($payload['schema'] ?? 'scheduled_output_audit.v1'),
            'compact' => true,
            'mode' => 'observe',
            'read_only' => true,
            'status' => (string) ($payload['status'] ?? 'unknown'),
            'generated_at' => (string) ($payload['generated_at'] ?? now()->toIso8601String()),
            'summary' => $payload['summary'] ?? [],
            'forbidden_key_counts' => $payload['forbidden_key_counts'] ?? [],
            'posture' => [
                'aggregate_only' => true,
                'raw_scheduled_output_included' => false,
                'job_rows_included' => false,
                'job_names_included' => false,
                'forbidden_key_policy_included' => false,
                'writes_enabled' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function forbiddenKeyHits(mixed $value): array
    {
        $hits = [];

        $walk = function (mixed $node) use (&$walk, &$hits): void {
            if (! is_array($node)) {
                return;
            }

            foreach ($node as $key => $child) {
                if (in_array((string) $key, self::FORBIDDEN_KEYS, true)) {
                    $hits[] = (string) $key;
                }

                $walk($child);
            }
        };

        $walk($value);

        return $hits;
    }
}
