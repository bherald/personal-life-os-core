<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyDocumentIngestionService;
use App\Services\Genealogy\GenealogyIntakeReferenceCopyService;
use App\Services\Genealogy\GenealogyIntakeRunStoreService;
use App\Services\Genealogy\GenealogyIntakeRunSummaryService;
use App\Services\Genealogy\GenealogyIntakeStagingService;
use App\Services\Genealogy\GenealogyStagedPacketPreviewService;
use App\Services\Genealogy\TreeManagementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * N135 — Document Ingestion Pipeline command.
 *
 * Scans a Nextcloud folder for genealogy documents and imports them into
 * genealogy_media so the N140 enrichment pipeline can process them.
 */
class GenealogyIngestDocuments extends Command
{
    protected $signature = 'genealogy:ingest-documents
                            {--tree=4       : Tree ID to assign ingested media to}
                            {--folder= : Nextcloud folder to scan (default: configured genealogy root)}
                            {--limit=100    : Max new records to create per run}
                            {--dry-run      : Show eligible files without importing}
                            {--stage        : Preview packet/document staging for the selected scope}
                            {--packet-label= : Force one packet label for the staged scope}
                            {--preview-packet= : Preview one staged packet by label; defaults to the first staged packet}
                            {--copy-preview : Evaluate reference-copy actions for the selected staged packet without writing}
                            {--apply-copies : Execute ready reference-copy actions for the selected staged packet}
                            {--save-run     : Persist the staged scope snapshot for later resume}
                            {--resume-run=  : Resume a previously saved staged intake run by run key}
                            {--report       : Show saved intake run health summaries}
                            {--list-trees   : Show available genealogy trees}
                            {--unprocessed-only : Stage only files not already represented in the listing metadata}
                            {--status       : Show ingestion + enrichment pipeline stats}
                            {--ai-classify  : Use AI to classify ambiguous filenames}';

    protected $description = 'N135: Scan Nextcloud for genealogy documents and seed genealogy_media for N140 enrichment';

    public function handle(
        GenealogyDocumentIngestionService $ingester,
        GenealogyIntakeRunStoreService $runStore,
        GenealogyIntakeReferenceCopyService $referenceCopies,
        GenealogyIntakeStagingService $staging,
        GenealogyStagedPacketPreviewService $packetPreview,
        GenealogyIntakeRunSummaryService $runSummary,
        TreeManagementService $trees
    ): int {
        $treeId = (int) $this->option('tree');
        $folder = $this->option('folder') ?: config('genealogy.nextcloud_root', '/Library/Genealogy');
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = $this->option('dry-run');
        $aiClassify = $this->option('ai-classify');
        $resumeRun = trim((string) $this->option('resume-run'));

        if ($this->option('status')) {
            return $this->showStatus($ingester, $treeId);
        }

        if ($this->option('list-trees')) {
            return $this->showTreeList($trees);
        }

        if ($this->option('report')) {
            return $this->showRunReport($runStore, $runSummary, $treeId, $limit, $resumeRun);
        }

        if ($resumeRun !== '') {
            return $this->showSavedRunPreview($runStore, $packetPreview, $referenceCopies, $resumeRun);
        }

        $tree = DB::selectOne('SELECT id, name FROM genealogy_trees WHERE id = ?', [$treeId]);
        if (! $tree) {
            $this->error("Tree #{$treeId} not found.");
            $availableTrees = $trees->listTrees();
            if ($availableTrees === []) {
                $this->line('No genealogy trees are available in this environment.');
                $this->line('Use a seeded/local tree before staging, or run `--list-trees` to confirm.');
            } else {
                $this->line('Available trees:');
                $rows = array_map(static fn ($row): array => [
                    (int) ($row->id ?? 0),
                    (string) ($row->name ?? 'unknown'),
                ], array_slice($availableTrees, 0, 10));
                $this->table(['Tree ID', 'Name'], $rows);
                $this->line('Re-run with `--tree={id}` or use `--list-trees` for the full list.');
            }

            return Command::FAILURE;
        }

        $this->info("N135: Document ingestion — tree #{$treeId} ({$tree->name})");
        $this->info("  Folder : {$folder}");
        $this->info("  Limit  : {$limit}");
        if ($dryRun) {
            $this->warn('  DRY RUN — no records will be created');
        }
        if ($aiClassify) {
            $this->info('  AI classify : enabled');
        }
        if ($this->option('stage')) {
            $this->info('  Mode   : packet staging preview');
        }
        $this->newLine();

        if ($this->option('stage')) {
            return $this->showStagingPreview($staging, $runStore, $packetPreview, $referenceCopies, $treeId, $folder, $limit);
        }

        $result = $ingester->ingestFolder($treeId, $folder, $limit, $dryRun, $aiClassify);

        if (isset($result['error'])) {
            $this->error('Ingestion failed: '.$result['error']);

            return Command::FAILURE;
        }

        if ($dryRun && ! empty($result['dry_run'])) {
            $rows = array_map(fn ($r) => [
                $r['type'],
                number_format($r['size']),
                $r['path'],
            ], $result['dry_run']);
            $this->table(['Type', 'Size (bytes)', 'Path'], $rows);
        }

        $this->table(['Metric', 'Value'], [
            ['Files scanned',  $result['scanned']],
            ['Imported',       $result['imported']],
            ['Skipped (dupe/photo/ext)', $result['skipped']],
            ['Failed',         $result['failed']],
        ]);

        if (! empty($result['errors'])) {
            $this->warn('Errors:');
            foreach ($result['errors'] as $err) {
                $this->line("  {$err['path']}: {$err['error']}");
            }
        }

        if (! $dryRun && $result['imported'] > 0) {
            $this->info("{$result['imported']} records created — N140 enrichment will process them next run.");
        }

        return Command::SUCCESS;
    }

    private function showTreeList(TreeManagementService $trees): int
    {
        $rows = array_values($trees->listTrees());
        if ($rows === []) {
            $this->info('No genealogy trees found.');

            return Command::SUCCESS;
        }

        $this->info('Available Genealogy Trees');
        $this->table(['Tree ID', 'Name', 'Description', 'People', 'Families', 'Sources'], array_map(
            static fn ($row): array => [
                (int) ($row->id ?? 0),
                (string) ($row->name ?? 'unknown'),
                (string) ($row->description ?? ''),
                (int) ($row->person_count ?? 0),
                (int) ($row->family_count ?? 0),
                (int) ($row->source_count ?? 0),
            ],
            $rows
        ));

        return Command::SUCCESS;
    }

    private function showStagingPreview(
        GenealogyIntakeStagingService $staging,
        GenealogyIntakeRunStoreService $runStore,
        GenealogyStagedPacketPreviewService $packetPreview,
        GenealogyIntakeReferenceCopyService $referenceCopies,
        int $treeId,
        string $folder,
        int $limit
    ): int {
        $result = $staging->stageScope($treeId, $folder, $limit, [
            'packet_label' => $this->option('packet-label'),
            'unprocessed_only' => $this->option('unprocessed-only'),
        ]);

        if (! ($result['success'] ?? false)) {
            $this->error('Staging failed: '.($result['error'] ?? 'unknown'));

            return Command::FAILURE;
        }

        if ($this->option('save-run')) {
            $saved = $runStore->saveStagedRun($result + ['status' => 'staged']);
            if (! ($saved['success'] ?? false)) {
                $this->error('Failed to save run: '.($saved['error'] ?? 'unknown'));

                return Command::FAILURE;
            }
        }

        $this->table(['Metric', 'Value'], [
            ['Run Key', $result['run_key']],
            ['Root Path', $result['root_path']],
            ['Files Staged', $result['file_count']],
            ['Packets', $result['packet_count']],
            ['Packet Label Override', $result['packet_label'] ?? '—'],
            ['Saved Run', $this->option('save-run') ? 'yes' : 'no'],
        ]);

        if (! empty($result['packets'])) {
            $rows = array_map(fn (array $packet): array => [
                $packet['packet_label'],
                $packet['packet_type'],
                $packet['file_count'],
                $packet['estimated_pages'],
                $packet['folder'],
            ], $result['packets']);

            $this->table(['Packet', 'Type', 'Files', 'Est. Pages', 'Folder'], $rows);
        }

        $selectedPacket = $packetPreview->selectPacket($result, $this->option('preview-packet'));
        if ($selectedPacket !== null) {
            $preview = $packetPreview->previewPacket($selectedPacket);
            $packet = (array) ($preview['preview'] ?? []);

            $this->newLine();
            $this->info('Packet Preview');
            $this->line('Packet: '.($preview['packet_label'] ?? 'unknown'));
            $this->line('Documents: '.($preview['document_count'] ?? 0));
            $this->line('Status: '.($packet['status'] ?? 'unknown'));
            $this->line('Summary: '.($packet['packet_summary'] ?? ''));
            $registration = (array) ($preview['registration'] ?? []);
            $this->renderRegistrationPlan($registration);
            $this->renderCopyExecution(
                $referenceCopies,
                $runStore,
                $this->option('save-run') ? (string) ($result['run_key'] ?? '') : null,
                $registration
            );

            if (! empty($packet['questions'])) {
                $this->line('Questions:');
                foreach ($packet['questions'] as $question) {
                    $this->line("  - {$question}");
                }
            }
        }

        return Command::SUCCESS;
    }

    private function showSavedRunPreview(
        GenealogyIntakeRunStoreService $runStore,
        GenealogyStagedPacketPreviewService $packetPreview,
        GenealogyIntakeReferenceCopyService $referenceCopies,
        string $runKey
    ): int {
        $loaded = $runStore->getRun($runKey);
        if (! ($loaded['success'] ?? false)) {
            $this->error('Resume failed: '.($loaded['error'] ?? 'unknown'));

            return Command::FAILURE;
        }

        $result = (array) ($loaded['run'] ?? []);

        $this->table(['Metric', 'Value'], [
            ['Run Key', $result['run_key'] ?? $runKey],
            ['Root Path', $result['root_path'] ?? '—'],
            ['Files Staged', $result['file_count'] ?? 0],
            ['Packets', $result['packet_count'] ?? 0],
            ['Saved Status', $result['status'] ?? 'staged'],
        ]);

        $selectedPacket = $packetPreview->selectPacket($result, $this->option('preview-packet'));
        if ($selectedPacket !== null) {
            $preview = $packetPreview->previewPacket($selectedPacket);
            $packet = (array) ($preview['preview'] ?? []);

            $this->newLine();
            $this->info('Packet Preview');
            $this->line('Packet: '.($preview['packet_label'] ?? 'unknown'));
            $this->line('Documents: '.($preview['document_count'] ?? 0));
            $this->line('Status: '.($packet['status'] ?? 'unknown'));
            $this->line('Summary: '.($packet['packet_summary'] ?? ''));
            $registration = (array) ($preview['registration'] ?? []);
            $this->renderRegistrationPlan($registration);
            $this->renderCopyExecution($referenceCopies, $runStore, $runKey, $registration);
        }

        return Command::SUCCESS;
    }

    private function showRunReport(
        GenealogyIntakeRunStoreService $runStore,
        GenealogyIntakeRunSummaryService $runSummary,
        int $treeId,
        int $limit,
        string $runKey
    ): int {
        if ($runKey !== '') {
            $loaded = $runStore->getRun($runKey);
            if (! ($loaded['success'] ?? false)) {
                $this->error('Report failed: '.($loaded['error'] ?? 'unknown'));

                return Command::FAILURE;
            }

            $run = (array) ($loaded['run'] ?? []);
            $summary = $runSummary->summarizeRun($run);
            $proposalReadiness = (array) ($summary['proposal_readiness'] ?? []);
            $applyProgress = (array) ($summary['approval_apply_progress'] ?? []);

            $this->info('Saved Intake Run Report');
            $this->table(['Metric', 'Value'], [
                ['Run Key', $run['run_key'] ?? $runKey],
                ['Tree ID', $run['tree_id'] ?? $treeId],
                ['Root Path', $run['root_path'] ?? '—'],
                ['Saved Status', $run['status'] ?? 'staged'],
                ['Updated At', $summary['updated_at'] ?? '—'],
                ['Run Health', $summary['run_health'] ?? 'unknown'],
                ['Next Action', $summary['next_action'] ?? '—'],
                ['Packets', $summary['packet_totals']['total_packets'] ?? 0],
            ]);

            $this->table(['Metric', 'Value'], [
                ['Copied', $summary['copy_progress']['copied'] ?? 0],
                ['Already In Place', $summary['copy_progress']['already_in_place'] ?? 0],
                ['Blocked Conflicts', $summary['copy_progress']['blocked_conflicts'] ?? 0],
                ['Copy Failures', $summary['copy_progress']['failed'] ?? 0],
                ['Proposal Ready', ($proposalReadiness['ready_packets'] ?? 0).' / '.($proposalReadiness['total_packets'] ?? 0)],
                ['Approved Packets', $proposalReadiness['approved_packets'] ?? 0],
                ['Applied Packets', $applyProgress['applied_packets'] ?? 0],
                ['Apply Pending', $applyProgress['pending_packets'] ?? 0],
            ]);

            $blockedPackets = array_values((array) ($summary['blocked_packets'] ?? []));
            if ($blockedPackets !== []) {
                $this->line('Blocked Packets: '.implode(', ', $blockedPackets));
            }

            $packetRows = array_map(fn (array $packet): array => [
                $packet['packet_label'] ?? 'unknown',
                (string) (($packet['preview_state']['status'] ?? '—')),
                ! empty($packet['preview_state']['proposal_ready']) ? 'yes' : 'no',
                (string) (($packet['review_decision']['decision'] ?? 'pending')),
                $this->describePacketCopyState($packet),
                (string) (($packet['proposal_generation_state']['status'] ?? '—')),
                (string) (($packet['approval_apply_state']['status'] ?? '—')),
            ], array_values((array) ($run['packets'] ?? [])));

            if ($packetRows !== []) {
                $this->newLine();
                $this->info('Packets');
                $this->table(['Packet', 'Preview', 'Proposal Ready', 'Decision', 'Copy', 'Generated', 'Apply'], $packetRows);
            }

            return Command::SUCCESS;
        }

        $listed = $runStore->listRuns($treeId, max(1, min($limit, 200)));
        if (! ($listed['success'] ?? false)) {
            $this->error('Report failed: '.($listed['error'] ?? 'unknown'));

            return Command::FAILURE;
        }

        $runs = array_values((array) ($listed['runs'] ?? []));
        if ($runs === []) {
            $this->info('No saved intake runs found.');

            return Command::SUCCESS;
        }

        $rows = array_map(function (array $run) use ($runSummary): array {
            $summary = $runSummary->summarizeRunListItem($run);
            $copy = (array) ($summary['copy_progress'] ?? []);

            return [
                $run['run_key'] ?? 'unknown',
                $run['status'] ?? 'staged',
                $summary['run_health'] ?? 'unknown',
                $summary['next_action'] ?? '—',
                $run['updated_at'] ?? '—',
                sprintf(
                    '%d/%d/%d/%d',
                    (int) ($copy['copied'] ?? 0),
                    (int) ($copy['already_in_place'] ?? 0),
                    (int) ($copy['blocked_conflicts'] ?? 0),
                    (int) ($copy['failed'] ?? 0)
                ),
            ];
        }, $runs);

        $this->info('Saved Intake Runs');
        $this->table(['Run Key', 'Status', 'Health', 'Next Action', 'Updated At', 'Copy C/A/B/F'], $rows);
        $this->line('Use --report --resume-run={run_key} for packet-level detail.');

        return Command::SUCCESS;
    }

    private function renderRegistrationPlan(array $registration): void
    {
        if ($registration === []) {
            return;
        }

        $referenceCopyRoot = $registration['reference_copy_root'] ?? null;
        $copyStatus = $registration['copy_status'] ?? null;
        if ($copyStatus) {
            $this->line('Copy Status: '.$copyStatus);
        }
        if ($referenceCopyRoot) {
            $this->line('FT Copy Root: '.$referenceCopyRoot);
        }

        $documents = array_slice(array_values((array) ($registration['documents'] ?? [])), 0, 3);
        if ($documents === []) {
            return;
        }

        $this->line('Planned Documents:');
        foreach ($documents as $document) {
            $this->line(sprintf(
                '  - %s -> %s (%s page%s, %s)',
                $document['source_name'] ?? 'document',
                $document['reference_copy_path'] ?? 'n/a',
                $document['page_count'] ?? 0,
                ((int) ($document['page_count'] ?? 0)) === 1 ? '' : 's',
                ($document['copy_plan']['status'] ?? $document['duplicate_scope'] ?? 'unknown')
            ));
        }
    }

    private function renderCopyExecution(
        GenealogyIntakeReferenceCopyService $referenceCopies,
        GenealogyIntakeRunStoreService $runStore,
        ?string $runKey,
        array $registration
    ): void {
        if (! $this->option('copy-preview') && ! $this->option('apply-copies')) {
            return;
        }

        $execution = $referenceCopies->executeRegistrationPlan($registration, (bool) $this->option('apply-copies'));
        $summary = (array) ($execution['summary'] ?? []);

        $this->newLine();
        $this->info($this->option('apply-copies') ? 'Reference Copy Execution' : 'Reference Copy Preview');
        $this->table(['Metric', 'Value'], [
            ['Mode', $this->option('apply-copies') ? 'apply' : 'preview'],
            ['Copied', $summary['copied'] ?? 0],
            ['Already In Place', $summary['already_in_place'] ?? 0],
            ['Blocked Conflicts', $summary['blocked_conflicts'] ?? 0],
            ['Skipped', $summary['skipped'] ?? 0],
            ['Failed', $summary['failed'] ?? 0],
        ]);

        foreach (array_slice(array_values((array) ($execution['results'] ?? [])), 0, 5) as $result) {
            $this->line(sprintf(
                '  - %s: %s (%s)',
                $result['source_name'] ?? 'document',
                $result['action'] ?? 'unknown',
                $result['reference_copy_path'] ?? 'n/a'
            ));
        }

        if ($this->option('apply-copies') && $runKey) {
            $saved = $runStore->recordCopyExecution($runKey, $registration, $execution);
            $this->line('Run Snapshot: '.(($saved['success'] ?? false) ? 'updated' : 'update_failed'));
        }
    }

    private function showStatus(GenealogyDocumentIngestionService $ingester, int $treeId): int
    {
        $stats = $ingester->getStats($treeId);

        $this->info("N135 Document Ingestion — Tree #{$treeId}");
        $this->newLine();

        // Unified pipeline view — joins file-scanning pipeline counts with
        // genealogy ingest counts so the operator sees the full funnel in
        // one place. Win/win: any drop between stages points at the exact
        // enrichment job that needs attention.
        $pipeline = $this->loadPipelineCounts($treeId);
        $this->info('Pipeline funnel:');
        $this->table(
            ['Stage', 'Count', 'Δ vs previous'],
            array_map(static function (array $row): array {
                return [
                    $row['stage'],
                    number_format((int) $row['count']),
                    $row['delta'] === null ? '' : ($row['delta'] >= 0 ? '+'.number_format($row['delta']) : number_format($row['delta'])),
                ];
            }, $pipeline)
        );

        $this->info('Records by type:');
        $rows = array_map(fn ($r) => [
            $r->media_type,
            $r->total,
            $r->enriched,
            $r->pending_enrichment,
            $r->failed,
        ], $stats['by_type']);
        $this->table(['Type', 'Total', 'Enriched', 'Pending N140', 'Failed'], $rows);

        if (! empty($stats['recent'])) {
            $this->info('10 most recently ingested:');
            $rows = array_map(fn ($r) => [
                $r->id,
                $r->media_type,
                $r->enrichment_status ?? 'pending',
                $r->imported_at,
                mb_strimwidth($r->local_filename, 0, 60, '…'),
            ], $stats['recent']);
            $this->table(['ID', 'Type', 'Enrich Status', 'Imported At', 'Filename'], $rows);
        }

        return Command::SUCCESS;
    }

    /**
     * Build the unified pipeline funnel rows: files on disk (file_registry)
     * → extracted by file_enrich_ai → linked in genealogy_media → bonded to
     * persons. Each count is independent; delta shows drop vs the previous
     * stage so the operator spots where the pipeline is losing rows.
     *
     * @return array<int, array{stage: string, count: int, delta: ?int}>
     */
    private function loadPipelineCounts(int $treeId): array
    {
        $rootPrefix = (string) ($this->option('folder') ?: config('genealogy.nextcloud_root', '/Library/Genealogy'));
        $like = rtrim($rootPrefix, '/').'/%';

        $registryCount = (int) (DB::selectOne(
            'SELECT COUNT(*) AS n FROM file_registry WHERE current_path LIKE ?',
            [$like]
        )->n ?? 0);

        $registryExtractedCount = (int) (DB::selectOne(
            'SELECT COUNT(*) AS n FROM file_registry WHERE current_path LIKE ? AND ai_detected_text IS NOT NULL',
            [$like]
        )->n ?? 0);

        $mediaCount = (int) (DB::selectOne(
            'SELECT COUNT(*) AS n FROM genealogy_media WHERE tree_id = ?',
            [$treeId]
        )->n ?? 0);

        $bondedCount = (int) (DB::selectOne(
            'SELECT COUNT(DISTINCT media_id) AS n
               FROM genealogy_person_media gpm
               JOIN genealogy_media gm ON gm.id = gpm.media_id
              WHERE gm.tree_id = ?',
            [$treeId]
        )->n ?? 0);

        $raw = [
            ['stage' => "file_registry (under {$rootPrefix})", 'count' => $registryCount],
            ['stage' => 'extracted by file_enrich_ai',        'count' => $registryExtractedCount],
            ['stage' => 'linked in genealogy_media',           'count' => $mediaCount],
            ['stage' => 'bonded to persons',                   'count' => $bondedCount],
        ];

        $rows = [];
        $prev = null;
        foreach ($raw as $row) {
            $delta = $prev === null ? null : ($row['count'] - $prev);
            $rows[] = ['stage' => $row['stage'], 'count' => $row['count'], 'delta' => $delta];
            $prev = $row['count'];
        }

        return $rows;
    }

    private function describePacketCopyState(array $packet): string
    {
        $summary = (array) ($packet['reference_copy_execution']['execution']['summary'] ?? []);
        if ($summary === []) {
            return 'pending';
        }

        $blocked = (int) ($summary['blocked_conflicts'] ?? 0);
        $failed = (int) ($summary['failed'] ?? 0);
        if ($blocked > 0 || $failed > 0) {
            return 'attention';
        }

        return 'ready';
    }
}
