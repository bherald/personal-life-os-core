<?php

namespace App\Console\Commands;

use App\Services\AgentProposalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * N51: Scan agent episodes for genealogy findings that were never
 * converted to review proposals. Resubmits orphaned findings via
 * AgentProposalService using the original episode data.
 */
class RetroactiveProposalCommand extends Command
{
    protected $signature = 'agent:retroactive-proposals
        {--days=90 : Look back N days for orphaned findings}
        {--limit=100 : Max episodes to process per run}
        {--dry-run : Show what would be submitted without creating proposals}
        {--stats : Show orphaned finding statistics only}';

    protected $description = 'Scan past agent episodes for genealogy findings never submitted to review queue';

    public function handle(): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $orphans = $this->findOrphanedFindings($days, $limit);

        if (empty($orphans)) {
            $this->info('No orphaned findings found.');
            return Command::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Found ' . count($orphans) . ' orphaned findings.');

        $submitted = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($orphans as $episode) {
            $details = json_decode($episode->details, true);
            if (!$details) {
                $skipped++;
                continue;
            }

            $personId = $details['person_id'] ?? null;
            $proposals = $details['proposals_generated'] ?? $details['proposals'] ?? [];

            if (!$personId || empty($proposals)) {
                $skipped++;
                continue;
            }

            // Get tree_id for this person
            $person = DB::selectOne(
                "SELECT tree_id, given_name, surname FROM genealogy_persons WHERE id = ?",
                [$personId]
            );

            if (!$person) {
                $this->warn("  Person #{$personId} not found, skipping episode #{$episode->id}");
                $skipped++;
                continue;
            }

            $personName = trim(($person->given_name ?? '') . ' ' . ($person->surname ?? ''));

            if ($dryRun) {
                $this->line("  Would submit " . count($proposals) . " proposals for {$personName} (#{$personId}) from episode #{$episode->id} ({$episode->created_at})");
                $submitted += count($proposals);
                continue;
            }

            try {
                $result = $this->submitProposals(
                    $proposals,
                    $personId,
                    (int) $person->tree_id,
                    $episode->agent_id,
                    (int) $episode->id
                );
                $submitted += $result['submitted'];
                $skipped += $result['skipped'];

                $this->info("  Episode #{$episode->id}: {$result['submitted']} submitted, {$result['skipped']} deduped for {$personName}");
            } catch (\Throwable $e) {
                $errors++;
                $this->error("  Episode #{$episode->id}: {$e->getMessage()}");
                Log::error('RetroactiveProposal: Error processing episode', [
                    'episode_id' => $episode->id,
                    'person_id' => $personId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Done. Submitted: {$submitted}, Skipped: {$skipped}, Errors: {$errors}");

        Log::info('RetroactiveProposal: Run complete', [
            'days' => $days,
            'orphans_found' => count($orphans),
            'submitted' => $submitted,
            'skipped' => $skipped,
            'errors' => $errors,
            'dry_run' => $dryRun,
        ]);

        return Command::SUCCESS;
    }

    private function findOrphanedFindings(int $days, int $limit): array
    {
        return DB::select("
            SELECT ae.id, ae.agent_id, ae.session_id, ae.summary, ae.details, ae.created_at
            FROM agent_episodes ae
            WHERE ae.event_type = 'finding'
              AND ae.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND JSON_EXTRACT(ae.details, '$.person_id') IS NOT NULL
              AND (
                  JSON_LENGTH(IFNULL(JSON_EXTRACT(ae.details, '$.proposals_generated'), '[]')) > 0
                  OR JSON_LENGTH(IFNULL(JSON_EXTRACT(ae.details, '$.proposals'), '[]')) > 0
              )
              AND NOT EXISTS (
                  SELECT 1 FROM agent_review_queue arq
                  WHERE arq.review_type = 'genealogy_finding'
                    AND JSON_EXTRACT(arq.details, '$.person_id') = JSON_EXTRACT(ae.details, '$.person_id')
                    AND arq.created_at BETWEEN DATE_SUB(ae.created_at, INTERVAL 2 HOUR) AND DATE_ADD(ae.created_at, INTERVAL 2 HOUR)
                    AND arq.status IN ('pending', 'approved', 'rejected')
              )
            ORDER BY ae.created_at DESC
            LIMIT ?
        ", [$days, $limit]);
    }

    private function submitProposals(array $proposals, int $personId, int $treeId, string $agentId, int $episodeId): array
    {
        $submitted = 0;
        $skipped = 0;

        $changes = [];
        $relationships = [];
        $marriages = [];

        foreach ($proposals as $p) {
            $type = $p['change_type'] ?? $p['type'] ?? $p['relationship_type'] ?? null;

            if (in_array($type, ['parent', 'child', 'sibling'])) {
                $relationships[] = array_merge($p, ['person_id' => $personId]);
            } elseif ($type === 'spouse') {
                $marriages[] = array_merge($p, ['person1_id' => $personId]);
            } else {
                $changes[] = array_merge($p, ['person_id' => $personId]);
            }
        }

        $proposalService = app(AgentProposalService::class);
        $context = ['tree_id' => $treeId, 'retroactive_episode_id' => $episodeId];

        $finalData = [
            'proposed_changes' => $changes,
            'proposed_relationships' => $relationships,
            'proposed_marriages' => $marriages,
        ];

        $report = $proposalService->processProposals($finalData, $agentId, $context);

        // Count results from report
        $submitted = substr_count($report, '[QUEUED]');
        $skipped = substr_count($report, '[SKIP]') + substr_count($report, '[DEDUP]') + substr_count($report, '[FAILED]');

        return ['submitted' => $submitted, 'skipped' => $skipped];
    }

    private function showStats(): int
    {
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total_findings,
                COUNT(DISTINCT JSON_EXTRACT(details, '$.person_id')) as unique_persons
            FROM agent_episodes
            WHERE event_type = 'finding'
              AND JSON_EXTRACT(details, '$.person_id') IS NOT NULL
              AND (
                  JSON_LENGTH(IFNULL(JSON_EXTRACT(details, '$.proposals_generated'), '[]')) > 0
                  OR JSON_LENGTH(IFNULL(JSON_EXTRACT(details, '$.proposals'), '[]')) > 0
              )
        ");

        $orphans = DB::selectOne("
            SELECT COUNT(*) as orphan_count
            FROM agent_episodes ae
            WHERE ae.event_type = 'finding'
              AND JSON_EXTRACT(ae.details, '$.person_id') IS NOT NULL
              AND (
                  JSON_LENGTH(IFNULL(JSON_EXTRACT(ae.details, '$.proposals_generated'), '[]')) > 0
                  OR JSON_LENGTH(IFNULL(JSON_EXTRACT(ae.details, '$.proposals'), '[]')) > 0
              )
              AND NOT EXISTS (
                  SELECT 1 FROM agent_review_queue arq
                  WHERE arq.review_type = 'genealogy_finding'
                    AND JSON_EXTRACT(arq.details, '$.person_id') = JSON_EXTRACT(ae.details, '$.person_id')
                    AND arq.created_at BETWEEN DATE_SUB(ae.created_at, INTERVAL 2 HOUR) AND DATE_ADD(ae.created_at, INTERVAL 2 HOUR)
                    AND arq.status IN ('pending', 'approved', 'rejected')
              )
        ");

        $reviewQueue = DB::selectOne("
            SELECT
                COUNT(*) as total_reviews,
                SUM(status = 'pending') as pending,
                SUM(status = 'approved') as approved,
                SUM(status = 'rejected') as rejected
            FROM agent_review_queue
            WHERE review_type = 'genealogy_finding'
        ");

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total finding episodes (with proposals)', $stats->total_findings ?? 0],
                ['Unique persons with findings', $stats->unique_persons ?? 0],
                ['Orphaned findings (no review item)', $orphans->orphan_count ?? 0],
                ['---', '---'],
                ['Total genealogy review items', $reviewQueue->total_reviews ?? 0],
                ['  Pending', $reviewQueue->pending ?? 0],
                ['  Approved', $reviewQueue->approved ?? 0],
                ['  Rejected', $reviewQueue->rejected ?? 0],
            ]
        );

        return Command::SUCCESS;
    }
}
