<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;

class GenealogyIntakeExistingRelationshipLinkService
{
    public function __construct(
        private readonly FamilyService $familyService
    ) {}

    /**
     * Link two existing people through a conservative resolve-or-block strategy.
     * relationship_type is interpreted relative to person_id:
     * - parent: related_person_id is a parent of person_id
     * - child: related_person_id is a child of person_id
     * - spouse: related_person_id is a spouse of person_id
     * - sibling: related_person_id is a sibling of person_id
     */
    public function link(array $proposal): array
    {
        $treeId = (int) ($proposal['tree_id'] ?? 0);
        $personId = (int) ($proposal['person_id'] ?? 0);
        $relatedPersonId = (int) ($proposal['related_person_id'] ?? 0);
        $relationshipType = trim((string) ($proposal['relationship_type'] ?? ''));

        if ($treeId < 1 || $personId < 1 || $relatedPersonId < 1 || $relationshipType === '') {
            return ['success' => false, 'error' => 'missing_relationship_link_fields'];
        }

        if ($personId === $relatedPersonId) {
            return ['success' => false, 'error' => 'cannot_link_person_to_self'];
        }

        $person = $this->loadPerson($personId, $treeId);
        $related = $this->loadPerson($relatedPersonId, $treeId);
        if ($person === null || $related === null) {
            return ['success' => false, 'error' => 'relationship_person_not_found_in_tree'];
        }

        return match ($relationshipType) {
            'spouse' => $this->linkSpouses($treeId, $person, $related),
            'parent' => $this->linkParent($treeId, $person, $related),
            'child' => $this->linkChild($treeId, $person, $related),
            'sibling' => $this->linkSibling($treeId, $person, $related),
            default => ['success' => false, 'error' => 'unsupported_relationship_type'],
        };
    }

    private function linkSpouses(int $treeId, object $person, object $related): array
    {
        $existingFamily = DB::selectOne(
            'SELECT id FROM genealogy_families WHERE tree_id = ? AND ((husband_id = ? AND wife_id = ?) OR (husband_id = ? AND wife_id = ?)) LIMIT 1',
            [$treeId, $person->id, $related->id, $related->id, $person->id]
        );
        if ($existingFamily) {
            return ['success' => true, 'family_id' => (int) $existingFamily->id, 'status' => 'already_linked'];
        }

        [$husbandId, $wifeId, $slotError] = $this->resolveSpouseSlots($person, $related);
        if ($slotError !== null) {
            return ['success' => false, 'error' => $slotError];
        }

        $familyId = $this->familyService->createFamily($treeId, [
            'husband_id' => $husbandId,
            'wife_id' => $wifeId,
        ]);

        return ['success' => true, 'family_id' => $familyId, 'status' => 'created_family'];
    }

    private function linkParent(int $treeId, object $child, object $parent): array
    {
        $families = $this->getChildFamilies($child->id, $treeId);
        if (count($families) > 1) {
            return ['success' => false, 'error' => 'ambiguous_child_family'];
        }

        if ($families === []) {
            [$husbandId, $wifeId, $slotError] = $this->resolveParentSlots($parent, null, null);
            if ($slotError !== null) {
                return ['success' => false, 'error' => $slotError];
            }

            $familyId = $this->familyService->createFamily($treeId, [
                'husband_id' => $husbandId,
                'wife_id' => $wifeId,
            ]);
            $this->familyService->addChildToFamily($familyId, (int) $child->id);

            return ['success' => true, 'family_id' => $familyId, 'status' => 'created_family_with_child'];
        }

        $family = $families[0];
        if ((int) $family->husband_id === (int) $parent->id || (int) $family->wife_id === (int) $parent->id) {
            return ['success' => true, 'family_id' => (int) $family->id, 'status' => 'already_linked'];
        }

        [$husbandId, $wifeId, $slotError] = $this->resolveParentSlots($parent, $family->husband_id, $family->wife_id);
        if ($slotError !== null) {
            return ['success' => false, 'error' => $slotError];
        }

        $this->familyService->updateFamily((int) $family->id, [
            'husband_id' => $husbandId,
            'wife_id' => $wifeId,
        ]);

        return ['success' => true, 'family_id' => (int) $family->id, 'status' => 'updated_family'];
    }

    private function linkChild(int $treeId, object $parent, object $child): array
    {
        $families = $this->getChildFamilies($child->id, $treeId);
        if (count($families) > 1) {
            return ['success' => false, 'error' => 'ambiguous_child_family'];
        }

        if ($families !== []) {
            $family = $families[0];
            if ((int) $family->husband_id === (int) $parent->id || (int) $family->wife_id === (int) $parent->id) {
                return ['success' => true, 'family_id' => (int) $family->id, 'status' => 'already_linked'];
            }

            [$husbandId, $wifeId, $slotError] = $this->resolveParentSlots($parent, $family->husband_id, $family->wife_id);
            if ($slotError !== null) {
                return ['success' => false, 'error' => $slotError];
            }

            $this->familyService->updateFamily((int) $family->id, [
                'husband_id' => $husbandId,
                'wife_id' => $wifeId,
            ]);

            return ['success' => true, 'family_id' => (int) $family->id, 'status' => 'updated_family'];
        }

        $parentFamilies = DB::select(
            'SELECT id, husband_id, wife_id FROM genealogy_families WHERE tree_id = ? AND (husband_id = ? OR wife_id = ?) ORDER BY id ASC',
            [$treeId, $parent->id, $parent->id]
        );

        if (count($parentFamilies) > 1) {
            return ['success' => false, 'error' => 'ambiguous_parent_family'];
        }

        if ($parentFamilies !== []) {
            $familyId = (int) $parentFamilies[0]->id;
            $this->familyService->addChildToFamily($familyId, (int) $child->id);

            return ['success' => true, 'family_id' => $familyId, 'status' => 'added_child'];
        }

        [$husbandId, $wifeId, $slotError] = $this->resolveParentSlots($parent, null, null);
        if ($slotError !== null) {
            return ['success' => false, 'error' => $slotError];
        }

        $familyId = $this->familyService->createFamily($treeId, [
            'husband_id' => $husbandId,
            'wife_id' => $wifeId,
        ]);
        $this->familyService->addChildToFamily($familyId, (int) $child->id);

        return ['success' => true, 'family_id' => $familyId, 'status' => 'created_family_with_child'];
    }

    private function linkSibling(int $treeId, object $person, object $related): array
    {
        $personFamilies = $this->getChildFamilies($person->id, $treeId);
        $relatedFamilies = $this->getChildFamilies($related->id, $treeId);

        if (count($personFamilies) > 1 || count($relatedFamilies) > 1) {
            return ['success' => false, 'error' => 'ambiguous_sibling_family'];
        }

        if ($personFamilies !== [] && $relatedFamilies !== [] && (int) $personFamilies[0]->id !== (int) $relatedFamilies[0]->id) {
            return ['success' => false, 'error' => 'distinct_existing_families'];
        }

        if ($personFamilies !== [] && $relatedFamilies !== [] && (int) $personFamilies[0]->id === (int) $relatedFamilies[0]->id) {
            return ['success' => true, 'family_id' => (int) $personFamilies[0]->id, 'status' => 'already_linked'];
        }

        if ($personFamilies !== []) {
            $familyId = (int) $personFamilies[0]->id;
            $this->familyService->addChildToFamily($familyId, (int) $related->id);

            return ['success' => true, 'family_id' => $familyId, 'status' => 'added_sibling_to_family'];
        }

        if ($relatedFamilies !== []) {
            $familyId = (int) $relatedFamilies[0]->id;
            $this->familyService->addChildToFamily($familyId, (int) $person->id);

            return ['success' => true, 'family_id' => $familyId, 'status' => 'added_sibling_to_family'];
        }

        $familyId = $this->familyService->createFamily($treeId, []);
        $this->familyService->addChildToFamily($familyId, (int) $person->id);
        $this->familyService->addChildToFamily($familyId, (int) $related->id);

        return ['success' => true, 'family_id' => $familyId, 'status' => 'created_sibling_family'];
    }

    private function loadPerson(int $personId, int $treeId): ?object
    {
        return DB::selectOne(
            'SELECT id, tree_id, sex FROM genealogy_persons WHERE id = ? AND tree_id = ? LIMIT 1',
            [$personId, $treeId]
        );
    }

    private function getChildFamilies(int $personId, int $treeId): array
    {
        return DB::select(
            'SELECT gf.id, gf.husband_id, gf.wife_id
             FROM genealogy_children gc
             JOIN genealogy_families gf ON gf.id = gc.family_id
             WHERE gc.person_id = ? AND gf.tree_id = ?
             ORDER BY gf.id ASC',
            [$personId, $treeId]
        );
    }

    private function resolveSpouseSlots(object $person, object $related): array
    {
        $personSex = strtoupper((string) ($person->sex ?? ''));
        $relatedSex = strtoupper((string) ($related->sex ?? ''));

        if ($personSex === 'M' && $relatedSex !== 'M') {
            return [(int) $person->id, (int) $related->id, null];
        }

        if ($personSex === 'F' && $relatedSex !== 'F') {
            return [(int) $related->id, (int) $person->id, null];
        }

        if ($personSex === 'U' && $relatedSex === 'M') {
            return [(int) $related->id, (int) $person->id, null];
        }

        if ($personSex === 'U' && $relatedSex === 'F') {
            return [(int) $person->id, (int) $related->id, null];
        }

        if ($personSex === 'M' && $relatedSex === 'U') {
            return [(int) $person->id, (int) $related->id, null];
        }

        if ($personSex === 'F' && $relatedSex === 'U') {
            return [(int) $related->id, (int) $person->id, null];
        }

        return [null, null, 'spouse_slot_ambiguous'];
    }

    private function resolveParentSlots(object $parent, mixed $existingHusbandId, mixed $existingWifeId): array
    {
        $parentId = (int) $parent->id;
        $sex = strtoupper((string) ($parent->sex ?? ''));
        $husbandId = $existingHusbandId !== null ? (int) $existingHusbandId : null;
        $wifeId = $existingWifeId !== null ? (int) $existingWifeId : null;

        if ($husbandId === $parentId || $wifeId === $parentId) {
            return [$husbandId, $wifeId, null];
        }

        if ($sex === 'M') {
            if ($husbandId !== null && $husbandId !== $parentId) {
                return [$husbandId, $wifeId, 'family_has_other_parents'];
            }

            return [$parentId, $wifeId, null];
        }

        if ($sex === 'F') {
            if ($wifeId !== null && $wifeId !== $parentId) {
                return [$husbandId, $wifeId, 'family_has_other_parents'];
            }

            return [$husbandId, $parentId, null];
        }

        if ($sex === 'U' && $husbandId === null && $wifeId === null) {
            return [$parentId, null, null];
        }

        if ($husbandId === null && $wifeId !== null) {
            return [$parentId, $wifeId, null];
        }

        if ($wifeId === null && $husbandId !== null) {
            return [$husbandId, $parentId, null];
        }

        return [$husbandId, $wifeId, 'parent_slot_ambiguous'];
    }
}
