<?php

namespace App\Console\Commands;

use App\Services\AgentProceduralMemoryService;
use App\Services\AIService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProceduralMemoryCommand extends Command
{
    protected $signature = 'agent:procedures
        {--stats : Show procedural memory statistics}
        {--consolidate : Run consolidation (merge, retire, promote)}
        {--agent= : Filter by agent ID}
        {--list : List active procedures}
        {--retired : Include retired procedures in list}
        {--backfill-embeddings : Generate embeddings for procedures missing them}
        {--limit=100 : Max procedures to backfill per run}
        {--json : Emit JSON output for read-only stats}
        {--compact : Emit compact JSON for read-only stats}';

    protected $description = 'Manage agent procedural memory — stats, consolidation, listing, embedding backfill';

    public function handle(AgentProceduralMemoryService $service): int
    {
        if (($this->option('json') || $this->option('compact')) && ($this->option('backfill-embeddings') || $this->option('consolidate') || $this->option('list'))) {
            $this->error('The --json/--compact options are only supported for read-only stats.');

            return 1;
        }

        if ($this->option('backfill-embeddings')) {
            return $this->backfillEmbeddings($service);
        }

        if ($this->option('consolidate')) {
            return $this->runConsolidation($service);
        }

        if ($this->option('list')) {
            return $this->listProcedures($service);
        }

        // Default: show stats
        return $this->showStats($service);
    }

    private function showStats(AgentProceduralMemoryService $service): int
    {
        $agentId = $this->option('agent');
        $result = $service->procedureStats(['agent_id' => $agentId]);

        if (! $result['success']) {
            if ($this->option('json')) {
                $this->line($this->encodeJson([
                    'generated_at' => now()->toIso8601String(),
                    'command' => 'agent:procedures',
                    'mode' => 'stats',
                    'compact' => (bool) $this->option('compact'),
                    'status' => 'error',
                    'agent_id' => $agentId,
                    'error' => $result['error'] ?? 'Failed to get stats',
                ]));

                return 1;
            }

            $this->error($result['error'] ?? 'Failed to get stats');

            return 1;
        }

        if ($this->option('json')) {
            $this->line($this->encodeJson($this->statsPayload($result, $agentId)));

            return 0;
        }

        $this->info($result['result_text']);

        return 0;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function statsPayload(array $result, ?string $agentId): array
    {
        $stats = (array) ($result['stats'] ?? []);
        $perAgent = array_values((array) ($result['per_agent'] ?? []));

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'command' => 'agent:procedures',
            'mode' => 'stats',
            'compact' => (bool) $this->option('compact'),
            'status' => 'pass',
            'agent_id' => $agentId,
            'summary' => [
                'total' => (int) ($stats['total'] ?? 0),
                'active' => (int) ($stats['active'] ?? 0),
                'retired' => (int) ($stats['retired'] ?? 0),
                'canonical' => (int) ($stats['canonical'] ?? 0),
                'failure_memories' => (int) ($stats['failure_memories'] ?? 0),
                'avg_success_rate' => (float) ($stats['avg_success_rate'] ?? 0),
                'total_uses' => (int) ($stats['total_uses'] ?? 0),
                'agents_with_memory' => (int) ($stats['agents_with_memory'] ?? 0),
                'agent_count' => count($perAgent),
            ],
        ];

        if (! $this->option('compact')) {
            $payload['per_agent'] = $perAgent;

            return $payload;
        }

        $payload['top_agents'] = array_slice($perAgent, 0, 10);

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

    private function runConsolidation(AgentProceduralMemoryService $service): int
    {
        $this->info('Running procedural memory consolidation...');
        $stats = $service->consolidate();

        $this->table(
            ['Action', 'Count'],
            [
                ['Merged (duplicates)', $stats['merged']],
                ['Retired (stale/low)', $stats['retired']],
                ['Promoted (canonical)', $stats['promoted']],
                ['Total active', $stats['total_active']],
                ['Total retired', $stats['total_retired']],
            ]
        );

        if (isset($stats['error'])) {
            $this->error("Error: {$stats['error']}");

            return 1;
        }

        $this->info('Consolidation complete.');

        return 0;
    }

    private function listProcedures(AgentProceduralMemoryService $service): int
    {
        $filters = [];

        if ($agentId = $this->option('agent')) {
            $filters['agent_id'] = $agentId;
        }

        if (! $this->option('retired')) {
            $filters['is_retired'] = 0;
        }

        $procedures = $service->getProcedures($filters);

        if (empty($procedures)) {
            $this->info('No procedures found.');

            return 0;
        }

        $rows = [];
        foreach ($procedures as $proc) {
            $tools = array_column($proc['action_sequence'] ?? [], 'tool');
            $rows[] = [
                $proc['id'],
                $proc['agent_id'],
                substr($proc['name'], 0, 40),
                $proc['procedure_type'],
                round(($proc['success_rate'] ?? 0) * 100).'%',
                $proc['times_used'],
                $proc['is_canonical'] ? 'Y' : '',
                $proc['is_retired'] ? 'Y' : '',
                implode(' → ', array_slice($tools, 0, 4)).(count($tools) > 4 ? '...' : ''),
            ];
        }

        $this->table(
            ['ID', 'Agent', 'Name', 'Type', 'Rate', 'Used', 'Canon', 'Retired', 'Tools'],
            $rows
        );

        return 0;
    }

    private function backfillEmbeddings(AgentProceduralMemoryService $service): int
    {
        $limit = (int) $this->option('limit');
        $agentId = $this->option('agent');

        // Get existing embeddings from PostgreSQL
        $existingIds = [];
        try {
            $existing = DB::connection('pgsql_rag')->select('
                SELECT procedure_id FROM agent_procedure_embeddings
            ');
            $existingIds = array_column(array_map(fn ($r) => (array) $r, $existing), 'procedure_id');
        } catch (\Throwable $e) {
            $this->warn("Could not query existing embeddings: {$e->getMessage()}");
        }

        // Get all active procedures from MySQL
        $whereAgent = $agentId ? 'AND agent_id = ?' : '';
        $mysqlBindings = $agentId ? [$agentId] : [];

        $allProcedures = DB::select("
            SELECT id, agent_id, trigger_pattern, name
            FROM agent_procedures
            WHERE is_retired = 0
              {$whereAgent}
            ORDER BY times_used DESC, success_rate DESC
        ", $mysqlBindings);

        // Filter to those without embeddings
        $toBackfill = array_filter($allProcedures, fn ($p) => ! in_array($p->id, $existingIds));
        $toBackfill = array_values(array_slice($toBackfill, 0, $limit));

        $total = count($toBackfill);
        $totalActive = count($allProcedures);
        $alreadyEmbedded = count($existingIds);

        $this->info("Backfill: {$total} procedures need embeddings ({$alreadyEmbedded}/{$totalActive} already embedded)");

        if ($total === 0) {
            $this->info('All active procedures already have embeddings.');

            return 0;
        }

        $aiService = app(AIService::class);
        $success = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($toBackfill as $proc) {
            try {
                $result = $aiService->generateEmbedding($proc->trigger_pattern);

                if (($result['success'] ?? false) && ! empty($result['embedding'])) {
                    $embeddingStr = '['.implode(',', $result['embedding']).']';

                    DB::connection('pgsql_rag')->statement('
                        INSERT INTO agent_procedure_embeddings
                            (procedure_id, agent_id, embedding, created_at, updated_at)
                        VALUES (?, ?, ?::vector, NOW(), NOW())
                        ON CONFLICT (procedure_id) DO UPDATE SET
                            embedding = EXCLUDED.embedding,
                            updated_at = NOW()
                    ', [$proc->id, $proc->agent_id, $embeddingStr]);

                    $success++;
                } else {
                    $failed++;
                    $this->newLine();
                    $this->warn("  Failed #{$proc->id}: ".($result['error'] ?? 'no embedding'));
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->warn("  Error #{$proc->id}: {$e->getMessage()}");
            }

            $bar->advance();
            usleep(100000); // 100ms between requests to not hammer Ollama
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total processed', $total],
                ['Successfully embedded', $success],
                ['Failed', $failed],
                ['Total now embedded', $alreadyEmbedded + $success],
                ['Total active procedures', $totalActive],
            ]
        );

        return $failed > 0 ? 1 : 0;
    }
}
