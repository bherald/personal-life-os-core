<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class FileRegistryDuplicateCandidateMaterializationService
{
    public const CONFIRM_TOKEN = 'FILE-DUPLICATE-CANDIDATES';

    /**
     * Classify exact same-filename/content duplicate groups and optionally
     * materialize review rows in file_registry_duplicates.
     */
    public function materializeCandidates(int $limit = 1000, bool $dryRun = true): array
    {
        $limit = max(1, min(5000, $limit));
        $groups = $this->candidateGroups($limit);

        $result = [
            'status' => $dryRun ? 'dry_run' : 'materialized',
            'dry_run' => $dryRun,
            'groups_scanned' => count($groups),
            'groups_planned' => 0,
            'groups_with_existing_pairs' => 0,
            'pairs_planned' => 0,
            'pairs_inserted' => 0,
            'pairs_existing' => 0,
            'candidate_bytes' => 0,
            'by_classification' => [],
            'by_status' => [],
            'samples' => [],
            'errors' => [],
        ];

        foreach ($groups as $group) {
            try {
                $files = $this->filesForGroup($group);
                if (count($files) < 2) {
                    continue;
                }

                $classification = $this->classifyFiles($files);
                $canonical = $this->chooseCanonicalFile($files);
                $status = $classification['status'];
                $pairs = array_values(array_filter(
                    $files,
                    fn (object $file): bool => (int) $file->id !== (int) $canonical->id
                ));

                if ($pairs === []) {
                    continue;
                }

                $result['groups_planned']++;
                $result['by_classification'][$classification['key']] = ($result['by_classification'][$classification['key']] ?? 0) + 1;

                $groupHadExistingPairs = false;
                foreach ($pairs as $duplicate) {
                    if ($this->existingPair((int) $canonical->id, (int) $duplicate->id, (string) $group->content_hash)) {
                        $result['pairs_existing']++;
                        $groupHadExistingPairs = true;

                        continue;
                    }

                    $result['pairs_planned']++;
                    $result['by_status'][$status] = ($result['by_status'][$status] ?? 0) + 1;
                    $result['candidate_bytes'] += (int) ($duplicate->file_size ?? 0);

                    if (! $dryRun) {
                        $this->insertPair($canonical, $duplicate, $group, $classification, $status);
                        $result['pairs_inserted']++;
                    }
                }

                if ($groupHadExistingPairs) {
                    $result['groups_with_existing_pairs']++;
                }

                if (count($result['samples']) < 10) {
                    $result['samples'][] = [
                        'classification' => $classification['key'],
                        'status' => $status,
                        'reason' => $classification['reason'],
                        'filename' => (string) ($group->filename ?? ''),
                        'file_size' => (int) ($group->file_size ?? 0),
                        'file_count' => count($files),
                        'canonical_path' => (string) ($canonical->current_path ?? ''),
                        'duplicate_paths' => array_slice(array_values(array_map(
                            fn (object $file): string => (string) ($file->current_path ?? ''),
                            $pairs
                        )), 0, 5),
                    ];
                }
            } catch (Throwable $e) {
                $result['errors'][] = [
                    'content_hash_prefix' => substr((string) ($group->content_hash ?? ''), 0, 12),
                    'filename' => (string) ($group->filename ?? ''),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * @param  array<int, object>  $files
     * @return array{key: string, status: string, reason: string}
     */
    public function classifyFiles(array $files): array
    {
        $paths = array_map(
            fn (object $file): string => strtolower(str_replace('\\', '/', (string) ($file->current_path ?? ''))),
            $files
        );

        if ($paths !== [] && count(array_filter($paths, fn (string $path): bool => $this->isSoftwareOrProjectAssetPath($path))) === count($paths)) {
            return [
                'key' => 'keep_both_software_or_project_asset',
                'status' => 'keep_both',
                'reason' => 'Exact duplicate content appears only in software/project asset paths where repeated copies are expected.',
            ];
        }

        if (array_filter($paths, fn (string $path): bool => $this->isBackupOrLegacyPath($path)) !== []) {
            return [
                'key' => 'review_backup_or_legacy_copy',
                'status' => 'pending_review',
                'reason' => 'Exact duplicate content includes backup, corrupted, legacy, or copied-media paths; review before consolidation.',
            ];
        }

        if (array_filter($paths, fn (string $path): bool => $this->isGenealogyMediaPath($path)) !== []) {
            return [
                'key' => 'review_genealogy_media_duplicate',
                'status' => 'pending_review',
                'reason' => 'Exact duplicate content appears in genealogy/media paths; review before any filesystem action.',
            ];
        }

        return [
            'key' => 'review_exact_duplicate',
            'status' => 'pending_review',
            'reason' => 'Exact duplicate content with the same filename requires operator or curator review.',
        ];
    }

    /**
     * @param  array<int, object>  $files
     */
    public function chooseCanonicalFile(array $files): object
    {
        usort($files, function (object $a, object $b): int {
            $score = $this->canonicalScore($b) <=> $this->canonicalScore($a);
            if ($score !== 0) {
                return $score;
            }

            $length = strlen((string) ($a->current_path ?? '')) <=> strlen((string) ($b->current_path ?? ''));
            if ($length !== 0) {
                return $length;
            }

            return ((int) ($a->id ?? 0)) <=> ((int) ($b->id ?? 0));
        });

        return $files[0];
    }

    /**
     * @return array<int, object>
     */
    private function candidateGroups(int $limit): array
    {
        return DB::select(
            "SELECT content_hash,
                    filename,
                    file_size,
                    COUNT(*) AS file_count,
                    COUNT(DISTINCT current_path) AS path_count
             FROM file_registry
             WHERE status = 'active'
               AND content_hash IS NOT NULL
               AND content_hash <> ''
               AND filename IS NOT NULL
             GROUP BY content_hash, filename, file_size
             HAVING COUNT(*) > 1 AND COUNT(DISTINCT current_path) > 1
             ORDER BY file_count DESC, path_count DESC
             LIMIT ?",
            [$limit]
        );
    }

    /**
     * @return array<int, object>
     */
    private function filesForGroup(object $group): array
    {
        return DB::select(
            "SELECT id,
                    asset_uuid,
                    current_path,
                    filename,
                    file_size,
                    content_hash,
                    mime_type,
                    category,
                    ai_document_type,
                    ai_tags,
                    original_source,
                    nextcloud_modified_at,
                    updated_at,
                    created_at
             FROM file_registry
             WHERE status = 'active'
               AND content_hash = ?
               AND filename = ?
               AND file_size <=> ?
             ORDER BY current_path ASC, id ASC",
            [
                (string) $group->content_hash,
                (string) $group->filename,
                $group->file_size,
            ]
        );
    }

    private function existingPair(int $canonicalId, int $duplicateId, string $contentHash): bool
    {
        $row = DB::selectOne(
            'SELECT id
             FROM file_registry_duplicates
             WHERE content_hash = ?
               AND (
                 (canonical_file_id = ? AND duplicate_file_id = ?)
                 OR (canonical_file_id = ? AND duplicate_file_id = ?)
               )
             LIMIT 1',
            [$contentHash, $canonicalId, $duplicateId, $duplicateId, $canonicalId]
        );

        return $row !== null;
    }

    /**
     * @param  array{key: string, status: string, reason: string}  $classification
     */
    private function insertPair(object $canonical, object $duplicate, object $group, array $classification, string $status): void
    {
        $notes = [
            'source' => 'files:materialize-duplicate-candidates',
            'classification' => $classification['key'],
            'reason' => $classification['reason'],
            'file_mutation' => false,
            'canonical_path' => (string) ($canonical->current_path ?? ''),
            'duplicate_path' => (string) ($duplicate->current_path ?? ''),
            'created_by' => 'codex_pipeline',
        ];

        DB::insert(
            'INSERT INTO file_registry_duplicates (
                content_hash,
                canonical_file_id,
                duplicate_file_id,
                status,
                reviewed_by,
                reviewed_at,
                notes,
                created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                (string) $group->content_hash,
                (int) $canonical->id,
                (int) $duplicate->id,
                $status,
                $status === 'keep_both' ? 'ai' : null,
                $status === 'keep_both' ? now()->toDateTimeString() : null,
                json_encode($notes, JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    private function canonicalScore(object $file): int
    {
        $path = strtolower(str_replace('\\', '/', (string) ($file->current_path ?? '')));
        $score = 0;

        if (! $this->isBackupOrLegacyPath($path)) {
            $score += 20;
        }

        if (! str_contains($path, '/data dvd copies/')) {
            $score += 5;
        }

        if (! empty($file->ai_tags) && $file->ai_tags !== '[]' && $file->ai_tags !== 'null') {
            $score += 4;
        }

        if (! empty($file->ai_document_type)) {
            $score += 3;
        }

        if (! empty($file->category)) {
            $score += 2;
        }

        if (! empty($file->updated_at)) {
            $score += 1;
        }

        return $score;
    }

    private function isSoftwareOrProjectAssetPath(string $path): bool
    {
        foreach ([
            '/10-projects/',
            '/source/',
            '/xsource/',
            '/node_modules/',
            '/vendor/',
            '/program files/',
            '/visual foxpro',
            '/phidgets',
            '/wwwroot/',
            '/public_html/',
        ] as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isBackupOrLegacyPath(string $path): bool
    {
        foreach ([
            '/backup',
            ' backup/',
            ' backups/',
            '/backups/',
            '/corrupted/',
            '/morecorrupted/',
            '/old/',
            '/archive/',
            '/data dvd copies/',
            '/copy of ',
            '/copies/',
        ] as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isGenealogyMediaPath(string $path): bool
    {
        foreach ([
            '/family tree maker/',
            '/genealogy/',
            '/genea/',
            '/ft/',
            '/familytree/',
            '/media/',
            '/photos/',
        ] as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }

        return false;
    }
}
