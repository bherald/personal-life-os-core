<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;

class GenealogyFamilyRemediationPreviewService
{
    public function preview(array $payload, int $index = 0): ?array
    {
        $operationType = $this->operationType($payload);

        return match ($operationType) {
            'family_duplicate_mark' => $this->previewFamilyDuplicateMark($payload, $index),
            'family_child_unlink' => $this->previewFamilyChildUnlink($payload, $index),
            default => null,
        };
    }

    private function previewFamilyDuplicateMark(array $payload, int $index): array
    {
        [$treeId, $suspectFamilyId, $retainedFamilyId, $childPersonId] = $this->familyRemediationIds($payload, true);
        $suspectFamily = $suspectFamilyId !== null ? $this->familySnapshot($suspectFamilyId) : null;
        $retainedFamily = $retainedFamilyId !== null ? $this->familySnapshot($retainedFamilyId) : null;
        $guards = $this->guards($treeId, $suspectFamilyId, $retainedFamilyId, $childPersonId, $suspectFamily, $retainedFamily);
        $blocked = collect($guards)->contains(fn (array $guard): bool => ($guard['status'] ?? null) === 'fail');
        $state = [
            'suspect_family' => $suspectFamily,
            'retained_family' => $retainedFamily,
            'shared_child_ids' => $this->sharedChildIds($suspectFamily, $retainedFamily),
            'requested_child_person_id' => $childPersonId,
        ];

        return [
            'index' => $index,
            'operation' => 'family_duplicate_mark_preview',
            'operation_type' => 'family_duplicate_mark',
            'target_table' => 'genealogy_families',
            'status' => $blocked ? 'blocked' : 'preview_only',
            'apply_enabled' => false,
            'mutates_accepted_facts' => false,
            'tree_id' => $treeId,
            'suspect_family_id' => $suspectFamilyId,
            'retained_family_id' => $retainedFamilyId,
            'child_person_id' => $childPersonId,
            'guards' => $guards,
            'current_state' => $state,
            'stale_hash' => $this->hash($state),
            'proposed_effect' => [
                'type' => 'mark_only',
                'description' => 'Would mark the suspect family as duplicate or incomplete only after a later approved apply path exists.',
                'rows_that_would_be_touched' => $suspectFamilyId !== null ? [[
                    'table' => 'genealogy_families',
                    'id' => $suspectFamilyId,
                    'action' => 'mark_duplicate_preview_only',
                ]] : [],
            ],
        ];
    }

    private function previewFamilyChildUnlink(array $payload, int $index): array
    {
        [$treeId, $suspectFamilyId, $retainedFamilyId, $childPersonId] = $this->familyRemediationIds($payload);
        $suspectFamily = $suspectFamilyId !== null ? $this->familySnapshot($suspectFamilyId) : null;
        $retainedFamily = $retainedFamilyId !== null ? $this->familySnapshot($retainedFamilyId) : null;
        $suspectChildLinks = $suspectFamilyId !== null && $childPersonId !== null
            ? $this->childLinksForFamilyAndPerson($suspectFamilyId, $childPersonId)
            : [];
        $retainedChildLinks = $retainedFamilyId !== null && $childPersonId !== null
            ? $this->childLinksForFamilyAndPerson($retainedFamilyId, $childPersonId)
            : [];
        $guards = $this->childUnlinkGuards(
            $treeId,
            $suspectFamilyId,
            $retainedFamilyId,
            $childPersonId,
            $suspectFamily,
            $retainedFamily,
            $suspectChildLinks,
            $retainedChildLinks,
        );
        $blocked = collect($guards)->contains(fn (array $guard): bool => ($guard['status'] ?? null) === 'fail');
        $state = [
            'suspect_family' => $suspectFamily,
            'retained_family' => $retainedFamily,
            'suspect_child_links' => $suspectChildLinks,
            'retained_child_links' => $retainedChildLinks,
            'requested_child_person_id' => $childPersonId,
        ];

        return [
            'index' => $index,
            'operation' => 'family_child_unlink_preview',
            'operation_type' => 'family_child_unlink',
            'target_table' => 'genealogy_children',
            'status' => $blocked ? 'blocked' : 'preview_only',
            'apply_enabled' => false,
            'mutates_accepted_facts' => false,
            'tree_id' => $treeId,
            'suspect_family_id' => $suspectFamilyId,
            'retained_family_id' => $retainedFamilyId,
            'child_person_id' => $childPersonId,
            'guards' => $guards,
            'current_state' => $state,
            'stale_hash' => $this->hash($state),
            'proposed_effect' => [
                'type' => 'unlink_child_preview_only',
                'description' => 'Would remove the requested child link from the suspect family only after a later approved apply path exists.',
                'rows_that_would_be_touched' => array_map(fn (array $link): array => [
                    'table' => 'genealogy_children',
                    'id' => (int) $link['id'],
                    'action' => 'unlink_child_preview_only',
                ], $suspectChildLinks),
            ],
        ];
    }

    private function operationType(array $payload): ?string
    {
        foreach (['operation_type', 'operation', 'type', 'change_type'] as $key) {
            $value = $payload[$key] ?? null;
            if (! is_scalar($value)) {
                continue;
            }

            $type = trim((string) $value);
            if (in_array($type, ['family_duplicate_mark', 'family_child_unlink'], true)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return array{0:?int,1:?int,2:?int,3:?int}
     */
    private function familyRemediationIds(array $payload, bool $acceptPrimaryFamilyAlias = false): array
    {
        $retainedFamilyId = $payload['retained_family_id'] ?? $payload['canonical_family_id'] ?? null;
        if ($retainedFamilyId === null && $acceptPrimaryFamilyAlias) {
            $retainedFamilyId = $payload['primary_family_id'] ?? null;
        }

        return [
            $this->positiveInt($payload['tree_id'] ?? $payload['target_tree_id'] ?? null),
            $this->positiveInt($payload['suspect_family_id'] ?? $payload['duplicate_family_id'] ?? $payload['family_id'] ?? null),
            $this->positiveInt($retainedFamilyId),
            $this->positiveInt($payload['child_person_id'] ?? $payload['person_id'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function familySnapshot(int $familyId): ?array
    {
        $family = DB::selectOne(
            'SELECT f.id, f.tree_id, f.gedcom_id, f.husband_id, f.wife_id, f.marriage_date, f.marriage_place,
                    h.given_name AS husband_given, h.surname AS husband_surname,
                    w.given_name AS wife_given, w.surname AS wife_surname
             FROM genealogy_families f
             LEFT JOIN genealogy_persons h ON h.id = f.husband_id
             LEFT JOIN genealogy_persons w ON w.id = f.wife_id
             WHERE f.id = ?',
            [$familyId]
        );

        if (! $family) {
            return null;
        }

        return [
            'id' => (int) $family->id,
            'tree_id' => (int) $family->tree_id,
            'gedcom_id' => $family->gedcom_id,
            'husband_id' => $family->husband_id !== null ? (int) $family->husband_id : null,
            'husband_name' => $this->personName($family->husband_given, $family->husband_surname),
            'wife_id' => $family->wife_id !== null ? (int) $family->wife_id : null,
            'wife_name' => $this->personName($family->wife_given, $family->wife_surname),
            'marriage_date' => $family->marriage_date,
            'marriage_place' => $family->marriage_place,
            'children' => $this->childrenSnapshot((int) $family->id),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function childrenSnapshot(int $familyId): array
    {
        $rows = DB::select(
            'SELECT c.id, c.family_id, c.person_id, c.birth_order, c.father_relationship, c.mother_relationship,
                    p.given_name, p.surname, p.birth_date, p.death_date
             FROM genealogy_children c
             JOIN genealogy_persons p ON p.id = c.person_id
             WHERE c.family_id = ?
             ORDER BY c.birth_order IS NULL, c.birth_order, c.person_id',
            [$familyId]
        );

        return array_map(fn (object $row): array => [
            'id' => (int) $row->id,
            'family_id' => (int) $row->family_id,
            'person_id' => (int) $row->person_id,
            'name' => $this->personName($row->given_name, $row->surname),
            'birth_date' => $row->birth_date,
            'death_date' => $row->death_date,
            'birth_order' => $row->birth_order !== null ? (int) $row->birth_order : null,
            'father_relationship' => $row->father_relationship,
            'mother_relationship' => $row->mother_relationship,
        ], $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function childLinksForFamilyAndPerson(int $familyId, int $personId): array
    {
        return array_values(array_filter(
            $this->childrenSnapshot($familyId),
            fn (array $child): bool => (int) $child['person_id'] === $personId
        ));
    }

    /**
     * @param  array<string, mixed>|null  $suspectFamily
     * @param  array<string, mixed>|null  $retainedFamily
     * @return array<int, array<string, string>>
     */
    private function guards(
        ?int $treeId,
        ?int $suspectFamilyId,
        ?int $retainedFamilyId,
        ?int $childPersonId,
        ?array $suspectFamily,
        ?array $retainedFamily
    ): array {
        $guards = [
            [
                'name' => 'required_ids',
                'status' => $suspectFamilyId !== null && $retainedFamilyId !== null ? 'pass' : 'fail',
                'message' => 'Both suspect_family_id and retained_family_id are required.',
            ],
            [
                'name' => 'distinct_families',
                'status' => $suspectFamilyId !== null && $retainedFamilyId !== null && $suspectFamilyId !== $retainedFamilyId ? 'pass' : 'fail',
                'message' => 'Suspect and retained families must be different rows.',
            ],
            [
                'name' => 'families_exist',
                'status' => $suspectFamily !== null && $retainedFamily !== null ? 'pass' : 'fail',
                'message' => 'Both family rows must still exist.',
            ],
        ];

        if ($suspectFamily === null || $retainedFamily === null) {
            return $guards;
        }

        $sameTree = $suspectFamily['tree_id'] === $retainedFamily['tree_id']
            && ($treeId === null || $treeId === $suspectFamily['tree_id']);
        $sharedChildIds = $this->sharedChildIds($suspectFamily, $retainedFamily);
        $requestedChildMatches = $childPersonId === null || in_array($childPersonId, $sharedChildIds, true);

        $guards[] = [
            'name' => 'same_tree',
            'status' => $sameTree ? 'pass' : 'fail',
            'message' => 'Families must belong to the same target tree.',
        ];
        $guards[] = [
            'name' => 'shared_child',
            'status' => $sharedChildIds !== [] && $requestedChildMatches ? 'pass' : 'fail',
            'message' => 'Duplicate-family preview requires at least one shared child, and the requested child must be shared when supplied.',
        ];
        $guards[] = [
            'name' => 'parent_consistency',
            'status' => $this->parentConflict($suspectFamily, $retainedFamily) ? 'fail' : 'pass',
            'message' => 'Parents/spouses must not conflict; missing parent fields are allowed for incomplete duplicate rows.',
        ];

        return $guards;
    }

    /**
     * @param  array<string, mixed>|null  $suspectFamily
     * @param  array<string, mixed>|null  $retainedFamily
     * @param  array<int, array<string, mixed>>  $suspectChildLinks
     * @param  array<int, array<string, mixed>>  $retainedChildLinks
     * @return array<int, array<string, string>>
     */
    private function childUnlinkGuards(
        ?int $treeId,
        ?int $suspectFamilyId,
        ?int $retainedFamilyId,
        ?int $childPersonId,
        ?array $suspectFamily,
        ?array $retainedFamily,
        array $suspectChildLinks,
        array $retainedChildLinks,
    ): array {
        $guards = [
            [
                'name' => 'required_ids',
                'status' => $suspectFamilyId !== null && $retainedFamilyId !== null && $childPersonId !== null ? 'pass' : 'fail',
                'message' => 'suspect_family_id, retained_family_id, and child_person_id are required.',
            ],
            [
                'name' => 'distinct_families',
                'status' => $suspectFamilyId !== null && $retainedFamilyId !== null && $suspectFamilyId !== $retainedFamilyId ? 'pass' : 'fail',
                'message' => 'Suspect and retained families must be different rows.',
            ],
            [
                'name' => 'families_exist',
                'status' => $suspectFamily !== null && $retainedFamily !== null ? 'pass' : 'fail',
                'message' => 'Both family rows must still exist.',
            ],
            [
                'name' => 'suspect_child_link_exists',
                'status' => $suspectChildLinks !== [] ? 'pass' : 'fail',
                'message' => 'The requested child link must still exist on the suspect family.',
            ],
            [
                'name' => 'retained_child_link_exists',
                'status' => $retainedChildLinks !== [] ? 'pass' : 'fail',
                'message' => 'The retained family must still carry the requested child before unlinking the duplicate child link.',
            ],
        ];

        if ($suspectFamily === null || $retainedFamily === null) {
            return $guards;
        }

        $sameTree = $suspectFamily['tree_id'] === $retainedFamily['tree_id']
            && ($treeId === null || $treeId === $suspectFamily['tree_id']);

        $guards[] = [
            'name' => 'same_tree',
            'status' => $sameTree ? 'pass' : 'fail',
            'message' => 'Families must belong to the same target tree.',
        ];
        $guards[] = [
            'name' => 'parent_consistency',
            'status' => $this->parentConflict($suspectFamily, $retainedFamily) ? 'fail' : 'pass',
            'message' => 'Parents/spouses must not conflict before a duplicate child link can be removed.',
        ];

        return $guards;
    }

    /**
     * @param  array<string, mixed>|null  $suspectFamily
     * @param  array<string, mixed>|null  $retainedFamily
     * @return int[]
     */
    private function sharedChildIds(?array $suspectFamily, ?array $retainedFamily): array
    {
        if ($suspectFamily === null || $retainedFamily === null) {
            return [];
        }

        $suspectIds = array_map(fn (array $child): int => (int) $child['person_id'], $suspectFamily['children'] ?? []);
        $retainedIds = array_map(fn (array $child): int => (int) $child['person_id'], $retainedFamily['children'] ?? []);
        $shared = array_values(array_intersect($suspectIds, $retainedIds));
        sort($shared);

        return $shared;
    }

    /**
     * @param  array<string, mixed>  $suspectFamily
     * @param  array<string, mixed>  $retainedFamily
     */
    private function parentConflict(array $suspectFamily, array $retainedFamily): bool
    {
        foreach (['husband_id', 'wife_id'] as $key) {
            if ($suspectFamily[$key] !== null && $retainedFamily[$key] !== null && $suspectFamily[$key] !== $retainedFamily[$key]) {
                return true;
            }
        }

        return false;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function personName(mixed $given, mixed $surname): ?string
    {
        $name = trim(implode(' ', array_filter([
            is_scalar($given) ? trim((string) $given) : '',
            is_scalar($surname) ? trim((string) $surname) : '',
        ])));

        return $name === '' ? null : $name;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function hash(array $state): string
    {
        $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $encoded === false ? serialize($state) : $encoded);
    }
}
