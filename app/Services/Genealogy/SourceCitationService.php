<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * SourceCitationService - Sources, Citations, and Repositories
 *
 * Extracted from GenealogyService as part of Priority 2.1 service extraction.
 * Handles source records, citations linking sources to facts, and repositories.
 *
 * @see /docs/genealogy-module-review.md Priority 2.1
 */
class SourceCitationService
{
    /**
     * GEDCOM 5.5.1 Citation Fact Types
     * Maps to the types of facts that can be cited
     */
    public const CITATION_FACT_TYPES = [
        'BIRT' => 'Birth',
        'DEAT' => 'Death',
        'MARR' => 'Marriage',
        'DIV' => 'Divorce',
        'BURI' => 'Burial',
        'BAPM' => 'Baptism',
        'CHR' => 'Christening',
        'CONF' => 'Confirmation',
        'GRAD' => 'Graduation',
        'EMIG' => 'Emigration',
        'IMMI' => 'Immigration',
        'NATU' => 'Naturalization',
        'CENS' => 'Census',
        'OCCU' => 'Occupation',
        'RESI' => 'Residence',
        'EDUC' => 'Education',
        'RELI' => 'Religion',
        'MILI' => 'Military Service',
        'PROB' => 'Probate',
        'WILL' => 'Will',
        'NAME' => 'Name',
        'NOTE' => 'General Note',
        'EVEN' => 'Custom Event',
    ];

    /**
     * Citation Quality/Certainty Levels per GEDCOM 5.5.1
     */
    public const CITATION_QUALITY_LEVELS = [
        0 => 'Unreliable evidence or estimated data',
        1 => 'Questionable reliability of evidence',
        2 => 'Secondary evidence, data officially recorded sometime after event',
        3 => 'Direct and primary evidence used, or by dominance of the evidence',
    ];

    protected TreeManagementService $treeService;

    /**
     * @param TreeManagementService $treeService
     */
    public function __construct(TreeManagementService $treeService)
    {
        $this->treeService = $treeService;
    }

    // =========================================================================
    // SOURCE CRUD OPERATIONS
    // =========================================================================

    /**
     * Get all sources for a tree
     *
     * @param int $treeId Tree ID
     * @return array
     */
    public function getSources(int $treeId): array
    {
        $sql = "SELECT s.id, s.gedcom_id, s.title, s.author, s.publication,
                       s.repository, s.call_number, s.url, s.notes,
                       s.created_at, s.updated_at,
                       (SELECT COUNT(*) FROM genealogy_citations WHERE source_id = s.id) as citation_count
                FROM genealogy_sources s
                WHERE s.tree_id = ?
                ORDER BY s.title ASC";

        return DB::select($sql, [$treeId]);
    }

    /**
     * Search sources by title or author
     *
     * @param int $treeId Tree ID
     * @param string $query Search query
     * @param int $limit Max results
     * @return array
     */
    public function searchSources(int $treeId, string $query, int $limit = 50): array
    {
        $sql = "SELECT s.id, s.gedcom_id, s.title, s.author, s.publication,
                       s.repository, s.call_number, s.url
                FROM genealogy_sources s
                WHERE s.tree_id = ?
                  AND (s.title LIKE ? OR s.author LIKE ? OR s.publication LIKE ?)
                ORDER BY s.title ASC
                LIMIT ?";

        $searchTerm = '%' . $query . '%';
        return DB::select($sql, [$treeId, $searchTerm, $searchTerm, $searchTerm, $limit]);
    }

    /**
     * Get a single source by ID
     *
     * @param int $sourceId Source ID
     * @return object|null
     */
    public function getSource(int $sourceId): ?object
    {
        $sql = "SELECT s.*, s.repository as repository_name
                FROM genealogy_sources s
                WHERE s.id = ?";

        return DB::selectOne($sql, [$sourceId]);
    }

    /**
     * Create a new source
     *
     * @param int $treeId Tree ID
     * @param array $data Source data
     * @return int New source ID
     * @throws InvalidArgumentException
     */
    public function createSource(int $treeId, array $data): int
    {
        $this->validateSourceData($data);

        $gedcomId = $data['gedcom_id'] ?? $this->generateGedcomId($treeId, 'S');

        $sql = "INSERT INTO genealogy_sources
                (tree_id, gedcom_id, title, author, publication,
                 repository, call_number, url, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        DB::insert($sql, [
            $treeId,
            $gedcomId,
            $data['title'],
            $data['author'] ?? null,
            $data['publication'] ?? null,
            $data['repository'] ?? null,
            $data['call_number'] ?? null,
            $data['url'] ?? null,
            $data['notes'] ?? null,
        ]);

        $sourceId = (int) DB::getPdo()->lastInsertId();

        // Update tree stats
        $this->treeService->updateTreeStats($treeId);

        Log::info('SourceCitationService: Source created', [
            'source_id' => $sourceId,
            'tree_id' => $treeId,
            'title' => $data['title'],
        ]);

        return $sourceId;
    }

    /**
     * Update a source
     *
     * @param int $sourceId Source ID
     * @param array $data Update data
     * @return bool Success
     */
    public function updateSource(int $sourceId, array $data): bool
    {
        $allowedFields = ['title', 'author', 'publication', 'repository', 'call_number', 'url', 'notes'];
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

        $fields[] = "updated_at = NOW()";
        $params[] = $sourceId;

        $sql = "UPDATE genealogy_sources SET " . implode(', ', $fields) . " WHERE id = ?";
        $updated = DB::update($sql, $params) > 0;

        if ($updated) {
            Log::info('SourceCitationService: Source updated', [
                'source_id' => $sourceId,
                'fields' => array_keys($data),
            ]);
        }

        return $updated;
    }

    /**
     * Delete a source and all its citations
     *
     * @param int $sourceId Source ID
     * @return bool Success
     */
    public function deleteSource(int $sourceId): bool
    {
        $source = $this->getSource($sourceId);
        if (!$source) {
            return false;
        }

        // Delete citations first (or rely on CASCADE)
        DB::delete("DELETE FROM genealogy_citations WHERE source_id = ?", [$sourceId]);

        // Delete source
        $sql = "DELETE FROM genealogy_sources WHERE id = ?";
        $deleted = DB::delete($sql, [$sourceId]) > 0;

        if ($deleted) {
            $this->treeService->updateTreeStats($source->tree_id);

            Log::info('SourceCitationService: Source deleted', [
                'source_id' => $sourceId,
                'title' => $source->title,
            ]);
        }

        return $deleted;
    }

    /**
     * Get tree ID for a source
     *
     * @param int $sourceId Source ID
     * @return int|null
     */
    public function getSourceTreeId(int $sourceId): ?int
    {
        $sql = "SELECT tree_id FROM genealogy_sources WHERE id = ?";
        $result = DB::selectOne($sql, [$sourceId]);
        return $result ? (int) $result->tree_id : null;
    }

    // =========================================================================
    // CITATION CRUD OPERATIONS
    // =========================================================================

    /**
     * Get citations for a person
     *
     * @param int $personId Person ID
     * @return array
     */
    public function getPersonCitations(int $personId): array
    {
        $sql = "SELECT c.*, s.title as source_title, s.author as source_author
                FROM genealogy_citations c
                JOIN genealogy_sources s ON s.id = c.source_id
                WHERE c.person_id = ?
                ORDER BY c.fact_type, c.created_at";

        return DB::select($sql, [$personId]);
    }

    /**
     * Get citations for a family
     *
     * @param int $familyId Family ID
     * @return array
     */
    public function getFamilyCitations(int $familyId): array
    {
        $sql = "SELECT c.*, s.title as source_title, s.author as source_author
                FROM genealogy_citations c
                JOIN genealogy_sources s ON s.id = c.source_id
                WHERE c.family_id = ?
                ORDER BY c.fact_type, c.created_at";

        return DB::select($sql, [$familyId]);
    }

    /**
     * Get all citations for a source
     *
     * @param int $sourceId Source ID
     * @return array
     */
    public function getSourceCitations(int $sourceId): array
    {
        $sql = "SELECT c.*,
                       CASE
                           WHEN c.person_id IS NOT NULL THEN CONCAT(p.given_name, ' ', p.surname)
                           ELSE NULL
                       END as person_name,
                       c.person_id, c.family_id
                FROM genealogy_citations c
                LEFT JOIN genealogy_persons p ON p.id = c.person_id
                WHERE c.source_id = ?
                ORDER BY c.created_at DESC";

        return DB::select($sql, [$sourceId]);
    }

    /**
     * Get a single citation
     *
     * @param int $citationId Citation ID
     * @return object|null
     */
    public function getCitation(int $citationId): ?object
    {
        $sql = "SELECT c.*, s.title as source_title, s.author as source_author
                FROM genealogy_citations c
                JOIN genealogy_sources s ON s.id = c.source_id
                WHERE c.id = ?";

        return DB::selectOne($sql, [$citationId]);
    }

    /**
     * Create a citation linking a source to a person or family fact
     *
     * @param array $data Citation data
     * @return int New citation ID
     * @throws InvalidArgumentException
     */
    public function createCitation(array $data): int
    {
        $this->validateCitationData($data);

        $sql = "INSERT INTO genealogy_citations
                (source_id, person_id, family_id, fact_type, page, quality, text, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

        DB::insert($sql, [
            $data['source_id'],
            $data['person_id'] ?? null,
            $data['family_id'] ?? null,
            $data['fact_type'] ?? 'NOTE',
            $data['page'] ?? null,
            $data['quality'] ?? 2,
            $data['note'] ?? $data['text'] ?? null,
        ]);

        $citationId = (int) DB::getPdo()->lastInsertId();

        Log::info('SourceCitationService: Citation created', [
            'citation_id' => $citationId,
            'source_id' => $data['source_id'],
            'person_id' => $data['person_id'] ?? null,
            'family_id' => $data['family_id'] ?? null,
            'fact_type' => $data['fact_type'] ?? 'NOTE',
        ]);

        return $citationId;
    }

    /**
     * Update a citation
     *
     * @param int $citationId Citation ID
     * @param array $data Update data
     * @return bool Success
     */
    public function updateCitation(int $citationId, array $data): bool
    {
        $allowedFields = ['source_id', 'fact_type', 'page', 'quality', 'note'];
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

        $fields[] = "updated_at = NOW()";
        $params[] = $citationId;

        $sql = "UPDATE genealogy_citations SET " . implode(', ', $fields) . " WHERE id = ?";
        $updated = DB::update($sql, $params) > 0;

        if ($updated) {
            Log::info('SourceCitationService: Citation updated', [
                'citation_id' => $citationId,
                'fields' => array_keys($data),
            ]);
        }

        return $updated;
    }

    /**
     * Delete a citation
     *
     * @param int $citationId Citation ID
     * @return bool Success
     */
    public function deleteCitation(int $citationId): bool
    {
        $sql = "DELETE FROM genealogy_citations WHERE id = ?";
        $deleted = DB::delete($sql, [$citationId]) > 0;

        if ($deleted) {
            Log::info('SourceCitationService: Citation deleted', [
                'citation_id' => $citationId,
            ]);
        }

        return $deleted;
    }

    // =========================================================================
    // PERSON-SOURCE LINKING
    // =========================================================================

    /**
     * Get all sources linked to a person (via citations)
     *
     * @param int $personId Person ID
     * @return array
     */
    public function getPersonSources(int $personId): array
    {
        $sql = "SELECT DISTINCT s.id, s.gedcom_id, s.title, s.author, s.publication,
                       s.repository, s.call_number, s.url,
                       (SELECT COUNT(*) FROM genealogy_citations
                        WHERE source_id = s.id AND person_id = ?) as citation_count
                FROM genealogy_sources s
                JOIN genealogy_citations c ON c.source_id = s.id
                WHERE c.person_id = ?
                ORDER BY s.title ASC";

        return DB::select($sql, [$personId, $personId]);
    }

    /**
     * Link a source to a person with a citation (public API)
     *
     * @param int $personId Person ID
     * @param int $sourceId Source ID
     * @param array $citationData Additional citation data
     * @return int Citation ID
     */
    public function linkPersonSource(int $personId, int $sourceId, array $citationData = []): int
    {
        $data = array_merge($citationData, [
            'person_id' => $personId,
            'source_id' => $sourceId,
        ]);

        return $this->createCitation($data);
    }

    /**
     * Unlink a source from a person (deletes all citations between them)
     *
     * @param int $personId Person ID
     * @param int $sourceId Source ID
     * @return int Number of citations deleted
     */
    public function unlinkPersonSource(int $personId, int $sourceId): int
    {
        $sql = "DELETE FROM genealogy_citations WHERE person_id = ? AND source_id = ?";
        $count = DB::delete($sql, [$personId, $sourceId]);

        if ($count > 0) {
            Log::info('SourceCitationService: Person-source link removed', [
                'person_id' => $personId,
                'source_id' => $sourceId,
                'citations_deleted' => $count,
            ]);
        }

        return $count;
    }

    // =========================================================================
    // REPOSITORY OPERATIONS
    // =========================================================================

    /**
     * Get all repositories for a tree
     *
     * @param int $treeId Tree ID
     * @return array
     */
    public function getRepositories(int $treeId): array
    {
        $sql = "SELECT r.*,
                       (SELECT COUNT(*) FROM genealogy_sources WHERE repository = r.name AND tree_id = r.tree_id) as source_count
                FROM genealogy_repositories r
                WHERE r.tree_id = ?
                ORDER BY r.name ASC";

        return DB::select($sql, [$treeId]);
    }

    /**
     * Get a single repository
     *
     * @param int $repositoryId Repository ID
     * @return object|null
     */
    public function getRepository(int $repositoryId): ?object
    {
        $sql = "SELECT r.*,
                       (SELECT COUNT(*) FROM genealogy_sources WHERE repository = r.name AND tree_id = r.tree_id) as source_count
                FROM genealogy_repositories r
                WHERE r.id = ?";

        return DB::selectOne($sql, [$repositoryId]);
    }

    /**
     * Create a new repository
     *
     * @param int $treeId Tree ID
     * @param array $data Repository data
     * @return int New repository ID
     * @throws InvalidArgumentException
     */
    public function createRepository(int $treeId, array $data): int
    {
        if (empty($data['name'])) {
            throw new InvalidArgumentException('Repository name is required');
        }

        $gedcomId = $data['gedcom_id'] ?? $this->generateGedcomId($treeId, 'R');

        // Build full address from components if individual fields provided
        $address = $data['address'] ?? null;
        if (!$address) {
            $parts = array_filter([
                $data['city'] ?? null,
                $data['state'] ?? null,
                $data['postal_code'] ?? null,
                $data['country'] ?? null,
            ]);
            $address = $parts ? implode(', ', $parts) : null;
        }

        $sql = "INSERT INTO genealogy_repositories
                (tree_id, gedcom_id, name, address, phone, email, url, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        DB::insert($sql, [
            $treeId,
            $gedcomId,
            $data['name'],
            $address,
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['url'] ?? $data['website'] ?? null,
            $data['notes'] ?? $data['note'] ?? null,
        ]);

        $repositoryId = (int) DB::getPdo()->lastInsertId();

        Log::info('SourceCitationService: Repository created', [
            'repository_id' => $repositoryId,
            'tree_id' => $treeId,
            'name' => $data['name'],
        ]);

        return $repositoryId;
    }

    /**
     * Update a repository
     *
     * @param int $repositoryId Repository ID
     * @param array $data Update data
     * @return bool Success
     */
    public function updateRepository(int $repositoryId, array $data): bool
    {
        $allowedFields = ['name', 'address', 'city', 'state', 'postal_code',
                          'country', 'phone', 'email', 'website', 'note'];
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

        $fields[] = "updated_at = NOW()";
        $params[] = $repositoryId;

        $sql = "UPDATE genealogy_repositories SET " . implode(', ', $fields) . " WHERE id = ?";
        $updated = DB::update($sql, $params) > 0;

        if ($updated) {
            Log::info('SourceCitationService: Repository updated', [
                'repository_id' => $repositoryId,
                'fields' => array_keys($data),
            ]);
        }

        return $updated;
    }

    /**
     * Delete a repository
     *
     * @param int $repositoryId Repository ID
     * @return bool Success
     */
    public function deleteRepository(int $repositoryId): bool
    {
        $repository = $this->getRepository($repositoryId);
        if (!$repository) {
            return false;
        }

        // Check if any sources reference this repository
        if ($repository->source_count > 0) {
            // Unlink sources from repository instead of preventing deletion
            // Unlink sources by clearing the repository name string
            DB::update("UPDATE genealogy_sources SET repository = NULL WHERE repository = ? AND tree_id = ?",
                [$repository->name, $repository->tree_id]);
        }

        $sql = "DELETE FROM genealogy_repositories WHERE id = ?";
        $deleted = DB::delete($sql, [$repositoryId]) > 0;

        if ($deleted) {
            Log::info('SourceCitationService: Repository deleted', [
                'repository_id' => $repositoryId,
                'name' => $repository->name,
            ]);
        }

        return $deleted;
    }

    /**
     * Get tree ID for a repository
     *
     * @param int $repositoryId Repository ID
     * @return int|null
     */
    public function getRepositoryTreeId(int $repositoryId): ?int
    {
        $sql = "SELECT tree_id FROM genealogy_repositories WHERE id = ?";
        $result = DB::selectOne($sql, [$repositoryId]);
        return $result ? (int) $result->tree_id : null;
    }

    // =========================================================================
    // METADATA & HELPERS
    // =========================================================================

    /**
     * Get available citation fact types
     *
     * @return array
     */
    public function getCitationFactTypes(): array
    {
        return self::CITATION_FACT_TYPES;
    }

    /**
     * Get citation quality levels with descriptions
     *
     * @return array
     */
    public function getCitationQualityLevels(): array
    {
        return self::CITATION_QUALITY_LEVELS;
    }

    /**
     * Get sources with pagination
     *
     * @param int $treeId Tree ID
     * @param int $page Page number (1-based)
     * @param int $perPage Items per page
     * @param string|null $search Search query
     * @return array ['data' => array, 'total' => int, 'page' => int, 'per_page' => int]
     */
    public function getSourcesPaginated(int $treeId, int $page = 1, int $perPage = 25, ?string $search = null): array
    {
        $offset = ($page - 1) * $perPage;

        $countSql = "SELECT COUNT(*) as total FROM genealogy_sources WHERE tree_id = ?";
        $dataSql = "SELECT s.id, s.gedcom_id, s.title, s.author, s.publication,
                           s.repository, s.call_number, s.url,
                           (SELECT COUNT(*) FROM genealogy_citations WHERE source_id = s.id) as citation_count
                    FROM genealogy_sources s
                    WHERE s.tree_id = ?";

        $params = [$treeId];

        if ($search) {
            $searchClause = " AND (s.title LIKE ? OR s.author LIKE ? OR s.publication LIKE ?)";
            $countSql .= $searchClause;
            $dataSql .= $searchClause;
            $searchTerm = '%' . $search . '%';
            $params = [$treeId, $searchTerm, $searchTerm, $searchTerm];
        }

        $dataSql .= " ORDER BY s.title ASC LIMIT ? OFFSET ?";

        $countResult = DB::selectOne($countSql, $search ? [$treeId, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%'] : [$treeId]);
        $total = (int) $countResult->total;

        $dataParams = $params;
        $dataParams[] = $perPage;
        $dataParams[] = $offset;
        $data = DB::select($dataSql, $dataParams);

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => (int) ceil($total / $perPage),
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Generate a unique GEDCOM ID for a record
     *
     * @param int $treeId Tree ID
     * @param string $prefix Prefix (S for source, R for repository)
     * @return string
     */
    private function generateGedcomId(int $treeId, string $prefix): string
    {
        $table = $prefix === 'S' ? 'genealogy_sources' : 'genealogy_repositories';

        $sql = "SELECT MAX(CAST(SUBSTRING(gedcom_id, 2) AS UNSIGNED)) as max_id
                FROM {$table}
                WHERE tree_id = ? AND gedcom_id LIKE ?";

        $result = DB::selectOne($sql, [$treeId, $prefix . '%']);
        $nextId = ($result->max_id ?? 0) + 1;

        return $prefix . $nextId;
    }

    /**
     * Validate source data
     *
     * @param array $data Source data
     * @throws InvalidArgumentException
     */
    private function validateSourceData(array $data): void
    {
        if (empty($data['title'])) {
            throw new InvalidArgumentException('Source title is required');
        }

        if (strlen($data['title']) > 500) {
            throw new InvalidArgumentException('Source title must be 500 characters or less');
        }

        // repository is a varchar name field, not an FK — no need to validate against repositories table
    }

    /**
     * Validate citation data
     *
     * @param array $data Citation data
     * @throws InvalidArgumentException
     */
    private function validateCitationData(array $data): void
    {
        if (empty($data['source_id'])) {
            throw new InvalidArgumentException('Source ID is required for citation');
        }

        // Must have either person_id or family_id
        if (empty($data['person_id']) && empty($data['family_id'])) {
            throw new InvalidArgumentException('Citation must reference either a person or family');
        }

        // Validate fact type if provided
        if (!empty($data['fact_type']) && !array_key_exists($data['fact_type'], self::CITATION_FACT_TYPES)) {
            throw new InvalidArgumentException('Invalid fact type: ' . $data['fact_type']);
        }

        // Validate quality if provided
        if (isset($data['quality']) && !array_key_exists((int) $data['quality'], self::CITATION_QUALITY_LEVELS)) {
            throw new InvalidArgumentException('Quality must be between 0 and 3');
        }

        // Verify source exists
        $source = $this->getSource((int) $data['source_id']);
        if (!$source) {
            throw new InvalidArgumentException('Invalid source ID');
        }
    }
}
