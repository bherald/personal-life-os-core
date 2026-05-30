<?php

namespace App\Services\Genealogy;

use App\Services\AIService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Priority A.1: AI Genealogist Persona Service
 *
 * Provides professional-grade genealogical research assistance using AIService.
 * Implements Evidence Explained methodology, Genealogical Proof Standard,
 * and FAN cluster research patterns.
 *
 * Uses RAW SQL queries - NO Eloquent models
 */
class GenealogyAIResearchService
{
    private AIService $aiService;

    private ?GenealogyLessonPromptContextService $lessonPromptContext = null;

    /**
     * Professional genealogist system prompt
     * Based on Evidence Explained methodology and Genealogical Proof Standard
     */
    private const GENEALOGIST_SYSTEM_PROMPT = <<<'PROMPT'
You are a professional genealogist. Be CONCISE - the user values brevity.

Expertise: vital records, census, immigration, military, land, church records, DNA genealogy, GEDCOM, Evidence Explained citations.

Output style:
- Bullet points over paragraphs
- Skip obvious/general advice
- Only actionable specifics for the person's time period and location
- Lead with most important items
- No lengthy explanations unless asked
PROMPT;

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    private function lessonContext(): GenealogyLessonPromptContextService
    {
        return $this->lessonPromptContext ??= app(GenealogyLessonPromptContextService::class);
    }

    /**
     * Research a person and suggest next steps
     *
     * @param  int  $personId  The genealogy person ID
     * @param  array  $options  Optional configuration:
     *                          - focus: 'ancestry'|'descendants'|'siblings'|'general'
     *                          - include_sources: bool - include existing source analysis
     *                          - brick_wall: bool - focus on breaking through research barriers
     * @return array Research suggestions and strategy
     */
    public function researchPerson(int $personId, array $options = []): array
    {
        $person = $this->getPersonWithContext($personId);

        if (! $person) {
            return [
                'success' => false,
                'error' => 'Person not found',
            ];
        }

        $focus = $options['focus'] ?? 'general';
        $includeSources = $options['include_sources'] ?? true;
        $isBrickWall = $options['brick_wall'] ?? false;

        // Build comprehensive context about the person
        $context = $this->buildPersonContext($person, $includeSources, $focus);

        // Build the research prompt
        $prompt = $this->buildResearchPrompt($person, $context, $focus, $isBrickWall);

        // Cache key for research results (24 hour cache)
        $cacheKey = "genealogy_ai_research:{$personId}:{$focus}:".md5(json_encode($options).'|'.$context);

        return Cache::remember($cacheKey, 86400, function () use ($prompt, $person, $focus) {
            Log::info('GenealogyAIResearchService: Researching person', [
                'person_id' => $person->id,
                'name' => $person->given_name.' '.$person->surname,
                'focus' => $focus,
            ]);

            $result = $this->aiService->process($prompt, [
                'system_prompt' => self::GENEALOGIST_SYSTEM_PROMPT,
                'factual_mode' => true, // Enforces temp=0.1 + anti-hallucination for genealogy accuracy
                'model_role' => 'quality', // N120: Use quality-tier model for genealogy research accuracy
                'sensitive_data' => true,
                'data_class' => 'genealogy_person_research',
            ]);

            if (! $result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'AI processing failed',
                    'person_id' => $person->id,
                ];
            }

            return [
                'success' => true,
                'person_id' => $person->id,
                'person_name' => trim($person->given_name.' '.$person->surname),
                'focus' => $focus,
                'research_suggestions' => $result['response'],
                'provider' => $result['provider'] ?? 'unknown',
                'duration_ms' => $result['duration_ms'] ?? 0,
                'cached' => false,
            ];
        });
    }

    /**
     * Suggest research strategies for breaking through a brick wall
     *
     * @param  int  $personId  The genealogy person ID
     * @return array Brick wall breaking strategies
     */
    public function suggestResearchForBrickWall(int $personId): array
    {
        $person = $this->getPersonWithContext($personId);

        if (! $person) {
            return [
                'success' => false,
                'error' => 'Person not found',
            ];
        }

        // Identify what's missing
        $gaps = $this->identifyResearchGaps($person);
        $context = $this->buildPersonContext($person, true, 'brick_wall');

        $prompt = <<<PROMPT
Brick wall research strategy needed.

Person: {$context}

Gaps: {$gaps}

Provide CONCISE, bullet-point response:
1. **Alternative Records** (3-5 non-obvious record types)
2. **Name Variations** (list only)
3. **FAN Cluster** (specific people/records to search)
4. **Priority Actions** (5 steps max, most likely to succeed first)

Skip general advice. Only specific strategies for this person's era/location.
PROMPT;

        $cacheKey = "genealogy_ai_brickwall:{$personId}:".md5($gaps.'|'.$context);

        return Cache::remember($cacheKey, 86400, function () use ($prompt, $person) {
            Log::info('GenealogyAIResearchService: Brick wall analysis', [
                'person_id' => $person->id,
                'name' => $person->given_name.' '.$person->surname,
            ]);

            $result = $this->aiService->process($prompt, [
                'system_prompt' => self::GENEALOGIST_SYSTEM_PROMPT,
                'factual_mode' => true, // Even brick wall strategies need factual grounding
                'model_role' => 'quality', // N120: quality-tier for genealogy accuracy
                'sensitive_data' => true,
                'data_class' => 'genealogy_brick_wall',
            ]);

            if (! $result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'AI processing failed',
                    'person_id' => $person->id,
                ];
            }

            return [
                'success' => true,
                'person_id' => $person->id,
                'person_name' => trim($person->given_name.' '.$person->surname),
                'brick_wall_strategies' => $result['response'],
                'research_gaps' => $this->parseResearchGaps($person),
                'provider' => $result['provider'] ?? 'unknown',
                'duration_ms' => $result['duration_ms'] ?? 0,
            ];
        });
    }

    /**
     * Evaluate a source for genealogical quality
     *
     * @param  string  $sourceDescription  Description of the source to evaluate
     * @param  array  $options  Optional: person_id for context
     * @return array Source evaluation with GPS compliance rating
     */
    public function evaluateSource(string $sourceDescription, array $options = []): array
    {
        $personContext = '';
        if (! empty($options['person_id'])) {
            $person = $this->getPersonWithContext($options['person_id']);
            if ($person) {
                $lessonContext = $this->lessonContext()->build(
                    ['tree_id' => (int) ($person->tree_id ?? 0)],
                    $this->lessonSearchTerms($person, 'source_evaluation'),
                    4,
                    '## Reusable Genea Lessons',
                    [
                        'title_limit' => 0,
                        'lesson_limit' => 320,
                        'fallback_limit' => 3,
                    ]
                );
                $personContext = "\n\n## Person Being Researched\nName: {$person->given_name} {$person->surname}\nBirth: {$person->birth_date} at {$person->birth_place}\nDeath: {$person->death_date} at {$person->death_place}{$lessonContext}";
            }
        }

        $prompt = <<<PROMPT
Please evaluate the following genealogical source using the Genealogical Proof Standard criteria:

## Source Description
{$sourceDescription}
{$personContext}

## Evaluation Criteria
Please assess this source on:

1. **Source Classification**
   - Is this an original or derivative source?
   - Is this a primary or secondary source?
   - Is the information direct or indirect evidence?

2. **Reliability Assessment**
   - How close was the informant to the event?
   - What biases might affect the information?
   - Are there internal consistency issues?

3. **Citation Quality**
   - Provide a proper Evidence Explained-style citation for this source
   - What elements are missing that would improve the citation?

4. **Correlation Value**
   - How well does this correlate with other sources?
   - What would strengthen or weaken this evidence?

5. **GPS Compliance Score** (1-10)
   - Rate how well this source contributes to meeting the Genealogical Proof Standard
   - Explain the rating

6. **Recommendations**
   - What additional sources should be sought to corroborate this?
   - How should this source be used in a proof argument?
PROMPT;

        $cacheKey = 'genealogy_ai_source_eval:'.md5($sourceDescription.json_encode($options));

        return Cache::remember($cacheKey, 86400, function () use ($prompt, $sourceDescription) {
            Log::info('GenealogyAIResearchService: Evaluating source');

            $result = $this->aiService->process($prompt, [
                'system_prompt' => self::GENEALOGIST_SYSTEM_PROMPT,
                'factual_mode' => true, // Source evaluation requires strict accuracy
                'model_role' => 'quality', // N120: quality-tier for genealogy accuracy
                'sensitive_data' => true,
                'data_class' => 'genealogy_source_evaluation',
            ]);

            if (! $result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'AI processing failed',
                ];
            }

            return [
                'success' => true,
                'source_description' => $sourceDescription,
                'evaluation' => $result['response'],
                'provider' => $result['provider'] ?? 'unknown',
                'duration_ms' => $result['duration_ms'] ?? 0,
            ];
        });
    }

    /**
     * Analyze the relationship between two persons
     *
     * @param  int  $person1Id  First person ID
     * @param  int  $person2Id  Second person ID
     * @return array Relationship analysis
     */
    public function analyzeRelationship(int $person1Id, int $person2Id): array
    {
        $person1 = $this->getPersonWithContext($person1Id);
        $person2 = $this->getPersonWithContext($person2Id);

        if (! $person1 || ! $person2) {
            return [
                'success' => false,
                'error' => 'One or both persons not found',
            ];
        }

        $context1 = $this->buildPersonContext($person1, false, 'relationship');
        $context2 = $this->buildPersonContext($person2, false, 'relationship');

        // Get known relationship path if any
        $relationshipPath = $this->findRelationshipPath($person1Id, $person2Id);

        $prompt = <<<PROMPT
Analyze the potential genealogical relationship between these two individuals:

## Person 1
{$context1}

## Person 2
{$context2}

## Known Relationship Data
{$relationshipPath}

## Analysis Requested
Please provide:

1. **Relationship Assessment**
   - What is the most likely relationship between these individuals?
   - What evidence supports this relationship?
   - Are there any conflicting indicators?

2. **Common Ancestor Analysis**
   - If related, who is/are the common ancestor(s)?
   - What is the degree of relationship (e.g., 3rd cousins once removed)?

3. **Research Suggestions**
   - What records would help confirm or refute this relationship?
   - Are there FAN cluster connections to explore?

4. **DNA Predictions** (if applicable)
   - If DNA tested, what cM range would be expected?
   - What relationship predictions might DNA companies show?
PROMPT;

        $cacheKey = "genealogy_ai_relationship:{$person1Id}:{$person2Id}";

        return Cache::remember($cacheKey, 86400, function () use ($prompt, $person1, $person2) {
            Log::info('GenealogyAIResearchService: Analyzing relationship', [
                'person1' => $person1->given_name.' '.$person1->surname,
                'person2' => $person2->given_name.' '.$person2->surname,
            ]);

            $result = $this->aiService->process($prompt, [
                'system_prompt' => self::GENEALOGIST_SYSTEM_PROMPT,
                'factual_mode' => true, // Relationship analysis requires accuracy
                'model_role' => 'quality', // N120: quality-tier for genealogy accuracy
                'sensitive_data' => true,
                'data_class' => 'genealogy_relationship_analysis',
            ]);

            if (! $result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'AI processing failed',
                ];
            }

            return [
                'success' => true,
                'person1' => [
                    'id' => $person1->id,
                    'name' => trim($person1->given_name.' '.$person1->surname),
                ],
                'person2' => [
                    'id' => $person2->id,
                    'name' => trim($person2->given_name.' '.$person2->surname),
                ],
                'analysis' => $result['response'],
                'provider' => $result['provider'] ?? 'unknown',
                'duration_ms' => $result['duration_ms'] ?? 0,
            ];
        });
    }

    /**
     * Clear cached AI research results for a person
     */
    public function clearCache(int $personId): void
    {
        // Use Redis SCAN to find matching keys (Cache::forget doesn't support wildcards)
        $cachePrefix = config('cache.prefix', config('database.redis.options.prefix', ''));
        $dbPrefix = config('database.redis.options.prefix', '');
        $patterns = [
            "genealogy_ai_research:{$personId}:general:*",
            "genealogy_ai_research:{$personId}:ancestry:*",
            "genealogy_ai_research:{$personId}:descendants:*",
            "genealogy_ai_research:{$personId}:siblings:*",
            "genealogy_ai_brickwall:{$personId}:*",
        ];

        $redis = \Illuminate\Support\Facades\Redis::connection(config('cache.stores.redis.connection', 'cache'));
        foreach ($patterns as $pattern) {
            $keys = $redis->keys($cachePrefix.$pattern);
            foreach ($keys as $key) {
                $cleanKey = str_starts_with($key, $dbPrefix) ? substr($key, strlen($dbPrefix)) : $key;
                $redis->del($cleanKey);
            }
        }
    }

    /**
     * Get person with extended family context
     */
    private function getPersonWithContext(int $personId): ?object
    {
        return DB::selectOne("
            SELECT
                p.*,
                -- Get father info
                (SELECT CONCAT(given_name, ' ', surname) FROM genealogy_persons WHERE id = (
                    SELECT f.husband_id FROM genealogy_families f
                    INNER JOIN genealogy_children c ON c.family_id = f.id
                    WHERE c.person_id = p.id LIMIT 1
                )) as father_name,
                (SELECT id FROM genealogy_persons WHERE id = (
                    SELECT f.husband_id FROM genealogy_families f
                    INNER JOIN genealogy_children c ON c.family_id = f.id
                    WHERE c.person_id = p.id LIMIT 1
                )) as father_id,
                -- Get mother info
                (SELECT CONCAT(given_name, ' ', surname) FROM genealogy_persons WHERE id = (
                    SELECT f.wife_id FROM genealogy_families f
                    INNER JOIN genealogy_children c ON c.family_id = f.id
                    WHERE c.person_id = p.id LIMIT 1
                )) as mother_name,
                (SELECT id FROM genealogy_persons WHERE id = (
                    SELECT f.wife_id FROM genealogy_families f
                    INNER JOIN genealogy_children c ON c.family_id = f.id
                    WHERE c.person_id = p.id LIMIT 1
                )) as mother_id
            FROM genealogy_persons p
            WHERE p.id = ?
        ", [$personId]);
    }

    /**
     * Build comprehensive person context for AI prompt
     */
    private function buildPersonContext(object $person, bool $includeSources = true, ?string $focus = null): string
    {
        $context = "## Basic Information\n";
        $context .= "- **Name:** {$person->given_name} {$person->surname}\n";
        $context .= '- **Sex:** '.($person->sex === 'M' ? 'Male' : ($person->sex === 'F' ? 'Female' : 'Unknown'))."\n";

        if ($person->birth_date) {
            $context .= "- **Birth:** {$person->birth_date}";
            if ($person->birth_place) {
                $context .= " at {$person->birth_place}";
            }
            $context .= "\n";
        } else {
            $context .= "- **Birth:** Unknown\n";
        }

        if ($person->death_date) {
            $context .= "- **Death:** {$person->death_date}";
            if ($person->death_place) {
                $context .= " at {$person->death_place}";
            }
            $context .= "\n";
        } elseif (! $this->isLiving($person)) {
            $context .= "- **Death:** Unknown (presumed deceased)\n";
        }

        // Parents
        $context .= "\n## Parents\n";
        if ($person->father_name) {
            $context .= "- **Father:** {$person->father_name}\n";
        } else {
            $context .= "- **Father:** Unknown\n";
        }
        if ($person->mother_name) {
            $context .= "- **Mother:** {$person->mother_name}\n";
        } else {
            $context .= "- **Mother:** Unknown\n";
        }

        // Spouses
        $spouses = $this->getSpouses($person->id);
        if (! empty($spouses)) {
            $context .= "\n## Spouses\n";
            foreach ($spouses as $spouse) {
                $context .= "- {$spouse->spouse_name}";
                if ($spouse->marriage_date) {
                    $context .= " (married {$spouse->marriage_date}";
                    if ($spouse->marriage_place) {
                        $context .= " at {$spouse->marriage_place}";
                    }
                    $context .= ')';
                }
                $context .= "\n";
            }
        }

        // Children
        $children = $this->getChildren($person->id);
        if (! empty($children)) {
            $context .= "\n## Children\n";
            foreach ($children as $child) {
                $context .= "- {$child->given_name} {$child->surname}";
                if ($child->birth_date) {
                    $context .= " (b. {$child->birth_date})";
                }
                $context .= "\n";
            }
        }

        // Sources
        if ($includeSources) {
            $sources = $this->getPersonSources($person->id);
            if (! empty($sources)) {
                $context .= "\n## Attached Sources\n";
                foreach ($sources as $source) {
                    $context .= "- {$source->title}";
                    if ($source->author) {
                        $context .= " by {$source->author}";
                    }
                    $context .= "\n";
                }
            }
        }

        $context .= $this->lessonContext()->build(
            ['tree_id' => (int) ($person->tree_id ?? 0)],
            $this->lessonSearchTerms($person, $focus),
            6,
            '## Reusable Genea Lessons',
            [
                'title_limit' => 0,
                'lesson_limit' => 320,
                'fallback_limit' => 3,
            ]
        );

        return $context;
    }

    /**
     * @return list<string>
     */
    private function lessonSearchTerms(object $person, ?string $focus): array
    {
        $terms = [
            $focus,
            $person->given_name ?? null,
            $person->surname ?? null,
            trim((string) ($person->given_name ?? '').' '.(string) ($person->surname ?? '')),
            $person->birth_place ?? null,
            $person->death_place ?? null,
        ];

        foreach ([$person->birth_place ?? null, $person->death_place ?? null] as $place) {
            foreach (explode(',', (string) $place) as $part) {
                $terms[] = trim($part);
            }
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $term): string => trim((string) $term), $terms),
            static fn (string $term): bool => $term !== '' && mb_strlen($term) >= 3
        )));
    }

    /**
     * Build research prompt based on focus area
     */
    private function buildResearchPrompt(object $person, string $context, string $focus, bool $isBrickWall): string
    {
        $focusInstructions = match ($focus) {
            'ancestry' => 'Focus on researching the ancestry/parents of this person. Who were their parents, grandparents, and earlier generations?',
            'descendants' => 'Focus on researching descendants of this person. Who were their children, grandchildren, and later generations?',
            'siblings' => 'Focus on finding siblings of this person. Were there brothers and sisters we might have missed?',
            default => 'Provide a general research plan for expanding knowledge about this person and their family.',
        };

        $brickWallNote = $isBrickWall
            ? "\n\n**NOTE: This is a brick wall ancestor.** The researcher has been stuck and needs creative solutions to break through."
            : '';

        return <<<PROMPT
Analyze this person and provide CONCISE research suggestions.

{$context}

Focus: {$focusInstructions}{$brickWallNote}

Be brief - bullet points only. Provide:
1. **Priority Records** (3-5 specific record types to search NOW)
2. **Name Variations** (list format, no explanations)
3. **Key Locations** (specific archives/repositories)
4. **Next Actions** (3 immediate steps, prioritized)

Skip general advice. Only actionable specifics for THIS person's time/place.
PROMPT;
    }

    /**
     * Identify research gaps for a person
     */
    private function identifyResearchGaps(object $person): string
    {
        $gaps = [];

        if (! $person->birth_date) {
            $gaps[] = '- Birth date is unknown';
        } elseif (str_starts_with($person->birth_date, 'ABT') || str_starts_with($person->birth_date, 'BEF') || str_starts_with($person->birth_date, 'AFT')) {
            $gaps[] = "- Birth date is approximate ({$person->birth_date})";
        }

        if (! $person->birth_place) {
            $gaps[] = '- Birth place is unknown';
        }

        if (! $person->death_date && ! $this->isLiving($person)) {
            $gaps[] = '- Death date is unknown (person is presumably deceased)';
        }

        if (! $person->death_place && $person->death_date) {
            $gaps[] = '- Death place is unknown';
        }

        if (! $person->father_id) {
            $gaps[] = '- Father is unknown';
        }

        if (! $person->mother_id) {
            $gaps[] = '- Mother is unknown';
        }

        $sources = $this->getPersonSources($person->id);
        if (empty($sources)) {
            $gaps[] = '- No sources are attached to this person';
        } elseif (count($sources) < 3) {
            $gaps[] = '- Only '.count($sources).' source(s) attached (consider adding more)';
        }

        if (empty($gaps)) {
            return "No obvious research gaps identified. Consider reviewing for:\n- Missing siblings\n- Incomplete spouse information\n- Unverified sources";
        }

        return implode("\n", $gaps);
    }

    /**
     * Parse research gaps into structured array
     */
    private function parseResearchGaps(object $person): array
    {
        $gaps = [];

        if (! $person->birth_date) {
            $gaps['birth_date'] = 'unknown';
        } elseif (str_starts_with($person->birth_date, 'ABT') || str_starts_with($person->birth_date, 'BEF') || str_starts_with($person->birth_date, 'AFT')) {
            $gaps['birth_date'] = 'approximate';
        }

        if (! $person->birth_place) {
            $gaps['birth_place'] = 'unknown';
        }

        if (! $person->death_date && ! $this->isLiving($person)) {
            $gaps['death_date'] = 'unknown';
        }

        if (! $person->father_id) {
            $gaps['father'] = 'unknown';
        }

        if (! $person->mother_id) {
            $gaps['mother'] = 'unknown';
        }

        return $gaps;
    }

    /**
     * Check if person is considered living
     */
    private function isLiving(object $person): bool
    {
        // Has death date = not living
        if ($person->death_date) {
            return false;
        }

        // No birth date = assume not living (historical)
        if (! $person->birth_date) {
            return false;
        }

        // Extract year from GEDCOM date
        if (preg_match('/(\d{4})/', $person->birth_date, $matches)) {
            $birthYear = (int) $matches[1];
            $currentYear = (int) date('Y');

            // Over 105 years old without death date = presumed deceased
            return ($currentYear - $birthYear) < 105;
        }

        return false;
    }

    /**
     * Get spouses for a person
     */
    private function getSpouses(int $personId): array
    {
        return DB::select("
            SELECT
                CASE
                    WHEN f.husband_id = ? THEN CONCAT(w.given_name, ' ', w.surname)
                    ELSE CONCAT(h.given_name, ' ', h.surname)
                END as spouse_name,
                CASE
                    WHEN f.husband_id = ? THEN w.id
                    ELSE h.id
                END as spouse_id,
                f.marriage_date,
                f.marriage_place,
                f.divorce_date
            FROM genealogy_families f
            LEFT JOIN genealogy_persons h ON f.husband_id = h.id
            LEFT JOIN genealogy_persons w ON f.wife_id = w.id
            WHERE f.husband_id = ? OR f.wife_id = ?
            ORDER BY f.marriage_date
        ", [$personId, $personId, $personId, $personId]);
    }

    /**
     * Get children for a person
     */
    private function getChildren(int $personId): array
    {
        return DB::select('
            SELECT c.given_name, c.surname, c.birth_date, c.id
            FROM genealogy_persons c
            INNER JOIN genealogy_children ch ON ch.person_id = c.id
            INNER JOIN genealogy_families f ON f.id = ch.family_id
            WHERE f.husband_id = ? OR f.wife_id = ?
            ORDER BY c.birth_date, c.given_name
        ', [$personId, $personId]);
    }

    /**
     * Get sources attached to a person
     */
    private function getPersonSources(int $personId): array
    {
        return DB::select('
            SELECT s.id, s.title, s.author, s.publication
            FROM genealogy_sources s
            INNER JOIN genealogy_person_sources ps ON ps.source_id = s.id
            WHERE ps.person_id = ?
            ORDER BY s.title
        ', [$personId]);
    }

    /**
     * Find relationship path between two persons
     */
    private function findRelationshipPath(int $person1Id, int $person2Id): string
    {
        // Check direct parent-child relationship
        $direct = DB::selectOne("
            SELECT 'child' as relationship
            FROM genealogy_children ch
            INNER JOIN genealogy_families f ON f.id = ch.family_id
            WHERE ch.person_id = ? AND (f.husband_id = ? OR f.wife_id = ?)
            UNION
            SELECT 'parent' as relationship
            FROM genealogy_children ch
            INNER JOIN genealogy_families f ON f.id = ch.family_id
            WHERE ch.person_id = ? AND (f.husband_id = ? OR f.wife_id = ?)
        ", [$person1Id, $person2Id, $person2Id, $person2Id, $person1Id, $person1Id]);

        if ($direct) {
            return "Direct relationship found: Person 1 is the {$direct->relationship} of Person 2.";
        }

        // Check spouse relationship
        $spouse = DB::selectOne("
            SELECT 'spouse' as relationship, f.marriage_date
            FROM genealogy_families f
            WHERE (f.husband_id = ? AND f.wife_id = ?)
               OR (f.husband_id = ? AND f.wife_id = ?)
        ", [$person1Id, $person2Id, $person2Id, $person1Id]);

        if ($spouse) {
            $marriageInfo = $spouse->marriage_date ? " (married {$spouse->marriage_date})" : '';

            return "These individuals are spouses{$marriageInfo}.";
        }

        // Check sibling relationship
        $sibling = DB::selectOne("
            SELECT 'sibling' as relationship
            FROM genealogy_children c1
            INNER JOIN genealogy_children c2 ON c1.family_id = c2.family_id
            WHERE c1.person_id = ? AND c2.person_id = ?
        ", [$person1Id, $person2Id]);

        if ($sibling) {
            return 'These individuals are siblings (share at least one parent).';
        }

        return "No direct relationship path found in the current data. They may be more distantly related or the connection hasn't been documented yet.";
    }

    /**
     * Extract structured data from research results that can be applied to person fields
     *
     * Uses AI to parse research text and extract actionable data items mapped to GEDCOM fields
     *
     * @param  int  $personId  The person being researched
     * @param  string  $researchText  The raw research text to parse
     * @return array Structured extraction results
     */
    public function extractStructuredData(int $personId, string $researchText): array
    {
        $person = $this->getPersonWithContext($personId);

        if (! $person) {
            return [
                'success' => false,
                'error' => 'Person not found',
            ];
        }

        $currentData = $this->formatCurrentPersonData($person);

        $prompt = <<<PROMPT
Analyze the following genealogy research results and extract any specific, actionable data that could be added to this person's record.

## Current Person Record
{$currentData}

## Research Text to Analyze
{$researchText}

## Instructions
Extract ONLY concrete, specific data mentioned in the research that is NOT already in the current record.
Return a JSON array of items to potentially add. Each item should have:
- field: The GEDCOM field name (see valid fields below)
- value: The specific value to add
- source: Where in the research this came from (quote or describe)
- confidence: "high", "medium", or "low" based on how certain the research is
- action: "update" (change existing), "add" (new data), or "note" (add to notes)

## Valid GEDCOM Fields
- birth_date (format: DD MMM YYYY, e.g., "15 MAR 1886")
- birth_place (location string)
- death_date (format: DD MMM YYYY)
- death_place (location string)
- burial_date (format: DD MMM YYYY)
- burial_place (location string)
- occupation (job/profession)
- education (schools attended)
- religion (religious affiliation)
- nationality (country of origin/citizenship)
- cause_of_death (medical cause)
- physical_description (height, hair, eyes, etc.)
- title (Mr., Mrs., Dr., Rev., etc.)
- notes (general research notes - use for anything that doesn't fit other fields)

## Important Rules
1. Only extract data that appears to be factual, not suggestions or hypotheses
2. Date formats must be "DD MMM YYYY" (e.g., "15 MAR 1886", "ABT 1900", "BEF 1920")
3. If data is uncertain, set confidence to "low" and action to "note"
4. Do NOT extract data that's already in the current record
5. For research strategies, tips, or suggestions - these go to "notes" field
6. Return empty array [] if no extractable data found

Return ONLY valid JSON array, no markdown or explanation.
PROMPT;

        try {
            $result = $this->aiService->process($prompt, [
                'factual_mode' => true, // Anti-hallucination mode for precise extraction
                'model_role' => 'quality', // N120: quality-tier for genealogy accuracy
                'sensitive_data' => true,
                'data_class' => 'genealogy_proposal_extraction',
            ]);

            if (! $result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'AI processing failed',
                ];
            }

            // Parse the JSON response
            $response = trim($result['response'] ?? '');

            // Clean up response - remove markdown code blocks if present
            $response = preg_replace('/^```json\s*/i', '', $response);
            $response = preg_replace('/^```\s*/i', '', $response);
            $response = preg_replace('/\s*```$/i', '', $response);

            $extractedItems = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('GenealogyAIResearchService: Failed to parse extraction JSON', [
                    'error' => json_last_error_msg(),
                    'response' => substr($response, 0, 500),
                ]);

                return [
                    'success' => false,
                    'error' => 'Failed to parse AI response as JSON',
                    'raw_response' => $response,
                ];
            }

            // Validate and clean the extracted items
            $validItems = $this->validateExtractedItems($extractedItems, $person);

            return [
                'success' => true,
                'person_id' => $personId,
                'person_name' => trim($person->given_name.' '.$person->surname),
                'extracted_items' => $validItems,
                'item_count' => count($validItems),
                'provider' => $result['provider'] ?? 'unknown',
            ];

        } catch (\Exception $e) {
            Log::error('GenealogyAIResearchService: Extraction failed', [
                'person_id' => $personId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Extraction failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Format current person data for AI context
     */
    private function formatCurrentPersonData($person): string
    {
        $lines = [];
        $lines[] = "Name: {$person->given_name} {$person->surname}";

        if (! empty($person->birth_date)) {
            $lines[] = "Birth Date: {$person->birth_date}";
        }
        if (! empty($person->birth_place)) {
            $lines[] = "Birth Place: {$person->birth_place}";
        }
        if (! empty($person->death_date)) {
            $lines[] = "Death Date: {$person->death_date}";
        }
        if (! empty($person->death_place)) {
            $lines[] = "Death Place: {$person->death_place}";
        }
        if (! empty($person->burial_date)) {
            $lines[] = "Burial Date: {$person->burial_date}";
        }
        if (! empty($person->burial_place)) {
            $lines[] = "Burial Place: {$person->burial_place}";
        }
        if (! empty($person->occupation)) {
            $lines[] = "Occupation: {$person->occupation}";
        }
        if (! empty($person->education)) {
            $lines[] = "Education: {$person->education}";
        }
        if (! empty($person->religion)) {
            $lines[] = "Religion: {$person->religion}";
        }
        if (! empty($person->nationality)) {
            $lines[] = "Nationality: {$person->nationality}";
        }

        return implode("\n", $lines);
    }

    /**
     * Validate and clean extracted items
     */
    private function validateExtractedItems(array $items, $person): array
    {
        $validFields = [
            'birth_date', 'birth_place', 'death_date', 'death_place',
            'burial_date', 'burial_place', 'occupation', 'education',
            'religion', 'nationality', 'cause_of_death', 'physical_description',
            'title', 'notes',
        ];

        $validActions = ['update', 'add', 'note'];
        $validConfidences = ['high', 'medium', 'low'];

        $validated = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $field = $item['field'] ?? null;
            $value = $item['value'] ?? null;

            // Skip invalid items
            if (! $field || ! $value || ! in_array($field, $validFields)) {
                continue;
            }

            // Skip if value is same as current
            $currentValue = $person->$field ?? null;
            if ($currentValue && strtolower(trim($currentValue)) === strtolower(trim($value))) {
                continue;
            }

            $validated[] = [
                'field' => $field,
                'field_label' => $this->getFieldLabel($field),
                'value' => trim($value),
                'current_value' => $currentValue,
                'source' => $item['source'] ?? 'Research results',
                'confidence' => in_array($item['confidence'] ?? '', $validConfidences) ? $item['confidence'] : 'medium',
                'action' => in_array($item['action'] ?? '', $validActions) ? $item['action'] : 'add',
            ];
        }

        return $validated;
    }

    /**
     * Get human-readable field label
     */
    private function getFieldLabel(string $field): string
    {
        $labels = [
            'birth_date' => 'Birth Date',
            'birth_place' => 'Birth Place',
            'death_date' => 'Death Date',
            'death_place' => 'Death Place',
            'burial_date' => 'Burial Date',
            'burial_place' => 'Burial Place',
            'occupation' => 'Occupation',
            'education' => 'Education',
            'religion' => 'Religion',
            'nationality' => 'Nationality',
            'cause_of_death' => 'Cause of Death',
            'physical_description' => 'Physical Description',
            'title' => 'Title',
            'notes' => 'Research Notes',
        ];

        return $labels[$field] ?? ucwords(str_replace('_', ' ', $field));
    }

    /**
     * Apply selected extracted items to a person record
     *
     * @param  array  $itemsToApply  Array of items with field and value
     * @return array Result of the update
     */
    public function applyExtractedData(int $personId, array $itemsToApply): array
    {
        $person = DB::selectOne('SELECT * FROM genealogy_persons WHERE id = ?', [$personId]);

        if (! $person) {
            return [
                'success' => false,
                'error' => 'Person not found',
            ];
        }

        $updates = [];
        $notesAdditions = [];

        foreach ($itemsToApply as $item) {
            $field = $item['field'] ?? null;
            $value = $item['value'] ?? null;

            if (! $field || ! $value) {
                continue;
            }

            if ($field === 'notes') {
                $notesAdditions[] = $value;
            } else {
                $updates[$field] = $value;
            }
        }

        // Apply field updates
        if (! empty($updates)) {
            $fields = [];
            $params = [];

            foreach ($updates as $field => $value) {
                $fields[] = "{$field} = ?";
                $params[] = $value;
            }

            $fields[] = 'updated_at = NOW()';
            $params[] = $personId;

            $sql = 'UPDATE genealogy_persons SET '.implode(', ', $fields).' WHERE id = ?';
            DB::update($sql, $params);
        }

        // Append notes if any
        if (! empty($notesAdditions)) {
            $today = date('Y-m-d');
            $noteText = "\n\n---\n## AI Research Findings ({$today})\n\n".implode("\n\n", $notesAdditions);

            $existingNotes = $person->notes ?? '';
            $newNotes = $existingNotes.$noteText;

            DB::update('UPDATE genealogy_persons SET notes = ?, updated_at = NOW() WHERE id = ?', [$newNotes, $personId]);
        }

        $appliedCount = count($updates) + count($notesAdditions);

        Log::info('GenealogyAIResearchService: Applied extracted data', [
            'person_id' => $personId,
            'fields_updated' => array_keys($updates),
            'notes_added' => count($notesAdditions),
        ]);

        return [
            'success' => true,
            'person_id' => $personId,
            'applied_count' => $appliedCount,
            'fields_updated' => array_keys($updates),
            'notes_added' => count($notesAdditions),
        ];
    }
}
