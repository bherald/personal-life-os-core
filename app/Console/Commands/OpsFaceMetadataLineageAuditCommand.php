<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class OpsFaceMetadataLineageAuditCommand extends Command
{
    private const EXPORT_CONFIG_SECTION = 'faces';

    private const EXPORT_CONFIG_KEY = 'photoprism_face_export_path';

    protected $signature = 'ops:face-metadata-lineage-audit
        {--export= : PhotoPrism export zip, export directory, or TSV file}
        {--tsv=FACES-EXPORT/faces_new.tsv : TSV path inside zip or export directory}
        {--tree=4 : Genealogy tree id for FT/media checks}
        {--name= : Optional case-insensitive name filter, such as Debra}
        {--limit=25 : Maximum sample rows per bucket}
        {--compact : Omit sample rows from JSON output}
        {--json : Emit machine-readable JSON}
        {--dry-run : Validate command shape without reading export or querying row data}';

    protected $description = 'Read-only lineage audit from operator-vetted PhotoPrism face export to PLOS face and FT links';

    public function handle(): int
    {
        $exportPath = $this->resolveExportPath();
        $tsvPath = (string) $this->option('tsv');
        $treeId = max(1, (int) $this->option('tree'));
        $limit = max(1, min(250, (int) $this->option('limit')));
        $nameFilter = $this->cleanOptionString($this->option('name'));

        $payload = $this->basePayload($exportPath, $tsvPath, $treeId, $limit, $nameFilter);

        if ((bool) $this->option('dry-run')) {
            $payload['dry_run'] = true;
            $payload['summary']['query_would_run'] = false;
            $this->renderPayload($payload);

            return self::SUCCESS;
        }

        if ($exportPath === null) {
            $payload['summary']['query_would_run'] = false;
            $payload['summary']['export_read_error'] = sprintf(
                'Provide --export or configure %s.%s / PLOS_FACE_PHOTOPRISM_EXPORT_PATH.',
                self::EXPORT_CONFIG_SECTION,
                self::EXPORT_CONFIG_KEY
            );
            $payload['next_action'] = 'configure_export_path_before_running_lineage_audit';
            $this->renderPayload($payload);

            return self::FAILURE;
        }

        try {
            $export = $this->loadExport($exportPath, $tsvPath, $nameFilter);
        } catch (\Throwable $e) {
            $payload['summary']['query_would_run'] = false;
            $payload['summary']['export_read_error'] = $e->getMessage();
            $payload['next_action'] = 'fix_export_path_or_tsv_before_running_lineage_audit';
            $this->renderPayload($payload);

            return self::FAILURE;
        }

        $payload['export'] = $export['summary'];
        $payload['summary']['query_would_run'] = true;

        $comparison = $this->compareLineage($export, $treeId, $limit, $nameFilter);
        $payload['summary'] = array_merge($payload['summary'], $comparison['summary']);
        $payload['lineage'] = $comparison['lineage'];
        $payload['samples'] = $comparison['samples'];
        $payload['next_action'] = $this->nextAction($comparison['summary']);

        if ((bool) $this->option('compact')) {
            unset($payload['samples']);
            $payload['compact'] = true;
            $payload['posture']['samples_included'] = false;
            $payload['posture']['row_identifiers_included'] = false;
        }

        $this->renderPayload($payload);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadExport(string $exportPath, string $tsvPath, ?string $nameFilter): array
    {
        $rows = $this->readTsvRows($exportPath, $tsvPath);

        $byPath = [];
        $byPathName = [];
        $names = [];
        $paths = [];
        $includedRows = 0;
        $filteredRows = 0;

        foreach ($rows as $row) {
            $rawPath = trim((string) ($row[0] ?? ''));
            $rawName = trim((string) ($row[1] ?? ''));
            if ($rawPath === '' || $rawName === '') {
                continue;
            }

            if ($nameFilter !== null && ! str_contains(mb_strtolower($rawName), mb_strtolower($nameFilter))) {
                $filteredRows++;
                continue;
            }

            $pathKey = $this->normalizePath($rawPath);
            $nameKey = $this->normalizeName($rawName);
            if ($pathKey === '' || $nameKey === '') {
                continue;
            }

            $includedRows++;
            $paths[$pathKey] = true;
            $names[$nameKey] = ($names[$nameKey] ?? 0) + 1;
            $byPath[$pathKey][$nameKey] = ($byPath[$pathKey][$nameKey] ?? 0) + 1;
            $byPathName[$pathKey.'|'.$nameKey] = ($byPathName[$pathKey.'|'.$nameKey] ?? 0) + 1;
        }

        return [
            'by_path' => $byPath,
            'by_path_name' => $byPathName,
            'summary' => [
                'source' => $exportPath,
                'tsv' => $tsvPath,
                'name_filter' => $nameFilter,
                'included_face_rows' => $includedRows,
                'filtered_face_rows' => $filteredRows,
                'unique_paths' => count($paths),
                'unique_names' => count($names),
                'top_names' => $this->topCounts($names, 15),
            ],
        ];
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readTsvRows(string $exportPath, string $tsvPath): array
    {
        if (is_dir($exportPath)) {
            $fullPath = rtrim($exportPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$tsvPath;

            return $this->readPlainTsv($fullPath);
        }

        if (is_file($exportPath) && ! str_ends_with(mb_strtolower($exportPath), '.zip')) {
            return $this->readPlainTsv($exportPath);
        }

        if (! is_file($exportPath)) {
            throw new \RuntimeException('Export file not found: '.$exportPath);
        }

        $zip = new ZipArchive;
        if ($zip->open($exportPath) !== true) {
            throw new \RuntimeException('Could not open export zip: '.$exportPath);
        }

        $stream = $zip->getStream($tsvPath);
        if ($stream === false) {
            $zip->close();
            throw new \RuntimeException('TSV not found in export zip: '.$tsvPath);
        }

        $rows = $this->readTsvStream($stream);
        fclose($stream);
        $zip->close();

        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readPlainTsv(string $path): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException('TSV file not found: '.$path);
        }

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Could not open TSV file: '.$path);
        }

        $rows = $this->readTsvStream($stream);
        fclose($stream);

        return $rows;
    }

    /**
     * @param  resource  $stream
     * @return array<int, array<int, string>>
     */
    private function readTsvStream($stream): array
    {
        $rows = [];
        while (($row = fgetcsv($stream, 0, "\t")) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }
            $rows[] = array_map(static fn ($value): string => (string) $value, $row);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $export
     * @return array<string, mixed>
     */
    private function compareLineage(array $export, int $treeId, int $limit, ?string $nameFilter): array
    {
        $lineage = [
            'file_registry_faces' => [
                'rows_on_export_paths' => 0,
                'exact_export_label_rows' => 0,
                'label_differs_from_export_rows' => 0,
                'linked_person_canonical_differs_rows' => 0,
                'p_los_only_named_rows' => 0,
                'amplified_path_name_pairs' => 0,
            ],
            'genealogy_media_scan_log' => [
                'rows_on_export_paths' => 0,
                'name_differs_from_export_rows' => 0,
                'scan_log_only_name_rows' => 0,
            ],
            'genealogy_person_media' => [
                'links_on_export_paths' => 0,
                'linked_person_canonical_differs_rows' => 0,
                'confirmed_links_canonical_differs_rows' => 0,
            ],
            'export_coverage' => [
                'export_path_name_pairs' => count($export['by_path_name']),
                'export_pairs_seen_exactly_in_faces' => 0,
                'export_pairs_not_seen_exactly_in_faces' => 0,
            ],
        ];

        $samples = [
            'face_label_differs_from_export' => [],
            'face_linked_person_canonical_differs' => [],
            'plos_only_named_faces' => [],
            'amplified_export_pairs' => [],
            'scan_log_name_differs_from_export' => [],
            'person_media_canonical_differs_from_export' => [],
            'export_pairs_not_seen_exactly_in_faces' => [],
        ];

        $seenExactPairs = [];
        $faceExactCounts = [];

        foreach ($this->faceRows($treeId) as $row) {
            $pathKey = $this->firstExportPathKey($export, [$row->current_path ?? null, $row->original_path ?? null]);
            $nameKey = $this->normalizeName($row->person_name ?? '');
            $canonicalKey = $this->normalizeName($row->canonical_name ?? '');
            $hasName = $nameKey !== '';
            $hasLinkedPerson = (int) ($row->genealogy_person_id ?? 0) > 0;
            if ($nameFilter !== null
                && $pathKey === null
                && ! $this->nameMatchesFilter($nameKey, $nameFilter)
                && ! $this->nameMatchesFilter($canonicalKey, $nameFilter)) {
                continue;
            }

            if ($pathKey === null) {
                if ($hasName || $hasLinkedPerson) {
                    $lineage['file_registry_faces']['p_los_only_named_rows']++;
                    $this->appendSample($samples['plos_only_named_faces'], $limit, [
                        'face_id' => (int) ($row->face_id ?? 0),
                        'file_registry_id' => (int) ($row->file_registry_id ?? 0),
                        'name' => (string) ($row->person_name ?? ''),
                        'person_id' => $hasLinkedPerson ? (int) $row->genealogy_person_id : null,
                        'reason' => 'face_row_path_not_found_in_export',
                    ]);
                }
                continue;
            }

            $lineage['file_registry_faces']['rows_on_export_paths']++;
            $exportNames = $export['by_path'][$pathKey] ?? [];
            $pairKey = $pathKey.'|'.$nameKey;

            if ($hasName && isset($exportNames[$nameKey])) {
                $lineage['file_registry_faces']['exact_export_label_rows']++;
                $seenExactPairs[$pairKey] = true;
                $faceExactCounts[$pairKey] = ($faceExactCounts[$pairKey] ?? 0) + 1;
            } elseif ($hasName) {
                $lineage['file_registry_faces']['label_differs_from_export_rows']++;
                $this->appendSample($samples['face_label_differs_from_export'], $limit, [
                    'face_id' => (int) ($row->face_id ?? 0),
                    'file_registry_id' => (int) ($row->file_registry_id ?? 0),
                    'name' => (string) ($row->person_name ?? ''),
                    'export_names' => $this->displayNames($exportNames),
                    'reason' => 'plos_face_label_not_exactly_in_operator_export_for_path',
                ]);
            }

            if ($hasLinkedPerson && $canonicalKey !== '' && ! isset($exportNames[$canonicalKey])) {
                $lineage['file_registry_faces']['linked_person_canonical_differs_rows']++;
                $this->appendSample($samples['face_linked_person_canonical_differs'], $limit, [
                    'face_id' => (int) ($row->face_id ?? 0),
                    'file_registry_id' => (int) ($row->file_registry_id ?? 0),
                    'person_id' => (int) $row->genealogy_person_id,
                    'face_label' => (string) ($row->person_name ?? ''),
                    'canonical_name' => (string) ($row->canonical_name ?? ''),
                    'export_names' => $this->displayNames($exportNames),
                    'reason' => 'linked_ft_canonical_name_not_exactly_in_operator_export_for_path',
                ]);
            }
        }

        foreach ($faceExactCounts as $pairKey => $count) {
            $exportCount = (int) ($export['by_path_name'][$pairKey] ?? 0);
            if ($exportCount > 0 && $count > $exportCount) {
                $lineage['file_registry_faces']['amplified_path_name_pairs']++;
                $this->appendSample($samples['amplified_export_pairs'], $limit, [
                    'path_name_key' => $this->redactPathNameKey($pairKey),
                    'export_count' => $exportCount,
                    'plos_exact_face_rows' => $count,
                    'reason' => 'plos_has_more_exact_face_rows_than_export_for_same_path_and_name',
                ]);
            }
        }

        foreach ($export['by_path_name'] as $pairKey => $count) {
            if (isset($seenExactPairs[$pairKey])) {
                $lineage['export_coverage']['export_pairs_seen_exactly_in_faces']++;
                continue;
            }

            $lineage['export_coverage']['export_pairs_not_seen_exactly_in_faces']++;
            $this->appendSample($samples['export_pairs_not_seen_exactly_in_faces'], $limit, [
                'path_name_key' => $this->redactPathNameKey($pairKey),
                'export_count' => (int) $count,
                'reason' => 'operator_export_pair_not_seen_as_exact_plos_face_label',
            ]);
        }

        foreach ($this->scanLogRows($treeId) as $row) {
            $pathKey = $this->firstExportPathKey($export, [$row->nextcloud_path ?? null]);
            if ($pathKey === null) {
                continue;
            }

            $lineage['genealogy_media_scan_log']['rows_on_export_paths']++;
            $exportNames = $export['by_path'][$pathKey] ?? [];
            $faceNames = $this->jsonNames($row->face_names ?? null);
            $diffNames = [];
            foreach ($faceNames as $faceName) {
                $key = $this->normalizeName($faceName);
                if ($key !== '' && ! isset($exportNames[$key])) {
                    $diffNames[] = $faceName;
                }
            }

            if ($diffNames !== []) {
                $lineage['genealogy_media_scan_log']['name_differs_from_export_rows']++;
                $lineage['genealogy_media_scan_log']['scan_log_only_name_rows'] += count($diffNames);
                $this->appendSample($samples['scan_log_name_differs_from_export'], $limit, [
                    'scan_log_id' => (int) ($row->id ?? 0),
                    'scan_names_not_in_export' => array_values(array_unique($diffNames)),
                    'export_names' => $this->displayNames($exportNames),
                    'reason' => 'scan_log_name_not_exactly_in_operator_export_for_path',
                ]);
            }
        }

        foreach ($this->personMediaRows($treeId) as $row) {
            $pathKey = $this->firstExportPathKey($export, [$row->original_path ?? null, $row->nextcloud_path ?? null]);
            if ($pathKey === null) {
                continue;
            }

            $lineage['genealogy_person_media']['links_on_export_paths']++;
            $exportNames = $export['by_path'][$pathKey] ?? [];
            $canonicalKey = $this->normalizeName($row->canonical_name ?? '');
            if ($canonicalKey !== '' && ! isset($exportNames[$canonicalKey])) {
                $lineage['genealogy_person_media']['linked_person_canonical_differs_rows']++;
                if ((int) ($row->face_confirmed ?? 0) === 1) {
                    $lineage['genealogy_person_media']['confirmed_links_canonical_differs_rows']++;
                }
                $this->appendSample($samples['person_media_canonical_differs_from_export'], $limit, [
                    'person_media_id' => (int) ($row->person_media_id ?? 0),
                    'media_id' => (int) ($row->media_id ?? 0),
                    'person_id' => (int) ($row->person_id ?? 0),
                    'canonical_name' => (string) ($row->canonical_name ?? ''),
                    'face_confirmed' => ((int) ($row->face_confirmed ?? 0)) === 1,
                    'export_names' => $this->displayNames($exportNames),
                    'reason' => 'ft_person_media_canonical_name_not_exactly_in_operator_export_for_path',
                ]);
            }
        }

        return [
            'summary' => [
                'face_rows_requiring_review' => $lineage['file_registry_faces']['label_differs_from_export_rows']
                    + $lineage['file_registry_faces']['linked_person_canonical_differs_rows']
                    + $lineage['file_registry_faces']['p_los_only_named_rows'],
                'scan_log_rows_requiring_review' => $lineage['genealogy_media_scan_log']['name_differs_from_export_rows'],
                'person_media_links_requiring_review' => $lineage['genealogy_person_media']['linked_person_canonical_differs_rows'],
                'export_pairs_not_seen_exactly' => $lineage['export_coverage']['export_pairs_not_seen_exactly_in_faces'],
                'amplified_path_name_pairs' => $lineage['file_registry_faces']['amplified_path_name_pairs'],
            ],
            'lineage' => $lineage,
            'samples' => $samples,
        ];
    }

    /**
     * @return array<int, object>
     */
    private function faceRows(int $treeId): array
    {
        return DB::select(
            "SELECT
                frf.id AS face_id,
                frf.file_registry_id,
                frf.person_name,
                frf.genealogy_person_id,
                frf.verified,
                frf.source,
                fr.current_path,
                fr.original_path,
                gp.tree_id,
                TRIM(CONCAT_WS(' ', NULLIF(gp.given_name, ''), NULLIF(gp.surname, ''), NULLIF(gp.suffix, ''))) AS canonical_name
             FROM file_registry_faces frf
             JOIN file_registry fr ON fr.id = frf.file_registry_id
             LEFT JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
             WHERE frf.hidden = 0
               AND (TRIM(COALESCE(frf.person_name, '')) <> '' OR frf.genealogy_person_id IS NOT NULL)
               AND (gp.id IS NULL OR gp.tree_id = ?)",
            [$treeId]
        );
    }

    /**
     * @return array<int, object>
     */
    private function scanLogRows(int $treeId): array
    {
        return DB::select(
            'SELECT id, nextcloud_path, face_names
             FROM genealogy_media_scan_log
             WHERE tree_id = ?
               AND has_faces = 1',
            [$treeId]
        );
    }

    /**
     * @return array<int, object>
     */
    private function personMediaRows(int $treeId): array
    {
        return DB::select(
            "SELECT
                gpm.id AS person_media_id,
                gpm.person_id,
                gpm.media_id,
                gpm.face_confirmed,
                gm.original_path,
                gm.nextcloud_path,
                TRIM(CONCAT_WS(' ', NULLIF(gp.given_name, ''), NULLIF(gp.surname, ''), NULLIF(gp.suffix, ''))) AS canonical_name
             FROM genealogy_person_media gpm
             JOIN genealogy_media gm ON gm.id = gpm.media_id
             JOIN genealogy_persons gp ON gp.id = gpm.person_id
             WHERE gp.tree_id = ?",
            [$treeId]
        );
    }

    /**
     * @param  array<string, mixed>  $export
     * @param  array<int, string|null>  $paths
     */
    private function firstExportPathKey(array $export, array $paths): ?string
    {
        foreach ($paths as $path) {
            $pathKey = $this->normalizePath($path);
            if ($pathKey !== '' && isset($export['by_path'][$pathKey])) {
                return $pathKey;
            }
        }

        return null;
    }

    private function normalizePath(?string $path): string
    {
        $value = mb_strtolower(trim((string) $path));
        if ($value === '') {
            return '';
        }

        $value = str_replace('\\', '/', $value);
        $value = preg_replace('#/+#', '/', $value) ?? $value;
        $value = ltrim($value, '/');

        foreach (['master/media/', 'master/', 'media/'] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                $value = substr($value, strlen($prefix));
                break;
            }
        }

        $picturesAt = strpos($value, 'pictures/');
        if ($picturesAt !== false) {
            $value = substr($value, $picturesAt);
        }

        return trim($value, '/');
    }

    private function normalizeName(?string $name): string
    {
        $value = mb_strtolower(trim((string) $name));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }

    private function nameMatchesFilter(string $normalizedName, string $nameFilter): bool
    {
        return $normalizedName !== ''
            && str_contains($normalizedName, $this->normalizeName($nameFilter));
    }

    /**
     * @return array<int, string>
     */
    private function jsonNames(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), fn (string $name): bool => trim($name) !== ''));
        }

        $decoded = json_decode((string) $value, true);
        if (! is_array($decoded)) {
            return [];
        }

        $names = [];
        foreach ($decoded as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $names[] = trim($entry);
                continue;
            }
            if (is_array($entry)) {
                $candidate = $entry['name'] ?? $entry['person_name'] ?? null;
                if (is_string($candidate) && trim($candidate) !== '') {
                    $names[] = trim($candidate);
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<int, array{name:string,count:int}>
     */
    private function topCounts(array $counts, int $limit): array
    {
        arsort($counts);
        $top = [];
        foreach (array_slice($counts, 0, $limit, true) as $name => $count) {
            $top[] = ['name' => (string) $name, 'count' => (int) $count];
        }

        return $top;
    }

    /**
     * @param  array<string, int>  $exportNames
     * @return array<int, string>
     */
    private function displayNames(array $exportNames): array
    {
        return array_slice(array_keys($exportNames), 0, 8);
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     * @param  array<string, mixed>  $sample
     */
    private function appendSample(array &$samples, int $limit, array $sample): void
    {
        if (count($samples) < $limit) {
            $samples[] = $sample;
        }
    }

    private function redactPathNameKey(string $pairKey): string
    {
        [$path, $name] = array_pad(explode('|', $pairKey, 2), 2, '');
        $parts = explode('/', $path);
        $suffix = implode('/', array_slice($parts, -3));

        return $suffix.'|'.$name;
    }

    private function cleanOptionString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function resolveExportPath(): ?string
    {
        $optionPath = $this->cleanOptionString($this->option('export'));
        if ($optionPath !== null) {
            return $optionPath;
        }

        $configuredPath = $this->cleanOptionString(config('services.faces.photoprism_export_path'));
        if ($configuredPath !== null) {
            return $configuredPath;
        }

        try {
            $tablePath = DB::table('system_configs')
                ->where('section', self::EXPORT_CONFIG_SECTION)
                ->where('config_key', self::EXPORT_CONFIG_KEY)
                ->value('config_value');
        } catch (\Throwable) {
            $tablePath = null;
        }

        return $this->cleanOptionString($tablePath);
    }

    /**
     * @param  array<string, int>  $summary
     */
    private function nextAction(array $summary): string
    {
        if ((int) ($summary['amplified_path_name_pairs'] ?? 0) > 0) {
            return 'review_amplified_export_pairs_before_any_face_sync_or_writeback';
        }

        if ((int) ($summary['face_rows_requiring_review'] ?? 0) > 0
            || (int) ($summary['person_media_links_requiring_review'] ?? 0) > 0) {
            return 'stage_operator_review_packets_for_metadata_drift_and_alias_resolution';
        }

        if ((int) ($summary['export_pairs_not_seen_exactly'] ?? 0) > 0) {
            return 'inspect_missing_exact_export_pairs_before_resuming_face_sync';
        }

        return 'lineage_audit_has_no_exact_drift_findings';
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(?string $exportPath, string $tsvPath, int $treeId, int $limit, ?string $nameFilter): array
    {
        return [
            'command' => 'ops:face-metadata-lineage-audit',
            'mode' => 'observe',
            'dry_run' => false,
            'read_only' => true,
            'mutation_allowed' => false,
            'captured_at' => now()->toIso8601String(),
            'filters' => [
                'export' => $exportPath,
                'tsv' => $tsvPath,
                'tree_id' => $treeId,
                'name_filter' => $nameFilter,
                'limit' => $limit,
            ],
            'posture' => [
                'schema' => 'face_metadata_lineage.ds.v1',
                'operator_vetted_export_is_high_trust' => true,
                'metadata_is_not_identity_proof' => true,
                'operator_review_required' => true,
                'automatic_link_allowed' => false,
                'create_person_allowed' => false,
                'metadata_writeback_allowed' => false,
                'recluster_allowed' => false,
                'samples_included' => true,
                'row_identifiers_included' => true,
            ],
            'summary' => [
                'query_would_run' => true,
                'export_read_error' => null,
                'face_rows_requiring_review' => 0,
                'scan_log_rows_requiring_review' => 0,
                'person_media_links_requiring_review' => 0,
                'export_pairs_not_seen_exactly' => 0,
                'amplified_path_name_pairs' => 0,
            ],
            'export' => null,
            'lineage' => [],
            'samples' => [],
            'next_action' => 'run_lineage_audit',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderPayload(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line(sprintf(
            'face-metadata-lineage-audit: export_faces=%d face_review=%d scan_review=%d person_media_review=%d amplified_pairs=%d rows_are_review_only=yes',
            (int) ($payload['export']['included_face_rows'] ?? 0),
            (int) ($payload['summary']['face_rows_requiring_review'] ?? 0),
            (int) ($payload['summary']['scan_log_rows_requiring_review'] ?? 0),
            (int) ($payload['summary']['person_media_links_requiring_review'] ?? 0),
            (int) ($payload['summary']['amplified_path_name_pairs'] ?? 0)
        ));
        $this->line((string) ($payload['next_action'] ?? ''));
    }
}
