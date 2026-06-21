<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class OpsFaceIdentityProvenanceMaterializeCommand extends Command
{
    private const EXPORT_CONFIG_SECTION = 'faces';

    private const EXPORT_CONFIG_KEY = 'photoprism_face_export_path';

    private const TRUSTED_STATUSES = [
        'export_exact',
        'copy_export_exact',
        'export_variant_supported',
        'copy_export_variant_supported',
    ];

    protected $signature = 'ops:face-identity-provenance-materialize
        {--export= : PhotoPrism export zip, export directory, or TSV file}
        {--tsv=FACES-EXPORT/faces_new.tsv : TSV path inside zip or export directory}
        {--batch= : Export batch id; defaults from export filename and mtime}
        {--tree=4 : Genealogy tree id}
        {--name= : Optional case-insensitive face-name/person-name filter}
        {--limit=0 : Optional max face rows to classify; 0 means all}
        {--write : Write import/provenance rows}
        {--confirm : Required with --write}
        {--json : Emit machine-readable JSON}
        {--compact : Omit sample rows from JSON output}
        {--dry-run : Validate command shape without reading export or querying row data}';

    protected $description = 'Materialize PhotoPrism/FT copy-backed face identity provenance for trusted person-face display';

    public function handle(): int
    {
        $exportPath = $this->resolveExportPath();
        $tsvPath = (string) $this->option('tsv');
        $treeId = max(1, (int) $this->option('tree'));
        $nameFilter = $this->cleanOptionString($this->option('name'));
        $limit = max(0, (int) $this->option('limit'));
        $write = (bool) $this->option('write');
        $confirm = (bool) $this->option('confirm');
        $batch = $this->cleanOptionString($this->option('batch')) ?: ($exportPath !== null ? $this->defaultBatch($exportPath) : 'unconfigured-export');
        $fullRefresh = $nameFilter === null && $limit === 0;

        $payload = [
            'command' => 'ops:face-identity-provenance-materialize',
            'mode' => $write ? 'write' : 'dry_run',
            'dry_run' => (bool) $this->option('dry-run') || ! $write,
            'read_only' => ! $write,
            'mutation_allowed' => $write && $confirm,
            'captured_at' => now()->toIso8601String(),
            'filters' => [
                'export' => $exportPath,
                'tsv' => $tsvPath,
                'batch' => $batch,
                'tree_id' => $treeId,
                'name_filter' => $nameFilter,
                'limit' => $limit,
                'full_refresh' => $fullRefresh,
            ],
            'posture' => [
                'operator_vetted_export_is_high_trust' => true,
                'metadata_is_not_identity_proof' => true,
                'uses_genealogy_name_variants' => true,
                'uses_genealogy_media_original_path_copy_map' => true,
                'automatic_link_allowed' => false,
                'create_person_allowed' => false,
                'metadata_writeback_allowed' => false,
                'recluster_allowed' => false,
                'trusted_statuses' => self::TRUSTED_STATUSES,
            ],
            'summary' => [
                'export_rows' => 0,
                'faces_classified' => 0,
                'trusted_faces' => 0,
                'review_faces' => 0,
                'write_required_confirm' => $write && ! $confirm,
            ],
            'status_counts' => [],
            'samples' => [],
        ];

        if ((bool) $this->option('dry-run')) {
            $this->renderPayload($payload);

            return self::SUCCESS;
        }

        if ($exportPath === null) {
            $payload['error'] = sprintf(
                'Provide --export or configure %s.%s / PLOS_FACE_PHOTOPRISM_EXPORT_PATH.',
                self::EXPORT_CONFIG_SECTION,
                self::EXPORT_CONFIG_KEY
            );
            $this->renderPayload($payload);

            return self::FAILURE;
        }

        if ($write && ! $confirm) {
            $payload['error'] = 'confirm=true is required with --write.';
            $this->renderPayload($payload);

            return self::FAILURE;
        }

        $export = $this->loadExport($exportPath, $tsvPath, $nameFilter);
        $payload['summary']['export_rows'] = count($export['rows']);
        $variants = $this->personVariantMap($treeId);
        $faces = $this->faceRows($treeId, $nameFilter, $limit);

        $statusCounts = [];
        $samples = [];
        $provenanceRows = [];

        foreach ($faces as $face) {
            $classification = $this->classifyFace($face, $export, $variants, $treeId, $batch);
            $statusCounts[$classification['status']] = ($statusCounts[$classification['status']] ?? 0) + 1;
            if (count($samples) < 25 && ! in_array($classification['status'], self::TRUSTED_STATUSES, true)) {
                $samples[] = [
                    'face_id' => $classification['file_registry_face_id'],
                    'file_registry_id' => $classification['file_registry_id'],
                    'status' => $classification['status'],
                    'face_name' => $classification['face_name'],
                    'person_id' => $classification['genealogy_person_id'],
                    'canonical_name' => $classification['canonical_name'],
                    'matched_export_name' => $classification['matched_export_name'],
                    'evidence_source' => $classification['evidence_source'],
                    'reasons' => $classification['reasons'],
                ];
            }
            $provenanceRows[] = $classification;
        }

        $trusted = 0;
        foreach (self::TRUSTED_STATUSES as $status) {
            $trusted += (int) ($statusCounts[$status] ?? 0);
        }

        $payload['summary']['faces_classified'] = count($provenanceRows);
        $payload['summary']['trusted_faces'] = $trusted;
        $payload['summary']['review_faces'] = count($provenanceRows) - $trusted;
        $payload['status_counts'] = $statusCounts;
        $payload['samples'] = $samples;

        if ($write) {
            $staleProvenanceDeleted = 0;
            DB::transaction(function () use ($batch, $export, $provenanceRows, $treeId, $fullRefresh, &$staleProvenanceDeleted): void {
                if ($fullRefresh) {
                    $staleProvenanceDeleted = DB::delete(
                        'DELETE FROM face_identity_provenance WHERE tree_id = ?',
                        [$treeId]
                    );
                }
                DB::delete('DELETE FROM photoprism_face_export_rows WHERE export_batch = ?', [$batch]);
                foreach (array_chunk($export['rows'], 500) as $chunk) {
                    $this->insertExportRows($batch, $chunk);
                }

                foreach (array_chunk($provenanceRows, 500) as $chunk) {
                    $this->upsertProvenanceRows($chunk);
                }
            });
            $payload['summary']['written_export_rows'] = count($export['rows']);
            $payload['summary']['written_provenance_rows'] = count($provenanceRows);
            $payload['summary']['stale_provenance_deleted'] = $staleProvenanceDeleted;
        }

        if ((bool) $this->option('compact')) {
            unset($payload['samples']);
            $payload['compact'] = true;
        }

        $this->renderPayload($payload);

        return self::SUCCESS;
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,by_path:array<string,array<string,int>>,by_path_name:array<string,int>}
     */
    private function loadExport(string $exportPath, string $tsvPath, ?string $nameFilter): array
    {
        $byPath = [];
        $byPathName = [];
        $rows = [];

        foreach ($this->readTsvRows($exportPath, $tsvPath) as $row) {
            $sourcePath = trim((string) ($row[0] ?? ''));
            $faceName = trim((string) ($row[1] ?? ''));
            if ($sourcePath === '' || $faceName === '') {
                continue;
            }
            if ($nameFilter !== null && ! str_contains($this->normalizeName($faceName), $this->normalizeName($nameFilter))) {
                continue;
            }

            $pathKey = $this->normalizePath($sourcePath);
            $nameKey = $this->normalizeName($faceName);
            if ($pathKey === '' || $nameKey === '') {
                continue;
            }

            $rowHash = hash('sha256', implode("\t", array_map('strval', $row)));
            $item = [
                'source_path' => $sourcePath,
                'normalized_path' => $pathKey,
                'normalized_path_hash' => hash('sha256', $pathKey),
                'face_name' => $faceName,
                'normalized_face_name' => $nameKey,
                'region_x' => $this->decimalOrNull($row[2] ?? null),
                'region_y' => $this->decimalOrNull($row[3] ?? null),
                'region_w' => $this->decimalOrNull($row[4] ?? null),
                'region_h' => $this->decimalOrNull($row[5] ?? null),
                'cluster_ref' => isset($row[6]) ? trim((string) $row[6]) : null,
                'row_hash' => $rowHash,
            ];

            $rows[] = $item;
            $byPath[$pathKey][$nameKey] = ($byPath[$pathKey][$nameKey] ?? 0) + 1;
            $byPathName[$pathKey.'|'.$nameKey] = ($byPathName[$pathKey.'|'.$nameKey] ?? 0) + 1;
        }

        return ['rows' => $rows, 'by_path' => $byPath, 'by_path_name' => $byPathName];
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readTsvRows(string $exportPath, string $tsvPath): array
    {
        if (is_dir($exportPath)) {
            return $this->readPlainTsv(rtrim($exportPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$tsvPath);
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
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Could not open TSV file: '.$path);
        }
        $rows = $this->readTsvStream($stream);
        fclose($stream);

        return $rows;
    }

    /**
     * @param resource $stream
     * @return array<int, array<int, string>>
     */
    private function readTsvStream($stream): array
    {
        $rows = [];
        while (($row = fgetcsv($stream, 0, "\t")) !== false) {
            $rows[] = array_map(static fn ($value): string => (string) $value, $row);
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function personVariantMap(int $treeId): array
    {
        $map = [];
        $persons = DB::select(
            "SELECT id, given_name, surname, suffix, nickname
             FROM genealogy_persons
             WHERE tree_id = ?",
            [$treeId]
        );

        foreach ($persons as $person) {
            $personId = (int) $person->id;
            $map[$personId] = ['names' => [], 'display' => []];
            $this->addPersonVariant($map[$personId], trim(implode(' ', array_filter([
                $person->given_name ?? '',
                $person->surname ?? '',
                $person->suffix ?? '',
            ]))));
            $nickname = trim((string) ($person->nickname ?? ''));
            $surname = trim((string) ($person->surname ?? ''));
            if ($nickname !== '') {
                $this->addPersonVariant($map[$personId], trim($nickname.' '.$surname));
            }
        }

        $variants = DB::select(
            "SELECT gnv.person_id, gnv.full_name, gnv.given_names, gnv.surname
             FROM genealogy_name_variants gnv
             JOIN genealogy_persons gp ON gp.id = gnv.person_id
             WHERE gp.tree_id = ?",
            [$treeId]
        );
        foreach ($variants as $variant) {
            $personId = (int) $variant->person_id;
            if (! isset($map[$personId])) {
                $map[$personId] = ['names' => [], 'display' => []];
            }
            $this->addPersonVariant($map[$personId], (string) ($variant->full_name ?? ''));
            $this->addPersonVariant($map[$personId], trim(((string) ($variant->given_names ?? '')).' '.((string) ($variant->surname ?? ''))));
        }

        return $map;
    }

    /**
     * @param array{names:array<string,bool>,display:array<string,string>} $person
     */
    private function addPersonVariant(array &$person, string $name): void
    {
        $key = $this->normalizeName($name);
        if ($key === '') {
            return;
        }
        $person['names'][$key] = true;
        $person['display'][$key] = trim($name);
    }

    /**
     * @return array<int, object>
     */
    private function faceRows(int $treeId, ?string $nameFilter, int $limit): array
    {
        $params = [$treeId];
        $where = 'frf.hidden = 0 AND (TRIM(COALESCE(frf.person_name, \'\')) <> \'\' OR frf.genealogy_person_id IS NOT NULL)';
        if ($nameFilter !== null) {
            $where .= " AND (
                LOWER(TRIM(frf.person_name)) LIKE ?
                OR LOWER(TRIM(CONCAT_WS(' ', NULLIF(gp.given_name, ''), NULLIF(gp.surname, ''), NULLIF(gp.suffix, '')))) LIKE ?
            )";
            $like = '%'.$this->normalizeName($nameFilter).'%';
            $params[] = $like;
            $params[] = $like;
        }

        $limitSql = '';
        if ($limit > 0) {
            $limitSql = ' LIMIT ?';
            $params[] = $limit;
        }

        $faces = DB::select(
            "SELECT
                frf.id AS face_id,
                frf.file_registry_id,
                frf.person_name,
                frf.genealogy_person_id,
                frf.verified,
                frf.source,
                fr.current_path,
                fr.original_path,
                TRIM(CONCAT_WS(' ', NULLIF(gp.given_name, ''), NULLIF(gp.surname, ''), NULLIF(gp.suffix, ''))) AS canonical_name
             FROM file_registry_faces frf
             JOIN file_registry fr ON fr.id = frf.file_registry_id
             LEFT JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id AND gp.tree_id = ?
             WHERE {$where}
             ORDER BY frf.id ASC{$limitSql}",
            $params
        );

        $mediaByPath = $this->genealogyMediaPathMap($treeId);
        foreach ($faces as $face) {
            $media = $this->matchingGenealogyMedia($face, $mediaByPath);
            $face->genealogy_original_path = $media['original_path'] ?? null;
            $face->genealogy_nextcloud_path = $media['nextcloud_path'] ?? null;
        }

        return $faces;
    }

    /**
     * @return array<string,array{original_path:?string,nextcloud_path:?string}>
     */
    private function genealogyMediaPathMap(int $treeId): array
    {
        $rows = DB::select(
            "SELECT original_path, nextcloud_path
             FROM genealogy_media
             WHERE tree_id = ?
               AND (
                    TRIM(COALESCE(original_path, '')) <> ''
                 OR TRIM(COALESCE(nextcloud_path, '')) <> ''
               )",
            [$treeId]
        );

        $map = [];
        foreach ($rows as $row) {
            $media = [
                'original_path' => trim((string) ($row->original_path ?? '')) ?: null,
                'nextcloud_path' => trim((string) ($row->nextcloud_path ?? '')) ?: null,
            ];
            foreach ($media as $path) {
                $key = $this->normalizePath($path);
                if ($key !== '' && ! isset($map[$key])) {
                    $map[$key] = $media;
                }
            }
        }

        return $map;
    }

    /**
     * @param array<string,array{original_path:?string,nextcloud_path:?string}> $mediaByPath
     * @return array{original_path:?string,nextcloud_path:?string}|null
     */
    private function matchingGenealogyMedia(object $face, array $mediaByPath): ?array
    {
        foreach ([$face->current_path ?? null, $face->original_path ?? null] as $path) {
            $key = $this->normalizePath($path);
            if ($key !== '' && isset($mediaByPath[$key])) {
                return $mediaByPath[$key];
            }
        }

        return null;
    }

    /**
     * @param array{by_path:array<string,array<string,int>>,by_path_name:array<string,int>} $export
     * @param array<int,array<string,mixed>> $variants
     * @return array<string,mixed>
     */
    private function classifyFace(object $face, array $export, array $variants, int $treeId, string $batch): array
    {
        [$pathKey, $evidenceSource] = $this->firstExportPathKey($export, [
            'file_current_path' => $face->current_path ?? null,
            'file_original_path' => $face->original_path ?? null,
            'genealogy_original_path' => $face->genealogy_original_path ?? null,
            'genealogy_nextcloud_path' => $face->genealogy_nextcloud_path ?? null,
        ]);

        $faceName = trim((string) ($face->person_name ?? ''));
        $nameKey = $this->normalizeName($faceName);
        $personId = $face->genealogy_person_id !== null ? (int) $face->genealogy_person_id : null;
        $canonicalName = trim((string) ($face->canonical_name ?? ''));
        $personNames = $personId !== null ? ($variants[$personId]['names'] ?? []) : [];
        $personDisplay = $personId !== null ? ($variants[$personId]['display'] ?? []) : [];
        $exportNames = $pathKey !== null ? ($export['by_path'][$pathKey] ?? []) : [];

        $status = 'plos_only_untrusted';
        $matchedExportName = null;
        $matchedVariantName = null;
        $reasons = [];

        if ($pathKey === null) {
            $reasons[] = 'face_path_not_found_in_photoprism_export_or_genealogy_copy_map';
        } else {
            if ($nameKey !== '' && isset($exportNames[$nameKey]) && ($personId === null || isset($personNames[$nameKey]))) {
                $status = $evidenceSource === 'direct_path' ? 'export_exact' : 'copy_export_exact';
                $matchedExportName = $nameKey;
                $reasons[] = 'face_label_matches_operator_export';
            } elseif ($nameKey !== '' && isset($exportNames[$nameKey]) && $personId !== null && ! isset($personNames[$nameKey])) {
                $status = 'export_exact_person_conflict';
                $matchedExportName = $nameKey;
                $reasons[] = 'face_label_matches_export_but_linked_ft_person_name_variants_do_not';
            } else {
                foreach ($personNames as $variantKey => $_) {
                    if (isset($exportNames[$variantKey])) {
                        $status = $evidenceSource === 'direct_path' ? 'export_variant_supported' : 'copy_export_variant_supported';
                        $matchedExportName = $variantKey;
                        $matchedVariantName = $personDisplay[$variantKey] ?? $variantKey;
                        $reasons[] = 'linked_ft_person_name_variant_matches_operator_export';
                        break;
                    }
                }
                if (! in_array($status, self::TRUSTED_STATUSES, true)) {
                    foreach (array_keys($exportNames) as $exportName) {
                        foreach ($personNames as $variantKey => $_) {
                            if ($this->isLikelySpellingVariant($exportName, $variantKey)) {
                                $status = 'export_spelling_variant_review';
                                $matchedExportName = $exportName;
                                $matchedVariantName = $personDisplay[$variantKey] ?? $variantKey;
                                $reasons[] = 'operator_export_name_is_close_to_ft_name_variant_but_not_exact';
                                break 2;
                            }
                        }
                    }
                }
                if ($status === 'plos_only_untrusted') {
                    $status = $personId !== null ? 'linked_person_differs_from_export' : 'label_differs_from_export';
                    $reasons[] = $personId !== null
                        ? 'linked_ft_person_variants_do_not_match_operator_export_for_path'
                        : 'face_label_does_not_match_operator_export_for_path';
                }
            }
        }

        return [
            'file_registry_face_id' => (int) $face->face_id,
            'file_registry_id' => (int) $face->file_registry_id,
            'genealogy_person_id' => $personId,
            'tree_id' => $treeId,
            'export_batch' => $batch,
            'status' => $status,
            'evidence_source' => $evidenceSource ?? 'none',
            'export_path_hash' => $pathKey !== null ? hash('sha256', $pathKey) : null,
            'export_path_key' => $pathKey,
            'face_name' => $faceName !== '' ? $faceName : null,
            'normalized_face_name' => $nameKey !== '' ? $nameKey : null,
            'canonical_name' => $canonicalName !== '' ? $canonicalName : null,
            'matched_export_name' => $matchedExportName,
            'matched_variant_name' => $matchedVariantName,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param array{by_path:array<string,array<string,int>>} $export
     * @param array<string,string|null> $paths
     * @return array{0:string|null,1:string|null}
     */
    private function firstExportPathKey(array $export, array $paths): array
    {
        foreach ($paths as $source => $path) {
            $pathKey = $this->normalizePath($path);
            if ($pathKey !== '' && isset($export['by_path'][$pathKey])) {
                return [$pathKey, str_starts_with($source, 'genealogy_') ? 'genealogy_media_original_path' : 'direct_path'];
            }
        }

        return [null, null];
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

    private function isLikelySpellingVariant(string $a, string $b): bool
    {
        if ($a === $b || $a === '' || $b === '') {
            return $a === $b;
        }
        $aParts = explode(' ', $a);
        $bParts = explode(' ', $b);
        $aSurname = end($aParts) ?: '';
        $bSurname = end($bParts) ?: '';
        $givenOverlap = count(array_intersect(array_slice($aParts, 0, -1), array_slice($bParts, 0, -1))) > 0;

        return $givenOverlap && $aSurname !== '' && $bSurname !== '' && levenshtein($aSurname, $bSurname) <= 2;
    }

    private function insertExportRows(string $batch, array $rows): void
    {
        foreach ($rows as $row) {
            DB::insert(
                'INSERT IGNORE INTO photoprism_face_export_rows
                    (export_batch, source_path, normalized_path, normalized_path_hash, face_name, normalized_face_name,
                     region_x, region_y, region_w, region_h, cluster_ref, row_hash, imported_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $batch,
                    $row['source_path'],
                    $row['normalized_path'],
                    $row['normalized_path_hash'],
                    $row['face_name'],
                    $row['normalized_face_name'],
                    $row['region_x'],
                    $row['region_y'],
                    $row['region_w'],
                    $row['region_h'],
                    $row['cluster_ref'],
                    $row['row_hash'],
                ]
            );
        }
    }

    private function upsertProvenanceRows(array $rows): void
    {
        foreach ($rows as $row) {
            DB::insert(
                'INSERT INTO face_identity_provenance
                    (file_registry_face_id, file_registry_id, genealogy_person_id, tree_id, export_batch, status,
                     evidence_source, export_path_hash, export_path_key, face_name, normalized_face_name,
                     canonical_name, matched_export_name, matched_variant_name, reasons, checked_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    file_registry_id = VALUES(file_registry_id),
                    genealogy_person_id = VALUES(genealogy_person_id),
                    tree_id = VALUES(tree_id),
                    export_batch = VALUES(export_batch),
                    status = VALUES(status),
                    evidence_source = VALUES(evidence_source),
                    export_path_hash = VALUES(export_path_hash),
                    export_path_key = VALUES(export_path_key),
                    face_name = VALUES(face_name),
                    normalized_face_name = VALUES(normalized_face_name),
                    canonical_name = VALUES(canonical_name),
                    matched_export_name = VALUES(matched_export_name),
                    matched_variant_name = VALUES(matched_variant_name),
                    reasons = VALUES(reasons),
                    checked_at = NOW(),
                    updated_at = NOW()',
                [
                    $row['file_registry_face_id'],
                    $row['file_registry_id'],
                    $row['genealogy_person_id'],
                    $row['tree_id'],
                    $row['export_batch'],
                    $row['status'],
                    $row['evidence_source'],
                    $row['export_path_hash'],
                    $row['export_path_key'],
                    $row['face_name'],
                    $row['normalized_face_name'],
                    $row['canonical_name'],
                    $row['matched_export_name'],
                    $row['matched_variant_name'],
                    json_encode($row['reasons'], JSON_UNESCAPED_SLASHES),
                ]
            );
        }
    }

    private function decimalOrNull(mixed $value): ?string
    {
        $string = trim((string) $value);
        if ($string === '' || ! is_numeric($string)) {
            return null;
        }

        return $string;
    }

    private function defaultBatch(string $exportPath): string
    {
        $mtime = is_file($exportPath) ? (string) filemtime($exportPath) : 'unknown';

        return substr(preg_replace('/[^a-zA-Z0-9_.-]+/', '-', basename($exportPath)) ?? 'faces-export', 0, 60).'-'.$mtime;
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

    private function renderPayload(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line(sprintf(
            'face-identity-provenance-materialize: mode=%s export=%d classified=%d trusted=%d review=%d',
            $payload['mode'],
            (int) ($payload['summary']['export_rows'] ?? 0),
            (int) ($payload['summary']['faces_classified'] ?? 0),
            (int) ($payload['summary']['trusted_faces'] ?? 0),
            (int) ($payload['summary']['review_faces'] ?? 0)
        ));
    }
}
