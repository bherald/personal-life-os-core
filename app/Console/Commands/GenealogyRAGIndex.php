<?php

namespace App\Console\Commands;

use App\Services\RAGService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to index genealogy persons into PostgreSQL RAG embeddings.
 *
 * Enables natural language queries like:
 * "Who were the Doe family members born in Pennsylvania before 1900?"
 *
 * Sprint 3: A.3 - Cross-Module RAG Integration
 */
class GenealogyRAGIndex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'genealogy:rag-index
                            {--tree-id= : Specific tree ID to index}
                            {--person-id= : Index a specific person only}
                            {--reindex : Re-index all persons (removes existing entries first)}
                            {--dry-run : Show what would be indexed without indexing}
                            {--exclude-living : Exclude persons explicitly marked living}
                            {--limit=500 : Maximum number of persons to index per run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Index genealogy persons into PostgreSQL RAG embeddings for natural language search';

    /**
     * Document type for genealogy persons in RAG.
     */
    private const DOCUMENT_TYPE = 'genealogy_person';

    /**
     * Designation for genealogy documents.
     */
    private const DESIGNATION = 'genealogy';

    private RAGService $ragService;

    public function __construct(RAGService $ragService)
    {
        parent::__construct();
        $this->ragService = $ragService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $treeId = $this->option('tree-id');
        $personId = $this->option('person-id');
        $reindex = $this->option('reindex');
        $dryRun = $this->option('dry-run');
        $excludeLiving = (bool) $this->option('exclude-living');
        $limit = (int) $this->option('limit');

        $this->info('Genealogy RAG Indexer');
        $this->info('=====================');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No documents will be indexed');
        }

        // Handle reindex: remove existing documents first
        if ($reindex && !$dryRun) {
            $this->info("\nRemoving existing genealogy RAG documents...");
            $deleted = $this->removeExistingDocuments($treeId, $personId);
            $this->info("Removed: {$deleted} documents");
        }

        // Get persons to index
        $persons = $this->getPersonsToIndex($treeId, $personId, $limit, $reindex, $excludeLiving);
        $this->info("\nPersons to index: " . count($persons));

        if (count($persons) === 0) {
            $this->info("No persons found to index.");
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->info("\nSample persons that would be indexed:");
            $this->table(
                ['ID', 'Name', 'Birth', 'Death'],
                array_map(fn($p) => [
                    $p->id,
                    substr(trim("{$p->given_name} {$p->surname}"), 0, 30),
                    $p->birth_date ?? 'Unknown',
                    $p->death_date ?? ($p->living ? 'Living' : 'Unknown'),
                ], array_slice($persons, 0, 15))
            );
            if (count($persons) > 15) {
                $this->info("... and " . (count($persons) - 15) . " more persons");
            }
            return Command::SUCCESS;
        }

        // Index persons
        $indexed = 0;
        $errors = 0;

        $progressBar = $this->output->createProgressBar(count($persons));
        $progressBar->start();

        foreach ($persons as $person) {
            try {
                $this->indexPerson($person);
                $indexed++;
            } catch (\Exception $e) {
                $errors++;
                Log::error('Failed to index genealogy person', [
                    'person_id' => $person->id,
                    'error' => $e->getMessage(),
                ]);
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Successfully indexed: {$indexed}");
        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        Log::info('genealogy:rag-index completed', [
            'tree_id' => $treeId,
            'indexed' => $indexed,
            'errors' => $errors,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Get persons to index from the database.
     */
    private function getPersonsToIndex(?int $treeId, ?int $personId, int $limit, bool $reindex, bool $excludeLiving): array
    {
        // First, get already indexed person IDs from PostgreSQL if not reindexing
        $alreadyIndexed = [];
        if (!$reindex && !$personId) {
            $indexedDocs = DB::connection('pgsql_rag')->select(
                "SELECT source_id FROM rag_documents WHERE document_type = ? AND source_type = ?",
                [self::DOCUMENT_TYPE, 'genealogy_person']
            );
            $alreadyIndexed = array_map(fn($r) => (int) $r->source_id, $indexedDocs);
        }

        $query = "
            SELECT
                p.id, p.tree_id, p.given_name, p.surname,
                p.birth_date, p.birth_place, p.death_date, p.death_place,
                p.sex, p.living, p.occupation, p.notes
            FROM genealogy_persons p
            WHERE 1=1
        ";
        $params = [];

        if ($personId) {
            $query .= " AND p.id = ?";
            $params[] = $personId;
        }

        if ($treeId) {
            $query .= " AND p.tree_id = ?";
            $params[] = $treeId;
        }

        if ($excludeLiving) {
            $query .= " AND (p.living IS NULL OR p.living = 0)";
        }

        // If not reindexing, exclude already indexed persons
        if (!empty($alreadyIndexed)) {
            $placeholders = implode(',', array_fill(0, count($alreadyIndexed), '?'));
            $query .= " AND p.id NOT IN ({$placeholders})";
            $params = array_merge($params, $alreadyIndexed);
        }

        $query .= " ORDER BY p.id LIMIT ?";
        $params[] = $limit;

        return DB::select($query, $params);
    }

    /**
     * Build rich text representation of a person for embedding.
     */
    private function buildPersonContent(object $person): string
    {
        $name = trim("{$person->given_name} {$person->surname}");

        // Build life dates
        $lifespan = '';
        if ($person->birth_date || $person->death_date) {
            $birth = $person->birth_date ?: '?';
            $death = $person->death_date ?: ($person->living ? 'living' : '?');
            $lifespan = " ({$birth} - {$death})";
        }

        $content = "{$name}{$lifespan}\n";

        // Add birth information
        if ($person->birth_date || $person->birth_place) {
            $content .= "Born: ";
            if ($person->birth_date) {
                $content .= $person->birth_date;
            }
            if ($person->birth_place) {
                $content .= $person->birth_date ? " in {$person->birth_place}" : $person->birth_place;
            }
            $content .= "\n";
        }

        // Add death information
        if ($person->death_date || $person->death_place) {
            $content .= "Died: ";
            if ($person->death_date) {
                $content .= $person->death_date;
            }
            if ($person->death_place) {
                $content .= $person->death_date ? " in {$person->death_place}" : $person->death_place;
            }
            $content .= "\n";
        }

        // Add sex/gender
        if ($person->sex) {
            $sexText = $person->sex === 'M' ? 'Male' : ($person->sex === 'F' ? 'Female' : $person->sex);
            $content .= "Gender: {$sexText}\n";
        }

        // Add occupation
        if ($person->occupation) {
            $content .= "Occupation: {$person->occupation}\n";
        }

        // Add spouse(s)
        $spouses = $this->getSpouses($person->id);
        if (!empty($spouses)) {
            $content .= "Spouse(s): " . implode(', ', $spouses) . "\n";
        }

        // Add parents
        $parents = $this->getParents($person->id);
        if (!empty($parents)) {
            $content .= "Parents: " . implode(' and ', $parents) . "\n";
        }

        // Add children
        $children = $this->getChildren($person->id);
        if (!empty($children)) {
            $content .= "Children: " . implode(', ', $children) . "\n";
        }

        // Add notes (truncated)
        if ($person->notes) {
            $notes = strip_tags($person->notes);
            if (strlen($notes) > 500) {
                $notes = substr($notes, 0, 500) . '...';
            }
            $content .= "Notes: {$notes}\n";
        }

        return trim($content);
    }

    /**
     * Get spouse names for a person.
     */
    private function getSpouses(int $personId): array
    {
        $query = "
            SELECT p.given_name, p.surname
            FROM genealogy_families f
            JOIN genealogy_persons p ON (
                (f.husband_id = ? AND f.wife_id = p.id) OR
                (f.wife_id = ? AND f.husband_id = p.id)
            )
            GROUP BY p.id, p.given_name, p.surname
        ";

        $spouses = DB::select($query, [$personId, $personId]);
        return array_map(fn($s) => trim("{$s->given_name} {$s->surname}"), $spouses);
    }

    /**
     * Get parent names for a person.
     */
    private function getParents(int $personId): array
    {
        $query = "
            SELECT p.given_name, p.surname
            FROM genealogy_children c
            JOIN genealogy_families f ON c.family_id = f.id
            JOIN genealogy_persons p ON (p.id = f.husband_id OR p.id = f.wife_id)
            WHERE c.person_id = ?
        ";

        $parents = DB::select($query, [$personId]);
        return array_map(fn($p) => trim("{$p->given_name} {$p->surname}"), $parents);
    }

    /**
     * Get children names for a person.
     */
    private function getChildren(int $personId): array
    {
        // Use subquery to avoid MySQL strict mode issue with DISTINCT + ORDER BY
        $query = "
            SELECT given_name, surname FROM (
                SELECT p.given_name, p.surname, p.birth_date
                FROM genealogy_families f
                JOIN genealogy_children c ON c.family_id = f.id
                JOIN genealogy_persons p ON c.person_id = p.id
                WHERE (f.husband_id = ? OR f.wife_id = ?)
                GROUP BY p.id, p.given_name, p.surname, p.birth_date
                ORDER BY p.birth_date
            ) AS children
        ";

        $children = DB::select($query, [$personId, $personId]);
        return array_map(fn($c) => trim("{$c->given_name} {$c->surname}"), $children);
    }

    /**
     * Index a single person into RAG.
     */
    private function indexPerson(object $person): void
    {
        $name = trim("{$person->given_name} {$person->surname}");
        $content = $this->buildPersonContent($person);

        // Build metadata
        $metadata = [
            'tree_id' => $person->tree_id,
            'birth_date' => $person->birth_date,
            'birth_place' => $person->birth_place,
            'death_date' => $person->death_date,
            'death_place' => $person->death_place,
            'sex' => $person->sex,
            'surname' => $person->surname,
        ];

        // Use RAGService to index
        $this->ragService->indexDocument(
            documentType: self::DOCUMENT_TYPE,
            content: $content,
            title: $name,
            metadata: $metadata,
            sourceId: (string) $person->id,
            sourceType: 'genealogy_person',
            designation: self::DESIGNATION,
            options: ['skip_dedup' => true]
        );
    }

    /**
     * Remove existing genealogy documents from RAG.
     */
    private function removeExistingDocuments(?int $treeId, ?int $personId): int
    {
        $query = "DELETE FROM rag_documents WHERE document_type = ? AND source_type = ?";
        $params = [self::DOCUMENT_TYPE, 'genealogy_person'];

        if ($personId) {
            $query .= " AND source_id = ?";
            $params[] = (string) $personId;
        }

        // Note: tree_id filter would require checking metadata JSON
        // For simplicity, reindex removes all genealogy documents

        return DB::connection('pgsql_rag')->delete($query, $params);
    }
}
