<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class CodexOfflineImprovementReviewCommand extends Command
{
    protected $signature = 'codex:offline-improvement-review
        {--report-dir=offline-improvement-proposals : storage/app-relative report directory}
        {--days=30 : Only include reports modified within this many days}
        {--limit=20 : Maximum report samples to include}
        {--stage-review : Preview review queue materialization without writing}
        {--queue-review : Create or reuse one pending review queue item when reports exist}
        {--json : Emit machine-readable JSON}
        {--compact : Emit compact scheduler-safe JSON}';

    protected $description = 'Summarize offline improvement proposal reports and optionally lift them into the review queue';

    public function handle(): int
    {
        $dir = $this->reportDirectory();
        if ($dir['error'] !== null) {
            return $this->finish([
                'schema' => 'plos_codex_offline_improvement_review.v1',
                'status' => 'invalid_input',
                'read_only' => true,
                'error' => $dir['error'],
            ], self::FAILURE);
        }

        $days = max(1, min((int) $this->option('days'), 365));
        $limit = max(1, min((int) $this->option('limit'), 100));
        $reports = $this->loadReports($dir['absolute'], $dir['relative'], $days, $limit);
        $counts = $this->counts($reports['all']);
        $counts['ignored_unactionable'] = $reports['ignored_unactionable'];
        $hasReports = $counts['reports'] > 0;
        $queueReview = (bool) $this->option('queue-review');

        $payload = [
            'schema' => 'plos_codex_offline_improvement_review.v1',
            'status' => $hasReports ? 'review_required' : 'empty',
            'mode' => $queueReview ? 'queue_review' : ((bool) $this->option('stage-review') ? 'stage_review' : 'observe'),
            'read_only' => ! $queueReview,
            'generated_at' => now()->toIso8601String(),
            'report_dir' => $dir['relative'],
            'window_days' => $days,
            'summary' => $counts,
            'samples' => $reports['samples'],
            'next_action' => $hasReports
                ? 'Review the queued skill_optimization item, then approve only specific skill, memory, tool, or test follow-up work.'
                : 'No actionable offline improvement proposals are pending.',
        ];

        if ($hasReports && ((bool) $this->option('stage-review') || $queueReview)) {
            $review = $this->materializeReview($payload, $queueReview);
            $payload['review_materialization'] = $review;

            if ($queueReview && ! ($review['success'] ?? false)) {
                return $this->finish($payload, self::FAILURE);
            }
        }

        return $this->finish($payload);
    }

    /**
     * @return array{relative:string,absolute:string,error:?string}
     */
    private function reportDirectory(): array
    {
        $relative = trim((string) ($this->option('report-dir') ?: 'offline-improvement-proposals'));
        $relative = trim(str_replace('\\', '/', $relative), '/');

        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            return ['relative' => '', 'absolute' => '', 'error' => '--report-dir must be a storage/app-relative path without ..'];
        }

        return [
            'relative' => $relative,
            'absolute' => storage_path('app/'.$relative),
            'error' => null,
        ];
    }

    /**
     * @return array{all:list<array<string, mixed>>,samples:list<array<string, mixed>>,ignored_unactionable:int}
     */
    private function loadReports(string $absoluteDir, string $relativeDir, int $days, int $limit): array
    {
        if (! is_dir($absoluteDir)) {
            return ['all' => [], 'samples' => [], 'ignored_unactionable' => 0];
        }

        $cutoff = now()->subDays($days)->getTimestamp();
        $files = collect(File::files($absoluteDir))
            ->filter(fn ($file): bool => $file->getExtension() === 'json' && $file->getMTime() >= $cutoff)
            ->sortByDesc(fn ($file): int => $file->getMTime())
            ->values();

        $all = [];
        $ignored = 0;
        $seen = [];
        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file->getPathname()), true);
            if (! is_array($decoded) || ($decoded['schema'] ?? null) !== 'plos_codex_offline_improvement.v1') {
                continue;
            }

            if (! $this->isActionableReport($decoded)) {
                $ignored++;

                continue;
            }

            $actionable = $decoded['actionable'];
            $fingerprint = hash('sha256', implode('|', [
                (string) $decoded['area'], (string) $actionable['lesson'],
                (string) $actionable['evidence_ref'], (string) $actionable['target'],
                (string) $actionable['proposal_type'],
            ]));
            if (isset($seen[$fingerprint])) {
                $ignored++;

                continue;
            }
            $seen[$fingerprint] = true;

            $proposals = is_array($decoded['proposals'] ?? null) ? $decoded['proposals'] : [];
            $proposalTypes = [];
            foreach ($proposals as $proposal) {
                if (is_array($proposal) && isset($proposal['type'])) {
                    $proposalTypes[] = (string) $proposal['type'];
                }
            }

            $all[] = [
                'relative_path' => $relativeDir.'/'.$file->getFilename(),
                'mtime' => date('c', $file->getMTime()),
                'area' => (string) ($decoded['area'] ?? 'unknown'),
                'summary_sha256' => (string) ($decoded['summary_sha256'] ?? ''),
                'summary_bytes' => (int) ($decoded['summary_bytes'] ?? 0),
                'proposal_types' => array_values(array_unique($proposalTypes)),
                'proposal_count' => count($proposalTypes),
                'mode' => (string) ($decoded['mode'] ?? 'unknown'),
                'actionable' => $actionable,
                'fingerprint' => $fingerprint,
            ];
        }

        return [
            'all' => $all,
            'samples' => array_slice($all, 0, $limit),
            'ignored_unactionable' => $ignored,
        ];
    }

    /** @param array<string,mixed> $report */
    private function isActionableReport(array $report): bool
    {
        $a = $report['actionable'] ?? null;
        $proposals = $report['proposals'] ?? null;
        if (($report['mode'] ?? null) !== 'write_report' || ! is_array($a)
            || ! is_array($proposals) || count($proposals) !== 1 || ! is_array($proposals[0])) {
            return false;
        }
        $lesson = trim((string) ($a['lesson'] ?? ''));
        $ref = trim((string) ($a['evidence_ref'] ?? ''));
        $target = trim((string) ($a['target'] ?? ''));
        $type = (string) ($a['proposal_type'] ?? '');
        $validRef = static fn (string $value, int $max): bool => strlen($value) <= $max
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#-]{2,}$/', $value) === 1
            && ! str_contains($value, '..') && ! str_contains($value, '://');

        return mb_strlen($lesson) >= 20 && mb_strlen($lesson) <= 300
            && ! preg_match('/(?:password|api[_-]?key|secret|bearer)\s*[:=]/i', $lesson)
            && $validRef($ref, 200) && $validRef($target, 160)
            && in_array($type, ['skill_update', 'memory_note', 'tool_surface', 'test_guard', 'prompt_candidate_eval'], true)
            && ($proposals[0]['type'] ?? null) === $type
            && ($proposals[0]['artifact_target'] ?? null) === $target;
    }

    /**
     * @param  list<array<string, mixed>>  $reports
     * @return array<string, mixed>
     */
    private function counts(array $reports): array
    {
        $proposalCounts = [];
        $areaCounts = [];

        foreach ($reports as $report) {
            $area = (string) ($report['area'] ?? 'unknown');
            $areaCounts[$area] = ($areaCounts[$area] ?? 0) + 1;

            foreach ((array) ($report['proposal_types'] ?? []) as $type) {
                $proposalCounts[(string) $type] = ($proposalCounts[(string) $type] ?? 0) + 1;
            }
        }

        ksort($proposalCounts);
        ksort($areaCounts);

        return [
            'reports' => count($reports),
            'proposal_types' => $proposalCounts,
            'areas' => $areaCounts,
            'latest_report' => $reports[0]['relative_path'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function materializeReview(array $payload, bool $execute): array
    {
        $details = [
            'source' => 'codex:offline-improvement-review',
            'report_dir' => $payload['report_dir'] ?? null,
            'window_days' => $payload['window_days'] ?? null,
            'summary' => $payload['summary'] ?? [],
            'samples' => $payload['samples'] ?? [],
            'review_policy' => [
                'autonomous_mutation_allowed' => false,
                'approval_required_for_skill_memory_tool_or_test_change' => true,
            ],
        ];
        $title = 'Offline improvement proposals need review';
        $first = (array) data_get($payload, 'samples.0.actionable', []);
        $summary = sprintf(
            '%d actionable offline improvement report(s). First target: %s. Lesson: %s',
            (int) data_get($payload, 'summary.reports', 0),
            (string) ($first['target'] ?? 'unknown'),
            (string) ($first['lesson'] ?? 'missing'),
        );
        $dedupKey = 'offline-dev|skill_optimization|'.$title;

        if (! $execute) {
            return [
                'success' => true,
                'execute' => false,
                'action' => 'preview',
                'review_type' => 'skill_optimization',
                'finding_type' => 'offline_improvement_proposal',
                'title' => $title,
                'summary' => $summary,
                'pending_dedup_key' => $dedupKey,
            ];
        }

        try {
            if (! Schema::hasTable('agent_review_queue')) {
                return [
                    'success' => false,
                    'execute' => true,
                    'action' => 'none',
                    'error' => 'agent_review_queue_missing',
                ];
            }

            $query = DB::table('agent_review_queue')
                ->where('status', 'pending')
                ->where('review_type', 'skill_optimization')
                ->where('agent_id', 'offline-dev')
                ->where('title', $title);

            $existing = $query->first(['id']);
            $now = now()->toDateTimeString();
            $row = [
                'agent_id' => 'offline-dev',
                'review_type' => 'skill_optimization',
                'title' => $title,
                'summary' => $summary,
                'details' => json_encode($details, JSON_UNESCAPED_SLASHES),
                'confidence' => 0.7,
                'priority' => 1,
                'status' => 'pending',
                'expires_at' => now()->addDays(14)->toDateTimeString(),
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('agent_review_queue', 'finding_type')) {
                $row['finding_type'] = 'offline_improvement_proposal';
            }

            if ($existing !== null) {
                DB::table('agent_review_queue')->where('id', $existing->id)->update($row);

                return [
                    'success' => true,
                    'execute' => true,
                    'action' => 'reused',
                    'review_queue_id' => (int) $existing->id,
                    'review_type' => 'skill_optimization',
                    'finding_type' => 'offline_improvement_proposal',
                ];
            }

            $row['token'] = bin2hex(random_bytes(16));
            $row['created_at'] = $now;
            $id = DB::table('agent_review_queue')->insertGetId($row);

            return [
                'success' => true,
                'execute' => true,
                'action' => 'created',
                'review_queue_id' => (int) $id,
                'review_type' => 'skill_optimization',
                'finding_type' => 'offline_improvement_proposal',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'execute' => true,
                'action' => 'none',
                'error' => 'review_queue_failed',
                'message' => 'Review queue write failed; saved proposal reports remain available.',
                'exception_type' => $e::class,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finish(array $payload, int $exit = self::SUCCESS): int
    {
        if ((bool) $this->option('compact')) {
            $payload = $this->compact($payload);
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        $this->line(sprintf(
            'Offline improvement review: %s reports=%d latest=%s',
            $payload['status'] ?? 'unknown',
            (int) data_get($payload, 'summary.reports', 0),
            (string) (data_get($payload, 'summary.latest_report') ?? 'none'),
        ));

        return $exit;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compact(array $payload): array
    {
        return array_filter([
            'schema' => 'plos_codex_offline_improvement_review_compact.v1',
            'status' => $payload['status'] ?? null,
            'mode' => $payload['mode'] ?? null,
            'read_only' => $payload['read_only'] ?? null,
            'report_dir' => $payload['report_dir'] ?? null,
            'window_days' => $payload['window_days'] ?? null,
            'summary' => $payload['summary'] ?? null,
            'samples_count' => count((array) ($payload['samples'] ?? [])),
            'review_materialization' => $payload['review_materialization'] ?? null,
            'next_action' => $payload['next_action'] ?? null,
        ], fn ($value): bool => $value !== null);
    }
}
