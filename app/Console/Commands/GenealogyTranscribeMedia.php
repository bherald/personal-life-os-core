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

    private const HTR_EXTENSIONS = ['jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp', 'webp'];

    private const HTR_MIME_TYPES = ['image/jpeg', 'image/png', 'image/tiff', 'image/bmp', 'image/webp'];

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
            SELECT gm.id, gm.tree_id, gm.media_type, gm.title, gm.nextcloud_path, gm.file_format, gm.mime_type
            FROM genealogy_media gm
            WHERE gm.media_type IN ({$typePlaceholders})
              AND (gm.transcription_text IS NULL OR TRIM(gm.transcription_text) = '')
              AND (gm.transcription IS NULL OR TRIM(gm.transcription) = '')
              AND gm.file_exists = 1
              AND {$this->htrFormatSql('gm')}
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
            'SELECT id, tree_id, media_type, title, transcription_text, nextcloud_path, local_filename, file_format, mime_type FROM genealogy_media WHERE id = ?',
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

        if (! $this->isHtrSupportedRecord($record)) {
            $this->warn("Media {$mediaId} is not an HTR image format; route it through text extraction/enrichment instead.");

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
        $missingTranscriptSql = "(transcription_text IS NULL OR TRIM(transcription_text) = '') AND (transcription IS NULL OR TRIM(transcription) = '')";
        $htrFormatSql = $this->htrFormatSql();
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
            "SELECT COUNT(*) AS cnt FROM genealogy_media WHERE media_type IN ('document','certificate','census','military') AND {$missingTranscriptSql} AND file_exists = 1 AND {$htrFormatSql}"
        );
        $done = DB::selectOne(
            "SELECT COUNT(*) AS cnt FROM genealogy_media WHERE NOT ({$missingTranscriptSql})"
        );
        $this->line('Pending transcription: '.($pending->cnt ?? 0).' | Already transcribed: '.($done->cnt ?? 0));

        $eligibilityStats = DB::select("
            SELECT reason, COUNT(*) AS total
            FROM (
                SELECT
                    CASE
                        WHEN media_type NOT IN ('document','certificate','census','military') THEN 'unsupported_media_type'
                        WHEN NOT ({$missingTranscriptSql}) THEN 'already_transcribed'
                        WHEN COALESCE(file_exists, 0) <> 1 THEN 'file_missing'
                        WHEN nextcloud_path IS NULL OR nextcloud_path = '' THEN 'path_missing'
                        WHEN analysis_status = 'skipped' THEN 'analysis_skipped'
                        WHEN NOT ({$htrFormatSql}) THEN 'unsupported_htr_format'
                        ELSE 'ready_for_htr'
                    END AS reason
                FROM genealogy_media
            ) AS htr_eligibility
            GROUP BY reason
            ORDER BY total DESC, reason ASC
        ");

        $this->newLine();
        $this->info('HTR Eligibility By Reason');
        if ($eligibilityStats === []) {
            $this->line('No genealogy media records found.');
        } else {
            $this->table(
                ['Reason', 'Count'],
                array_map(fn ($r) => [$r->reason, $r->total], $eligibilityStats)
            );
        }

        return 0;
    }

    private function htrFormatSql(string $alias = ''): string
    {
        $prefix = $alias !== '' ? $alias.'.' : '';
        $extensions = implode(',', array_map(fn (string $ext): string => "'{$ext}'", self::HTR_EXTENSIONS));
        $mimeTypes = implode(',', array_map(fn (string $mime): string => "'{$mime}'", self::HTR_MIME_TYPES));

        return "(LOWER(COALESCE({$prefix}file_format, SUBSTRING_INDEX({$prefix}local_filename, '.', -1), '')) IN ({$extensions})
            OR LOWER(COALESCE({$prefix}mime_type, '')) IN ({$mimeTypes}))";
    }

    private function isHtrSupportedRecord(object $record): bool
    {
        $extension = strtolower((string) ($record->file_format ?: pathinfo((string) ($record->local_filename ?? $record->nextcloud_path ?? ''), PATHINFO_EXTENSION)));
        $mimeType = strtolower((string) ($record->mime_type ?? ''));

        return in_array($extension, self::HTR_EXTENSIONS, true)
            || in_array($mimeType, self::HTR_MIME_TYPES, true);
    }
}
