<?php

namespace App\Services\Genealogy;

use App\Controllers\NotificationController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * FamilyService - Family CRUD and Related Operations
 *
 * Extracted from GenealogyService as part of Priority 2.1 service extraction.
 * Handles family creation, updates, deletion, children management, and family events.
 *
 * @see /docs/genealogy-module-review.md Priority 2.1
 */
class FamilyService
{
    /**
     * GEDCOM 5.5.1 Family Event Types
     */
    public const FAMILY_EVENT_TYPES = [
        'MARB' => 'Marriage Bann',
        'MARC' => 'Marriage Contract',
        'MARL' => 'Marriage License',
        'MARS' => 'Marriage Settlement',
        'ENGA' => 'Engagement',
        'ANUL' => 'Annulment',
        'CENS' => 'Census',
        'EVEN' => 'Custom Event',
    ];

    protected TreeManagementService $treeService;

    protected PersonService $personService;

    protected ?GenealogyChangeHistoryService $changeHistory = null;

    protected ?GenealogyService $genealogyService = null;

    public function __construct(TreeManagementService $treeService, PersonService $personService, ?GenealogyChangeHistoryService $changeHistory = null)
    {
        $this->treeService = $treeService;
        $this->personService = $personService;
        $this->changeHistory = $changeHistory;
    }

    /**
     * Get the change history service (lazy load if not injected)
     */
    protected function getChangeHistory(): GenealogyChangeHistoryService
    {
        if ($this->changeHistory === null) {
            $this->changeHistory = app(GenealogyChangeHistoryService::class);
        }

        return $this->changeHistory;
    }

    /**
     * Get GenealogyService (lazy load — avoids circular dependency at construction time)
     */
    protected function getGenealogyService(): GenealogyService
    {
        if ($this->genealogyService === null) {
            $this->genealogyService = app(GenealogyService::class);
        }

        return $this->genealogyService;
    }

    // ========================================================================
    // FAMILY CRUD
    // ========================================================================

    /**
     * Get all families for a tree
     *
     * @param  int  $treeId  Tree ID
     */
    public function getFamilies(int $treeId): array
    {
        $sql = "SELECT f.*,
                       h.id as husband_id, h.given_name as husband_given, h.surname as husband_surname,
                       w.id as wife_id, w.given_name as wife_given, w.surname as wife_surname
                FROM genealogy_families f
                LEFT JOIN genealogy_persons h ON h.id = f.husband_id
                LEFT JOIN genealogy_persons w ON w.id = f.wife_id
                WHERE f.tree_id = ?
                ORDER BY COALESCE(f.marriage_date, '9999')";

        return DB::select($sql, [$treeId]);
    }

    /**
     * Get a family by ID with full details
     *
     * @param  int  $familyId  Family ID
     */
    public function getFamily(int $familyId): ?array
    {
        $sql = 'SELECT f.*,
                       h.id as husband_id, h.given_name as husband_given, h.surname as husband_surname,
                       h.birth_date as husband_birth, h.death_date as husband_death,
                       w.id as wife_id, w.given_name as wife_given, w.surname as wife_surname,
                       w.birth_date as wife_birth, w.death_date as wife_death
                FROM genealogy_families f
                LEFT JOIN genealogy_persons h ON h.id = f.husband_id
                LEFT JOIN genealogy_persons w ON w.id = f.wife_id
                WHERE f.id = ?';

        $family = DB::selectOne($sql, [$familyId]);
        if (! $family) {
            return null;
        }

        $result = (array) $family;
        $result['children'] = $this->getFamilyChildren($familyId);
        $result['events'] = $this->getFamilyEvents($familyId);

        return $result;
    }

    /**
     * Get basic family data without relations
     *
     * @param  int  $familyId  Family ID
     */
    public function getFamilyBasic(int $familyId): ?object
    {
        $sql = 'SELECT * FROM genealogy_families WHERE id = ?';

        return DB::selectOne($sql, [$familyId]);
    }

    /**
     * Create a new family
     *
     * @param  int  $treeId  Tree ID
     * @param  array  $data  Family data
     * @return int New family ID
     *
     * @throws InvalidArgumentException If validation fails
     */
    public function createFamily(int $treeId, array $data): int
    {
        // Validate business rules
        $this->validateFamilyData($data);

        $gedcomId = $data['gedcom_id'] ?? $this->generateGedcomId($treeId, 'F');

        $sql = 'INSERT INTO genealogy_families (
                    tree_id, gedcom_id, husband_id, wife_id,
                    marriage_date, marriage_place, marriage_lat, marriage_lon,
                    divorce_date, divorce_place, annulment_date, notes,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

        DB::insert($sql, [
            $treeId,
            $gedcomId,
            $data['husband_id'] ?? null,
            $data['wife_id'] ?? null,
            $data['marriage_date'] ?? null,
            $data['marriage_place'] ?? null,
            $data['marriage_lat'] ?? null,
            $data['marriage_lon'] ?? null,
            $data['divorce_date'] ?? null,
            $data['divorce_place'] ?? null,
            $data['annulment_date'] ?? null,
            $data['notes'] ?? null,
        ]);

        $familyId = (int) DB::getPdo()->lastInsertId();
        $this->treeService->updateTreeStats($treeId);

        // Record change history
        $this->getChangeHistory()->recordCreate('family', $familyId, $treeId, $data);

        Log::info('FamilyService: Family created', [
            'family_id' => $familyId,
            'tree_id' => $treeId,
            'husband_id' => $data['husband_id'] ?? null,
            'wife_id' => $data['wife_id'] ?? null,
        ]);

        return $familyId;
    }

    /**
     * Update a family
     *
     * @param  int  $familyId  Family ID
     * @param  array  $data  Update data
     * @return bool Success
     *
     * @throws InvalidArgumentException If validation fails
     */
    public function updateFamily(int $familyId, array $data): bool
    {
        // Merge with existing family data for validation
        $existingFamily = $this->getFamilyBasic($familyId);
        $oldData = $existingFamily ? (array) $existingFamily : [];

        if ($existingFamily) {
            $validationData = array_merge([
                'husband_id' => $existingFamily->husband_id,
                'wife_id' => $existingFamily->wife_id,
                'marriage_date' => $existingFamily->marriage_date,
            ], $data);
            $this->validateFamilyData($validationData, $familyId);
        }

        $allowedFields = [
            'husband_id', 'wife_id',
            'marriage_date', 'marriage_place', 'marriage_lat', 'marriage_lon',
            'divorce_date', 'divorce_place', 'annulment_date', 'notes',
        ];

        $fields = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'updated_at = NOW()';
        $params[] = $familyId;

        $sql = 'UPDATE genealogy_families SET '.implode(', ', $fields).' WHERE id = ?';
        $updated = DB::update($sql, $params) > 0;

        if ($updated) {
            // Record change history
            $treeId = $oldData['tree_id'] ?? $this->getFamilyTreeId($familyId);
            if ($treeId) {
                $this->getChangeHistory()->recordUpdate('family', $familyId, $treeId, $oldData, $data);
            }

            Log::info('FamilyService: Family updated', [
                'family_id' => $familyId,
                'fields' => array_keys($data),
            ]);
        }

        return $updated;
    }

    /**
     * Delete a family
     *
     * @param  int  $familyId  Family ID
     * @return bool Success
     */
    public function deleteFamily(int $familyId): bool
    {
        // Get full family data for change history
        $family = $this->getFamilyBasic($familyId);
        if (! $family) {
            return false;
        }

        $familyData = (array) $family;
        $treeId = $family->tree_id;

        // Foreign keys with CASCADE will handle related records (children links, media, sources)
        $sql = 'DELETE FROM genealogy_families WHERE id = ?';
        $result = DB::delete($sql, [$familyId]) > 0;

        if ($result) {
            // Record change history before stats update
            $this->getChangeHistory()->recordDelete('family', $familyId, $treeId, $familyData);

            $this->treeService->updateTreeStats($treeId);
            Log::info('FamilyService: Family deleted', [
                'family_id' => $familyId,
                'tree_id' => $treeId,
            ]);
        }

        return $result;
    }

    // ========================================================================
    // CHILDREN MANAGEMENT
    // ========================================================================

    /**
     * Get children of a family
     *
     * @param  int  $familyId  Family ID
     */
    public function getFamilyChildren(int $familyId): array
    {
        $sql = 'SELECT p.id, p.given_name, p.surname, p.sex, p.birth_date, p.death_date,
                       c.birth_order, c.father_relationship, c.mother_relationship
                FROM genealogy_children c
                JOIN genealogy_persons p ON p.id = c.person_id
                WHERE c.family_id = ?
                ORDER BY c.birth_order, p.birth_date';

        return DB::select($sql, [$familyId]);
    }

    /**
     * Add a child to a family
     *
     * @param  int  $familyId  Family ID
     * @param  int  $personId  Person ID
     * @param  array  $options  Options (birth_order, father_relationship, mother_relationship)
     * @return bool Success
     *
     * @throws InvalidArgumentException If validation fails
     */
    public function addChildToFamily(int $familyId, int $personId, array $options = []): bool
    {
        // Validate child-family relationship business rules
        $this->validateChildFamilyRelationship($familyId, $personId);

        $this->linkChildToFamily(
            $familyId,
            $personId,
            $options['birth_order'] ?? null
        );

        // Update relationship types if specified
        if (isset($options['father_relationship']) || isset($options['mother_relationship'])) {
            $sql = 'UPDATE genealogy_children SET
                        father_relationship = COALESCE(?, father_relationship),
                        mother_relationship = COALESCE(?, mother_relationship)
                    WHERE family_id = ? AND person_id = ?';

            DB::update($sql, [
                $options['father_relationship'] ?? null,
                $options['mother_relationship'] ?? null,
                $familyId,
                $personId,
            ]);
        }

        Log::info('FamilyService: Child added to family', [
            'family_id' => $familyId,
            'person_id' => $personId,
        ]);

        return true;
    }

    /**
     * Remove a child from a family
     *
     * @param  int  $familyId  Family ID
     * @param  int  $personId  Person ID
     * @return bool Success
     */
    public function removeChildFromFamily(int $familyId, int $personId): bool
    {
        $sql = 'DELETE FROM genealogy_children WHERE family_id = ? AND person_id = ?';
        $result = DB::delete($sql, [$familyId, $personId]) > 0;

        if ($result) {
            Log::info('FamilyService: Child removed from family', [
                'family_id' => $familyId,
                'person_id' => $personId,
            ]);
        }

        return $result;
    }

    /**
     * Sync children for a family - replace all children with the given list
     *
     * @param  int  $familyId  Family ID
     * @param  array  $childIds  Array of person IDs
     */
    public function syncFamilyChildren(int $familyId, array $childIds): void
    {
        // Get current children
        $sql = 'SELECT person_id FROM genealogy_children WHERE family_id = ?';
        $currentChildren = DB::select($sql, [$familyId]);
        $currentIds = array_column($currentChildren, 'person_id');

        // Remove children no longer in the list
        $toRemove = array_diff($currentIds, $childIds);
        foreach ($toRemove as $personId) {
            $this->removeChildFromFamily($familyId, $personId);
        }

        // Add new children
        $toAdd = array_diff($childIds, $currentIds);
        foreach ($toAdd as $personId) {
            $this->addChildToFamily($familyId, $personId);
        }
    }

    /**
     * Link a child to a family (internal method)
     *
     * @param  int  $familyId  Family ID
     * @param  int  $personId  Person ID
     * @param  int|null  $birthOrder  Birth order
     */
    private function linkChildToFamily(int $familyId, int $personId, ?int $birthOrder = null): void
    {
        // Check if link already exists
        $existing = DB::selectOne(
            'SELECT id FROM genealogy_children WHERE family_id = ? AND person_id = ?',
            [$familyId, $personId]
        );

        if (! $existing) {
            $sql = 'INSERT INTO genealogy_children (family_id, person_id, birth_order, created_at)
                    VALUES (?, ?, ?, NOW())';
            DB::insert($sql, [$familyId, $personId, $birthOrder]);
        }
    }

    // ========================================================================
    // FAMILY EVENTS
    // ========================================================================

    /**
     * Get all family events for a family
     *
     * @param  int  $familyId  Family ID
     */
    public function getFamilyEvents(int $familyId): array
    {
        $sql = 'SELECT e.*, s.title as source_title
                FROM genealogy_family_events e
                LEFT JOIN genealogy_sources s ON s.id = e.source_id
                WHERE e.family_id = ?
                ORDER BY e.event_date';

        return DB::select($sql, [$familyId]);
    }

    /**
     * Get a single family event by ID
     *
     * @param  int  $eventId  Event ID
     */
    public function getFamilyEvent(int $eventId): ?object
    {
        $sql = 'SELECT e.*, s.title as source_title,
                       f.id as family_id,
                       h.given_name as husband_given, h.surname as husband_surname,
                       w.given_name as wife_given, w.surname as wife_surname
                FROM genealogy_family_events e
                LEFT JOIN genealogy_sources s ON s.id = e.source_id
                LEFT JOIN genealogy_families f ON f.id = e.family_id
                LEFT JOIN genealogy_persons h ON h.id = f.husband_id
                LEFT JOIN genealogy_persons w ON w.id = f.wife_id
                WHERE e.id = ?';

        return DB::selectOne($sql, [$eventId]);
    }

    /**
     * Create a new family event
     *
     * @param  int  $familyId  Family ID
     * @param  array  $data  Event data
     * @return int Event ID
     */
    public function createFamilyEvent(int $familyId, array $data): int
    {
        $sql = 'INSERT INTO genealogy_family_events (
                    family_id, event_type, event_date, event_place,
                    latitude, longitude, description, source_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())';

        DB::insert($sql, [
            $familyId,
            $data['event_type'] ?? 'EVEN',
            $data['event_date'] ?? null,
            $data['event_place'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['description'] ?? null,
            $data['source_id'] ?? null,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Update a family event
     *
     * @param  int  $eventId  Event ID
     * @param  array  $data  Update data
     * @return bool Success
     */
    public function updateFamilyEvent(int $eventId, array $data): bool
    {
        $allowedFields = [
            'event_type', 'event_date', 'event_place',
            'latitude', 'longitude', 'description', 'source_id',
        ];

        $fields = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $eventId;

        $sql = 'UPDATE genealogy_family_events SET '.implode(', ', $fields).' WHERE id = ?';

        return DB::update($sql, $params) > 0;
    }

    /**
     * Delete a family event
     *
     * @param  int  $eventId  Event ID
     * @return bool Success
     */
    public function deleteFamilyEvent(int $eventId): bool
    {
        $sql = 'DELETE FROM genealogy_family_events WHERE id = ?';

        return DB::delete($sql, [$eventId]) > 0;
    }

    /**
     * Get all family event types (for dropdown)
     */
    public function getFamilyEventTypes(): array
    {
        return self::FAMILY_EVENT_TYPES;
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Generate a unique GEDCOM ID for a family
     *
     * @param  int  $treeId  Tree ID
     * @param  string  $prefix  ID prefix (F for family)
     * @return string Generated ID
     */
    private function generateGedcomId(int $treeId, string $prefix): string
    {
        $sql = 'SELECT gedcom_id FROM genealogy_families
                WHERE tree_id = ? AND gedcom_id LIKE ?
                ORDER BY CAST(SUBSTRING(gedcom_id, 2) AS UNSIGNED) DESC
                LIMIT 1';

        $result = DB::selectOne($sql, [$treeId, $prefix.'%']);

        if ($result) {
            $num = (int) substr($result->gedcom_id, 1);

            return $prefix.($num + 1);
        }

        return $prefix.'1';
    }

    /**
     * Validate family data for business rule compliance
     *
     * @param  array  $data  Family data
     * @param  int|null  $familyId  Family ID (for updates)
     *
     * @throws InvalidArgumentException If validation fails
     */
    private function validateFamilyData(array $data, ?int $familyId = null): void
    {
        $errors = [];

        // Rule 1: Person cannot marry themselves
        $husbandId = $data['husband_id'] ?? null;
        $wifeId = $data['wife_id'] ?? null;

        if ($husbandId && $wifeId && $husbandId === $wifeId) {
            $errors[] = 'A person cannot be married to themselves';
        }

        // Rule 2: Validate marriage date is after both spouses' birth dates
        if (isset($data['marriage_date']) && $data['marriage_date']) {
            $marriageYear = $this->extractYearFromGedcomDate($data['marriage_date']);

            if ($marriageYear && $husbandId) {
                $husband = $this->personService->getPersonBasic($husbandId);
                if ($husband && $husband->birth_date) {
                    $husbandBirthYear = $this->extractYearFromGedcomDate($husband->birth_date);
                    if ($husbandBirthYear && $marriageYear < $husbandBirthYear) {
                        $errors[] = 'Marriage date cannot be before husband\'s birth date';
                    }
                }
            }

            if ($marriageYear && $wifeId) {
                $wife = $this->personService->getPersonBasic($wifeId);
                if ($wife && $wife->birth_date) {
                    $wifeBirthYear = $this->extractYearFromGedcomDate($wife->birth_date);
                    if ($wifeBirthYear && $marriageYear < $wifeBirthYear) {
                        $errors[] = 'Marriage date cannot be before wife\'s birth date';
                    }
                }
            }
        }

        if (! empty($errors)) {
            throw new InvalidArgumentException(implode('; ', $errors));
        }
    }

    /**
     * Validate child-family relationship for business rule compliance
     *
     * @param  int  $familyId  Family ID
     * @param  int  $personId  Person ID
     *
     * @throws InvalidArgumentException If validation fails
     */
    private function validateChildFamilyRelationship(int $familyId, int $personId): void
    {
        $errors = [];

        // Get family information
        $family = $this->getFamilyBasic($familyId);
        if (! $family) {
            throw new InvalidArgumentException('Family not found');
        }

        // Rule: Child cannot be their own parent
        if ($personId === $family->husband_id || $personId === $family->wife_id) {
            $errors[] = 'A person cannot be their own parent';
        }

        // Rule: Child should be born after parents (if birth dates are known)
        $child = $this->personService->getPersonBasic($personId);
        if ($child && $child->birth_date) {
            $childBirthYear = $this->extractYearFromGedcomDate($child->birth_date);

            if ($childBirthYear && $family->husband_id) {
                $father = $this->personService->getPersonBasic($family->husband_id);
                if ($father && $father->birth_date) {
                    $fatherBirthYear = $this->extractYearFromGedcomDate($father->birth_date);
                    if ($fatherBirthYear && $childBirthYear <= $fatherBirthYear) {
                        $errors[] = 'Child\'s birth date must be after father\'s birth date';
                    }
                }
            }

            if ($childBirthYear && $family->wife_id) {
                $mother = $this->personService->getPersonBasic($family->wife_id);
                if ($mother && $mother->birth_date) {
                    $motherBirthYear = $this->extractYearFromGedcomDate($mother->birth_date);
                    if ($motherBirthYear && $childBirthYear <= $motherBirthYear) {
                        $errors[] = 'Child\'s birth date must be after mother\'s birth date';
                    }
                }
            }
        }

        if (! empty($errors)) {
            throw new InvalidArgumentException(implode('; ', $errors));
        }
    }

    /**
     * Extract year from GEDCOM date format
     *
     * @param  string|null  $date  GEDCOM date string
     * @return int|null Year or null
     */
    private function extractYearFromGedcomDate(?string $date): ?int
    {
        if (! $date) {
            return null;
        }
        // Match 4-digit year anywhere in the string
        if (preg_match('/\b(\d{4})\b/', $date, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Get the tree ID for a family
     *
     * @param  int  $familyId  Family ID
     * @return int|null Tree ID or null if family not found
     */
    public function getFamilyTreeId(int $familyId): ?int
    {
        $result = DB::selectOne('SELECT tree_id FROM genealogy_families WHERE id = ?', [$familyId]);

        return $result ? (int) $result->tree_id : null;
    }

    /**
     * Propose a new family relationship for human review.
     *
     * Called by the genealogy-researcher agent when it discovers potential
     * parents, children, siblings, or spouses from research data.
     * The proposal is queued for human approval before any tree changes.
     */
    public function proposeRelationship(
        int $personId,
        string $relationshipType,
        string $proposedName,
        ?string $proposedSex = null,
        ?string $proposedBirthDate = null,
        ?string $proposedBirthPlace = null,
        ?string $proposedDeathDate = null,
        ?string $proposedDeathPlace = null,
        ?array $evidenceSources = null,
        string $evidenceSummary = '',
        float $confidence = 0.5,
        ?string $agentId = null,
        ?int $treeId = null
    ): array {
        // Validate relationship type
        $validTypes = ['parent', 'child', 'sibling', 'spouse'];
        if (! in_array($relationshipType, $validTypes)) {
            return ['success' => false, 'error' => 'Invalid relationship_type. Must be: '.implode(', ', $validTypes)];
        }

        // Verify the existing person exists
        $person = DB::selectOne('SELECT id, tree_id, given_name, surname FROM genealogy_persons WHERE id = ?', [$personId]);
        if (! $person) {
            return ['success' => false, 'error' => "Person {$personId} not found"];
        }

        $treeId = $treeId ?? $person->tree_id;

        // Parse name into given/surname
        $nameParts = explode(' ', trim($proposedName));
        $proposedSurname = count($nameParts) > 1 ? array_pop($nameParts) : '';
        $proposedGivenName = implode(' ', $nameParts);

        // Check for duplicate proposals
        $existing = DB::selectOne(
            "SELECT id FROM genealogy_proposed_relationships
             WHERE person_id = ? AND relationship_type = ? AND proposed_name = ? AND status IN ('pending', 'approved')",
            [$personId, $relationshipType, $proposedName]
        );

        if ($existing) {
            return [
                'success' => false,
                'error' => "Duplicate: proposal already exists (ID: {$existing->id})",
                'proposal_id' => (int) $existing->id,
            ];
        }

        // Insert proposal
        DB::insert(
            "INSERT INTO genealogy_proposed_relationships
             (tree_id, person_id, relationship_type, proposed_name, proposed_given_name, proposed_surname,
              proposed_sex, proposed_birth_date, proposed_birth_place, proposed_death_date, proposed_death_place,
              evidence_sources, evidence_summary, confidence, agent_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
            [
                $treeId, $personId, $relationshipType, $proposedName,
                $proposedGivenName, $proposedSurname, $proposedSex,
                $proposedBirthDate, $proposedBirthPlace,
                $proposedDeathDate, $proposedDeathPlace,
                $evidenceSources ? json_encode($evidenceSources) : null,
                $evidenceSummary, $confidence, $agentId,
            ]
        );

        $proposalId = (int) DB::getPdo()->lastInsertId();

        Log::info('FamilyService: Relationship proposed', [
            'proposal_id' => $proposalId,
            'person_id' => $personId,
            'type' => $relationshipType,
            'proposed' => $proposedName,
            'confidence' => $confidence,
        ]);

        $this->sendRelationshipProposalNotification(
            $proposalId,
            (int) $person->id,
            trim($person->given_name.' '.$person->surname),
            $relationshipType,
            $proposedName,
            $evidenceSummary,
            $confidence
        );

        return [
            'success' => true,
            'proposal_id' => $proposalId,
            'message' => "Proposed {$relationshipType} relationship: {$proposedName} for "
                .trim($person->given_name.' '.$person->surname)
                ." (confidence: {$confidence}). Awaiting human approval.",
        ];
    }

    private function sendRelationshipProposalNotification(
        int $proposalId,
        int $personId,
        string $personName,
        string $relationshipType,
        string $proposedName,
        string $evidenceSummary,
        float $confidence
    ): void {
        try {
            $baseUrl = rtrim((string) config('app.public_url', config('app.url', 'http://localhost')), '/');
            $quickUrl = "{$baseUrl}/api/reviews/quick/proposal:{$proposalId}";
            $personLabel = $personName !== '' ? $personName : 'person reference present';
            $summary = trim(mb_substr($evidenceSummary, 0, 280));
            $message = "Proposed {$relationshipType}: {$proposedName} for {$personLabel}";
            if ($summary !== '') {
                $message .= "\n\n{$summary}";
            }

            app(NotificationController::class)->send('pushover', [
                'source_group' => 'agent_approval_review',
                'title' => 'Genealogy - Relationship Proposal',
                'message' => $message,
                'priority' => $confidence < 0.70 ? 1 : 0,
                'sound' => 'pushover',
                'url' => "{$quickUrl}?action=view",
                'url_title' => 'View Details',
                'actions' => [
                    ['label' => 'Approve', 'url' => "{$quickUrl}?action=approve"],
                    ['label' => 'Reject', 'url' => "{$quickUrl}?action=reject"],
                    ['label' => 'View', 'url' => "{$quickUrl}?action=view"],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('FamilyService: Failed to send genealogy relationship proposal notification', [
                'proposal_id' => $proposalId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Human-review-UI entry point: flip a pending/pending_review relationship
     * proposal to 'approved' AND apply it in one step. This is what the review
     * queue's Approve button should invoke via review_type_registry.approve_method
     * for review_type='proposal'.
     *
     * applyProposedRelationship() itself stays strict (requires status='approved')
     * so programmatic apply can't bypass human review.
     */
    public function approveAndApplyRelationship(int $proposalId, ?string $notes = null): array
    {
        $row = DB::selectOne(
            'SELECT id, status FROM genealogy_proposed_relationships WHERE id = ?',
            [$proposalId]
        );
        if (! $row) {
            return ['success' => false, 'error' => "Proposal {$proposalId} not found"];
        }

        if ($row->status === 'applied') {
            return ['success' => false, 'error' => 'Proposal already applied'];
        }

        if (! in_array($row->status, ['pending', 'pending_review', 'approved'], true)) {
            return [
                'success' => false,
                'error' => "Proposal status '{$row->status}' is not approvable",
            ];
        }

        if ($row->status !== 'approved') {
            DB::update(
                "UPDATE genealogy_proposed_relationships
                 SET status = 'approved', updated_at = NOW()
                 WHERE id = ? AND status IN ('pending', 'pending_review')",
                [$proposalId]
            );
        }

        return $this->applyProposedRelationship($proposalId);
    }

    /**
     * Apply an approved relationship proposal — creates the person and links them.
     *
     * Hard gate: caller MUST have transitioned the row to status='approved' first.
     * The UI approve path calls approveAndApplyRelationship() which combines flip + apply.
     */
    public function applyProposedRelationship(int $proposalId): array
    {
        $proposal = DB::selectOne('SELECT * FROM genealogy_proposed_relationships WHERE id = ?', [$proposalId]);
        if (! $proposal) {
            return ['success' => false, 'error' => "Proposal {$proposalId} not found"];
        }

        // Hard gate: apply is only legal from 'approved' status. The previous
        // pending-then-auto-approve loophole let any caller bypass human review
        // by invoking this method directly. Enforced at the service layer now.
        if ($proposal->status !== 'approved') {
            return [
                'success' => false,
                'error' => "Proposal status is '{$proposal->status}', not 'approved'. Human approval required before apply.",
            ];
        }

        try {
            if ($this->isExistingLinkProposal($proposal)) {
                return $this->applyExistingLinkProposal($proposalId, $proposal);
            }

            // Block 4 hard-fail guard: if EITHER link_existing signal is present
            // but the pair is incomplete, refuse to fall through to create_person.
            // Silent fallback here is how duplicate persons get created when an
            // intake upstream dropped one of the two identifiers.
            $proposalMode = (string) ($proposal->proposal_mode ?? 'create_person');
            $relatedPersonId = (int) ($proposal->related_person_id ?? 0);
            $linkIntentDetected = $proposalMode === 'link_existing' || $relatedPersonId > 0;
            if ($linkIntentDetected && ! $this->isExistingLinkProposal($proposal)) {
                Log::warning('FamilyService: link_existing intent detected but identifiers incomplete — refusing to create_person fallback', [
                    'proposal_id' => $proposalId,
                    'proposal_mode' => $proposalMode,
                    'related_person_id' => $relatedPersonId,
                    'person_id' => (int) ($proposal->person_id ?? 0),
                    'tree_id' => (int) ($proposal->tree_id ?? 0),
                    'relationship_type' => (string) ($proposal->relationship_type ?? ''),
                ]);

                return [
                    'success' => false,
                    'error' => sprintf(
                        "link_existing intent detected but identifiers incomplete (proposal_mode='%s', related_person_id=%d). Cannot safely apply — operator review required.",
                        $proposalMode,
                        $relatedPersonId
                    ),
                ];
            }

            // Create the new person
            $newPersonId = $this->personService->createPerson((int) $proposal->tree_id, [
                'given_name' => $proposal->proposed_given_name,
                'surname' => $proposal->proposed_surname,
                'sex' => $proposal->proposed_sex ?? 'U',
                'birth_date' => $proposal->proposed_birth_date,
                'birth_place' => $proposal->proposed_birth_place,
                'death_date' => $proposal->proposed_death_date,
                'death_place' => $proposal->proposed_death_place,
                'occupation' => $proposal->proposed_occupation ?? null,
                'notes' => $proposal->proposed_notes ?? null,
            ]);

            // Transfer evidence sources to genealogy_person_sources for the new person
            $this->transferEvidenceSources($newPersonId, (int) $proposal->tree_id, $proposal->evidence_sources ?? null);

            $familyId = null;

            // Link relationship based on type
            switch ($proposal->relationship_type) {
                case 'spouse':
                    $person = DB::selectOne('SELECT sex FROM genealogy_persons WHERE id = ?', [$proposal->person_id]);
                    $husbandId = ($person->sex ?? 'M') === 'M' ? $proposal->person_id : $newPersonId;
                    $wifeId = ($person->sex ?? 'M') === 'M' ? $newPersonId : $proposal->person_id;

                    // Use explicit marriage fields (N91) — fall back to regex on old proposals
                    $familyData = [
                        'husband_id' => $husbandId,
                        'wife_id' => $wifeId,
                        'marriage_date' => $proposal->proposed_marriage_date ?? null,
                        'marriage_place' => $proposal->proposed_marriage_place ?? null,
                    ];
                    if (! $familyData['marriage_date']) {
                        $summary = $proposal->evidence_summary ?? '';
                        if (preg_match('/Marriage date:\s*([^\n]+)/i', $summary, $m)) {
                            $familyData['marriage_date'] = trim($m[1]);
                        }
                        if (preg_match('/(?:Marriage )?[Pp]lace:\s*([^\n]+)/i', $summary, $m)) {
                            $familyData['marriage_place'] = trim($m[1]);
                        }
                    }

                    $familyId = $this->createFamily((int) $proposal->tree_id, $familyData);
                    break;

                case 'child':
                    // Find or create a family where proposal->person_id is a parent
                    $existingFamily = DB::selectOne(
                        'SELECT id FROM genealogy_families WHERE tree_id = ? AND (husband_id = ? OR wife_id = ?)',
                        [$proposal->tree_id, $proposal->person_id, $proposal->person_id]
                    );
                    if ($existingFamily) {
                        $familyId = (int) $existingFamily->id;
                    } else {
                        // Create a single-parent family
                        $person = DB::selectOne('SELECT sex FROM genealogy_persons WHERE id = ?', [$proposal->person_id]);
                        $familyId = $this->createFamily((int) $proposal->tree_id, [
                            'husband_id' => ($person->sex ?? 'M') === 'M' ? $proposal->person_id : null,
                            'wife_id' => ($person->sex ?? 'M') === 'F' ? $proposal->person_id : null,
                        ]);
                    }
                    $this->addChildToFamily($familyId, $newPersonId);
                    break;

                case 'parent':
                    // Find or create a family where proposal->person_id is a child
                    $childFamily = DB::selectOne(
                        'SELECT gc.family_id FROM genealogy_children gc
                         JOIN genealogy_families gf ON gf.id = gc.family_id
                         WHERE gc.person_id = ? AND gf.tree_id = ?',
                        [$proposal->person_id, $proposal->tree_id]
                    );
                    if ($childFamily) {
                        $familyId = (int) $childFamily->family_id;
                        // Update the family to include the new parent
                        $family = DB::selectOne('SELECT husband_id, wife_id FROM genealogy_families WHERE id = ?', [$familyId]);
                        $newSex = $proposal->proposed_sex ?? 'U';
                        if (! $family->husband_id && $newSex !== 'F') {
                            DB::update('UPDATE genealogy_families SET husband_id = ? WHERE id = ?', [$newPersonId, $familyId]);
                        } elseif (! $family->wife_id && $newSex !== 'M') {
                            DB::update('UPDATE genealogy_families SET wife_id = ? WHERE id = ?', [$newPersonId, $familyId]);
                        }
                    } else {
                        // Create family with new parent and existing person as child
                        $newSex = $proposal->proposed_sex ?? 'U';
                        $familyId = $this->createFamily((int) $proposal->tree_id, [
                            'husband_id' => $newSex === 'M' ? $newPersonId : null,
                            'wife_id' => $newSex === 'F' ? $newPersonId : null,
                        ]);
                        $this->addChildToFamily($familyId, (int) $proposal->person_id);
                    }
                    break;

                case 'sibling':
                    // Find the family where person_id is a child, add new person there
                    $siblingFamily = DB::selectOne(
                        'SELECT gc.family_id FROM genealogy_children gc
                         JOIN genealogy_families gf ON gf.id = gc.family_id
                         WHERE gc.person_id = ? AND gf.tree_id = ?',
                        [$proposal->person_id, $proposal->tree_id]
                    );
                    if ($siblingFamily) {
                        $familyId = (int) $siblingFamily->family_id;
                        $this->addChildToFamily($familyId, $newPersonId);
                    } else {
                        // No existing family — create one with both as children
                        $familyId = $this->createFamily((int) $proposal->tree_id, []);
                        $this->addChildToFamily($familyId, (int) $proposal->person_id);
                        $this->addChildToFamily($familyId, $newPersonId);
                    }
                    break;
            }

            // Update proposal status
            DB::update(
                "UPDATE genealogy_proposed_relationships
                 SET status = 'applied', applied_person_id = ?, applied_family_id = ?, applied_at = NOW()
                 WHERE id = ?",
                [$newPersonId, $familyId, $proposalId]
            );

            Log::info('FamilyService: Relationship proposal applied', [
                'proposal_id' => $proposalId,
                'new_person_id' => $newPersonId,
                'family_id' => $familyId,
                'type' => $proposal->relationship_type,
            ]);

            // Rebuild ancestor paths + priority scores so new person is immediately ranked
            $this->rebuildCoverageAfterProposal((int) $proposal->tree_id);

            return [
                'success' => true,
                'new_person_id' => $newPersonId,
                'family_id' => $familyId,
                'message' => "Created {$proposal->proposed_name} (person #{$newPersonId}) as {$proposal->relationship_type} and linked to family #{$familyId}",
            ];

        } catch (\Throwable $e) {
            Log::error('FamilyService: Failed to apply proposal', [
                'proposal_id' => $proposalId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function isExistingLinkProposal(object $proposal): bool
    {
        return (string) ($proposal->proposal_mode ?? 'create_person') === 'link_existing'
            && (int) ($proposal->related_person_id ?? 0) > 0;
    }

    protected function applyExistingLinkProposal(int $proposalId, object $proposal): array
    {
        $linkResult = $this->existingRelationshipLinkService()->link([
            'tree_id' => (int) ($proposal->tree_id ?? 0),
            'person_id' => (int) ($proposal->person_id ?? 0),
            'related_person_id' => (int) ($proposal->related_person_id ?? 0),
            'relationship_type' => (string) ($proposal->relationship_type ?? ''),
        ]);

        if (! ($linkResult['success'] ?? false)) {
            return ['success' => false, 'error' => (string) ($linkResult['error'] ?? 'relationship_link_failed')];
        }

        $familyId = isset($linkResult['family_id']) ? (int) $linkResult['family_id'] : null;

        DB::update(
            "UPDATE genealogy_proposed_relationships
             SET status = 'applied', applied_person_id = NULL, applied_family_id = ?, applied_at = NOW()
             WHERE id = ?",
            [$familyId, $proposalId]
        );

        Log::info('FamilyService: Existing relationship proposal applied', [
            'proposal_id' => $proposalId,
            'person_id' => (int) ($proposal->person_id ?? 0),
            'related_person_id' => (int) ($proposal->related_person_id ?? 0),
            'family_id' => $familyId,
            'type' => (string) ($proposal->relationship_type ?? ''),
            'status' => (string) ($linkResult['status'] ?? ''),
        ]);

        $this->rebuildCoverageAfterProposal((int) $proposal->tree_id);

        return [
            'success' => true,
            'family_id' => $familyId,
            'status' => (string) ($linkResult['status'] ?? 'linked_existing'),
            'message' => sprintf(
                'Linked existing %s relationship between person #%d and person #%d',
                (string) ($proposal->relationship_type ?? 'family'),
                (int) ($proposal->person_id ?? 0),
                (int) ($proposal->related_person_id ?? 0)
            ),
        ];
    }

    protected function existingRelationshipLinkService(): GenealogyIntakeExistingRelationshipLinkService
    {
        return app(GenealogyIntakeExistingRelationshipLinkService::class);
    }

    /**
     * Transfer evidence_sources from a proposal to genealogy_person_sources for the new person.
     * Sources stored as a JSON array or newline-separated string.
     */
    protected function transferEvidenceSources(int $personId, int $treeId, ?string $evidenceSources): void
    {
        if (! $evidenceSources) {
            return;
        }

        // Decode — may be JSON array or plain text
        $sources = json_decode($evidenceSources, true);
        if (! is_array($sources)) {
            $sources = array_filter(array_map('trim', explode("\n", $evidenceSources)));
        }

        foreach ($sources as $citation) {
            if (! $citation) {
                continue;
            }
            try {
                // Create a minimal source record for this citation string
                DB::insert('
                    INSERT INTO genealogy_sources (tree_id, title, created_at, updated_at)
                    VALUES (?, ?, NOW(), NOW())
                ', [$treeId, $citation]);
                $sourceId = DB::getPdo()->lastInsertId();
                DB::insert(
                    'INSERT IGNORE INTO genealogy_person_sources (person_id, source_id, page, quality, created_at)
                     VALUES (?, ?, ?, ?, NOW())',
                    [$personId, $sourceId, null, 'secondary']
                );
            } catch (\Throwable $e) {
                Log::warning("FamilyService: Failed to transfer evidence source for person #{$personId}", [
                    'citation' => mb_substr($citation, 0, 100),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Rebuild ancestor paths and priority scores after a proposal is applied.
     * Called synchronously — typically < 2s for a 2000-person tree.
     */
    protected function rebuildCoverageAfterProposal(int $treeId): void
    {
        try {
            $tree = DB::selectOne('SELECT root_person_id FROM genealogy_trees WHERE id = ?', [$treeId]);
            if (! $tree || ! $tree->root_person_id) {
                return;
            }
            $gs = $this->getGenealogyService();
            $gs->rebuildAncestorPaths($treeId, (int) $tree->root_person_id);
            $gs->refreshPersonCoverage($treeId);
        } catch (\Throwable $e) {
            // Non-fatal — nightly job will catch it
            Log::warning('FamilyService: Coverage rebuild after proposal failed (nightly job will recover)', [
                'tree_id' => $treeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get pending relationship proposals for a tree.
     */
    public function getPendingProposals(int $treeId): array
    {
        return DB::select(
            "SELECT pr.*, p.given_name AS existing_given_name, p.surname AS existing_surname
             FROM genealogy_proposed_relationships pr
             JOIN genealogy_persons p ON p.id = pr.person_id
             WHERE pr.tree_id = ? AND pr.status = 'pending'
             ORDER BY pr.confidence DESC, pr.created_at ASC",
            [$treeId]
        );
    }
}
