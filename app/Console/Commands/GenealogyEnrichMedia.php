<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyMediaEnrichmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * N140 — Media → AI Vetting → Person Enrichment Pipeline command.
 */
class GenealogyEnrichMedia extends Command
{
    protected $signature = 'genealogy:enrich-media
                            {--tree=    : Tree ID (omit to run all trees)}
                            {--media=   : Process a single media ID}
                            {--preview  : Preview a transcript-backed intake packet for a single media ID}
                            {--limit=10 : Max records per tree}
                            {--dry-run  : Show eligible records without processing}
                            {--status   : Show pipeline stats and exit}
                            {--quarantined : Show skipped/quarantined media details in status mode}';

    protected $description = 'N140: Media → AI fact extraction → person enrichment proposals';

    public function handle(GenealogyMediaEnrichmentService $enricher): int
    {
        if ($this->option('status')) {
            return $this->showStatus();
        }

        if ($mediaId = $this->option('media')) {
            return $this->handleSingle($enricher, (int) $mediaId);
        }

        $treeId = $this->option('tree') ? (int) $this->option('tree') : null;
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = $this->option('dry-run');

        if ($treeId) {
            $results = [$treeId => $enricher->processBatch($treeId, $limit, $dryRun)];
        } else {
            $results = $enricher->processBatchAllTrees($limit, $dryRun);
        }

        foreach ($results as $tid => $result) {
            $this->line("Tree {$tid}:");
            if ($dryRun) {
                $this->line("  Eligible: {$result['eligible']}");
                foreach ($result['records'] as $r) {
                    $this->line("  [{$r['id']}] {$r['type']}: {$r['title']}");
                }

                continue;
            }
            $this->line("  Processed: {$result['processed']} | Skipped: {$result['skipped']} | Errors: {$result['errors']}");
            $this->line("  Proposals: {$result['proposals']} | New relationships: {$result['relationships']}");

            if (! empty($result['log'])) {
                $rows = array_map(fn ($l) => [
                    $l['media_id'],
                    $l['type'],
                    $l['skipped'] ? 'skipped' : ($l['success'] ? 'ok' : 'error'),
                    $l['proposals'] ?? 0,
                    $l['reason'] ?? ($l['matched'] ?? '—'),
                ], $result['log']);
                $this->table(['Media', 'Type', 'Result', 'Proposals', 'Detail'], $rows);
            }
        }

        return 0;
    }

    private function handleSingle(GenealogyMediaEnrichmentService $enricher, int $mediaId): int
    {
        $media = DB::selectOne(
            'SELECT id, media_type, title, analysis_status, enrichment_status FROM genealogy_media WHERE id = ?',
            [$mediaId]
        );

        if (! $media) {
            $this->error("Media {$mediaId} not found.");

            return 1;
        }

        $this->line("Processing media {$mediaId} ({$media->media_type}: {$media->title})");
        $this->line("analysis_status={$media->analysis_status} | enrichment_status=".($media->enrichment_status ?? 'null'));

        if ($this->option('preview')) {
            $result = $enricher->previewMediaIntakePacket($mediaId);
            if (! ($result['success'] ?? false)) {
                $this->error('Preview failed: '.($result['reason'] ?? 'unknown'));

                return 1;
            }

            $packet = (array) ($result['packet'] ?? []);
            $this->newLine();
            $this->info('Intake Preview');
            $this->line('Status: '.($packet['status'] ?? 'unknown'));
            $this->line('Proposal ready: '.(($packet['proposal_ready'] ?? false) ? 'yes' : 'no'));
            $this->line('Summary: '.($packet['packet_summary'] ?? ''));

            if (! empty($packet['page_anchors'])) {
                $this->line('Anchors:');
                foreach ($packet['page_anchors'] as $anchor) {
                    $this->line("  - {$anchor}");
                }
            }

            if (! empty($packet['person_candidates'])) {
                $rows = array_map(fn ($candidate) => [
                    $candidate['name'],
                    $candidate['match_type'],
                    $candidate['confidence'],
                    $candidate['matched_person_id'] ?? '—',
                    implode(', ', $candidate['pages'] ?? []),
                ], $packet['person_candidates']);
                $this->table(['Name', 'Match', 'Confidence', 'FT Person', 'Pages'], $rows);
            }

            if (! empty($packet['questions'])) {
                $this->line('Questions:');
                foreach ($packet['questions'] as $question) {
                    $this->line("  - {$question}");
                }
            }

            return 0;
        }

        $result = $enricher->processMedia($mediaId);

        if ($result['skipped']) {
            $this->warn('Skipped: '.($result['reason'] ?? 'unknown'));

            return 0;
        }
        if (! $result['success']) {
            $this->error('Failed: '.($result['reason'] ?? 'unknown'));

            return 1;
        }

        $this->info("Done — proposals: {$result['proposals']} | relationships: {$result['relationships']} | matched: {$result['matched']} | unmatched: {$result['unmatched']}");

        return 0;
    }

    private function showStatus(): int
    {
        $stats = DB::select("
            SELECT
                media_type,
                COUNT(*) AS total,
                SUM(CASE WHEN enrichment_status = 'completed' THEN 1 ELSE 0 END) AS enriched,
                SUM(CASE WHEN enrichment_status = 'failed'    THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN enrichment_status = 'skipped'   THEN 1 ELSE 0 END) AS skipped,
                SUM(CASE WHEN enrichment_status IS NULL AND analysis_status = 'completed' THEN 1 ELSE 0 END) AS pending
            FROM genealogy_media
            WHERE media_type IN ('obituary','census','certificate','document','military')
            GROUP BY media_type
            ORDER BY media_type
        ");

        $this->table(
            ['Type', 'Total', 'Enriched', 'Failed', 'Skipped', 'Pending'],
            array_map(fn ($r) => [$r->media_type, $r->total, $r->enriched, $r->failed, $r->skipped, $r->pending], $stats)
        );

        $proposals = DB::selectOne(
            "SELECT COUNT(*) AS cnt FROM genealogy_proposed_changes WHERE agent_id = 'genealogy-media-enrichment'"
        );
        $relationships = DB::selectOne(
            "SELECT COUNT(*) AS cnt FROM genealogy_proposed_relationships WHERE agent_id = 'genealogy-media-enrichment'"
        );

        $this->line('Total proposals created: '.($proposals->cnt ?? 0));
        $this->line('Total relationships proposed: '.($relationships->cnt ?? 0));

        $eligibilityStats = DB::select("
            SELECT reason, COUNT(*) AS total
            FROM (
                SELECT
                    CASE
                        WHEN media_type NOT IN ('obituary','census','certificate','document','military') THEN 'unsupported_media_type'
                        WHEN COALESCE(file_exists, 0) <> 1 THEN 'file_missing'
                        WHEN COALESCE(analysis_status, '') <> 'completed' THEN 'analysis_not_completed'
                        WHEN enrichment_status = 'processing' THEN 'already_processing'
                        WHEN enrichment_status = 'completed' THEN 'already_completed'
                        WHEN enrichment_status = 'skipped' THEN 'skipped_or_quarantined'
                        WHEN enrichment_status IS NULL THEN 'eligible_pending'
                        WHEN enrichment_status = 'failed' THEN 'eligible_retry_failed'
                        ELSE 'other_status'
                    END AS reason
                FROM genealogy_media
            ) AS eligibility
            GROUP BY reason
            ORDER BY total DESC, reason ASC
        ");

        $this->newLine();
        $this->info('Enrichment Eligibility By Reason');
        if ($eligibilityStats === []) {
            $this->line('No genealogy media records found.');
        } else {
            $this->table(
                ['Reason', 'Count'],
                array_map(fn ($r) => [$r->reason, $r->total], $eligibilityStats)
            );
        }

        if ($this->option('quarantined')) {
            $this->newLine();
            $this->info('Skipped / Quarantined By Reason');

            $quarantineStats = DB::select("
                SELECT
                    COALESCE(enrichment_error, 'unspecified') AS reason,
                    COUNT(*) AS total
                FROM genealogy_media
                WHERE enrichment_status = 'skipped'
                GROUP BY enrichment_error
                ORDER BY total DESC, reason ASC
            ");

            if ($quarantineStats === []) {
                $this->line('No skipped genealogy media records found.');
            } else {
                $this->table(
                    ['Reason', 'Count'],
                    array_map(fn ($r) => [$r->reason, $r->total], $quarantineStats)
                );
            }

            $recentSkipped = DB::select("
                SELECT id, media_type, title, enrichment_error, updated_at
                FROM genealogy_media
                WHERE enrichment_status = 'skipped'
                ORDER BY updated_at DESC
                LIMIT 10
            ");

            if ($recentSkipped !== []) {
                $this->newLine();
                $this->info('Recent Skipped Media');
                $this->table(
                    ['Media', 'Type', 'Title', 'Reason', 'Updated'],
                    array_map(fn ($r) => [
                        $r->id,
                        $r->media_type,
                        mb_strimwidth((string) $r->title, 0, 40, '...'),
                        $r->enrichment_error ?? 'unspecified',
                        $r->updated_at,
                    ], $recentSkipped)
                );
            }
        }

        return 0;
    }
}
