<?php

namespace App\Console\Commands;

use App\Services\Genealogy\HtrTranscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * N72 — Batch HTR transcription for genealogy_media.
 *
 * Finds document-type media records without a transcription and runs
 * TrOCR on them via HtrTranscriptionService. Writes results to
 * genealogy_media.transcription_text.
 *
 * Uses ComputeRouterService internally — runs on GPU if available, CPU fallback.
 */
class GenealogyTranscribeMedia extends Command
{
    protected $signature = 'genealogy:transcribe-media
                            {--tree=    : Scope to a specific tree ID}
                            {--media=   : Transcribe a single media ID}
                            {--limit=20 : Max records to process}
                            {--status   : Show pipeline status and exit}
                            {--dry-run  : Show eligible records without transcribing}';

    protected $description = 'N72: Batch HTR (TrOCR) transcription of handwritten genealogy documents';

    private const DOCUMENT_TYPES = ['document', 'certificate', 'census', 'military'];

    public function handle(HtrTranscriptionService $htr): int
    {
        if ($this->option('status')) {
            return $this->showStatus($htr);
        }

        $mediaId = $this->option('media');
        if (! $htr->isAvailable()) {
            $this->warn('HTR pipeline not available or currently busy; skipping scheduled transcription batch.');
            $this->line('Expected model: microsoft/trocr-base-handwritten (GPU) or trocr-small-handwritten (CPU)');

            return $mediaId ? 1 : 0;
        }

        // Single-media mode
        if ($mediaId) {
            return $this->handleSingle($htr, (int) $mediaId);
        }

        $treeId = $this->option('tree') ? (int) $this->option('tree') : null;
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = $this->option('dry-run');

        $records = $this->fetchEligible($treeId, $limit);

        if (empty($records)) {
            $this->info('No eligible media found'.($treeId ? " for tree {$treeId}" : '').'.');

            return 0;
        }

        $this->line('Found '.count($records).' eligible media records.'.($dryRun ? ' [DRY RUN]' : ''));

        if ($dryRun) {
            $rows = array_map(fn ($r) => [
                $r->id, $r->tree_id, $r->media_type, mb_substr($r->title ?? '—', 0, 40), $r->nextcloud_path ? 'yes' : 'no',
            ], $records);
            $this->table(['ID', 'Tree', 'Type', 'Title', 'Has Path'], $rows);

            return 0;
        }

        $processed = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($records as $record) {
            $result = $htr->transcribeGenealogyMedia((int) $record->id);

            if ($result === null) {
                $this->warn("  [{$record->id}] Skipped — path unresolvable or HTR unavailable");
                $skipped++;

                continue;
            }

            if (! empty($result['text'])) {
                $conf = round($result['confidence'] * 100);
                $this->line("  [{$record->id}] OK — {$result['line_count']} lines, {$conf}% confidence ({$result['device']})");
                $processed++;
            } else {
                $this->warn("  [{$record->id}] Empty output");
                $failed++;
            }
        }

        $this->newLine();
        $this->line("Done — processed: {$processed} | skipped: {$skipped} | failed: {$failed}");

        return $failed > 0 ? 1 : 0;
    }

    private function fetchEligible(?int $treeId, int $limit): array
    {
        $typePlaceholders = implode(',', array_fill(0, count(self::DOCUMENT_TYPES), '?'));
        $params = self::DOCUMENT_TYPES;

        $treeClause = '';
        if ($treeId) {
            $treeClause = ' AND gm.tree_id = ?';
            $params[] = $treeId;
        }

        $params[] = $limit;

        return DB::select("
            SELECT gm.id, gm.tree_id, gm.media_type, gm.title, gm.nextcloud_path
            FROM genealogy_media gm
            WHERE gm.media_type IN ({$typePlaceholders})
              AND gm.transcription_text IS NULL
              AND gm.file_exists = 1
              AND (gm.analysis_status IS NULL OR gm.analysis_status != 'skipped')
              {$treeClause}
            ORDER BY
              FIELD(gm.media_type, 'census', 'certificate', 'document', 'military'),
              gm.id ASC
            LIMIT ?
        ", $params);
    }

    private function handleSingle(HtrTranscriptionService $htr, int $mediaId): int
    {
        $record = DB::selectOne(
            'SELECT id, tree_id, media_type, title, transcription_text FROM genealogy_media WHERE id = ?',
            [$mediaId]
        );

        if (! $record) {
            $this->error("Media ID {$mediaId} not found.");

            return 1;
        }

        if ($record->transcription_text) {
            $this->warn("Media {$mediaId} already has a transcription (use --dry-run to inspect).");

            return 0;
        }

        $this->line("Transcribing media {$mediaId} ({$record->media_type}: {$record->title})...");
        $result = $htr->transcribeGenealogyMedia($mediaId);

        if (! $result) {
            $this->error('Transcription failed — check logs.');

            return 1;
        }

        $this->info('Confidence: '.round($result['confidence'] * 100)."% | Lines: {$result['line_count']} | Device: {$result['device']}");
        $this->line("\n--- TRANSCRIPTION ---");
        $this->line($result['text']);

        return 0;
    }

    private function showStatus(HtrTranscriptionService $htr): int
    {
        $status = $htr->getStatus();
        $this->line('HTR Pipeline Status:');
        $this->table(['Key', 'Value'], [
            ['Available', $status['installed'] ? 'YES' : 'NO'],
            ['Routed To', $status['routed_to'] ?? '—'],
            ['GPU Model', $status['gpu_model'] ?? 'CPU'],
            ['VRAM MB', $status['gpu_vram_mb'] ?? '—'],
            ['Host', $status['host'] ?? '—'],
            ['Model', $status['model']],
        ]);

        $pending = DB::selectOne(
            "SELECT COUNT(*) AS cnt FROM genealogy_media WHERE media_type IN ('document','certificate','census','military') AND transcription_text IS NULL AND file_exists = 1"
        );
        $done = DB::selectOne(
            'SELECT COUNT(*) AS cnt FROM genealogy_media WHERE transcription_text IS NOT NULL'
        );
        $this->line('Pending transcription: '.($pending->cnt ?? 0).' | Already transcribed: '.($done->cnt ?? 0));

        return 0;
    }
}
