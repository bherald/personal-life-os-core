<?php

namespace App\Services\Genealogy;

use App\Controllers\NotificationController;
use App\Services\Genealogy\Support\ProximityNameMatcher;
use App\Services\Genealogy\Support\TemporalProximityChecker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * PersonService - Person CRUD and Related Operations
 *
 * Extracted from GenealogyService as part of Priority 2.1 service extraction.
 * Handles person creation, updates, deletion, search, and related data (events, residences).
 *
 * @see /docs/genealogy-module-review.md Priority 2.1
 */
class PersonService
{
    private const GENEALOGY_FINDING_MATERIALIZABLE_CHANGE_TYPES = [
        'fact_update',
        'event_add',
        'source_add',
        'media_link',
        'notes_append',
        'residence_add',
        'family_event_update',
        'external_record_link',
        'source_create',
        'clipping_link',
        'media_metadata_update',
    ];

    private const GENEALOGY_FINDING_ACKNOWLEDGEMENT_CHANGE_TYPES = [
        'search_complete',
    ];

    private const GENEALOGY_FINDING_REMEDIATION_REQUIRED_CHANGE_TYPES = [
        'data_quality_review',
        'source_duplicate_cleanup',
    ];

    /**
     * GEDCOM 5.5.1 Event Types Reference
     */
    public const EVENT_TYPES = [
        'CHR' => 'Christening',
        'BAPM' => 'Baptism',
        'CONF' => 'Confirmation',
        'BARM' => 'Bar Mitzvah',
        'BASM' => 'Bas Mitzvah',
        'BLES' => 'Blessing',
        'CHRA' => 'Adult Christening',
        'FCOM' => 'First Communion',
        'ORDN' => 'Ordination',
        'GRAD' => 'Graduation',
        'EMIG' => 'Emigration',
        'IMMI' => 'Immigration',
        'NATU' => 'Naturalization',
        'RETI' => 'Retirement',
        'CENS' => 'Census',
        'PROB' => 'Probate',
        'WILL' => 'Will',
        'CREM' => 'Cremation',
        'ADOP' => 'Adoption',
        'EVEN' => 'Custom Event',
        'MIL' => 'Military Service',
        'EDUC' => 'Education',
        'OCCU' => 'Occupation',
    ];

    protected TreeManagementService $treeService;

    protected ?GenealogyChangeHistoryService $changeHistory = null;

    public function __construct(TreeManagementService $treeService, ?GenealogyChangeHistoryService $changeHistory = null)
    {
        $this->treeService = $treeService;
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

    // ========================================================================
    // PERSON CRUD
    // ========================================================================

    /**
     * Get a person with all related data
     *
     * @param  int  $personId  Person ID
     * @return array|null Person data or null if not found
     */
    public function getPerson(int $personId): ?array
    {
        $sql = 'SELECT p.*, t.name as tree_name
                FROM genealogy_persons p
                JOIN genealogy_trees t ON t.id = p.tree_id
                WHERE p.id = ?';

        $person = DB::selectOne($sql, [$personId]);
        if (! $person) {
            return null;
        }

        $result = (array) $person;

        // Get families where person is spouse
        $result['families_as_spouse'] = $this->getPersonFamiliesAsSpouse($personId);

        // Get family where person is child
        $result['family_as_child'] = $this->getPersonFamilyAsChild($personId);

        // Get media
        $result['media'] = $this->getPersonMedia($personId);

        // Get residences
        $result['residences'] = $this->getPersonResidences($personId);

        // Get events
        $result['events'] = $this->getPersonEvents($personId);

        return $result;
    }

    /**
     * Get basic person data without relations
     *
     * @param  int  $personId  Person ID
     */
    public function getPersonBasic(int $personId): ?object
    {
        $sql = 'SELECT * FROM genealogy_persons WHERE id = ?';

        return DB::selectOne($sql, [$personId]);
    }

    /**
     * List all persons in a tree
     *
     * @param  int  $treeId  Tree ID
     * @param  int  $limit  Maximum results
     */
    public function listPersons(int $treeId, int $limit = 5000): array
    {
        $sql = 'SELECT id, gedcom_id, given_name, surname, suffix, sex, birth_date, death_date
                FROM genealogy_persons
                WHERE tree_id = ?
                ORDER BY surname, given_name
                LIMIT ?';

        return DB::select($sql, [$treeId, $limit]);
    }

    /**
     * Search persons by name
     *
     * @param  int  $treeId  Tree ID
     * @param  string  $query  Search query
     * @param  int  $limit  Maximum results
     */
    public function searchPersons(int $treeId, string $query, int $limit = 50): array
    {
        $searchTerm = '%'.$query.'%';

        $sql = "SELECT id, gedcom_id, given_name, surname, suffix, nickname, sex,
                       birth_date, birth_place, death_date, death_place
                FROM genealogy_persons
                WHERE tree_id = ?
                  AND (
                      given_name LIKE ?
                      OR surname LIKE ?
                      OR nickname LIKE ?
                      OR CONCAT(given_name, ' ', surname) LIKE ?
                  )
                ORDER BY surname, given_name
                LIMIT ?";

        return DB::select($sql, [$treeId, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]);
    }

    /**
     * List persons by surname
     *
     * @param  int  $treeId  Tree ID
     * @param  string  $surname  Surname to filter by
     */
    public function listPersonsBySurname(int $treeId, string $surname): array
    {
        $sql = 'SELECT id, gedcom_id, given_name, surname, suffix, nickname, sex,
                       birth_date, birth_place, death_date, death_place
                FROM genealogy_persons
                WHERE tree_id = ? AND surname = ?
                ORDER BY given_name, birth_date';

        return DB::select($sql, [$treeId, $surname]);
    }

    /**
     * Get surname list with counts
     *
     * @param  int  $treeId  Tree ID
     */
    public function getSurnameList(int $treeId): array
    {
        $sql = "SELECT surname, COUNT(*) as person_count
                FROM genealogy_persons
                WHERE tree_id = ? AND surname IS NOT NULL AND surname != ''
                GROUP BY surname
                ORDER BY surname";

        return DB::select($sql, [$treeId]);
    }

    /**
     * Create a new person
     *
     * @param  int  $treeId  Tree ID
     * @param  array  $data  Person data
     * @return int New person ID
     */
    public function createPerson(int $treeId, array $data): int
    {
        $sql = 'INSERT INTO genealogy_persons (
                    tree_id, gedcom_id, given_name, surname, suffix, nickname, sex,
                    birth_date, birth_place, birth_lat, birth_lon,
                    death_date, death_place, death_lat, death_lon,
                    burial_date, burial_place, burial_lat, burial_lon,
                    occupation, education, religion, notes,
                    title, physical_description, nationality, ssn, id_number, property, cause_of_death,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

        // Generate GEDCOM ID if not provided
        $gedcomId = $data['gedcom_id'] ?? $this->generateGedcomId($treeId, 'I');

        DB::insert($sql, [
            $treeId,
            $gedcomId,
            $data['given_name'] ?? null,
            $data['surname'] ?? null,
            $data['suffix'] ?? null,
            $data['nickname'] ?? null,
            $data['sex'] ?? null,
            $data['birth_date'] ?? null,
            $data['birth_place'] ?? null,
            $data['birth_lat'] ?? null,
            $data['birth_lon'] ?? null,
            $data['death_date'] ?? null,
            $data['death_place'] ?? null,
            $data['death_lat'] ?? null,
            $data['death_lon'] ?? null,
            $data['burial_date'] ?? null,
            $data['burial_place'] ?? null,
            $data['burial_lat'] ?? null,
            $data['burial_lon'] ?? null,
            $data['occupation'] ?? null,
            $data['education'] ?? null,
            $data['religion'] ?? null,
            $data['notes'] ?? null,
            $data['title'] ?? null,
            $data['physical_description'] ?? null,
            $data['nationality'] ?? null,
            $data['ssn'] ?? null,
            $data['id_number'] ?? null,
            $data['property'] ?? null,
            $data['cause_of_death'] ?? null,
        ]);

        $personId = (int) DB::getPdo()->lastInsertId();
        $this->treeService->updateTreeStats($treeId);

        // Record change history
        $this->getChangeHistory()->recordCreate('person', $personId, $treeId, $data);

        Log::info('PersonService: Person created', [
            'person_id' => $personId,
            'tree_id' => $treeId,
            'name' => trim(($data['given_name'] ?? '').' '.($data['surname'] ?? '')),
        ]);

        return $personId;
    }

    /**
     * Update a person
     *
     * @param  int  $personId  Person ID
     * @param  array  $data  Update data
     * @return bool Success
     *
     * @throws InvalidArgumentException If validation fails
     */
    public function updatePerson(int $personId, array $data): bool
    {
        // Validate business rules before updating
        $this->validatePersonData($data, $personId);

        // Get current data for change tracking
        $currentPerson = $this->getPersonBasic($personId);
        $oldData = $currentPerson ? (array) $currentPerson : [];

        $allowedFields = [
            'given_name', 'surname', 'suffix', 'nickname', 'sex',
            'birth_date', 'birth_place', 'birth_lat', 'birth_lon',
            'death_date', 'death_place', 'death_lat', 'death_lon',
            'burial_date', 'burial_place', 'burial_lat', 'burial_lon',
            'occupation', 'education', 'religion', 'notes', 'primary_photo_id',
            'title', 'physical_description', 'nationality', 'ssn', 'id_number', 'property', 'cause_of_death',
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
        $params[] = $personId;

        $sql = 'UPDATE genealogy_persons SET '.implode(', ', $fields).' WHERE id = ?';
        $updated = DB::update($sql, $params) > 0;

        if ($updated) {
            // Record change history
            $treeId = $oldData['tree_id'] ?? $this->getPersonTreeId($personId);
            if ($treeId) {
                $this->getChangeHistory()->recordUpdate('person', $personId, $treeId, $oldData, $data);
            }

            Log::info('PersonService: Person updated', [
                'person_id' => $personId,
                'fields' => array_keys($data),
            ]);
        }

        return $updated;
    }

    /**
     * Delete a person
     *
     * @param  int  $personId  Person ID
     * @return bool Success
     */
    public function deletePerson(int $personId): bool
    {
        // Get full person data for change history
        $person = $this->getPersonBasic($personId);
        if (! $person) {
            return false;
        }

        $personData = (array) $person;
        $treeId = $person->tree_id;

        // Foreign keys with CASCADE will handle related records
        $sql = 'DELETE FROM genealogy_persons WHERE id = ?';
        $result = DB::delete($sql, [$personId]) > 0;

        if ($result) {
            // Record change history before stats update
            $this->getChangeHistory()->recordDelete('person', $personId, $treeId, $personData);

            $this->treeService->updateTreeStats($treeId);
            Log::info('PersonService: Person deleted', [
                'person_id' => $personId,
                'tree_id' => $treeId,
            ]);
        }

        return $result;
    }

    // ========================================================================
    // FAMILY RELATIONSHIPS
    // ========================================================================

    /**
     * Get families where person is a spouse
     *
     * @param  int  $personId  Person ID
     */
    public function getPersonFamiliesAsSpouse(int $personId): array
    {
        $sql = 'SELECT f.*,
                       h.id as husband_db_id, h.given_name as husband_given, h.surname as husband_surname,
                       w.id as wife_db_id, w.given_name as wife_given, w.surname as wife_surname
                FROM genealogy_families f
                LEFT JOIN genealogy_persons h ON h.id = f.husband_id
                LEFT JOIN genealogy_persons w ON w.id = f.wife_id
                WHERE f.husband_id = ? OR f.wife_id = ?
                ORDER BY f.marriage_date';

        $families = DB::select($sql, [$personId, $personId]);

        // Get children for each family
        foreach ($families as &$family) {
            $family->children = $this->getFamilyChildren($family->id);
        }

        return $families;
    }

    /**
     * Get family where person is a child
     *
     * @param  int  $personId  Person ID
     */
    public function getPersonFamilyAsChild(int $personId): ?object
    {
        $sql = 'SELECT f.*, c.father_relationship, c.mother_relationship, c.birth_order,
                       h.id as father_id, h.given_name as father_given, h.surname as father_surname,
                       w.id as mother_id, w.given_name as mother_given, w.surname as mother_surname
                FROM genealogy_children c
                JOIN genealogy_families f ON f.id = c.family_id
                LEFT JOIN genealogy_persons h ON h.id = f.husband_id
                LEFT JOIN genealogy_persons w ON w.id = f.wife_id
                WHERE c.person_id = ?';

        return DB::selectOne($sql, [$personId]);
    }

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

    // ========================================================================
    // PERSON MEDIA
    // ========================================================================

    /**
     * Get media for a person
     *
     * @param  int  $personId  Person ID
     */
    public function getPersonMedia(int $personId): array
    {
        $sql = 'SELECT m.*, pm.is_primary, pm.face_region_x, pm.face_region_y,
                       pm.face_region_w, pm.face_region_h, pm.face_confirmed, pm.notes as link_notes
                FROM genealogy_person_media pm
                JOIN genealogy_media m ON m.id = pm.media_id
                WHERE pm.person_id = ?
                ORDER BY pm.is_primary DESC, m.media_date';

        return DB::select($sql, [$personId]);
    }

    // ========================================================================
    // RESIDENCES
    // ========================================================================

    /**
     * Get residences for a person
     *
     * @param  int  $personId  Person ID
     */
    public function getPersonResidences(int $personId): array
    {
        $sql = 'SELECT r.*, s.title as source_title
                FROM genealogy_residences r
                LEFT JOIN genealogy_sources s ON s.id = r.source_id
                WHERE r.person_id = ?
                ORDER BY r.residence_date';

        return DB::select($sql, [$personId]);
    }

    /**
     * Get a single residence
     *
     * @param  int  $id  Residence ID
     */
    public function getResidence(int $id): ?object
    {
        $sql = 'SELECT r.*, s.title as source_title
                FROM genealogy_residences r
                LEFT JOIN genealogy_sources s ON s.id = r.source_id
                WHERE r.id = ?';
        $result = DB::select($sql, [$id]);

        return $result[0] ?? null;
    }

    /**
     * Create a new residence
     *
     * @param  int  $personId  Person ID
     * @param  array  $data  Residence data
     * @return object Created residence
     */
    public function createResidence(int $personId, array $data): object
    {
        $sql = 'INSERT INTO genealogy_residences (person_id, residence_date, place, latitude, longitude, source_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())';
        DB::insert($sql, [
            $personId,
            $data['residence_date'] ?? null,
            $data['place'],
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['source_id'] ?? null,
        ]);

        $id = DB::getPdo()->lastInsertId();

        return $this->getResidence($id);
    }

    /**
     * Update a residence
     *
     * @param  int  $id  Residence ID
     * @param  array  $data  Update data
     * @return object|null Updated residence or null if not found
     */
    public function updateResidence(int $id, array $data): ?object
    {
        $residence = $this->getResidence($id);
        if (! $residence) {
            return null;
        }

        $sql = 'UPDATE genealogy_residences SET
                residence_date = ?, place = ?, latitude = ?, longitude = ?, source_id = ?
                WHERE id = ?';
        DB::update($sql, [
            $data['residence_date'] ?? null,
            $data['place'],
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['source_id'] ?? null,
            $id,
        ]);

        return $this->getResidence($id);
    }

    /**
     * Delete a residence
     *
     * @param  int  $id  Residence ID
     * @return bool Success
     */
    public function deleteResidence(int $id): bool
    {
        $sql = 'DELETE FROM genealogy_residences WHERE id = ?';

        return DB::delete($sql, [$id]) > 0;
    }

    // ========================================================================
    // EVENTS
    // ========================================================================

    /**
     * Get events for a person
     *
     * @param  int  $personId  Person ID
     */
    public function getPersonEvents(int $personId): array
    {
        $sql = 'SELECT e.*, s.title as source_title
                FROM genealogy_events e
                LEFT JOIN genealogy_sources s ON s.id = e.source_id
                WHERE e.person_id = ?
                ORDER BY e.event_date';

        return DB::select($sql, [$personId]);
    }

    /**
     * Get a single event by ID
     *
     * @param  int  $eventId  Event ID
     */
    public function getEvent(int $eventId): ?object
    {
        $sql = 'SELECT e.*, s.title as source_title, p.given_name, p.surname
                FROM genealogy_events e
                LEFT JOIN genealogy_sources s ON s.id = e.source_id
                LEFT JOIN genealogy_persons p ON p.id = e.person_id
                WHERE e.id = ?';

        return DB::selectOne($sql, [$eventId]);
    }

    /**
     * Create a new event for a person
     *
     * @param  int  $personId  Person ID
     * @param  array  $data  Event data
     * @return int Event ID
     */
    public function createEvent(int $personId, array $data): int
    {
        $sourceId = $this->normalizeGenealogySourceId($data['source_id'] ?? null);

        $sql = 'INSERT INTO genealogy_events (
                    person_id, event_type, event_date, event_place,
                    latitude, longitude, description, source_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())';

        DB::insert($sql, [
            $personId,
            $data['event_type'] ?? 'EVEN',
            $data['event_date'] ?? null,
            $data['event_place'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
            $data['description'] ?? null,
            $sourceId,
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Update an event
     *
     * @param  int  $eventId  Event ID
     * @param  array  $data  Update data
     * @return bool Success
     */
    public function updateEvent(int $eventId, array $data): bool
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
        $sql = 'UPDATE genealogy_events SET '.implode(', ', $fields).' WHERE id = ?';

        return DB::update($sql, $params) > 0;
    }

    /**
     * Delete an event
     *
     * @param  int  $eventId  Event ID
     * @return bool Success
     */
    public function deleteEvent(int $eventId): bool
    {
        $sql = 'DELETE FROM genealogy_events WHERE id = ?';

        return DB::delete($sql, [$eventId]) > 0;
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Generate a unique GEDCOM ID for a person
     *
     * @param  int  $treeId  Tree ID
     * @param  string  $prefix  ID prefix (I for individual)
     * @return string Generated ID
     */
    private function generateGedcomId(int $treeId, string $prefix): string
    {
        $sql = 'SELECT gedcom_id FROM genealogy_persons
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
     * Validate person data for business rule compliance
     *
     * @param  array  $data  Person data
     * @param  int|null  $personId  Person ID (for updates)
     *
     * @throws InvalidArgumentException If validation fails
     */
    private function validatePersonData(array $data, ?int $personId = null): void
    {
        $errors = [];

        // Rule: Death date must be after birth date
        if (isset($data['birth_date']) && isset($data['death_date']) && $data['birth_date'] && $data['death_date']) {
            $birthYear = $this->extractYearFromGedcomDate($data['birth_date']);
            $deathYear = $this->extractYearFromGedcomDate($data['death_date']);

            if ($birthYear && $deathYear && $deathYear < $birthYear) {
                $errors[] = 'Death date cannot be before birth date';
            }
        }

        // Rule: Burial date must be after death date
        if (isset($data['death_date']) && isset($data['burial_date']) && $data['death_date'] && $data['burial_date']) {
            $deathYear = $this->extractYearFromGedcomDate($data['death_date']);
            $burialYear = $this->extractYearFromGedcomDate($data['burial_date']);

            if ($deathYear && $burialYear && $burialYear < $deathYear) {
                $errors[] = 'Burial date cannot be before death date';
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
     * Get the tree ID for a person
     *
     * @param  int  $personId  Person ID
     * @return int|null Tree ID or null if person not found
     */
    public function getPersonTreeId(int $personId): ?int
    {
        $result = DB::selectOne('SELECT tree_id FROM genealogy_persons WHERE id = ?', [$personId]);

        return $result ? (int) $result->tree_id : null;
    }

    /**
     * N52: Auto-create a genealogy_sources record from an agent-proposed URL.
     *
     * Domain-based classification with source category, quality, confidence.
     * Deduplicates by URL within tree. Returns source_id or null on failure.
     */
    /**
     * 2.1d — proposal-gate backstop. At source_add apply time, verify the
     * source URL actually mentions the target person's full name as a
     * proximity-valid phrase. Protects against bad matches that slipped
     * past upstream gates (2.1b/2.1c/2.1e).
     *
     * Returns an error reason when the backstop rejects, or null when the
     * proposal should apply normally. Skips when:
     *   - proposal has no agent_id (manual operator add — trust)
     *   - person record is missing or lacks given/surname (can't verify)
     *   - no URL is derivable from proposed_value (nothing to fetch)
     *   - source content fetch fails (network tolerance — let apply proceed)
     */
    protected function runSourceAddBackstop(object $proposal): ?string
    {
        if (empty($proposal->agent_id)) {
            return null;
        }

        $person = $this->getPersonBasic((int) $proposal->person_id);
        if (! $person || empty($person->given_name) || empty($person->surname)) {
            return null;
        }

        // Operator-found defect (E. Billington / Mary Billington): the
        // genealogy-records agent searches by surname alone and proposes
        // sources whose date is far outside the person's lifetime
        // (Civil War pensions for someone who died 1718). Reject at
        // apply time when the source is clearly outside lifetime ±50yr
        // margin. Display-time signal lives in
        // ReviewContextEnrichmentService::detectTemporalMismatch.
        $temporalError = $this->checkTemporalProximity($proposal, $person);
        if ($temporalError !== null) {
            return $temporalError;
        }

        $url = $this->extractBackstopUrl((string) ($proposal->proposed_value ?? ''));
        if ($url === null) {
            // No URL on the proposal itself — the planner emits bare
            // source_id proposals (GenealogyIntakeProposalPersistencePlannerService).
            // Dereference the source_id to its stored URL so the
            // backstop applies uniformly regardless of emission style.
            $url = $this->resolveBackstopUrlFromProposal($proposal);
        }

        if ($url === null) {
            // No verifiable URL anywhere — cannot prove or disprove the
            // source matches. Under the operator's STRICT directive,
            // reject rather than silently allow.
            return 'source_add has no verifiable URL (neither proposed_value nor resolved genealogy_sources row exposes one)';
        }

        $content = $this->fetchSourceContentForBackstop($url);
        if ($content === null) {
            // Fail-closed on fetch failure: operator directive is
            // STRICT verification. A transient network error produces
            // a rejection with a clearly-labeled reviewer note so the
            // operator can distinguish "unreachable" from "scatter".
            Log::warning('PersonService: source_add backstop — fetch failed, rejecting per STRICT policy', [
                'proposal_id' => $proposal->id ?? null,
                'url' => $url,
            ]);

            return sprintf(
                'Source at %s could not be fetched for verification (network or server error). Re-approve after source is reachable to retry.',
                $url
            );
        }

        if (ProximityNameMatcher::matchesFullName($content, $person->given_name, $person->surname)) {
            return null;
        }

        Log::warning('PersonService: source_add backstop REJECTED', [
            'proposal_id' => $proposal->id ?? null,
            'person_id' => $proposal->person_id ?? null,
            'url' => $url,
            'target_name' => $person->given_name.' '.$person->surname,
        ]);

        return sprintf(
            "Source at %s does not contain '%s %s' as a proximity-valid phrase",
            $url,
            $person->given_name,
            $person->surname
        );
    }

    /**
     * Pull a URL out of a source_add proposed_value, which may be:
     *   - a bare URL string, or
     *   - a JSON blob `{source_id, url, auto_created}` produced by
     *     autoCreateSourceFromUrl during proposeChange.
     *
     * Numeric source_id is NOT resolved here — that path goes through
     * resolveBackstopUrlFromProposal so the DB lookup stays out of this
     * pure-string parser.
     */
    private function extractBackstopUrl(string $proposedValue): ?string
    {
        $trimmed = trim($proposedValue);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded) && ! empty($decoded['url']) && is_string($decoded['url'])) {
            return $decoded['url'];
        }

        if (preg_match('/^https?:\/\//i', $trimmed)) {
            return $trimmed;
        }

        return null;
    }

    /**
     * Resolve a URL for a proposal whose `proposed_value` is a numeric
     * source_id (emitted by GenealogyIntakeProposalPersistencePlannerService)
     * or a JSON blob with source_id but no url. Looks up
     * genealogy_sources.url by id. Returns null when the source row
     * lacks a URL — the backstop treats that as "no verifiable URL".
     */
    private function resolveBackstopUrlFromProposal(object $proposal): ?string
    {
        $value = trim((string) ($proposal->proposed_value ?? ''));
        if ($value === '') {
            return null;
        }

        $sourceId = null;
        if (preg_match('/^\d+$/', $value)) {
            $sourceId = (int) $value;
        } else {
            $decoded = json_decode($value, true);
            if (is_array($decoded) && ! empty($decoded['source_id']) && is_numeric($decoded['source_id'])) {
                $sourceId = (int) $decoded['source_id'];
            }
        }

        if ($sourceId === null || $sourceId <= 0) {
            return null;
        }

        $row = DB::selectOne('SELECT url FROM genealogy_sources WHERE id = ? LIMIT 1', [$sourceId]);
        if (! $row || empty($row->url)) {
            return null;
        }

        return (string) $row->url;
    }

    /**
     * Fetch source content for backstop verification. 24h cache by URL
     * hash. 10s connect + 10s read timeout. 2MB body cap.
     *
     * Before strip_tags, we preserve attribute values (alt, title,
     * aria-label) and META content so provider-rendered names that
     * live in attributes rather than visible text are still seen by
     * the tokenizer. Plain strip_tags was dropping names in <img alt>
     * and <meta content> fields.
     */
    protected function fetchSourceContentForBackstop(string $url): ?string
    {
        $cacheKey = 'source_content:backstop:'.hash('sha256', $url);
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }

        try {
            $response = Http::connectTimeout(10)->timeout(10)->get($url);
            if (! $response->successful()) {
                return null;
            }

            $body = substr((string) $response->body(), 0, 2 * 1024 * 1024);

            // Extract attribute text BEFORE strip_tags erases it.
            $attributes = [];
            if (preg_match_all('/\b(alt|title|aria-label|content|data-name)\s*=\s*(["\'])(.*?)\2/i', $body, $matches)) {
                $attributes = $matches[3];
            }

            $visible = strip_tags($body);
            $stripped = trim($visible.' '.implode(' ', $attributes));

            Cache::put($cacheKey, $stripped, 60 * 60 * 24);

            return $stripped;
        } catch (\Throwable $e) {
            Log::warning('PersonService: source_add backstop fetch failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function autoCreateSourceFromUrl(string $url, string $evidenceSummary, ?int $treeId, ?string $agentId): ?int
    {
        if (! $treeId) {
            return null;
        }

        try {
            // Check for duplicate URL in this tree
            $existing = DB::selectOne(
                'SELECT id FROM genealogy_sources WHERE tree_id = ? AND url = ? LIMIT 1',
                [$treeId, $url]
            );
            if ($existing) {
                return (int) $existing->id;
            }

            // Classify source from URL domain
            $classification = $this->classifySourceUrl($url);

            // Derive title from evidence_summary (first sentence) or URL hostname
            $title = $evidenceSummary
                ? mb_substr(explode('.', $evidenceSummary)[0], 0, 200)
                : ($classification['site_name'] ?? parse_url($url, PHP_URL_HOST)).' — agent-sourced record';

            DB::insert("
                INSERT INTO genealogy_sources
                    (tree_id, title, url, source_quality, source_category, information_quality,
                     classification_method, classification_confidence, classification_notes,
                     classified_at, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'auto', ?, ?, NOW(), ?, NOW(), NOW())
            ", [
                $treeId,
                $title,
                $url,
                $classification['quality'],
                $classification['category'],
                $classification['info_quality'],
                $classification['confidence'],
                $classification['notes'],
                'Auto-created from agent proposal'.($agentId ? " (agent: {$agentId})" : '').'. '.mb_substr($evidenceSummary, 0, 500),
            ]);

            return (int) DB::getPdo()->lastInsertId();
        } catch (\Exception $e) {
            Log::warning('PersonService: Failed to auto-create source from URL', [
                'url' => $url,
                'tree_id' => $treeId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * N52: Classify a URL into source quality, category, and confidence.
     * Domain-based type classification for genealogy sources.
     */
    private function classifySourceUrl(string $url): array
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');

        // Domain → [quality, category, info_quality, confidence, site_name, notes]
        $domainMap = [
            // Government archives — original records
            'archives.gov' => ['original', 'original', 'primary', 0.95, 'U.S. National Archives', 'Federal government records repository'],
            'nara.gov' => ['original', 'original', 'primary', 0.95, 'NARA', 'National Archives and Records Administration'],
            'catalog.archives.gov' => ['original', 'original', 'primary', 0.95, 'NARA Catalog', 'Federal records catalog'],

            // FamilySearch — mixed (some originals, some derivatives)
            'familysearch.org' => ['original', 'original', 'primary', 0.85, 'FamilySearch', 'LDS Church archives; quality varies by collection'],

            // Ancestry — derivative indexes of originals
            'ancestry.com' => ['derivative', 'derivative', 'secondary', 0.80, 'Ancestry', 'Indexed transcriptions of original records'],

            // Cemetery records
            'findagrave.com' => ['derivative', 'derivative', 'secondary', 0.75, 'Find a Grave', 'User-contributed cemetery data'],
            'billiongraves.com' => ['derivative', 'derivative', 'secondary', 0.70, 'BillionGraves', 'GPS-tagged headstone photos'],

            // Newspapers
            'newspapers.com' => ['derivative', 'derivative', 'secondary', 0.80, 'Newspapers.com', 'Digitized newspaper archives'],
            'chroniclingamerica.loc.gov' => ['original', 'original', 'primary', 0.90, 'Chronicling America', 'Library of Congress newspaper archive'],

            // Military records
            'fold3.com' => ['derivative', 'derivative', 'secondary', 0.80, 'Fold3', 'Military records (Ancestry subsidiary)'],

            // WikiTree — authored/community
            'wikitree.com' => ['authored', 'authored', 'undetermined', 0.50, 'WikiTree', 'Community-contributed profiles; verify sources'],

            // Wikipedia — authored reference
            'wikipedia.org' => ['authored', 'authored', 'secondary', 0.40, 'Wikipedia', 'Encyclopedia; not a primary genealogy source'],
            'en.wikipedia.org' => ['authored', 'authored', 'secondary', 0.40, 'Wikipedia', 'Encyclopedia; not a primary genealogy source'],
        ];

        // Match domain (check longest match first for subdomains)
        $matched = null;
        foreach ($domainMap as $domain => $classification) {
            if (str_contains($host, $domain)) {
                if (! $matched || strlen($domain) > strlen($matched[0])) {
                    $matched = [$domain, $classification];
                }
            }
        }

        if ($matched) {
            [, $c] = $matched;

            return [
                'quality' => $c[0],
                'category' => $c[1],
                'info_quality' => $c[2],
                'confidence' => $c[3],
                'site_name' => $c[4],
                'notes' => $c[5],
            ];
        }

        // Heuristic classification for unknown domains
        $confidence = 0.40;
        $category = 'derivative';
        $quality = 'derivative';
        $infoQuality = 'undetermined';
        $notes = 'Unknown domain; manual review recommended';

        // Government domains tend to be authoritative
        if (str_ends_with($host, '.gov') || str_ends_with($host, '.gov.uk') || str_ends_with($host, '.gc.ca')) {
            $quality = 'original';
            $category = 'original';
            $infoQuality = 'primary';
            $confidence = 0.75;
            $notes = 'Government domain; likely official records';
        }

        // Education/museum domains
        if (str_ends_with($host, '.edu') || str_contains($host, 'museum') || str_contains($host, 'library')) {
            $quality = 'original';
            $category = 'original';
            $infoQuality = 'secondary';
            $confidence = 0.65;
            $notes = 'Academic/institutional domain';
        }

        return [
            'quality' => $quality,
            'category' => $category,
            'info_quality' => $infoQuality,
            'confidence' => $confidence,
            'site_name' => null,
            'notes' => $notes,
        ];
    }

    /**
     * Propose a change to an existing person for human review.
     */
    public function proposeChange(
        int $personId,
        string $changeType,
        ?string $fieldName,
        string $proposedValue,
        ?array $evidenceSources,
        string $evidenceSummary,
        float $confidence = 0.5,
        ?string $agentId = null,
        ?int $treeId = null
    ): array {
        $validTypes = [
            'fact_update', 'event_add', 'source_add', 'media_link',
            'notes_append', 'residence_add', 'family_event_update',
            'external_record_link', 'source_create', 'clipping_link',
            'media_metadata_update',
        ];
        if (! in_array($changeType, $validTypes)) {
            return ['success' => false, 'error' => "Invalid change_type: {$changeType}"];
        }

        // Confidence floor for structured proposals (not notes_append which has no floor by design)
        if ($changeType !== 'notes_append' && $confidence < 0.50) {
            return ['success' => false, 'error' => "Confidence {$confidence} below 0.50 threshold for {$changeType}. Use notes_append for low-confidence findings."];
        }

        // GPS Sprint #3: creation-time gate. Filter agent-emitted
        // proposals through the domain validator before they pollute
        // genealogy_proposed_changes. The Mary Billington defect
        // (Civil War pension for 1718-died person) gets caught here
        // — the agent's surname-only search produces it, but the
        // temporal gate refuses to insert. Operator-emitted
        // proposals (agentId === null) bypass per the same trust
        // convention runSourceAddBackstop uses.
        $validation = app(ProposalValidatorService::class)->validateAndLog(
            $personId,
            $changeType,
            $fieldName,
            $proposedValue,
            $evidenceSummary,
            $evidenceSources ?? [],
            $agentId
        );
        if ($validation['ok'] === false) {
            return [
                'success' => false,
                'error' => $validation['reason'] ?? 'Proposal rejected by validator',
                'gate' => $validation['gate'] ?? null,
                'severity' => $validation['severity'] ?? null,
                'validator_filtered' => true,
            ];
        }

        // For source_add: proposed_value must be a URL or numeric source_id — not free text
        // N104: When a URL is provided, auto-create a real genealogy_sources record so the
        // proposal references a structured source (not just a note). This lets applyProposedChange
        // link the person to a real source record when approved.
        if ($changeType === 'source_add') {
            $val = trim($proposedValue);
            $hasUrl = (bool) preg_match('/https?:\/\//i', $val);
            $hasSourceId = (bool) preg_match('/^\d+$/', $val);
            if (! $hasUrl && ! $hasSourceId) {
                return ['success' => false, 'error' => 'source_add proposed_value must be a URL or numeric source_id. Use notes_append to record free-text source references.'];
            }

            if ($hasUrl && ! $hasSourceId) {
                // Auto-create a genealogy_sources record for this URL
                $sourceId = $this->autoCreateSourceFromUrl($val, $evidenceSummary, $treeId ?? $this->getPersonTreeId($personId), $agentId);
                if ($sourceId) {
                    // Replace URL with structured JSON so applyProposedChange can link via source_id
                    $proposedValue = json_encode([
                        'source_id' => $sourceId,
                        'url' => $val,
                        'auto_created' => true,
                    ]);
                }
            }
        }

        // For fact_update, validate field_name against allowed fields
        if ($changeType === 'fact_update') {
            $allowedFields = [
                'given_name', 'surname', 'suffix', 'nickname', 'sex',
                'birth_date', 'birth_place', 'birth_lat', 'birth_lon',
                'death_date', 'death_place', 'death_lat', 'death_lon',
                'burial_date', 'burial_place', 'burial_lat', 'burial_lon',
                'occupation', 'education', 'religion', 'notes', 'primary_photo_id',
                'title', 'physical_description', 'nationality', 'ssn', 'id_number', 'property', 'cause_of_death',
            ];
            if (! $fieldName || ! in_array($fieldName, $allowedFields)) {
                return ['success' => false, 'error' => "Invalid field_name for fact_update: {$fieldName}"];
            }
        }

        // Resolve tree_id if not provided
        if (! $treeId) {
            $treeId = $this->getPersonTreeId($personId);
            if (! $treeId) {
                return ['success' => false, 'error' => "Person {$personId} not found"];
            }
        }

        // Snapshot current value for fact_update
        $currentValue = null;
        if ($changeType === 'fact_update' && $fieldName) {
            $person = $this->getPersonBasic($personId);
            if ($person && isset($person->{$fieldName})) {
                $currentValue = $person->{$fieldName};
            }
        }

        // Dedup check: skip if identical pending OR already-applied proposal exists
        $existing = DB::selectOne(
            "SELECT id, status FROM genealogy_proposed_changes
             WHERE person_id = ? AND change_type = ? AND field_name <=> ? AND proposed_value = ? AND status IN ('pending', 'applied')",
            [$personId, $changeType, $fieldName, $proposedValue]
        );
        if ($existing) {
            return ['success' => true, 'proposal_id' => (int) $existing->id, 'deduplicated' => true, 'existing_status' => $existing->status];
        }

        DB::insert(
            "INSERT INTO genealogy_proposed_changes
                (tree_id, person_id, change_type, field_name, current_value, proposed_value,
                 evidence_sources, evidence_summary, confidence, agent_id, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())",
            [
                $treeId,
                $personId,
                $changeType,
                $fieldName,
                $currentValue,
                $proposedValue,
                $evidenceSources ? json_encode($evidenceSources) : null,
                $evidenceSummary,
                $confidence,
                $agentId,
            ]
        );

        $proposalId = (int) DB::getPdo()->lastInsertId();

        Log::info('PersonService: Change proposed', [
            'proposal_id' => $proposalId,
            'person_id' => $personId,
            'change_type' => $changeType,
            'field_name' => $fieldName,
            'confidence' => $confidence,
        ]);

        $this->sendChangeProposalNotification(
            $proposalId,
            $personId,
            $changeType,
            $fieldName,
            $proposedValue,
            $currentValue,
            $confidence
        );

        return ['success' => true, 'proposal_id' => $proposalId];
    }

    private function sendChangeProposalNotification(
        int $proposalId,
        int $personId,
        string $changeType,
        ?string $fieldName,
        string $proposedValue,
        mixed $currentValue,
        float $confidence
    ): void {
        try {
            $person = DB::selectOne(
                "SELECT TRIM(CONCAT(COALESCE(given_name, ''), ' ', COALESCE(surname, ''))) AS person_name
                 FROM genealogy_persons
                 WHERE id = ?",
                [$personId]
            );

            $personName = trim((string) ($person->person_name ?? 'Unknown person'));
            $personLabel = $personName !== '' ? $personName : "Person #{$personId}";
            $baseUrl = rtrim((string) config('app.public_url', config('app.url', 'http://localhost')), '/');
            $quickUrl = "{$baseUrl}/api/reviews/quick/change:{$proposalId}";
            $message = $this->buildChangeProposalNotificationMessage(
                $personLabel,
                $changeType,
                $fieldName,
                $proposedValue,
                $currentValue,
                $confidence
            );

            app(NotificationController::class)->send('pushover', [
                'source_group' => 'agent_approval_review',
                'title' => 'Genealogy Review Needed',
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
            Log::warning('PersonService: Failed to send genealogy change proposal notification', [
                'proposal_id' => $proposalId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildChangeProposalNotificationMessage(
        string $personLabel,
        string $changeType,
        ?string $fieldName,
        string $proposedValue,
        mixed $currentValue,
        float $confidence
    ): string {
        $headline = match ($changeType) {
            'notes_append' => "{$personLabel} - New note",
            'fact_update' => "{$personLabel} - ".$this->humanizeFieldName($fieldName),
            'event_add' => "{$personLabel} - New event",
            'source_add' => "{$personLabel} - New source",
            'media_link' => "{$personLabel} - New media link",
            default => "{$personLabel} - ".ucfirst(str_replace('_', ' ', $changeType)),
        };

        $detail = match ($changeType) {
            'notes_append' => $this->truncateNotificationText($proposedValue, 220),
            'fact_update' => $this->buildFactUpdatePreview($currentValue, $proposedValue),
            default => $this->truncateNotificationText($proposedValue, 180),
        };

        return implode("\n", array_values(array_filter([
            $headline,
            $detail,
            'Confidence: '.max(0, min(100, (int) round($confidence * 100))).'%',
        ], static fn ($line) => trim((string) $line) !== '')));
    }

    private function humanizeFieldName(?string $fieldName): string
    {
        $value = trim((string) $fieldName);

        return $value !== ''
            ? ucfirst(str_replace('_', ' ', $value))
            : 'Fact';
    }

    private function truncateNotificationText(string $text, int $limit): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($normalized === '') {
            return '';
        }

        return mb_strlen($normalized) > $limit
            ? rtrim(mb_substr($normalized, 0, max(1, $limit - 1))).'…'
            : $normalized;
    }

    private function buildFactUpdatePreview(mixed $currentValue, string $proposedValue): string
    {
        $next = $this->truncateNotificationText($proposedValue, 140);
        $current = $this->truncateNotificationText((string) ($currentValue ?? ''), 80);

        if ($current !== '') {
            return $current.' -> '.$next;
        }

        return '-> '.$next;
    }

    /**
     * Human-review-UI entry point: flip a pending/pending_review proposal to
     * 'approved' AND apply it in one step. This is what the review queue's
     * Approve button should invoke via review_type_registry.approve_method.
     *
     * applyProposedChange() itself stays strict (requires status='approved')
     * so programmatic apply can't bypass human review. This wrapper is the
     * sanctioned UI path that combines the flip + apply.
     */
    public function approveAndApplyChange(int $proposalId, ?string $notes = null): array
    {
        $row = DB::selectOne(
            'SELECT id, status FROM genealogy_proposed_changes WHERE id = ?',
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
                "UPDATE genealogy_proposed_changes
                 SET status = 'approved', reviewer_notes = COALESCE(?, reviewer_notes), updated_at = NOW()
                 WHERE id = ? AND status IN ('pending', 'pending_review')",
                [$notes, $proposalId]
            );
        }

        return $this->applyProposedChange($proposalId);
    }

    /**
     * Apply an approved proposed change to the tree.
     *
     * Hard gate: caller MUST have already transitioned the row to status='approved'
     * via an explicit human-facing review action. This enforces the operator-in-loop
     * rule at the service layer (previously only the controller boundary checked it).
     * The UI approve path calls approveAndApplyChange() which combines flip + apply.
     */
    public function applyProposedChange(int $proposalId): array
    {
        $proposal = DB::selectOne('SELECT * FROM genealogy_proposed_changes WHERE id = ?', [$proposalId]);
        if (! $proposal) {
            return ['success' => false, 'error' => "Proposal {$proposalId} not found"];
        }

        if ($proposal->status === 'applied') {
            return ['success' => false, 'error' => 'Proposal already applied'];
        }

        if ($proposal->status !== 'approved') {
            return [
                'success' => false,
                'error' => "Proposal status is '{$proposal->status}', not 'approved'. Human approval required before apply.",
            ];
        }

        try {
            switch ($proposal->change_type) {
                case 'fact_update':
                    $this->updatePerson((int) $proposal->person_id, [
                        $proposal->field_name => $proposal->proposed_value,
                    ]);
                    break;

                case 'event_add':
                    // genealogy-media-enrichment (and likely future enrichment
                    // agents) sometimes emit proposed_value as a bare string
                    // alongside a meaningful field_name (e.g. proposed_value =
                    // "preacher", field_name = "occupation"). Pre-fix the apply
                    // path returned "Invalid event data JSON" and the operator
                    // had no way to approve. Now: when the value isn't JSON
                    // but field_name names a GEDCOM event type or attribute,
                    // synthesize the event. Only the structural shape changes
                    // — operator still gates on the actual content via the
                    // human-approval flow upstream.
                    $eventData = json_decode((string) $proposal->proposed_value, true);
                    if (! is_array($eventData)) {
                        $bareValue = (string) ($proposal->proposed_value ?? '');
                        $fieldName = trim((string) ($proposal->field_name ?? ''));
                        if ($bareValue === '' || $fieldName === '') {
                            return [
                                'success' => false,
                                'error' => 'event_add proposal has neither valid JSON nor field_name+bare value pair',
                            ];
                        }
                        // GEDCOM event type tag: 4-letter uppercase. Common
                        // ones (OCCU, EDUC, RESI, RELI, NATU, EMIG, IMMI,
                        // CENS) map directly from a lowercase field_name.
                        // Unknown field_names fall back to "EVEN" (custom
                        // event) with the field_name preserved in description.
                        $knownTags = ['OCCU', 'EDUC', 'RESI', 'RELI', 'NATU', 'EMIG', 'IMMI', 'CENS', 'PROB', 'WILL', 'MIL', 'GRAD', 'RETI'];
                        $upper = strtoupper(substr($fieldName, 0, 4));
                        $eventType = in_array($upper, $knownTags, true) ? $upper : 'EVEN';
                        $description = $eventType === 'EVEN'
                            ? "{$fieldName}: {$bareValue}"
                            : $bareValue;
                        $eventData = [
                            'event_type' => $eventType,
                            'description' => $description,
                        ];
                    }
                    $this->createEvent((int) $proposal->person_id, $eventData);
                    break;

                case 'source_add':
                    // 2.1d proposal-gate backstop — verify the source content
                    // actually mentions the target person's full name before
                    // linking. Skips manual operator additions (no agent_id).
                    $backstopError = $this->runSourceAddBackstop($proposal);
                    if ($backstopError !== null) {
                        DB::update(
                            "UPDATE genealogy_proposed_changes
                             SET status = 'rejected',
                                 reviewer_notes = CONCAT(COALESCE(reviewer_notes, ''),
                                   CASE WHEN reviewer_notes IS NULL OR reviewer_notes = '' THEN '' ELSE '\n' END,
                                   '[proximity backstop] ', ?),
                                 updated_at = NOW()
                             WHERE id = ?",
                            [$backstopError, $proposal->id]
                        );

                        return [
                            'success' => false,
                            'error' => $backstopError,
                            'backstop_rejected' => true,
                            'proposal_id' => (int) $proposal->id,
                        ];
                    }

                    $sourceData = json_decode($proposal->proposed_value, true);
                    if (is_array($sourceData) && ! empty($sourceData['source_id'])) {
                        // Structured source: link to person
                        $citationData = $sourceData;
                        $sourceId = (int) $citationData['source_id'];
                        unset($citationData['source_id']);
                        app(SourceCitationService::class)->linkPersonSource(
                            (int) $proposal->person_id,
                            $sourceId,
                            $citationData
                        );
                    } elseif (preg_match('/https?:\/\//', $proposal->proposed_value ?? '')) {
                        // URL-based source: append as research note with URL + the
                        // evidence_summary the agent captured (title, date, snippet)
                        // so the narrative context isn't lost at apply time. Block 6
                        // finding #6: prior code only persisted the bare URL.
                        $url = $proposal->proposed_value;
                        $summary = trim((string) ($proposal->evidence_summary ?? ''));
                        $noteLines = ['[Research] '.$url];
                        if ($summary !== '' && stripos($summary, $url) === false) {
                            $noteLines[] = $summary;
                        }
                        $person = $this->getPersonBasic((int) $proposal->person_id);
                        $existingNotes = $person->notes ?? '';
                        $separator = $existingNotes ? "\n" : '';
                        $this->updatePerson((int) $proposal->person_id, [
                            'notes' => $existingNotes.$separator.implode("\n", $noteLines),
                        ]);
                    } else {
                        // N48c: Free-text with no source_id and no URL — skip, not actionable
                        Log::info('PersonService: Skipping free-text source_add, not actionable', [
                            'person_id' => $proposal->person_id,
                            'value_preview' => substr($proposal->proposed_value ?? '', 0, 100),
                        ]);

                        return ['success' => false, 'error' => 'Free-text source_add without source_id or URL is not actionable'];
                    }
                    break;

                case 'media_link':
                    app(GenealogyService::class)->linkPersonToMedia(
                        (int) $proposal->person_id,
                        (int) $proposal->proposed_value
                    );
                    break;

                case 'notes_append':
                    $person = $this->getPersonBasic((int) $proposal->person_id);
                    $existingNotes = $person->notes ?? '';
                    $separator = $existingNotes ? "\n\n" : '';
                    $dateStr = date('Y-m-d');
                    $appendText = "[Agent Note, {$dateStr}]: ".trim($proposal->proposed_value);
                    $this->updatePerson((int) $proposal->person_id, [
                        'notes' => $existingNotes.$separator.$appendText,
                    ]);
                    break;

                case 'residence_add':
                    $data = json_decode($proposal->proposed_value, true);
                    if (! is_array($data)) {
                        return ['success' => false, 'error' => 'Invalid residence_add JSON payload'];
                    }
                    $sourceId = $this->normalizeGenealogySourceId($data['source_id'] ?? null);
                    DB::insert(
                        'INSERT INTO genealogy_residences (person_id, residence_date, place, source_id, created_at)
                         VALUES (?, ?, ?, ?, NOW())',
                        [
                            (int) $proposal->person_id,
                            $data['residence_date'] ?? null,
                            $data['place'] ?? null,
                            $sourceId,
                        ]
                    );
                    break;

                case 'family_event_update':
                    $data = json_decode($proposal->proposed_value, true);
                    if (! is_array($data) || empty($data['family_id'])) {
                        return ['success' => false, 'error' => 'Invalid family_event_update JSON payload (requires family_id)'];
                    }
                    $updates = array_filter([
                        'marriage_date' => $data['marriage_date'] ?? null,
                        'marriage_place' => $data['marriage_place'] ?? null,
                        'divorce_date' => $data['divorce_date'] ?? null,
                    ], fn ($v) => $v !== null);
                    if (! empty($updates)) {
                        $setClauses = implode(', ', array_map(fn ($k) => "{$k} = ?", array_keys($updates)));
                        DB::update(
                            "UPDATE genealogy_families SET {$setClauses}, updated_at = NOW() WHERE id = ?",
                            [...array_values($updates), (int) $data['family_id']]
                        );
                    }
                    break;

                case 'external_record_link':
                    $data = json_decode($proposal->proposed_value, true);
                    // N143: DB-driven service type resolution
                    if (! is_array($data) || empty($data['service_type']) || empty($data['external_id'])) {
                        $rawValue = is_array($data) ? ($data['external_id'] ?? $proposal->proposed_value) : (string) $proposal->proposed_value;
                        $fieldName = $proposal->field_name ?? '';
                        $serviceType = null;

                        // Parse "SERVICE:ID" format (e.g. "NARA:147373613", "FindAGrave:12345")
                        if (! is_array($data) && preg_match('/^([A-Za-z_]+):(.+)$/', trim($rawValue), $m)) {
                            $serviceRow = DB::selectOne(
                                'SELECT service_type FROM genealogy_external_service_registry
                                 WHERE is_active = 1 AND LOWER(service_type) = LOWER(?)
                                 LIMIT 1',
                                [strtolower($m[1])]
                            );
                            if ($serviceRow) {
                                $serviceType = $serviceRow->service_type;
                                $rawValue = trim($m[2]);
                            }
                        }

                        // Fallback: match field_alias first, then URL pattern
                        if (! $serviceType) {
                            $serviceRow = DB::selectOne(
                                'SELECT service_type FROM genealogy_external_service_registry
                                 WHERE is_active = 1
                                   AND (field_alias = ? OR (url_pattern IS NOT NULL AND ? LIKE url_pattern))
                                 ORDER BY field_alias = ? DESC
                                 LIMIT 1',
                                [$fieldName, (string) $rawValue, $fieldName]
                            );
                            $serviceType = $serviceRow->service_type ?? null;
                        }

                        if (! $serviceType || empty($rawValue)) {
                            return ['success' => false, 'error' => 'Invalid external_record_link: cannot determine service_type from field_name or value'];
                        }
                        $data = [
                            'service_type' => $serviceType,
                            'external_id' => trim((string) $rawValue),
                            'record_type' => $data['record_type'] ?? null,
                            'match_confidence' => $data['match_confidence'] ?? null,
                        ];
                    }
                    $treeId = $this->getPersonTreeId((int) $proposal->person_id);
                    // Skip if this service+external_id already exists
                    $existing = DB::selectOne(
                        'SELECT id FROM genealogy_external_records WHERE service_type = ? AND external_id = ? LIMIT 1',
                        [$data['service_type'], $data['external_id']]
                    );
                    if ($existing) {
                        Log::info('external_record_link: already exists', [
                            'service_type' => $data['service_type'],
                            'external_id' => $data['external_id'],
                            'existing_id' => $existing->id,
                        ]);
                        break;
                    }
                    DB::insert(
                        "INSERT INTO genealogy_external_records
                            (tree_id, person_id, service_type, external_id, record_type, record_data, match_confidence, status, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())",
                        [
                            $treeId,
                            (int) $proposal->person_id,
                            $data['service_type'],
                            $data['external_id'],
                            $data['record_type'] ?? null,
                            json_encode($data['record_data'] ?? []),
                            ! empty($data['match_confidence']) ? (float) $data['match_confidence'] : null,
                        ]
                    );

                    // N144: NARA approval triggers media download + attach to person
                    if ($data['service_type'] === 'nara' && ! empty($data['external_id'])) {
                        try {
                            $downloadService = app(GenealogyMediaDownloadService::class);
                            $downloadResult = $downloadService->downloadNaraRecord(
                                $data['external_id'], $treeId, (int) $proposal->person_id
                            );
                            if ($downloadResult['success']) {
                                Log::info('N144: NARA media downloaded and attached', [
                                    'naId' => $data['external_id'],
                                    'person_id' => $proposal->person_id,
                                    'downloaded' => $downloadResult['downloaded'],
                                ]);
                            }
                        } catch (\Exception $e) {
                            Log::warning('N144: NARA media download failed after approval', [
                                'external_id' => $data['external_id'],
                                'person_id' => $proposal->person_id,
                                'error' => $e->getMessage(),
                            ]);
                            // Non-fatal — external record was already inserted
                        }
                    }
                    break;

                case 'source_create':
                    $data = json_decode($proposal->proposed_value, true);
                    if (! is_array($data) || empty($data['title'])) {
                        // Agent may have written plain text instead of JSON — use as title + notes
                        $plainText = trim($proposal->proposed_value ?? '');
                        if (empty($plainText)) {
                            return ['success' => false, 'error' => 'Empty source_create payload'];
                        }
                        $data = [
                            'title' => mb_substr($plainText, 0, 255),
                            'notes' => strlen($plainText) > 255 ? $plainText : null,
                        ];
                    }
                    $treeId = $this->getPersonTreeId((int) $proposal->person_id);
                    DB::insert(
                        'INSERT INTO genealogy_sources
                            (tree_id, title, author, publication, repository, url, source_quality, information_quality, notes, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                        [
                            $treeId,
                            $data['title'],
                            $data['author'] ?? null,
                            $data['publication'] ?? null,
                            $data['repository'] ?? null,
                            $data['url'] ?? null,
                            $data['source_quality'] ?? 'original',
                            $data['information_quality'] ?? 'undetermined',
                            $data['notes'] ?? null,
                        ]
                    );
                    break;

                case 'clipping_link':
                    $data = json_decode($proposal->proposed_value, true);
                    if (! is_array($data) || empty($data['clipping_id'])) {
                        return ['success' => false, 'error' => 'Invalid clipping_link JSON payload (requires clipping_id)'];
                    }
                    DB::insert(
                        'INSERT INTO genealogy_person_clippings
                            (person_id, clipping_id, relevance_type, relationship_note, confidence, match_method, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, NOW())',
                        [
                            (int) $proposal->person_id,
                            (int) $data['clipping_id'],
                            $data['relevance_type'] ?? 'subject',
                            $data['relationship_note'] ?? null,
                            ! empty($data['confidence']) ? (float) $data['confidence'] : null,
                            $data['match_method'] ?? 'ai_suggested',
                        ]
                    );
                    break;

                case 'media_metadata_update':
                    $data = json_decode($proposal->proposed_value, true);
                    if (! is_array($data) || empty($data['media_id'])) {
                        return ['success' => false, 'error' => 'Invalid media_metadata_update JSON payload (requires media_id)'];
                    }
                    $mediaUpdates = array_filter([
                        'title' => $data['title'] ?? null,
                        'description' => $data['description'] ?? null,
                        'media_date' => $data['media_date'] ?? null,
                        'media_type' => $data['media_type'] ?? null,
                        'transcription' => $data['transcription'] ?? null,
                    ], fn ($v) => $v !== null);
                    if (! empty($mediaUpdates)) {
                        $setClauses = implode(', ', array_map(fn ($k) => "{$k} = ?", array_keys($mediaUpdates)));
                        DB::update(
                            "UPDATE genealogy_media SET {$setClauses}, updated_at = NOW() WHERE id = ?",
                            [...array_values($mediaUpdates), (int) $data['media_id']]
                        );
                    }
                    break;

                default:
                    return ['success' => false, 'error' => "Unknown change_type: {$proposal->change_type}"];
            }

            DB::update(
                "UPDATE genealogy_proposed_changes SET status = 'applied', applied_at = NOW(), updated_at = NOW() WHERE id = ?",
                [$proposalId]
            );

            Log::info('PersonService: Proposed change applied', [
                'proposal_id' => $proposalId,
                'person_id' => $proposal->person_id,
                'change_type' => $proposal->change_type,
            ]);

            return ['success' => true];
        } catch (\Throwable $e) {
            Log::error('PersonService: Failed to apply proposed change', [
                'proposal_id' => $proposalId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function normalizeGenealogySourceId(mixed $sourceId): ?int
    {
        if ($sourceId === null || $sourceId === '' || ! is_numeric($sourceId)) {
            return null;
        }

        $normalized = (int) $sourceId;
        if ($normalized <= 0) {
            return null;
        }

        $exists = DB::selectOne(
            'SELECT id FROM genealogy_sources WHERE id = ? LIMIT 1',
            [$normalized]
        );

        return $exists ? $normalized : null;
    }

    /**
     * Approve a genealogy research finding: materialize proposals into genealogy_proposed_changes,
     * apply each to the tree, and mark the review queue item as approved.
     *
     * Called by ReviewTypeRegistryService when the 'genealogy_finding' type is approved.
     *
     * @param  string  $token  The agent_review_queue token
     * @param  string|null  $notes  Optional reviewer notes
     */
    public function approveGenealogyFinding(string $token, ?string $notes = null): array
    {
        $queueItem = DB::selectOne(
            'SELECT id, details, agent_id FROM agent_review_queue WHERE token = ?',
            [$token]
        );

        if (! $queueItem) {
            return ['success' => false, 'error' => "Queue item with token '{$token}' not found"];
        }

        $details = json_decode($queueItem->details ?? '{}', true);
        $proposals = $details['proposals'] ?? [];
        $personId = (int) ($details['person_id'] ?? 0);

        if (empty($proposals)) {
            // No proposals — just mark approved
            DB::update(
                "UPDATE agent_review_queue SET status = 'approved', reviewer_notes = ?, reviewed_at = NOW(), updated_at = NOW() WHERE token = ?",
                [$notes, $token]
            );

            return ['success' => true, 'message' => 'Approved (no proposals to apply)', 'applied' => 0, 'failed' => 0];
        }

        $materializationGate = $this->genealogyFindingMaterializationGate($proposals);
        if ($materializationGate !== null) {
            if (($materializationGate['acknowledge'] ?? false) === true) {
                DB::update(
                    "UPDATE agent_review_queue SET status = 'approved', reviewer_notes = ?, reviewed_at = NOW(), updated_at = NOW() WHERE token = ?",
                    [$this->appendReviewNote($notes, $materializationGate['message']), $token]
                );

                return [
                    'success' => true,
                    'message' => $materializationGate['message'],
                    'applied' => 0,
                    'failed' => 0,
                    'acknowledged' => true,
                    'final_status' => 'approved',
                ];
            }

            DB::update(
                'UPDATE agent_review_queue SET reviewer_notes = ?, updated_at = NOW() WHERE token = ?',
                [$this->appendReviewNote($notes, $materializationGate['message']), $token]
            );

            return [
                'success' => false,
                'message' => $materializationGate['message'],
                'error' => $materializationGate['message'],
                'applied' => 0,
                'failed' => 0,
                'errors' => $materializationGate['errors'],
                'final_status' => 'pending',
                'requires_materialization' => true,
                'unsupported_change_types' => $materializationGate['unsupported_change_types'],
            ];
        }

        $applied = 0;
        $failed = 0;
        $errors = [];

        foreach ($proposals as $proposal) {
            $pid = (int) ($proposal['person_id'] ?? $personId);
            if ($pid <= 0) {
                $failed++;
                $errors[] = 'Proposal missing person_id';

                continue;
            }

            $changeType = $proposal['change_type'] ?? null;
            $proposedValue = $proposal['proposed_value'] ?? null;
            $fieldName = $proposal['field_name'] ?? null;

            if (! $changeType || $proposedValue === null) {
                $failed++;
                $errors[] = 'Proposal missing change_type or proposed_value';

                continue;
            }

            // Transform external_record_link: agent may submit plain string ID
            // with field_name like "wikitree_id" — wrap in proper JSON for applyProposedChange
            if ($changeType === 'external_record_link' && is_string($proposedValue)) {
                $decoded = json_decode($proposedValue, true);
                if (! is_array($decoded) || empty($decoded['service_type'])) {
                    $fieldServiceMap = [
                        'wikitree_id' => 'wikitree', 'findagrave_id' => 'findagrave',
                        'ancestry_id' => 'ancestry', 'familysearch_id' => 'familysearch',
                        'geni_id' => 'geni', 'myheritage_id' => 'myheritage',
                    ];
                    $serviceType = $fieldServiceMap[$fieldName ?? ''] ?? null;
                    if ($serviceType) {
                        $proposedValue = json_encode([
                            'service_type' => $serviceType,
                            'external_id' => trim($proposedValue),
                        ]);
                    }
                }
            }

            // Materialize into genealogy_proposed_changes for audit trail
            try {
                $treeId = $this->getPersonTreeId($pid);
                $evidenceSummary = $proposal['evidence_summary'] ?? 'Approved from agent review queue';
                $evidenceSources = $proposal['evidence_sources'] ?? [];
                DB::insert(
                    "INSERT INTO genealogy_proposed_changes
                        (person_id, tree_id, change_type, field_name, proposed_value, evidence_sources, evidence_summary, confidence, agent_id, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())",
                    [
                        $pid,
                        $treeId,
                        $changeType,
                        $fieldName,
                        is_array($proposedValue) ? json_encode($proposedValue) : (string) $proposedValue,
                        is_array($evidenceSources) ? json_encode($evidenceSources) : (string) $evidenceSources,
                        is_string($evidenceSummary) ? $evidenceSummary : json_encode($evidenceSummary),
                        $proposal['confidence'] ?? 0.50,
                        $queueItem->agent_id,
                    ]
                );
                $proposalId = (int) DB::selectOne('SELECT LAST_INSERT_ID() as id')->id;

                // approveGenealogyFinding runs inside the human-approval path for a
                // parent agent_review_queue item, so transition each spawned proposal
                // row to 'approved' before apply. Satisfies the service-level guard.
                // applied_at is set by applyProposedChange() itself on status=applied.
                DB::update(
                    "UPDATE genealogy_proposed_changes SET status='approved', reviewer_notes=COALESCE(reviewer_notes,'Approved via genealogy finding apply'), updated_at=NOW() WHERE id=? AND status='pending'",
                    [$proposalId]
                );

                $result = $this->applyProposedChange($proposalId);
                if ($result['success']) {
                    $applied++;
                } else {
                    $failed++;
                    $errors[] = "{$changeType} for person {$pid}: ".($result['error'] ?? 'unknown');
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "{$changeType} for person {$pid}: ".$e->getMessage();
                Log::error('PersonService: Failed to apply proposal from review queue', [
                    'token' => $token,
                    'person_id' => $pid,
                    'change_type' => $changeType,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Only finalize the parent review row when every child proposal applied.
        // Partial failure leaves the row as 'pending' so the operator can retry
        // or drill into the specific proposal that broke, rather than losing the
        // failure signal behind an "approved" status.
        $finalStatus = $failed === 0 ? 'approved' : 'pending';
        $notesAppended = $failed === 0
            ? $notes
            : trim(($notes ?? '')."\n[Partial apply failure: {$applied} applied, {$failed} failed — row left pending for retry]");

        if ($finalStatus === 'approved') {
            DB::update(
                "UPDATE agent_review_queue SET status = 'approved', reviewer_notes = ?, reviewed_at = NOW(), updated_at = NOW() WHERE token = ?",
                [$notesAppended, $token]
            );
        } else {
            DB::update(
                'UPDATE agent_review_queue SET reviewer_notes = ?, updated_at = NOW() WHERE token = ?',
                [$notesAppended, $token]
            );
        }

        $success = $failed === 0 && $applied > 0;
        $message = $success
            ? "Approved: {$applied} applied, {$failed} failed"
            : "Partial: {$applied} applied, {$failed} failed — review row left pending for retry";

        Log::info('PersonService: Genealogy finding approve result', [
            'token' => $token,
            'person_id' => $personId,
            'applied' => $applied,
            'failed' => $failed,
            'final_status' => $finalStatus,
            'errors' => $errors,
        ]);

        return [
            'success' => $success,
            'message' => $message,
            'applied' => $applied,
            'failed' => $failed,
            'errors' => $errors,
            'final_status' => $finalStatus,
        ];
    }

    /**
     * @param  array<int, mixed>  $proposals
     * @return array<string, mixed>|null
     */
    private function genealogyFindingMaterializationGate(array $proposals): ?array
    {
        $changeTypes = [];
        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            $changeType = trim((string) ($proposal['change_type'] ?? ''));
            if ($changeType !== '') {
                $changeTypes[] = $changeType;
            }
        }

        $changeTypes = array_values(array_unique($changeTypes));
        if ($changeTypes === []) {
            return null;
        }

        $nonMaterializable = array_values(array_diff(
            $changeTypes,
            self::GENEALOGY_FINDING_MATERIALIZABLE_CHANGE_TYPES
        ));

        if ($nonMaterializable === []) {
            return null;
        }

        if (array_diff($nonMaterializable, self::GENEALOGY_FINDING_ACKNOWLEDGEMENT_CHANGE_TYPES) === []
            && array_diff($changeTypes, self::GENEALOGY_FINDING_ACKNOWLEDGEMENT_CHANGE_TYPES) === []) {
            return [
                'acknowledge' => true,
                'message' => 'Acknowledged search-complete genealogy finding; no data changes were applied.',
                'errors' => [],
                'unsupported_change_types' => $nonMaterializable,
            ];
        }

        $remediationTypes = array_values(array_intersect(
            $nonMaterializable,
            self::GENEALOGY_FINDING_REMEDIATION_REQUIRED_CHANGE_TYPES
        ));

        $message = $remediationTypes !== []
            ? 'Genealogy finding requires a materialized remediation preview before approval can apply data changes.'
            : 'Genealogy finding contains unsupported change types and cannot be applied.';

        return [
            'acknowledge' => false,
            'message' => $message,
            'errors' => array_map(
                static fn (string $type): string => "change_type {$type} is not directly materializable from the approve action",
                $nonMaterializable
            ),
            'unsupported_change_types' => $nonMaterializable,
        ];
    }

    private function appendReviewNote(?string $notes, string $systemNote): string
    {
        $notes = trim((string) $notes);
        $suffix = '[system] '.$systemNote;

        return $notes === '' ? $suffix : $notes."\n".$suffix;
    }

    /**
     * Phase 3 of the Genealogy Review UI redesign — apply only a subset
     * of a finding's proposals, with per-proposal accept/reject and
     * structured reject-reason capture.
     *
     * Mirrors approveGenealogyFinding but iterates ONLY the accepted
     * proposal indices. Records the operator's per-field decisions
     * (accepted indices, rejected indices + reason codes, conflict
     * resolutions, free-text notes) into agent_review_queue.reviewer_notes
     * as structured JSON so the agent learning loop can consume the
     * field-level signal.
     *
     * Status transitions:
     *   - all accepted applied successfully + 0 rejected: status='approved'
     *   - some accepted applied + some rejected: status='approved' (operator
     *     made an explicit per-field decision; not a partial failure)
     *   - any accepted apply failed: status stays 'pending' so the operator
     *     can retry the broken row, mirroring the existing apply-all behavior
     *
     * @param  int  $reviewId  agent_review_queue.id
     * @param  array<int>  $acceptedIndices  proposal indices to apply
     * @param  array<int>  $rejectedIndices  proposal indices to mark rejected
     * @param  array<int|string,string>  $rejectReasonCodes  {index → code}; codes: wrong_person, fan_mismatch, date_conflict, name_only_match, place_mismatch, low_evidence, other
     * @param  array<int|string,string>  $conflictResolutions  {index → "proposed"|"on_file"} for conflict rows
     * @param  string|null  $notes  free-text reviewer notes
     */
    public function applyPartialFinding(
        int $reviewId,
        array $acceptedIndices,
        array $rejectedIndices = [],
        array $rejectReasonCodes = [],
        array $conflictResolutions = [],
        ?string $notes = null
    ): array {
        $queueItem = DB::selectOne(
            'SELECT id, token, details, agent_id, status FROM agent_review_queue WHERE id = ?',
            [$reviewId]
        );

        if (! $queueItem) {
            return ['success' => false, 'error' => "Review row #{$reviewId} not found"];
        }
        if ($queueItem->status !== 'pending') {
            return ['success' => false, 'error' => "Review row #{$reviewId} already in status '{$queueItem->status}'"];
        }

        $details = json_decode($queueItem->details ?? '{}', true);
        $proposals = is_array($details['proposals'] ?? null) ? $details['proposals'] : [];
        $personId = (int) ($details['person_id'] ?? 0);

        // Normalize indices to ints
        $accepted = array_values(array_unique(array_map('intval', $acceptedIndices)));
        $rejected = array_values(array_unique(array_map('intval', $rejectedIndices)));

        // Validate indices
        $maxIdx = count($proposals) - 1;
        $invalid = array_filter(
            array_merge($accepted, $rejected),
            static fn (int $i) => $i < 0 || $i > $maxIdx
        );
        if ($invalid !== []) {
            return [
                'success' => false,
                'error' => 'Out-of-range proposal indices: '.implode(',', $invalid),
            ];
        }
        if ($accepted === [] && $rejected === []) {
            return ['success' => false, 'error' => 'No accepted or rejected proposals supplied'];
        }

        // F-05 fix: forbid the same index appearing in both lists.
        // Pre-fix accepted=[0]+rejected=[0] gave $decided=2 for a single
        // proposal, $undecided underflowed to 0, and the row went to
        // approved with a contradictory audit blob. The apply loop also
        // ran the proposal as accepted, so the operator's "reject" was
        // silently overridden.
        $overlap = array_intersect($accepted, $rejected);
        if ($overlap !== []) {
            return [
                'success' => false,
                'error' => 'Index(es) appear in both accepted and rejected: '.implode(',', array_values($overlap)),
            ];
        }

        // Normalize conflict resolutions once so the apply loop and the
        // audit blob share the same allowlisted values.
        $normalizedResolutions = $this->normalizeConflictResolutions($conflictResolutions);

        $applied = 0;
        $failed = 0;
        $keptOnFile = 0;  // F1: accepted-but-conflict-resolved-to-on_file count
        $errors = [];

        foreach ($accepted as $idx) {
            $proposal = $proposals[$idx];
            if (! is_array($proposal)) {
                $failed++;
                $errors[] = "Proposal #{$idx} not an array";

                continue;
            }

            // F1 fix: when the operator picked "On-file value" for a
            // conflict row, skip the apply — accepting the row means
            // "decision recorded, but keep the existing fact." Without
            // this guard the conflict choice was lost and the proposed
            // value silently overwrote the on-file value.
            if (($normalizedResolutions[$idx] ?? null) === 'on_file') {
                $keptOnFile++;

                continue;
            }

            $pid = (int) ($proposal['person_id'] ?? $personId);
            if ($pid <= 0) {
                $failed++;
                $errors[] = "Proposal #{$idx} missing person_id";

                continue;
            }

            $changeType = $proposal['change_type'] ?? null;
            $proposedValue = $proposal['proposed_value'] ?? null;
            $fieldName = $proposal['field_name'] ?? null;

            if (! $changeType || $proposedValue === null) {
                $failed++;
                $errors[] = "Proposal #{$idx} missing change_type or proposed_value";

                continue;
            }

            try {
                $treeId = $this->getPersonTreeId($pid);
                $evidenceSummary = $proposal['evidence_summary'] ?? 'Approved per-field via partial apply';
                $evidenceSources = $proposal['evidence_sources'] ?? [];
                DB::insert(
                    "INSERT INTO genealogy_proposed_changes
                        (person_id, tree_id, change_type, field_name, proposed_value, evidence_sources, evidence_summary, confidence, agent_id, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())",
                    [
                        $pid,
                        $treeId,
                        $changeType,
                        $fieldName,
                        is_array($proposedValue) ? json_encode($proposedValue) : (string) $proposedValue,
                        is_array($evidenceSources) ? json_encode($evidenceSources) : (string) $evidenceSources,
                        is_string($evidenceSummary) ? $evidenceSummary : json_encode($evidenceSummary),
                        $proposal['confidence'] ?? 0.50,
                        $queueItem->agent_id,
                    ]
                );
                $proposalId = (int) DB::selectOne('SELECT LAST_INSERT_ID() as id')->id;

                DB::update(
                    "UPDATE genealogy_proposed_changes SET status='approved', reviewer_notes='Approved via per-field partial apply (Phase 3)', updated_at=NOW() WHERE id=? AND status='pending'",
                    [$proposalId]
                );

                $result = $this->applyProposedChange($proposalId);
                if ($result['success']) {
                    $applied++;
                } else {
                    $failed++;
                    $errors[] = "Proposal #{$idx} ({$changeType}): ".($result['error'] ?? 'apply failed');
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Proposal #{$idx} ({$changeType}): ".$e->getMessage();
                Log::error('PersonService: applyPartialFinding apply error', [
                    'review_id' => $reviewId,
                    'proposal_index' => $idx,
                    'person_id' => $pid,
                    'change_type' => $changeType,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Build the structured decision audit blob — feeds back into
        // AgentProceduralMemoryService later (Phase 3.5).
        $auditBlob = [
            'phase' => 'phase3_partial_apply',
            'reviewed_at' => date('Y-m-d H:i:s'),
            'accepted_indices' => $accepted,
            'rejected_indices' => $rejected,
            'reject_reason_codes' => $this->normalizeReasonCodes($rejectReasonCodes),
            'conflict_resolutions' => $normalizedResolutions,
            'applied' => $applied,
            'kept_on_file' => $keptOnFile,
            'failed' => $failed,
            'errors' => $errors,
            'free_text' => $notes,
        ];

        // F2 fix: status only flips to approved when EVERY proposal has
        // a decision (accepted OR rejected). A reviewer accepting 1 of
        // 10 used to silently mark the row approved and discard the
        // other 9 — this guard keeps the row pending so the operator
        // can come back to the rest. The audit blob still captures
        // what was decided so far.
        $totalProposals = count($proposals);
        $decided = count($accepted) + count($rejected);
        $undecided = max(0, $totalProposals - $decided);
        $auditBlob['undecided_count'] = $undecided;

        // Pending if any apply failed OR any proposal is still undecided.
        $finalStatus = ($failed === 0 && $undecided === 0) ? 'approved' : 'pending';
        $reviewerNotes = json_encode($auditBlob, JSON_UNESCAPED_SLASHES);

        if ($finalStatus === 'approved') {
            DB::update(
                "UPDATE agent_review_queue SET status='approved', reviewer_notes=?, reviewed_at=NOW(), updated_at=NOW() WHERE id=?",
                [$reviewerNotes, $reviewId]
            );
        } else {
            DB::update(
                'UPDATE agent_review_queue SET reviewer_notes=?, updated_at=NOW() WHERE id=?',
                [$reviewerNotes, $reviewId]
            );
        }

        Log::info('PersonService: applyPartialFinding result', [
            'review_id' => $reviewId,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'applied' => $applied,
            'kept_on_file' => $keptOnFile,
            'failed' => $failed,
            'final_status' => $finalStatus,
        ]);

        return [
            // success means: no apply failures AND every proposal got a
            // decision. The frontend uses this to decide whether to
            // remove the item from the queue or keep it visible.
            'success' => $failed === 0 && $undecided === 0,
            'applied' => $applied,
            'kept_on_file' => $keptOnFile,
            'rejected' => count($rejected),
            'failed' => $failed,
            'undecided' => $undecided,
            'errors' => $errors,
            'final_status' => $finalStatus,
        ];
    }

    /**
     * @param  array<int|string, string>  $codes
     * @return array<int, string>
     */
    private function normalizeReasonCodes(array $codes): array
    {
        $allowed = ['wrong_person', 'fan_mismatch', 'date_conflict', 'name_only_match', 'place_mismatch', 'low_evidence', 'other'];
        $out = [];
        foreach ($codes as $idx => $code) {
            $code = is_string($code) ? $code : (string) $code;
            $out[(int) $idx] = in_array($code, $allowed, true) ? $code : 'other';
        }

        return $out;
    }

    /**
     * @param  array<int|string, string>  $resolutions
     * @return array<int, string>
     */
    private function normalizeConflictResolutions(array $resolutions): array
    {
        $allowed = ['proposed', 'on_file'];
        $out = [];
        foreach ($resolutions as $idx => $choice) {
            $choice = is_string($choice) ? $choice : (string) $choice;
            if (in_array($choice, $allowed, true)) {
                $out[(int) $idx] = $choice;
            }
        }

        return $out;
    }

    /**
     * Apply-time temporal-proximity backstop for source_add proposals.
     *
     * Returns null when the proposal's evidence has no extractable
     * year, when the person has no birth/death anchors, or when at
     * least one extracted year falls within (birth-50, death+100).
     * Returns a rejection reason string when EVERY extracted year
     * falls outside that range — operator's evidence is referencing
     * events outside this person's lifetime, almost certainly the
     * wrong person.
     *
     * Margins:
     *   birth - 50  → ancestral context (parents' marriage records)
     *   death + 100 → estate / probate / descendants citing ancestor
     *
     * @param  object  $proposal  genealogy_proposed_changes row
     * @param  object  $person  genealogy_persons row (basic)
     */
    private function checkTemporalProximity(object $proposal, object $person): ?string
    {
        $mismatch = TemporalProximityChecker::check(
            TemporalProximityChecker::extractYear($person->birth_date ?? null),
            TemporalProximityChecker::extractYear($person->death_date ?? null),
            (string) ($proposal->evidence_summary ?? '').' '.
            (string) ($proposal->proposed_value ?? '')
        );
        if ($mismatch === null) {
            return null;
        }
        $lifetime = ($mismatch['person_birth'] ?? '?').'–'.($mismatch['person_death'] ?? '?');

        return sprintf(
            "Source year %d is %d years outside this person's lifetime (%s) — likely wrong person. Reject and re-search with date constraints if this is truly the right person.",
            $mismatch['worst_year'],
            $mismatch['gap_years'],
            $lifetime
        );
    }

    /**
     * Reject a genealogy research finding and cascade-reject pending proposed changes for the same person.
     *
     * Called by ReviewTypeRegistryService when the 'genealogy_finding' type is rejected.
     * Prevents orphaned pending proposals for persons whose research findings were rejected.
     *
     * @param  string  $token  The agent_review_queue token
     * @param  string|null  $reason  Optional rejection reason
     */
    public function rejectGenealogyFinding(string $token, ?string $reason = null): array
    {
        $queueItem = DB::selectOne(
            'SELECT id, details, agent_id FROM agent_review_queue WHERE token = ?',
            [$token]
        );

        if (! $queueItem) {
            return ['success' => false, 'error' => "Queue item with token '{$token}' not found"];
        }

        $details = json_decode($queueItem->details ?? '{}', true);
        $personId = (int) ($details['person_id'] ?? 0);

        // Mark the finding as rejected
        DB::update(
            "UPDATE agent_review_queue SET status = 'rejected', reviewed_at = NOW(), updated_at = NOW() WHERE token = ?",
            [$token]
        );

        // Cascade-reject any pending proposed changes for the same person
        $cascaded = 0;
        if ($personId > 0) {
            $note = 'Cascade-rejected: research finding rejected'.($reason ? " — {$reason}" : '');
            $cascaded = DB::update(
                "UPDATE genealogy_proposed_changes
                 SET status = 'rejected', reviewer_notes = ?, updated_at = NOW()
                 WHERE person_id = ? AND status = 'pending'",
                [$note, $personId]
            );
        }

        Log::info('PersonService: Genealogy finding rejected with cascade', [
            'token' => $token,
            'person_id' => $personId,
            'cascaded_changes' => $cascaded,
        ]);

        return [
            'success' => true,
            'message' => 'Finding rejected'.($cascaded > 0 ? ", cascaded to {$cascaded} pending change(s)" : ''),
            'cascaded_changes' => $cascaded,
        ];
    }
}
