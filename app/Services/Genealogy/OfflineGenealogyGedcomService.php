<?php

namespace App\Services\Genealogy;

use App\Services\SystemConfigService;
use Illuminate\Support\Facades\File;

class OfflineGenealogyGedcomService
{
    public const POLICY_KEY = 'genealogy.offline_gedcom_snapshot_policy';

    /** @var array<string,array<string,mixed>> */
    private array $snapshots = [];

    public function __construct(
        private readonly SystemConfigService $systemConfig,
    ) {}

    public function resolveTreeId(?int $treeId): ?int
    {
        if ($treeId !== null && $treeId > 0) {
            return $treeId;
        }

        $policy = $this->systemConfig->get(self::POLICY_KEY);
        $trees = is_array($policy) && is_array($policy['trees'] ?? null)
            ? array_keys($policy['trees'])
            : [];
        if (count($trees) !== 1 || ! ctype_digit((string) $trees[0])) {
            return null;
        }

        return (int) $trees[0];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function personSearch(
        int $treeId,
        string $query,
        bool $includeLiving,
        int $limit,
    ): ?array {
        $snapshot = $this->snapshot($treeId);
        if ($snapshot === null) {
            return null;
        }

        $needle = mb_strtolower(trim($query));
        $rows = array_values(array_filter(
            $snapshot['persons'],
            function (array $person) use ($needle, $includeLiving): bool {
                if (! $includeLiving && $this->likelyLiving($person)) {
                    return false;
                }

                return str_contains(mb_strtolower(implode(' ', [
                    (string) ($person['gedcom_id'] ?? ''),
                    (string) ($person['given_name'] ?? ''),
                    (string) ($person['surname'] ?? ''),
                    (string) ($person['suffix'] ?? ''),
                    (string) ($person['nickname'] ?? ''),
                    (string) ($person['birth_date'] ?? ''),
                    (string) ($person['birth_place'] ?? ''),
                    (string) ($person['death_date'] ?? ''),
                    (string) ($person['death_place'] ?? ''),
                ])), $needle);
            }
        ));

        usort($rows, function (array $left, array $right) use ($needle): int {
            $leftName = mb_strtolower($this->displayName($left));
            $rightName = mb_strtolower($this->displayName($right));
            $exact = ((int) ($leftName !== $needle)) <=> ((int) ($rightName !== $needle));

            return $exact
                ?: strcasecmp((string) ($left['surname'] ?? ''), (string) ($right['surname'] ?? ''))
                ?: strcasecmp((string) ($left['given_name'] ?? ''), (string) ($right['given_name'] ?? ''))
                ?: $this->numericId((string) ($left['gedcom_id'] ?? ''))
                    <=> $this->numericId((string) ($right['gedcom_id'] ?? ''));
        });

        $rows = array_slice($rows, 0, max(1, $limit));
        $persons = array_map(fn (array $person): array => $this->personRow($treeId, $person), $rows);

        return [
            'tool' => 'person_search',
            'success' => true,
            'tree_id' => $treeId,
            'tree_name' => $snapshot['header']['source_file'] ?? $snapshot['file'],
            'query' => $query,
            'include_living' => $includeLiving,
            'count' => count($persons),
            'persons' => $persons,
            'results' => array_map(fn (array $person): array => [
                'person_id' => $person['id'],
                'gedcom_id' => $person['gedcom_id'],
                'display_name' => $person['display_name'],
                'lifespan' => trim(($person['birth_date'] ?: '?').' - '.($person['death_date'] ?: '?')),
                'living' => $person['living'],
                'source_count' => count($person['source_refs']),
                'media_count' => count($person['media_refs']),
                'profile_call' => [
                    'tool' => 'person_profile',
                    'arguments' => ['person_id' => $person['id']],
                ],
            ], $persons),
            ...$this->provenance($snapshot),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function treeSearch(int $treeId, string $query, string $type, int $limit): ?array
    {
        $snapshot = $this->snapshot($treeId);
        if ($snapshot === null) {
            return null;
        }

        $needle = mb_strtolower(trim($query));
        $result = [
            'tool' => 'tree_search',
            'success' => true,
            'query' => $query,
            'type' => $type,
            'tree_id' => $treeId,
            ...$this->provenance($snapshot),
            'timestamp' => now()->toIso8601String(),
        ];

        if ($type === 'person' || $type === 'all') {
            $people = $this->personSearch($treeId, $query, true, $limit);
            $result['persons'] = $people['persons'] ?? [];
        }

        if ($type === 'family' || $type === 'all') {
            $people = $snapshot['persons'];
            $names = [];
            foreach ($people as $person) {
                $names[(string) ($person['gedcom_id'] ?? '')] = $this->displayName($person);
            }
            $result['families'] = array_slice(array_values(array_filter(array_map(
                function (array $family) use ($names, $treeId): array {
                    $husband = $names[(string) ($family['husband_id'] ?? '')] ?? '';
                    $wife = $names[(string) ($family['wife_id'] ?? '')] ?? '';

                    return [
                        'id' => $this->numericId((string) ($family['gedcom_id'] ?? '')),
                        'tree_id' => $treeId,
                        'gedcom_id' => $family['gedcom_id'] ?? '',
                        'marriage_date' => $family['marriage_date'] ?? '',
                        'marriage_place' => $family['marriage_place'] ?? '',
                        'husband' => $husband,
                        'wife' => $wife,
                    ];
                },
                $snapshot['families']
            ), fn (array $family): bool => str_contains(mb_strtolower(implode(' ', $family)), $needle))), 0, $limit);
        }

        if ($type === 'source' || $type === 'all') {
            $result['sources'] = array_slice(array_values(array_filter(array_map(
                fn (array $source): array => [
                    'id' => $this->numericId((string) ($source['gedcom_id'] ?? '')),
                    'tree_id' => $treeId,
                    'gedcom_id' => $source['gedcom_id'] ?? '',
                    'title' => $source['title'] ?? '',
                    'author' => $source['author'] ?? '',
                    'publication_info' => $source['publication'] ?? '',
                    'repository_id' => $source['repository'] ?? '',
                ],
                $snapshot['sources']
            ), fn (array $source): bool => str_contains(mb_strtolower(implode(' ', $source)), $needle))), 0, $limit);
        }

        return $result;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function personProfile(int $personId, int $limit): ?array
    {
        $policy = $this->systemConfig->get(self::POLICY_KEY);
        $trees = is_array($policy) && is_array($policy['trees'] ?? null)
            ? array_keys($policy['trees'])
            : [];

        foreach ($trees as $treeIdValue) {
            if (! ctype_digit((string) $treeIdValue)) {
                continue;
            }
            $treeId = (int) $treeIdValue;
            $snapshot = $this->snapshot($treeId);
            if ($snapshot === null) {
                continue;
            }

            $person = collect($snapshot['persons'])->first(
                fn (array $candidate): bool => $this->numericId((string) ($candidate['gedcom_id'] ?? '')) === $personId
            );
            if (! is_array($person)) {
                continue;
            }

            $names = [];
            foreach ($snapshot['persons'] as $candidate) {
                $names[(string) ($candidate['gedcom_id'] ?? '')] = $this->displayName($candidate);
            }
            $parentFamilies = array_values(array_filter(
                $snapshot['families'],
                fn (array $family): bool => in_array(
                    (string) ($person['gedcom_id'] ?? ''),
                    array_column($family['children'] ?? [], 'id'),
                    true
                )
            ));
            $spouseFamilies = array_values(array_filter(
                $snapshot['families'],
                fn (array $family): bool => in_array(
                    (string) ($person['gedcom_id'] ?? ''),
                    [$family['husband_id'] ?? '', $family['wife_id'] ?? ''],
                    true
                )
            ));
            $childIds = [];
            foreach ($spouseFamilies as $family) {
                $childIds = array_merge($childIds, array_column($family['children'] ?? [], 'id'));
            }

            return [
                'tool' => 'person_profile',
                'success' => true,
                'person' => $this->personRow($treeId, $person),
                'parents' => array_slice(array_map(fn (array $family): array => [
                    'family_id' => $this->numericId((string) ($family['gedcom_id'] ?? '')),
                    'father_id' => $this->numericId((string) ($family['husband_id'] ?? '')),
                    'father' => $names[(string) ($family['husband_id'] ?? '')] ?? '',
                    'mother_id' => $this->numericId((string) ($family['wife_id'] ?? '')),
                    'mother' => $names[(string) ($family['wife_id'] ?? '')] ?? '',
                ], $parentFamilies), 0, $limit),
                'spouse_families' => array_slice(array_map(fn (array $family): array => [
                    'family_id' => $this->numericId((string) ($family['gedcom_id'] ?? '')),
                    'husband_id' => $this->numericId((string) ($family['husband_id'] ?? '')),
                    'husband' => $names[(string) ($family['husband_id'] ?? '')] ?? '',
                    'wife_id' => $this->numericId((string) ($family['wife_id'] ?? '')),
                    'wife' => $names[(string) ($family['wife_id'] ?? '')] ?? '',
                    'marriage_date' => $family['marriage_date'] ?? '',
                    'marriage_place' => $family['marriage_place'] ?? '',
                ], $spouseFamilies), 0, $limit),
                'children' => array_slice(array_values(array_filter(array_map(
                    function (string $gedcomId) use ($names): array {
                        return [
                            'person_id' => $this->numericId($gedcomId),
                            'gedcom_id' => $gedcomId,
                            'display_name' => $names[$gedcomId] ?? '',
                        ];
                    },
                    array_values(array_unique($childIds))
                ), static fn (array $child): bool => $child['person_id'] > 0)), 0, $limit),
                ...$this->provenance($snapshot),
                'timestamp' => now()->toIso8601String(),
            ];
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function snapshot(int $treeId): ?array
    {
        $policy = $this->systemConfig->get(self::POLICY_KEY);
        if (! is_array($policy) || ($policy['version'] ?? null) !== 1) {
            return null;
        }

        $tree = $policy['trees'][(string) $treeId] ?? null;
        if (! is_array($tree) || ($tree['selection'] ?? null) !== 'single_gedcom_only') {
            return null;
        }

        $directory = trim((string) ($tree['directory'] ?? ''));
        if ($directory === '') {
            return null;
        }

        $path = str_starts_with($directory, '/')
            ? $directory
            : base_path($directory);
        $resolvedDirectory = realpath($path);
        $resolvedBase = realpath(base_path());
        if (
            $resolvedDirectory === false
            || $resolvedBase === false
            || ! str_starts_with($resolvedDirectory.DIRECTORY_SEPARATOR, $resolvedBase.DIRECTORY_SEPARATOR)
        ) {
            return null;
        }

        $files = array_values(array_filter(
            File::files($resolvedDirectory),
            static fn (\SplFileInfo $file): bool => in_array(strtolower($file->getExtension()), ['ged', 'gedcom'], true)
        ));
        if (count($files) !== 1) {
            return null;
        }

        $file = $files[0]->getRealPath();
        if ($file === false) {
            return null;
        }
        $cacheKey = $file.':'.File::size($file).':'.File::lastModified($file);
        if (isset($this->snapshots[$cacheKey])) {
            return $this->snapshots[$cacheKey];
        }

        $parsed = GedcomParserService::parseFile($file);
        $snapshot = [
            ...$parsed,
            'file' => basename($file),
            'sha256' => hash_file('sha256', $file),
        ];
        $this->snapshots = [$cacheKey => $snapshot];

        return $snapshot;
    }

    /**
     * @return array<string,mixed>
     */
    private function personRow(int $treeId, array $person): array
    {
        return [
            'id' => $this->numericId((string) ($person['gedcom_id'] ?? '')),
            'tree_id' => $treeId,
            'gedcom_id' => $person['gedcom_id'] ?? '',
            'given_name' => $person['given_name'] ?? '',
            'surname' => $person['surname'] ?? '',
            'nickname' => $person['nickname'] ?? '',
            'sex' => $person['sex'] ?: null,
            'birth_date' => $person['birth_date'] ?? '',
            'birth_place' => $person['birth_place'] ?? '',
            'death_date' => $person['death_date'] ?? '',
            'death_place' => $person['death_place'] ?? '',
            'living' => $this->likelyLiving($person) ? 1 : 0,
            'display_name' => $this->displayName($person),
            'primary_photo_id' => null,
            'source_count' => count($person['sources'] ?? []),
            'media_count' => count($person['media_refs'] ?? []),
            'source_refs' => array_values($person['sources'] ?? []),
            'media_refs' => array_values($person['media_refs'] ?? []),
            'name_variants' => null,
        ];
    }

    private function displayName(array $person): string
    {
        return trim(implode(' ', array_filter([
            trim((string) ($person['given_name'] ?? '')),
            trim((string) ($person['surname'] ?? '')),
            trim((string) ($person['suffix'] ?? '')),
        ])));
    }

    private function likelyLiving(array $person): bool
    {
        if (trim((string) ($person['death_date'] ?? '')) !== '') {
            return false;
        }

        preg_match_all('/\b(1[0-9]{3}|20[0-9]{2})\b/', (string) ($person['birth_date'] ?? ''), $matches);
        $years = $matches[1];
        $year = $years === [] ? null : (int) end($years);

        return $year === null || $year > (int) now()->format('Y') - 100;
    }

    private function numericId(string $gedcomId): int
    {
        preg_match('/[0-9]+/', $gedcomId, $match);

        return isset($match[0]) ? (int) $match[0] : 0;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private function provenance(array $snapshot): array
    {
        return [
            'source' => 'local_gedcom_snapshot',
            'database_available' => false,
            'read_only' => true,
            'id_scope' => 'gedcom_numeric',
            'snapshot_file' => $snapshot['file'],
            'snapshot_sha256' => $snapshot['sha256'],
            'limitations' => [
                'Database-only citation, RAG, analysis, and media-link state is unavailable.',
                'Online source verification is unavailable while off-grid.',
            ],
        ];
    }
}
