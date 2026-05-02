<?php

namespace App\Console\Commands;

use App\Services\AIService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to auto-generate research topics from genealogy data gaps.
 *
 * Scans genealogy data for missing information and creates research topics
 * that can be processed by the research:run scheduler.
 *
 * Sprint 2: A.2 - Research Topic Auto-Generation
 */
class GenealogyAutoResearchTopics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'genealogy:auto-research-topics
                            {--tree-id= : Specific tree ID to scan}
                            {--dry-run : Show what topics would be created without creating them}
                            {--limit=50 : Maximum number of topics to create per category}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-generate research topics from genealogy data gaps (missing parents, death dates, etc.)';

    /**
     * The RAG category for genealogy research topics.
     */
    private const RAG_CATEGORY = 'genealogy';

    /**
     * Default frequency for genealogy research topics.
     */
    private const DEFAULT_FREQUENCY = 'monthly';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $treeId = $this->option('tree-id');
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $this->info('Genealogy Auto-Research Topics Generator');
        $this->info('=========================================');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No topics will be created');
        }

        $topics = [];
        $created = 0;
        $skipped = 0;

        // Scan for all gap types
        $this->info("\n1. Scanning for persons with missing parents...");
        $missingParents = $this->findPersonsWithMissingParents($treeId, $limit);
        $topics = array_merge($topics, $missingParents);
        $this->info("   Found: " . count($missingParents) . " persons");

        $this->info("\n2. Scanning for deceased persons with missing death date...");
        $missingDeath = $this->findPersonsWithMissingDeathDate($treeId, $limit);
        $topics = array_merge($topics, $missingDeath);
        $this->info("   Found: " . count($missingDeath) . " persons");

        $this->info("\n3. Scanning for families with missing marriage location...");
        $missingMarriage = $this->findFamiliesWithMissingMarriageLocation($treeId, $limit);
        $topics = array_merge($topics, $missingMarriage);
        $this->info("   Found: " . count($missingMarriage) . " families");

        $this->info("\n4. Scanning for persons with approximate birth dates...");
        $approxBirth = $this->findPersonsWithApproximateBirthDates($treeId, $limit);
        $topics = array_merge($topics, $approxBirth);
        $this->info("   Found: " . count($approxBirth) . " persons");

        $this->info("\n" . str_repeat('-', 50));
        $this->info("Total potential topics: " . count($topics));

        if (count($topics) === 0) {
            $this->info("\nNo gaps found to create research topics for.");
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->info("\nTopics that would be created:");
            $this->table(
                ['Type', 'Description', 'Content'],
                array_map(fn($t) => [
                    $t['type'],
                    substr($t['description'], 0, 40) . '...',
                    substr($t['topic_content'], 0, 50) . '...'
                ], array_slice($topics, 0, 20))
            );
            if (count($topics) > 20) {
                $this->info("... and " . (count($topics) - 20) . " more topics");
            }
            return Command::SUCCESS;
        }

        // Create topics in database
        $this->info("\nCreating research topics...");
        $progressBar = $this->output->createProgressBar(count($topics));
        $progressBar->start();

        foreach ($topics as $topic) {
            $result = $this->createTopic($topic);
            if ($result === 'created') {
                $created++;
            } else {
                $skipped++;
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Topics created: {$created}");
        $this->info("Topics skipped (already exist): {$skipped}");

        Log::info('genealogy:auto-research-topics completed', [
            'tree_id' => $treeId,
            'created' => $created,
            'skipped' => $skipped,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Find persons who are not children in any family (missing parents).
     *
     * @param int|null $treeId
     * @param int $limit
     * @return array
     */
    private function findPersonsWithMissingParents(?int $treeId, int $limit): array
    {
        $query = "
            SELECT p.id, p.given_name, p.surname, p.birth_date, p.birth_place
            FROM genealogy_persons p
            LEFT JOIN genealogy_children c ON p.id = c.person_id
            WHERE c.id IS NULL
            AND p.living = 0
        ";

        if ($treeId) {
            $query .= " AND p.tree_id = ?";
        }

        $query .= " ORDER BY p.surname, p.given_name LIMIT ?";

        $params = $treeId ? [$treeId, $limit] : [$limit];
        $persons = DB::select($query, $params);

        $topics = [];
        foreach ($persons as $person) {
            $name = trim("{$person->given_name} {$person->surname}");
            $topics[] = [
                'type' => 'missing_parents',
                'description' => "Research {$name} ancestry",
                'topic_content' => $this->buildAncestryResearchContent($person),
            ];
        }

        return $topics;
    }

    /**
     * Find deceased persons with missing death date.
     *
     * @param int|null $treeId
     * @param int $limit
     * @return array
     */
    private function findPersonsWithMissingDeathDate(?int $treeId, int $limit): array
    {
        $query = "
            SELECT p.id, p.given_name, p.surname, p.birth_date, p.birth_place, p.death_place
            FROM genealogy_persons p
            WHERE p.living = 0
            AND (p.death_date IS NULL OR p.death_date = '')
            AND p.birth_date IS NOT NULL
        ";

        if ($treeId) {
            $query .= " AND p.tree_id = ?";
        }

        $query .= " ORDER BY p.birth_date DESC LIMIT ?";

        $params = $treeId ? [$treeId, $limit] : [$limit];
        $persons = DB::select($query, $params);

        $topics = [];
        foreach ($persons as $person) {
            $name = trim("{$person->given_name} {$person->surname}");
            $topics[] = [
                'type' => 'missing_death',
                'description' => "Find {$name} death record",
                'topic_content' => $this->buildDeathRecordResearchContent($person),
            ];
        }

        return $topics;
    }

    /**
     * Find families with marriage date but no marriage location.
     *
     * @param int|null $treeId
     * @param int $limit
     * @return array
     */
    private function findFamiliesWithMissingMarriageLocation(?int $treeId, int $limit): array
    {
        $query = "
            SELECT f.id, f.marriage_date,
                   h.given_name as husband_given, h.surname as husband_surname,
                   w.given_name as wife_given, w.surname as wife_surname
            FROM genealogy_families f
            LEFT JOIN genealogy_persons h ON f.husband_id = h.id
            LEFT JOIN genealogy_persons w ON f.wife_id = w.id
            WHERE f.marriage_date IS NOT NULL
            AND f.marriage_date != ''
            AND (f.marriage_place IS NULL OR f.marriage_place = '')
        ";

        if ($treeId) {
            $query .= " AND f.tree_id = ?";
        }

        $query .= " ORDER BY f.marriage_date DESC LIMIT ?";

        $params = $treeId ? [$treeId, $limit] : [$limit];
        $families = DB::select($query, $params);

        $topics = [];
        foreach ($families as $family) {
            $husbandName = trim("{$family->husband_given} {$family->husband_surname}");
            $wifeName = trim("{$family->wife_given} {$family->wife_surname}");
            $names = "{$husbandName} and {$wifeName}";

            $topics[] = [
                'type' => 'missing_marriage_location',
                'description' => "Research {$names} marriage location",
                'topic_content' => $this->buildMarriageResearchContent($family),
            ];
        }

        return $topics;
    }

    /**
     * Find persons with approximate birth dates (ABT, BEF, AFT, etc.).
     *
     * @param int|null $treeId
     * @param int $limit
     * @return array
     */
    private function findPersonsWithApproximateBirthDates(?int $treeId, int $limit): array
    {
        // GEDCOM approximate date qualifiers
        $query = "
            SELECT p.id, p.given_name, p.surname, p.birth_date, p.birth_place
            FROM genealogy_persons p
            WHERE p.living = 0
            AND p.birth_date IS NOT NULL
            AND (
                p.birth_date LIKE 'ABT %' OR
                p.birth_date LIKE 'BEF %' OR
                p.birth_date LIKE 'AFT %' OR
                p.birth_date LIKE 'EST %' OR
                p.birth_date LIKE 'CAL %' OR
                p.birth_date LIKE 'BET %'
            )
        ";

        if ($treeId) {
            $query .= " AND p.tree_id = ?";
        }

        $query .= " ORDER BY p.surname, p.given_name LIMIT ?";

        $params = $treeId ? [$treeId, $limit] : [$limit];
        $persons = DB::select($query, $params);

        $topics = [];
        foreach ($persons as $person) {
            $name = trim("{$person->given_name} {$person->surname}");
            $topics[] = [
                'type' => 'approximate_birth',
                'description' => "Verify {$name} birth date",
                'topic_content' => $this->buildBirthVerificationContent($person),
            ];
        }

        return $topics;
    }

    /**
     * Build research content for ancestry research.
     */
    private function buildAncestryResearchContent(object $person): string
    {
        $name = trim("{$person->given_name} {$person->surname}");
        $content = "Research the ancestry and parents of {$name}.";

        if ($person->birth_date) {
            $content .= "\nBirth date: {$person->birth_date}";
        }
        if ($person->birth_place) {
            $content .= "\nBirth place: {$person->birth_place}";
        }

        $content .= "\n\nSearch for:";
        $content .= "\n- Birth certificates or church baptism records";
        $content .= "\n- Census records showing parents";
        $content .= "\n- Marriage records of parents";
        $content .= "\n- Immigration records";
        $content .= "\n- Obituaries mentioning parents";

        return $content;
    }

    /**
     * Build research content for death record research.
     */
    private function buildDeathRecordResearchContent(object $person): string
    {
        $name = trim("{$person->given_name} {$person->surname}");
        $content = "Find the death record for {$name}.";

        if ($person->birth_date) {
            $content .= "\nBirth date: {$person->birth_date}";
        }
        if ($person->birth_place) {
            $content .= "\nBirth place: {$person->birth_place}";
        }
        if ($person->death_place) {
            $content .= "\nDeath place (known): {$person->death_place}";
        }

        $content .= "\n\nSearch for:";
        $content .= "\n- Death certificates";
        $content .= "\n- Obituaries and death notices";
        $content .= "\n- FindAGrave or BillionGraves records";
        $content .= "\n- Cemetery records";
        $content .= "\n- Social Security Death Index (if US)";

        return $content;
    }

    /**
     * Build research content for marriage location research.
     */
    private function buildMarriageResearchContent(object $family): string
    {
        $husbandName = trim("{$family->husband_given} {$family->husband_surname}");
        $wifeName = trim("{$family->wife_given} {$family->wife_surname}");

        $content = "Research the marriage location of {$husbandName} and {$wifeName}.";

        if ($family->marriage_date) {
            $content .= "\nMarriage date: {$family->marriage_date}";
        }

        $content .= "\n\nSearch for:";
        $content .= "\n- Marriage certificates and licenses";
        $content .= "\n- Church marriage records";
        $content .= "\n- Marriage announcements in newspapers";
        $content .= "\n- County clerk records";

        return $content;
    }

    /**
     * Build research content for birth date verification.
     */
    private function buildBirthVerificationContent(object $person): string
    {
        $name = trim("{$person->given_name} {$person->surname}");
        $content = "Verify the exact birth date for {$name}.";

        if ($person->birth_date) {
            $content .= "\nCurrent birth date (approximate): {$person->birth_date}";
        }
        if ($person->birth_place) {
            $content .= "\nBirth place: {$person->birth_place}";
        }

        $content .= "\n\nSearch for:";
        $content .= "\n- Birth certificates (primary source)";
        $content .= "\n- Church baptism records";
        $content .= "\n- Census records (multiple years to triangulate)";
        $content .= "\n- Military records (often have birth info)";
        $content .= "\n- Passport applications";

        return $content;
    }

    /**
     * Create a research topic in the database.
     *
     * @param array $topic
     * @return string 'created' or 'skipped'
     */
    private function createTopic(array $topic): string
    {
        $conn = 'pgsql_rag';

        // Check if similar topic already exists
        $existing = DB::connection($conn)->select(
            "SELECT id FROM research_topics WHERE description = ? AND rag_category = ?",
            [$topic['description'], self::RAG_CATEGORY]
        );

        if (!empty($existing)) {
            return 'skipped';
        }

        try {
            // AI auto-refine: expand the basic topic into a structured research brief
            $refinedContent = $this->aiRefineTopicContent($topic['description'], $topic['topic_content']);

            DB::connection($conn)->statement("
                INSERT INTO research_topics (
                    description, topic_content, frequency, is_active, rag_category,
                    search_depth, max_sources, max_results_per_source, source,
                    require_recent_only, created_at, updated_at
                ) VALUES (?, ?, ?, true, ?, ?, ?, ?, 'auto', false, NOW(), NOW())
            ", [
                $topic['description'],
                $refinedContent,
                self::DEFAULT_FREQUENCY,
                self::RAG_CATEGORY,
                3,  // search_depth (was 2, deeper for better results)
                10, // max_sources (was 5)
                10, // max_results_per_source
            ]);

            return 'created';
        } catch (\Throwable $e) {
            Log::error('Failed to create research topic', [
                'description' => $topic['description'],
                'error' => $e->getMessage(),
            ]);

            return 'skipped';
        }
    }

    /**
     * Use AI to expand a basic topic description into a structured research brief.
     * Falls back to original content if AI is unavailable.
     */
    private function aiRefineTopicContent(string $description, string $basicContent): string
    {
        try {
            $aiService = app(AIService::class);

            $prompt = <<<PROMPT
You are a professional genealogy researcher. Given this research topic, create a structured research brief.

TOPIC: {$description}
BASIC INFO:
{$basicContent}

Generate a detailed research brief in markdown. Include:

## Primary Question
State the exact genealogical question to answer.

## Sub-questions
Number each specific thing to search for (3-5 items). Be specific to the names, dates, and locations provided.

## Context & Search Strategy
- What record types are most likely to have this information?
- What geographic regions and time periods to focus on?
- What denominations or institutions were common in this area/era?

## Success Criteria
What would constitute a successful research finding?

Respond with ONLY the markdown brief, no preamble.
PROMPT;

            $result = $aiService->process($prompt, [
                'temperature' => 0.3,
                'max_tokens' => 1500,
                'ai_timeout' => 30,
            ]);

            if ($result['success'] && !empty($result['response'])) {
                Log::info('GenealogyAutoResearch: AI refined topic', ['description' => $description]);
                return $result['response'];
            }
        } catch (\Throwable $e) {
            Log::warning('GenealogyAutoResearch: AI refine failed, using basic content', [
                'description' => $description,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback: return original basic content
        return $basicContent;
    }
}
