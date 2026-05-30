<?php

namespace App\Console\Commands;

use App\Services\AgentEpisodicMemoryService;
use App\Services\AIService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EpisodicMemoryCommand extends Command
{
    protected $signature = 'episodic:memory
        {--stats : Show episodic memory statistics}
        {--archive : Archive old low-importance episodes}
        {--backfill : Generate summaries from existing agent_episodes}
        {--days=30 : Days to look back for backfill}
        {--limit=50 : Maximum sessions to backfill per run, capped at 500}
        {--agent= : Filter by agent ID}
        {--dry-run : Preview backfill candidate counts without writing summaries}
        {--confirm : Confirm summary creation for backfill}
        {--json : Emit JSON output for read-only stats}
        {--compact : Emit compact JSON for read-only stats}';

    protected $description = 'Manage agent episodic memory — stats, archival, backfill from existing episodes';

    public function handle(AgentEpisodicMemoryService $service): int
    {
        if (($this->option('json') || $this->option('compact')) && ($this->option('archive') || $this->option('backfill'))) {
            $this->error('The --json/--compact options are only supported for read-only stats.');

            return 1;
        }

        if ($this->option('archive')) {
            return $this->runArchive($service);
        }

        if ($this->option('backfill')) {
            return $this->runBackfill($service);
        }

        // Default: show stats
        return $this->showStats($service);
    }

    private function showStats(AgentEpisodicMemoryService $service): int
    {
        $agentId = $this->option('agent');
        $stats = $service->getStats($agentId);

        if (isset($stats['error'])) {
            if ($this->option('json')) {
                $this->line($this->encodeJson([
                    'generated_at' => now()->toIso8601String(),
                    'command' => 'episodic:memory',
                    'mode' => 'stats',
                    'compact' => (bool) $this->option('compact'),
                    'status' => 'error',
                    'agent_id' => $agentId,
                    'error' => $stats['error'],
                ]));

                return 1;
            }

            $this->error("Error: {$stats['error']}");

            return 1;
        }

        if ($this->option('json')) {
            $this->line($this->encodeJson($this->statsPayload($stats, $agentId)));

            return 0;
        }

        $this->info("=== Episodic Memory Statistics ===\n");

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total summaries', $stats['total']],
                ['Active', $stats['active']],
                ['Archived', $stats['archived']],
                ['Embeddings', $stats['embeddings']],
                ['Avg importance', $stats['avg_importance']],
                ['Avg tools/run', $stats['avg_tools']],
                ['Avg duration', number_format($stats['avg_duration_ms']).'ms'],
                ['Avg tokens', number_format($stats['avg_tokens'])],
            ]
        );

        if (! empty($stats['outcomes'])) {
            $this->newLine();
            $this->info('Outcome Distribution:');
            $outcomeRows = [];
            foreach ($stats['outcomes'] as $outcome => $count) {
                $outcomeRows[] = [$outcome, $count];
            }
            $this->table(['Outcome', 'Count'], $outcomeRows);
        }

        if (! empty($stats['per_agent'])) {
            $this->newLine();
            $this->info('Per-Agent Breakdown:');
            $agentRows = [];
            foreach ($stats['per_agent'] as $a) {
                $agentRows[] = [
                    $a['agent_id'],
                    $a['total'],
                    $a['active'],
                    $a['successes'],
                    $a['failures'],
                    $a['avg_importance'],
                ];
            }
            $this->table(['Agent', 'Total', 'Active', 'Success', 'Failures', 'Avg Imp'], $agentRows);
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    private function statsPayload(array $stats, ?string $agentId): array
    {
        $payload = [
            'generated_at' => now()->toIso8601String(),
            'command' => 'episodic:memory',
            'mode' => 'stats',
            'compact' => (bool) $this->option('compact'),
            'status' => 'pass',
            'agent_id' => $agentId,
            'summary' => [
                'total' => (int) ($stats['total'] ?? 0),
                'active' => (int) ($stats['active'] ?? 0),
                'archived' => (int) ($stats['archived'] ?? 0),
                'embeddings' => (int) ($stats['embeddings'] ?? 0),
                'avg_importance' => (float) ($stats['avg_importance'] ?? 0),
                'avg_tools' => (float) ($stats['avg_tools'] ?? 0),
                'avg_duration_ms' => (int) ($stats['avg_duration_ms'] ?? 0),
                'avg_tokens' => (int) ($stats['avg_tokens'] ?? 0),
                'outcome_count' => count((array) ($stats['outcomes'] ?? [])),
                'agent_count' => count((array) ($stats['per_agent'] ?? [])),
            ],
        ];

        if (! $this->option('compact')) {
            $payload['outcomes'] = (array) ($stats['outcomes'] ?? []);
            $payload['per_agent'] = array_values((array) ($stats['per_agent'] ?? []));

            return $payload;
        }

        $payload['top_agents'] = array_slice(array_values((array) ($stats['per_agent'] ?? [])), 0, 10);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodeJson(array $payload): string
    {
        $flags = JSON_UNESCAPED_SLASHES;
        if (! $this->option('compact')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($payload, $flags) ?: '{}';
    }

    private function runArchive(AgentEpisodicMemoryService $service): int
    {
        $this->info('Running episodic memory archival...');
        $result = $service->archiveOldEpisodes();

        if (isset($result['error'])) {
            $this->error("Error: {$result['error']}");

            return 1;
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Archived this run', $result['archived']],
                ['Embeddings removed', $result['embeddings_removed']],
                ['Total active', $result['total_active']],
                ['Total archived', $result['total_archived']],
                ['Retention days', $result['retention_days']],
            ]
        );

        $this->info('Archival complete.');

        return 0;
    }

    private function runBackfill(AgentEpisodicMemoryService $service): int
    {
        $days = (int) $this->option('days');
        $agentFilter = $this->option('agent');
        $limit = max(1, min(500, (int) $this->option('limit')));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        if (! $dryRun && ! $this->option('confirm')) {
            $this->error('Backfill writes agent_episode_summaries and requires --confirm. Use --dry-run first to preview candidates.');

            return 1;
        }

        $this->info(($dryRun ? 'Previewing' : 'Backfilling')." episodic summaries from agent_episodes (last {$days} days, limit {$limit})...");

        // Get distinct sessions from agent_episodes that don't have summaries yet
        $whereAgent = $agentFilter ? 'AND ae.agent_id = ?' : '';
        $bindings = [$cutoff];
        if ($agentFilter) {
            $bindings[] = $agentFilter;
        }

        $sessions = DB::select("
            SELECT ae.agent_id, ae.session_id,
                   MIN(ae.created_at) as started_at,
                   MAX(ae.created_at) as ended_at,
                   COUNT(*) as episode_count,
                   SUM(ae.tokens_used) as total_tokens,
                   SUM(ae.duration_ms) as total_duration,
                   GROUP_CONCAT(DISTINCT ae.event_type) as event_types
            FROM agent_episodes ae
            LEFT JOIN agent_episode_summaries aes
                ON ae.agent_id = aes.agent_id
               AND ae.session_id = aes.session_id
            WHERE aes.id IS NULL
              AND ae.created_at >= ?
              AND ae.session_id IS NOT NULL
              {$whereAgent}
            GROUP BY ae.agent_id, ae.session_id
            ORDER BY ended_at DESC
            LIMIT {$limit}
        ", $bindings);

        $total = count($sessions);
        $this->info("Found {$total} candidate session(s) without summaries.");

        if ($total === 0) {
            $this->info('Nothing to backfill.');

            return 0;
        }

        if ($dryRun) {
            $this->table(
                ['Agent', 'Candidate sessions', 'Episodes', 'Tokens'],
                $this->backfillPreviewRows($sessions)
            );
            $this->info('Dry run complete. Re-run with --confirm to create summaries.');

            return 0;
        }

        $success = 0;
        $failed = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($sessions as $sess) {
            try {
                // Determine task from episodes
                $taskEpisode = DB::select("
                    SELECT summary FROM agent_episodes
                    WHERE agent_id = ? AND session_id = ? AND event_type = 'task_started'
                    LIMIT 1
                ", [$sess->agent_id, $sess->session_id]);

                $task = ! empty($taskEpisode) ? $taskEpisode[0]->summary : 'Unknown task';

                // Determine outcome from episodes
                $hasError = str_contains($sess->event_types ?? '', 'error');
                $hasCompletion = str_contains($sess->event_types ?? '', 'task_completed');
                $outcome = $hasError ? ($hasCompletion ? 'partial' : 'error') : ($hasCompletion ? 'success' : 'partial');

                // Extract tools from episode details
                $toolEpisodes = DB::select("
                    SELECT details FROM agent_episodes
                    WHERE agent_id = ? AND session_id = ? AND event_type = 'tool_call'
                ", [$sess->agent_id, $sess->session_id]);

                $toolsUsed = [];
                foreach ($toolEpisodes as $te) {
                    $details = json_decode($te->details, true);
                    if (! empty($details['tool'])) {
                        $toolsUsed[] = $details['tool'];
                    }
                }
                $toolsUsed = array_values(array_unique($toolsUsed));

                // Generate mechanical summary (no LLM for backfill to save resources)
                $durationMs = (int) ($sess->total_duration ?? 0);
                $toolList = ! empty($toolsUsed) ? implode(', ', array_slice($toolsUsed, 0, 5)) : 'none';
                $summary = "Executed {$task}. Used ".count($toolsUsed)." tools ({$toolList}). "
                    ."Outcome: {$outcome}. Duration: {$durationMs}ms. "
                    ."Recorded {$sess->episode_count} events.";

                // Importance: backfill gets base 0.40 (lower than live)
                $importance = 0.40;
                if (in_array($outcome, ['error', 'failure'])) {
                    $importance += 0.20;
                }

                DB::insert('
                    INSERT INTO agent_episode_summaries
                        (agent_id, session_id, task, summary, outcome, importance, tools_used,
                         tool_count, tokens_used, duration_ms, episode_count, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ', [
                    $sess->agent_id,
                    $sess->session_id,
                    substr($task, 0, 500),
                    $summary,
                    $outcome,
                    $importance,
                    json_encode($toolsUsed),
                    count($toolsUsed),
                    (int) ($sess->total_tokens ?? 0),
                    $durationMs,
                    (int) $sess->episode_count,
                    $sess->started_at,
                ]);

                $summaryId = (int) DB::getPdo()->lastInsertId();

                // Store embedding (non-fatal, uses Ollama)
                try {
                    $aiService = app(AIService::class);
                    $embResult = $aiService->generateEmbedding($summary);
                    if (($embResult['success'] ?? false) && ! empty($embResult['embedding'])) {
                        $embeddingStr = '['.implode(',', $embResult['embedding']).']';
                        DB::connection('pgsql_rag')->statement('
                            INSERT INTO agent_episode_embeddings (summary_id, agent_id, embedding, created_at, updated_at)
                            VALUES (?, ?, ?::vector, NOW(), NOW())
                            ON CONFLICT (summary_id) DO UPDATE SET embedding = EXCLUDED.embedding, updated_at = NOW()
                        ', [$summaryId, $sess->agent_id, $embeddingStr]);
                    }
                } catch (\Throwable $e) {
                    // Non-fatal: embedding will be missing but summary is stored
                }

                $success++;

            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->warn("  Error for {$sess->agent_id}/{$sess->session_id}: {$e->getMessage()}");
            }

            $bar->advance();
            usleep(50000); // 50ms between to not hammer DB
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Sessions processed', $total],
                ['Summaries created', $success],
                ['Failed', $failed],
            ]
        );

        return $failed > 0 ? 1 : 0;
    }

    /**
     * @param  list<object>  $sessions
     * @return list<array{0:string,1:int,2:int,3:int}>
     */
    private function backfillPreviewRows(array $sessions): array
    {
        $byAgent = [];
        foreach ($sessions as $session) {
            $agentId = (string) ($session->agent_id ?? 'unknown');
            $byAgent[$agentId] ??= [
                'sessions' => 0,
                'episodes' => 0,
                'tokens' => 0,
            ];
            $byAgent[$agentId]['sessions']++;
            $byAgent[$agentId]['episodes'] += (int) ($session->episode_count ?? 0);
            $byAgent[$agentId]['tokens'] += (int) ($session->total_tokens ?? 0);
        }

        ksort($byAgent);

        return array_map(
            static fn (string $agentId, array $counts): array => [
                $agentId,
                (int) $counts['sessions'],
                (int) $counts['episodes'],
                (int) $counts['tokens'],
            ],
            array_keys($byAgent),
            $byAgent
        );
    }
}
