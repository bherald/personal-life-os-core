<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GenealogyMediaIntakeReportService
{
    private const REPORT_VERSION = 1;

    private const ENRICHMENT_TYPES = ['obituary', 'census', 'certificate', 'document', 'military'];

    private const HTR_TYPES = ['document', 'certificate', 'census', 'military'];

    private const HTR_EXTENSIONS = ['jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp', 'webp'];

    private const HTR_MIME_TYPES = ['image/jpeg', 'image/png', 'image/tiff', 'image/bmp', 'image/webp'];

    public function collect(int $treeId, ?string $root, int $limit, bool $dryRun = false): array
    {
        $root = $this->normalizeRoot($root);
        $limit = max(1, min(200, $limit));

        $payload = [
            'version' => self::REPORT_VERSION,
            'command' => 'genealogy:media-intake-report',
            'mode' => 'observe',
            'dry_run' => $dryRun,
            'read_only' => true,
            'download_attempted' => false,
            'mutation_allowed' => false,
            'captured_at' => now()->toIso8601String(),
            'tree_id' => $treeId,
            'root' => $root,
            'root_hash' => substr(sha1($root), 0, 16),
            'limit' => $limit,
            'status' => $dryRun ? 'dry_run' : 'observe_ok',
            'summary' => $this->blankSummary(),
            'media_by_type' => [],
            'file_registry' => $this->blankRegistrySummary(),
            'review_queue' => ['table_available' => false, 'pending_review_packets' => 0],
            'samples' => ['unlinked_registry_files' => []],
            'pipeline_plan' => $this->pipelinePlan($treeId, $root, $limit),
            'next_actions' => [],
            'posture' => $this->posture(pathsIncluded: true),
        ];

        if ($dryRun) {
            $payload['summary']['query_would_run'] = true;
            $payload['next_actions'] = $this->dryRunActions();

            return $payload;
        }

        $missing = $this->missingTables(['genealogy_media']);
        if ($missing !== []) {
            $payload['status'] = 'schema_missing';
            $payload['missing_tables'] = $missing;
            $payload['next_actions'] = [[
                'code' => 'schema_missing',
                'label' => 'Genealogy media table is unavailable in this environment.',
                'write_required' => false,
            ]];

            return $payload;
        }

        $payload['summary'] = $this->mediaSummary($treeId);
        $payload['media_by_type'] = $this->mediaByType($treeId);
        $payload['file_registry'] = $this->fileRegistrySummary($treeId, $root);
        $payload['review_queue'] = $this->reviewQueueSummary();
        $payload['samples']['unlinked_registry_files'] = $this->unlinkedRegistrySamples($treeId, $root, $limit);
        $payload['next_actions'] = $this->nextActions($payload);

        return $payload;
    }

    public function compactPayload(array $payload): array
    {
        unset($payload['root'], $payload['media_by_type'], $payload['samples']);
        $payload['compact'] = true;
        $payload['posture'] = $this->posture(pathsIncluded: false);
        $payload['pipeline_plan'] = array_map(
            static fn (array $step): array => [
                'code' => $step['code'] ?? 'step',
                'label' => $step['label'] ?? '',
                'command_template' => $step['command_template'] ?? null,
                'write_required' => (bool) ($step['write_required'] ?? false),
                'canonical_write' => (bool) ($step['canonical_write'] ?? false),
            ],
            $payload['pipeline_plan'] ?? []
        );
        $payload['next_actions'] = array_map(
            static fn (array $action): array => array_intersect_key($action, array_flip(['code', 'label', 'write_required'])),
            $payload['next_actions'] ?? []
        );

        return $payload;
    }

    public function toText(array $payload): string
    {
        $summary = $payload['summary'] ?? [];
        $registry = $payload['file_registry'] ?? [];
        $review = $payload['review_queue'] ?? [];
        $lines = [
            'Genealogy media intake report',
            'Status: '.($payload['status'] ?? 'unknown').' | Tree: '.($payload['tree_id'] ?? 'unknown').' | Read-only: yes',
            'Media: total='.($summary['genealogy_media_total'] ?? 0)
                .' file_exists='.($summary['file_exists'] ?? 0)
                .' missing_file='.($summary['missing_file'] ?? 0)
                .' htr_pending='.($summary['htr_pending'] ?? 0)
                .' htr_blocked_path='.($summary['htr_blocked_path'] ?? 0)
                .' enrich_eligible='.($summary['enrich_eligible'] ?? 0),
            'File registry: total_under_root='.($registry['total_under_root'] ?? 0)
                .' not_in_genealogy_media='.($registry['not_in_genealogy_media'] ?? 0)
                .' text_extracted='.($registry['text_extracted'] ?? 0)
                .' audio='.($registry['audio'] ?? 0)
                .' html='.($registry['html'] ?? 0),
            'Review queue: pending_packets='.($review['pending_review_packets'] ?? 0),
            'Posture: no downloads, no storage writes, no genealogy links, no review decisions.',
        ];

        if (! empty($payload['next_actions'])) {
            $lines[] = 'Next actions:';
            foreach ($payload['next_actions'] as $action) {
                $lines[] = '  - '.($action['code'] ?? 'action').': '.($action['label'] ?? '');
            }
        }

        if (! empty($payload['pipeline_plan'])) {
            $lines[] = 'Pipeline plan:';
            foreach ($payload['pipeline_plan'] as $step) {
                $command = $step['command'] ?? $step['command_template'] ?? '';
                $lines[] = '  - '.($step['code'] ?? 'step').': '.($step['label'] ?? '').($command !== '' ? " ({$command})" : '');
            }
        }

        return implode(PHP_EOL, $lines);
    }

    public function toMarkdown(array $payload): string
    {
        $summary = $payload['summary'] ?? [];
        $registry = $payload['file_registry'] ?? [];
        $review = $payload['review_queue'] ?? [];
        $lines = [
            '# Genealogy Media Intake Report',
            '',
            '- Status: `'.($payload['status'] ?? 'unknown').'`',
            '- Tree: `'.($payload['tree_id'] ?? 'unknown').'`',
            '- Read-only: `true`',
            '- Mutation allowed: `false`',
            '',
            '## Summary',
            '',
            '| Metric | Value |',
            '|---|---:|',
            '| Genealogy media total | '.($summary['genealogy_media_total'] ?? 0).' |',
            '| File exists | '.($summary['file_exists'] ?? 0).' |',
            '| Missing file/path | '.($summary['missing_file'] ?? 0).' |',
            '| Pending HTR | '.($summary['htr_pending'] ?? 0).' |',
            '| HTR blocked by path/file | '.($summary['htr_blocked_path'] ?? 0).' |',
            '| Enrichment eligible | '.($summary['enrich_eligible'] ?? 0).' |',
            '| Registry files under root | '.($registry['total_under_root'] ?? 0).' |',
            '| Registry not in genealogy_media | '.($registry['not_in_genealogy_media'] ?? 0).' |',
            '| Registry text extracted | '.($registry['text_extracted'] ?? 0).' |',
            '| Pending review packets | '.($review['pending_review_packets'] ?? 0).' |',
            '',
            '## Next Actions',
            '',
        ];

        foreach ($payload['next_actions'] ?? [] as $action) {
            $lines[] = '- `'.($action['code'] ?? 'action').'`: '.($action['label'] ?? '');
        }

        $lines[] = '';
        $lines[] = '## Pipeline Plan';
        $lines[] = '';
        foreach ($payload['pipeline_plan'] ?? [] as $step) {
            $command = $step['command'] ?? $step['command_template'] ?? '';
            $suffix = $command !== '' ? ' `'.$command.'`' : '';
            $lines[] = '- `'.($step['code'] ?? 'step').'`: '.($step['label'] ?? '').$suffix;
        }

        $lines[] = '';
        $lines[] = 'Posture: no downloads, no storage writes, no genealogy links, no review decisions.';

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function missingTables(array $tables): array
    {
        return array_values(array_filter($tables, static fn (string $table): bool => ! Schema::hasTable($table)));
    }

    private function mediaSummary(int $treeId): array
    {
        $enrichmentTypes = $this->sqlStringList(self::ENRICHMENT_TYPES);
        $htrTypes = $this->sqlStringList(self::HTR_TYPES);
        $hasTranscript = $this->hasTranscriptSql();
        $htrFormatSql = $this->htrFormatSql();

        $row = DB::selectOne("
            SELECT
                COUNT(*) AS genealogy_media_total,
                SUM(CASE WHEN file_exists = 1 THEN 1 ELSE 0 END) AS file_exists,
                SUM(CASE WHEN file_exists = 1 THEN 0 ELSE 1 END) AS missing_file,
                SUM(CASE WHEN {$hasTranscript} THEN 1 ELSE 0 END) AS has_transcription,
                SUM(CASE WHEN media_type IN ({$htrTypes}) AND NOT ({$hasTranscript}) AND file_exists = 1 AND {$htrFormatSql} THEN 1 ELSE 0 END) AS htr_pending,
                SUM(CASE WHEN media_type IN ({$htrTypes}) AND NOT ({$hasTranscript}) AND (COALESCE(file_exists, 0) <> 1 OR nextcloud_path IS NULL OR nextcloud_path = '') THEN 1 ELSE 0 END) AS htr_blocked_path,
                SUM(CASE WHEN media_type IN ({$enrichmentTypes}) AND file_exists = 1 AND analysis_status = 'completed' AND (enrichment_status IS NULL OR enrichment_status = 'failed') THEN 1 ELSE 0 END) AS enrich_eligible,
                SUM(CASE WHEN enrichment_status = 'completed' THEN 1 ELSE 0 END) AS enrichment_completed,
                SUM(CASE WHEN enrichment_status = 'failed' THEN 1 ELSE 0 END) AS enrichment_failed,
                SUM(CASE WHEN enrichment_status = 'skipped' THEN 1 ELSE 0 END) AS enrichment_skipped
            FROM genealogy_media
            WHERE tree_id = ?
        ", [$treeId]);

        return $this->objectToIntArray($row, $this->blankSummary());
    }

    private function mediaByType(int $treeId): array
    {
        $hasTranscript = $this->hasTranscriptSql();
        $rows = DB::select("
            SELECT
                COALESCE(media_type, 'unknown') AS media_type,
                COUNT(*) AS total,
                SUM(CASE WHEN file_exists = 1 THEN 1 ELSE 0 END) AS file_exists,
                SUM(CASE WHEN {$hasTranscript} THEN 1 ELSE 0 END) AS has_transcription,
                SUM(CASE WHEN enrichment_status = 'completed' THEN 1 ELSE 0 END) AS enriched,
                SUM(CASE WHEN enrichment_status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN enrichment_status = 'skipped' THEN 1 ELSE 0 END) AS skipped,
                SUM(CASE WHEN enrichment_status IS NULL THEN 1 ELSE 0 END) AS pending
            FROM genealogy_media
            WHERE tree_id = ?
            GROUP BY COALESCE(media_type, 'unknown')
            ORDER BY total DESC, media_type ASC
        ", [$treeId]);

        return array_map(static fn ($row): array => [
            'media_type' => (string) $row->media_type,
            'total' => (int) $row->total,
            'file_exists' => (int) $row->file_exists,
            'has_transcription' => (int) $row->has_transcription,
            'enriched' => (int) $row->enriched,
            'failed' => (int) $row->failed,
            'skipped' => (int) $row->skipped,
            'pending' => (int) $row->pending,
        ], $rows);
    }

    private function fileRegistrySummary(int $treeId, string $root): array
    {
        if (! Schema::hasTable('file_registry')) {
            return $this->blankRegistrySummary();
        }

        $documentExtensions = $this->sqlStringList((array) config('file_types.document', []));
        $imageExtensions = $this->sqlStringList((array) config('file_types.image', []));
        $audioExtensions = $this->sqlStringList((array) config('file_types.audio', []));
        $rootLike = $this->rootLike($root);
        $hasRegistryText = "LENGTH(TRIM(COALESCE(fr.ai_detected_text, ''))) > 0";

        $row = DB::selectOne("
            SELECT
                COUNT(*) AS total_under_root,
                SUM(CASE WHEN gm.nextcloud_path IS NULL THEN 1 ELSE 0 END) AS not_in_genealogy_media,
                SUM(CASE WHEN {$hasRegistryText} THEN 1 ELSE 0 END) AS text_extracted,
                SUM(CASE WHEN {$hasRegistryText} THEN 0 ELSE 1 END) AS missing_text,
                SUM(CASE WHEN LOWER(COALESCE(fr.extension, '')) IN ({$documentExtensions}) THEN 1 ELSE 0 END) AS documents,
                SUM(CASE WHEN LOWER(COALESCE(fr.extension, '')) IN ({$imageExtensions}) THEN 1 ELSE 0 END) AS images,
                SUM(CASE WHEN LOWER(COALESCE(fr.extension, '')) IN ({$audioExtensions}) THEN 1 ELSE 0 END) AS audio,
                SUM(CASE WHEN LOWER(COALESCE(fr.extension, '')) IN ('html', 'htm') THEN 1 ELSE 0 END) AS html
            FROM file_registry fr
            LEFT JOIN (
                SELECT DISTINCT nextcloud_path
                FROM genealogy_media
                WHERE tree_id = ? AND nextcloud_path IS NOT NULL
            ) gm ON gm.nextcloud_path = fr.current_path
            WHERE fr.status = 'active'
              AND (fr.current_path = ? OR fr.current_path LIKE ?)
        ", [$treeId, $root, $rootLike]);

        return array_merge(
            $this->objectToIntArray($row, array_merge($this->blankRegistrySummary(), ['table_available' => true])),
            ['table_available' => true]
        );
    }

    private function reviewQueueSummary(): array
    {
        if (! Schema::hasTable('agent_review_queue')) {
            return ['table_available' => false, 'pending_review_packets' => 0];
        }

        $row = DB::selectOne(
            "SELECT COUNT(*) AS pending_review_packets
               FROM agent_review_queue
              WHERE review_type = 'genealogy_review_packet'
                AND status = 'pending'"
        );

        return [
            'table_available' => true,
            'pending_review_packets' => (int) ($row->pending_review_packets ?? 0),
        ];
    }

    private function unlinkedRegistrySamples(int $treeId, string $root, int $limit): array
    {
        if (! Schema::hasTable('file_registry')) {
            return [];
        }

        $rows = DB::select("
            SELECT fr.current_path, fr.extension, fr.mime_type, fr.ai_document_type, fr.ai_analyzed_at,
                   CASE WHEN LENGTH(TRIM(COALESCE(fr.ai_detected_text, ''))) > 0 THEN 1 ELSE 0 END AS text_extracted
            FROM file_registry fr
            LEFT JOIN (
                SELECT DISTINCT nextcloud_path
                FROM genealogy_media
                WHERE tree_id = ? AND nextcloud_path IS NOT NULL
            ) gm ON gm.nextcloud_path = fr.current_path
            WHERE fr.status = 'active'
              AND (fr.current_path = ? OR fr.current_path LIKE ?)
              AND gm.nextcloud_path IS NULL
            ORDER BY fr.updated_at DESC, fr.filename ASC
            LIMIT ?
        ", [$treeId, $root, $this->rootLike($root), $limit]);

        return array_map(static fn ($row): array => [
            'path' => (string) $row->current_path,
            'extension' => (string) ($row->extension ?? ''),
            'mime_type' => (string) ($row->mime_type ?? ''),
            'ai_document_type' => $row->ai_document_type !== null ? (string) $row->ai_document_type : null,
            'ai_analyzed_at' => $row->ai_analyzed_at !== null ? (string) $row->ai_analyzed_at : null,
            'text_extracted' => ((int) $row->text_extracted) === 1,
        ], $rows);
    }

    private function nextActions(array $payload): array
    {
        $summary = $payload['summary'] ?? [];
        $registry = $payload['file_registry'] ?? [];
        $review = $payload['review_queue'] ?? [];
        $actions = [];

        if (($registry['not_in_genealogy_media'] ?? 0) > 0) {
            $actions[] = [
                'code' => 'stage_unlinked_ft_media',
                'label' => 'Stage unlinked FT/file_registry media into intake packets before any copy/link writes.',
                'command' => 'php artisan genealogy:ingest-documents --stage --save-run --unprocessed-only',
                'write_required' => false,
            ];
        }

        if (($summary['htr_pending'] ?? 0) > 0) {
            $actions[] = [
                'code' => 'transcribe_pending_documents',
                'label' => 'Run HTR/transcription on document-class media with resolvable files.',
                'command' => 'php artisan genealogy:transcribe-media --dry-run',
                'write_required' => false,
            ];
        }

        if (($summary['htr_blocked_path'] ?? 0) > 0) {
            $actions[] = [
                'code' => 'fix_htr_path_blockers',
                'label' => 'Resolve missing file/path rows before expecting HTR to clear every document.',
                'write_required' => false,
            ];
        }

        if (($summary['enrich_eligible'] ?? 0) > 0) {
            $actions[] = [
                'code' => 'preview_enrichment_candidates',
                'label' => 'Preview enrichment-eligible media before proposal/review packet generation.',
                'command' => 'php artisan genealogy:enrich-media --dry-run',
                'write_required' => false,
            ];
        }

        if (($review['pending_review_packets'] ?? 0) > 0) {
            $actions[] = [
                'code' => 'review_pending_packets',
                'label' => 'Review pending genealogy packets and their evidence asset candidates before link writes.',
                'command' => 'php artisan genealogy:evidence-asset-candidates --json --compact',
                'write_required' => false,
            ];
        }

        if ($actions === []) {
            $actions[] = [
                'code' => 'observe_no_immediate_gap',
                'label' => 'No immediate media-intake gap was detected by this aggregate report.',
                'write_required' => false,
            ];
        }

        return $actions;
    }

    private function dryRunActions(): array
    {
        return [[
            'code' => 'dry_run_shape_only',
            'label' => 'Command shape is valid; rerun without --dry-run to collect aggregate read-only counts.',
            'write_required' => false,
        ]];
    }

    private function pipelinePlan(int $treeId, string $root, int $limit): array
    {
        return [
            [
                'code' => 'discover_stage',
                'label' => 'Discover unprocessed FT/source media and group it into reviewable intake packets.',
                'command' => sprintf('php artisan genealogy:ingest-documents --stage --tree=%d --folder=%s --limit=%d --unprocessed-only', $treeId, $root, $limit),
                'command_template' => 'php artisan genealogy:ingest-documents --stage --tree={tree} --folder={root} --limit={limit} --unprocessed-only',
                'write_required' => false,
                'canonical_write' => false,
            ],
            [
                'code' => 'optional_save_run',
                'label' => 'Persist the staged intake snapshot only when the operator wants a resumable review packet run.',
                'command' => sprintf('php artisan genealogy:ingest-documents --stage --save-run --tree=%d --folder=%s --limit=%d --unprocessed-only', $treeId, $root, $limit),
                'command_template' => 'php artisan genealogy:ingest-documents --stage --save-run --tree={tree} --folder={root} --limit={limit} --unprocessed-only',
                'write_required' => true,
                'canonical_write' => false,
            ],
            [
                'code' => 'transcribe_dry_run',
                'label' => 'List document-class media eligible for HTR/transcription before writing transcript text.',
                'command' => sprintf('php artisan genealogy:transcribe-media --dry-run --tree=%d --limit=%d', $treeId, $limit),
                'command_template' => 'php artisan genealogy:transcribe-media --dry-run --tree={tree} --limit={limit}',
                'write_required' => false,
                'canonical_write' => false,
            ],
            [
                'code' => 'enrich_dry_run',
                'label' => 'List media eligible for enrichment before generating proposals or links.',
                'command' => sprintf('php artisan genealogy:enrich-media --dry-run --tree=%d --limit=%d', $treeId, $limit),
                'command_template' => 'php artisan genealogy:enrich-media --dry-run --tree={tree} --limit={limit}',
                'write_required' => false,
                'canonical_write' => false,
            ],
            [
                'code' => 'review_assets',
                'label' => 'Inspect evidence asset candidates from pending review packets without downloads or writes.',
                'command' => 'php artisan genealogy:evidence-asset-candidates --json --compact',
                'command_template' => 'php artisan genealogy:evidence-asset-candidates --json --compact',
                'write_required' => false,
                'canonical_write' => false,
            ],
            [
                'code' => 'approval_apply_only',
                'label' => 'Apply media/person/source/family links only from explicit Review Hub approval paths.',
                'command' => null,
                'command_template' => null,
                'write_required' => true,
                'canonical_write' => true,
            ],
        ];
    }

    private function blankSummary(): array
    {
        return [
            'genealogy_media_total' => 0,
            'file_exists' => 0,
            'missing_file' => 0,
            'has_transcription' => 0,
            'htr_pending' => 0,
            'htr_blocked_path' => 0,
            'enrich_eligible' => 0,
            'enrichment_completed' => 0,
            'enrichment_failed' => 0,
            'enrichment_skipped' => 0,
            'query_would_run' => false,
        ];
    }

    private function blankRegistrySummary(): array
    {
        return [
            'table_available' => false,
            'total_under_root' => 0,
            'not_in_genealogy_media' => 0,
            'text_extracted' => 0,
            'missing_text' => 0,
            'documents' => 0,
            'images' => 0,
            'audio' => 0,
            'html' => 0,
        ];
    }

    private function posture(bool $pathsIncluded): array
    {
        return [
            'row_identifiers_included' => false,
            'raw_person_ids_included' => false,
            'paths_included' => $pathsIncluded,
            'downloads_enabled' => false,
            'storage_writes_enabled' => false,
            'genealogy_links_enabled' => false,
            'review_decisions_enabled' => false,
            'ai_calls_enabled' => false,
        ];
    }

    private function normalizeRoot(?string $root): string
    {
        $root = trim((string) ($root ?: config('genealogy.nextcloud_root', '/Library/Genealogy')));
        if ($root === '') {
            $root = '/Library/Genealogy';
        }

        return '/'.trim($root, '/');
    }

    private function rootLike(string $root): string
    {
        return rtrim($root, '/').'/%';
    }

    private function hasTranscriptSql(): string
    {
        return "(LENGTH(TRIM(COALESCE(transcription_text, ''))) > 0 OR LENGTH(TRIM(COALESCE(transcription, ''))) > 0)";
    }

    private function htrFormatSql(): string
    {
        $extensions = $this->sqlStringList(self::HTR_EXTENSIONS);
        $mimeTypes = $this->sqlStringList(self::HTR_MIME_TYPES);

        return "(LOWER(COALESCE(file_format, SUBSTRING_INDEX(local_filename, '.', -1), '')) IN ({$extensions})
            OR LOWER(COALESCE(mime_type, '')) IN ({$mimeTypes}))";
    }

    /**
     * @param  array<int, string>  $values
     */
    private function sqlStringList(array $values): string
    {
        $safe = array_values(array_filter(array_map(
            static fn ($value): string => preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)),
            $values
        )));

        if ($safe === []) {
            return "''";
        }

        return "'".implode("','", $safe)."'";
    }

    /**
     * @param  array<string, int|bool>  $defaults
     * @return array<string, int|bool>
     */
    private function objectToIntArray(?object $row, array $defaults): array
    {
        if (! $row) {
            return $defaults;
        }

        $result = $defaults;
        foreach ($defaults as $key => $default) {
            if (property_exists($row, $key)) {
                $result[$key] = is_bool($default) ? (bool) $row->{$key} : (int) ($row->{$key} ?? 0);
            }
        }

        return $result;
    }
}
