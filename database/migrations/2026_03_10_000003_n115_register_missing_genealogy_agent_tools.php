<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N115 — Register Missing Genealogy Agent Tools (CRITICAL #3 Fix)
 *
 * ~34 tools listed in the genealogy-researcher SKILL.md were absent from
 * agent_tool_registry, causing the framework to silently skip them every run.
 * This migration registers all missing tools so they actually execute.
 *
 * Categories fixed:
 *  - Core tree data      : list_persons, get_person, get_person_full, get_person_events,
 *                          get_person_sources, search_persons, get_siblings
 *  - Stats & gaps        : get_tree_statistics, get_missing_data_report
 *  - Record hints        : get_research_hints, update_hint_status,
 *                          generate_record_hints, generate_tree_hints
 *  - Research tasks      : create_research_task, get_open_research_tasks,
 *                          log_research_search, assess_gps_compliance
 *  - Place authority     : resolve_place, search_places
 *  - Search coverage     : get_search_coverage, update_search_coverage
 *  - Source conflicts    : detect_source_conflicts, get_source_conflicts
 *  - GPS proof           : generate_gps_proof
 *  - FAN methodology     : fan_extract_cooccurrences, fan_get_cooccurrences
 *  - Graph dedup         : find_graph_duplicates
 *  - HTR transcription   : htr_status, transcribe_handwriting, transcribe_media_handwriting
     *  - RAG                 : rag_search, rag_index
 *  - Repository routing  : get_repositories_for_person
 */
return new class extends Migration
{
    public function up(): void
    {
        $upsert = function (
            string $name,
            string $description,
            string $service,
            string $method,
            array  $parameters,
            string $returns,
            string $permissions = 'genealogy:read',
            int    $maxCalls    = 6,
            int    $maxTokens   = 3000
        ) {
            DB::statement("
                INSERT INTO agent_tool_registry
                    (name, description, service_class, method, parameters, returns_description,
                     permissions, risk_level, category, enabled, max_calls_per_run, max_tokens_per_call)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    description         = VALUES(description),
                    service_class       = VALUES(service_class),
                    method              = VALUES(method),
                    parameters          = VALUES(parameters),
                    returns_description = VALUES(returns_description),
                    enabled             = VALUES(enabled)
            ", [
                $name, $description, $service, $method,
                json_encode($parameters), $returns,
                json_encode([$permissions]), 'read', 'genealogy',
                1, $maxCalls, $maxTokens,
            ]);
        };

        $write = function (
            string $name,
            string $description,
            string $service,
            string $method,
            array  $parameters,
            string $returns,
            int    $maxCalls  = 6,
            int    $maxTokens = 3000
        ) use ($upsert) {
            DB::statement("
                INSERT INTO agent_tool_registry
                    (name, description, service_class, method, parameters, returns_description,
                     permissions, risk_level, category, enabled, max_calls_per_run, max_tokens_per_call)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    description         = VALUES(description),
                    service_class       = VALUES(service_class),
                    method              = VALUES(method),
                    parameters          = VALUES(parameters),
                    returns_description = VALUES(returns_description),
                    enabled             = VALUES(enabled)
            ", [
                $name, $description, $service, $method,
                json_encode($parameters), $returns,
                json_encode(['genealogy:write']), 'write', 'genealogy',
                1, $maxCalls, $maxTokens,
            ]);
        };

        $geo  = 'App\\Services\\Genealogy\\GenealogyService';
        $pSvc = 'App\\Services\\Genealogy\\PersonService';
        $rst  = 'App\\Services\\Genealogy\\ResearchTaskService';
        $rhs  = 'App\\Services\\Genealogy\\RecordHintService';
        $pa   = 'App\\Services\\Genealogy\\PlaceAuthorityService';
        $scs  = 'App\\Services\\Genealogy\\SearchCoverageService';
        $scs2 = 'App\\Services\\Genealogy\\SourceConflictService';
        $gps  = 'App\\Services\\Genealogy\\GPSProofGeneratorService';
        $fan  = 'App\\Services\\Genealogy\\FANCooccurrenceService';
        $gdd  = 'App\\Services\\Genealogy\\GraphDeduplicationService';
        $htr  = 'App\\Services\\Genealogy\\HtrTranscriptionService';
        $rag  = 'App\\Services\\RAGService';
        $rrs  = 'App\\Services\\Genealogy\\RepositoryRoutingService';

        // ── Core tree data ─────────────────────────────────────────────────

        $upsert('list_persons',
            'List all persons in a genealogy tree. Returns basic profile data for each person: id, given_name, surname, birth_year, death_year, sex. Use this to discover who is in the tree before selecting research targets.',
            $geo, 'listPersons',
            [
                'tree_id' => ['type' => 'integer', 'required' => true,  'description' => 'Tree ID to list persons for'],
                'limit'   => ['type' => 'integer', 'required' => false, 'description' => 'Max persons to return (default 5000)'],
            ],
            'Array of person objects: id, given_name, surname, full_name, birth_year, death_year, sex.',
            'genealogy:read', 4, 8000
        );

        $upsert('get_person',
            'Get a single genealogy person by ID. Returns full profile: names, birth/death dates and places, sex, notes, and family links. Use to verify a specific person before researching.',
            $geo, 'getPerson',
            [
                'person_id' => ['type' => 'integer', 'required' => true, 'description' => 'Person ID to retrieve'],
            ],
            'Person object with all fields or null if not found.',
            'genealogy:read', 12, 4000
        );

        $upsert('get_person_full',
            'Get FULL person data including name variants, all events, sources, manual external IDs, and per-repository search coverage (negative evidence map). Use INSTEAD of get_person when beginning a new research session — it shows what has already been searched and avoids repeating negative searches.',
            $geo, 'getPersonFull',
            [
                'person_id' => ['type' => 'integer', 'required' => true, 'description' => 'Person ID to retrieve full data for'],
            ],
            'Extended person object including name_variants, external_ids, events, sources, search_coverage.',
            'genealogy:read', 10, 6000
        );

        $upsert('get_person_events',
            'Get all life events for a person: birth, death, marriage, census, military, immigration, etc. Each event has date, place, source citation, and confidence. Use to see what is already documented before searching for more.',
            $geo, 'getPersonEvents',
            [
                'person_id' => ['type' => 'integer', 'required' => true, 'description' => 'Person ID'],
            ],
            'Array of event objects: id, event_type, date, place, source, confidence.',
            'genealogy:read', 12, 4000
        );

        $upsert('get_person_sources',
            'Get all source citations linked to a person. Returns each source: title, author, publication, URL, repository, quality level, and page reference. Use before searching to avoid re-citing sources already in the tree.',
            $geo, 'getPersonSources',
            [
                'person_id' => ['type' => 'integer', 'required' => true, 'description' => 'Person ID'],
            ],
            'Array of source citation objects: id, title, author, url, repository, quality, page.',
            'genealogy:read', 10, 4000
        );

        $upsert('search_persons',
            'Search for persons within a tree by name (given name, surname, or both). Returns ranked matches with basic profile data. Use to find a specific person when you know part of their name but not their ID.',
            $geo, 'searchPersons',
            [
                'tree_id' => ['type' => 'integer', 'required' => true,  'description' => 'Tree ID to search within'],
                'query'   => ['type' => 'string',  'required' => true,  'description' => 'Name query: given name, surname, or full name'],
                'limit'   => ['type' => 'integer', 'required' => false, 'description' => 'Max results (default 50)'],
            ],
            'Array of matching persons with id, full_name, birth_year, death_year, match_score.',
            'genealogy:read', 10, 4000
        );

        $upsert('get_siblings',
            'Get all known siblings of a person — others who share at least one parent family. Returns their names, birth/death dates, and parent family ID. Essential for family completeness verification and FAN cluster building.',
            $geo, 'getPersonSiblings',
            [
                'person_id' => ['type' => 'integer', 'required' => true, 'description' => 'Person ID to get siblings for'],
            ],
            'Array of sibling person objects: id, full_name, birth_year, death_year, sex, family_id.',
            'genealogy:read', 8, 3000
        );

        // ── Stats & gaps ───────────────────────────────────────────────────

        $upsert('get_tree_statistics',
            'Get overall completeness statistics for a family tree: total persons, percentage with birth/death dates and places, percentage with sources, average generation coverage, and counts by sex. Use in the assess phase to understand research gaps at a glance.',
            $geo, 'getTreeStatistics',
            [
                'tree_id' => ['type' => 'integer', 'required' => true, 'description' => 'Tree ID'],
            ],
            'Object with total_persons, pct_with_birth_date, pct_with_death_date, pct_with_sources, pct_with_places, generation_breakdown.',
            'genealogy:read', 4, 4000
        );

        $upsert('get_missing_data_report',
            'Get a prioritized report of persons with missing data gaps: no birth date, no death date, no parents, no sources, no place data. Results are ranked by ancestor closeness so you research the most genealogically important gaps first.',
            $geo, 'getMissingDataReport',
            [
                'tree_id' => ['type' => 'integer', 'required' => true,  'description' => 'Tree ID'],
            ],
            'Object with missing_birth[], missing_death[], missing_parents[], missing_sources[], missing_places[] — each entry has person_id, name, bloodline_tier.',
            'genealogy:read', 4, 6000
        );

        // ── Record hints ───────────────────────────────────────────────────

        $upsert('get_research_hints',
            'Get pending record hints for a tree or specific person. Hints are candidate matches found by automated hint generation from supported local/public sources. Each hint has a confidence score and source URL. Use to see what automated systems already flagged for review.',
            $geo, 'getResearchHints',
            [
                'tree_id'   => ['type' => 'integer', 'required' => true,  'description' => 'Tree ID'],
                'person_id' => ['type' => 'integer', 'required' => false, 'description' => 'Filter to one person (optional)'],
                'status'    => ['type' => 'string',  'required' => false, 'description' => 'Filter by status: pending, accepted, rejected, deferred (default: pending)'],
                'limit'     => ['type' => 'integer', 'required' => false, 'description' => 'Max hints to return (default 50)'],
            ],
            'Array of hint objects: id, person_id, person_name, source, source_url, record_type, confidence, status.',
            'genealogy:read', 6, 5000
        );

        $write('update_hint_status',
            'Update the status of a research hint after reviewing it. Statuses: accepted (evidence confirmed and applied), rejected (not a match), deferred (searched, found nothing — try again later). ALWAYS call this after processing a hint — do NOT leave hints in pending status.',
            $geo, 'updateResearchHintStatus',
            [
                'hint_id' => ['type' => 'integer', 'required' => true, 'description' => 'Hint ID from get_research_hints'],
                'status'  => ['type' => 'string',  'required' => true, 'description' => 'New status: accepted, rejected, deferred'],
                'notes'   => ['type' => 'string',  'required' => false, 'description' => 'Optional note explaining the decision (e.g. "Searched LOC/NARA, no records found")'],
            ],
            'Boolean success or error message.',
            15, 2000
        );

        $upsert('generate_record_hints',
            'Generate record hints for a single person by scoring candidate matches from supported local/public databases. Returns hints with confidence scores. Use when get_research_hints returns no pending hints for a person — this actively generates new ones.',
            $rhs, 'generateRecordHints',
            [
                'person_id'      => ['type' => 'integer', 'required' => true,  'description' => 'Person ID to generate hints for'],
                'min_confidence' => ['type' => 'number',  'required' => false, 'description' => 'Minimum confidence threshold (default 0.5)'],
            ],
            'Array of hint objects with confidence scores and source URLs. Hints are also saved to the database for future get_research_hints calls.',
            'genealogy:read', 6, 4000
        );

        $upsert('generate_tree_hints',
            'Generate record hints for an entire tree (batch mode). Processes up to limit persons and saves hints to the database. Use at the start of a research session to refresh the hint pool, especially when get_research_hints returns very few pending hints.',
            $rhs, 'generateTreeRecordHints',
            [
                'tree_id'        => ['type' => 'integer', 'required' => true,  'description' => 'Tree ID'],
                'limit'          => ['type' => 'integer', 'required' => false, 'description' => 'Max persons to process (default 50)'],
                'min_confidence' => ['type' => 'number',  'required' => false, 'description' => 'Minimum confidence (default 0.5)'],
            ],
            'Object: processed, hints_created, hints_updated, skipped.',
            'genealogy:read', 3, 4000
        );

        // ── Research tasks ─────────────────────────────────────────────────

        $write('create_research_task',
            'Create a research task for a person. Tasks are the tracking unit for GPS-documented research (GPS Element 1). Each search should be logged against a task. Creates only if no open task already exists for this person and question — always check get_open_research_tasks first to avoid duplicates.',
            $geo, 'createResearchTask',
            [
                'person_id' => ['type' => 'integer', 'required' => true,  'description' => 'Person ID this task is for'],
                'question'  => ['type' => 'string',  'required' => true,  'description' => 'The specific genealogical question to answer (e.g. "Who were the parents of John Smith born 1845?")'],
                'task_type' => ['type' => 'string',  'required' => false, 'description' => 'Task type: ancestry, vital_records, military, immigration, other (default: other)'],
                'priority'  => ['type' => 'integer', 'required' => false, 'description' => 'Priority 1-5, 1=highest (default 3)'],
            ],
            'Integer task_id if created, or null if a matching open task already exists.',
            10, 2000
        );

        $upsert('get_open_research_tasks',
            'Get all open (unresolved) research tasks for a tree, optionally filtered by person. Returns tasks with their status, person, question, and logged search history. Call in the assess phase to find existing tasks before creating new ones — never duplicate tasks.',
            $rst, 'getOpenTasks',
            [
                'tree_id'   => ['type' => 'integer', 'required' => true,  'description' => 'Tree ID'],
                'person_id' => ['type' => 'integer', 'required' => false, 'description' => 'Filter to one person (optional)'],
            ],
            'Array of task objects: id, person_id, person_name, question, status, task_type, priority, searches_logged, created_at.',
            'genealogy:read', 6, 5000
        );

        $write('log_research_search',
            'Log a completed search against a research task. REQUIRED by GPS (Element 1: exhaustive search documentation). Log EVERY search performed — both positive and negative results. This prevents re-searching the same dead ends in future runs. task_id MUST be a real ID from create_research_task or get_open_research_tasks — NEVER use 0.',
            $rst, 'logSearch',
            [
                'task_id'        => ['type' => 'integer', 'required' => true, 'description' => 'Research task ID from create_research_task or get_open_research_tasks. MUST be a real ID, never 0.'],
                'search_details' => ['type' => 'object',  'required' => true, 'description' => 'Object with: repository_searched (string), search_terms (string), results_summary (string), negative_result (boolean), result_count (integer), result_urls (string[])'],
            ],
            'Integer log entry ID or error.',
            15, 2000
        );

        $upsert('assess_gps_compliance',
            'Assess whether a research task meets Genealogical Proof Standard (GPS) compliance. Checks: exhaustive search (multiple repositories), complete citations, evidence correlation, conflict resolution, and written conclusion. Returns a compliance score and gap analysis with specific recommendations.',
            $rst, 'assessGPSCompliance',
            [
                'task_id' => ['type' => 'integer', 'required' => true, 'description' => 'Research task ID to assess'],
            ],
            'Object: compliance_score (0.0-1.0), gps_elements[] (each with status: pass/fail/partial and recommendation), overall_assessment.',
            'genealogy:read', 6, 4000
        );

        // ── Place authority ────────────────────────────────────────────────

        $upsert('resolve_place',
            'Resolve a raw place name string to a standardized geographic authority record. Normalizes spellings, fills in missing hierarchy (county, state, country), and links to canonical place IDs. Use on every place found in a record before storing it — ensures consistent place data across the tree.',
            $pa, 'findOrCreatePlace',
            [
                'place_string' => ['type' => 'string', 'required' => true, 'description' => 'Raw place string as found in the record (e.g. "Salem, Essex Co., Mass." or "Württemberg, Germany")'],
            ],
            'Integer place_id of the resolved canonical place record.',
            'genealogy:read', 8, 2000
        );

        $upsert('search_places',
            'Search the place authority database for known place names. Useful for finding canonical IDs of places before searching records. Returns matching place records with hierarchy (city, county, state, country) and coordinate information.',
            $pa, 'searchPlaces',
            [
                'query'   => ['type' => 'string',  'required' => true,  'description' => 'Place name query string'],
                'limit'   => ['type' => 'integer', 'required' => false, 'description' => 'Max results (default 20)'],
                'country' => ['type' => 'string',  'required' => false, 'description' => 'Filter by country code (US, DE, IE, etc.)'],
            ],
            'Array of place objects: id, name, county, state, country, latitude, longitude.',
            'genealogy:read', 8, 3000
        );

        // ── Search coverage (GPS Element 1) ────────────────────────────────

        $upsert('get_search_coverage',
            'Get the repository search coverage record for a person — which archives and databases have been searched, when, and with what results. This IS the agent memory for negative evidence. Call before searching any person to see what has already been exhaustively tried. Prevents duplicate searches.',
            $scs, 'getCoverageForPerson',
            [
                'person_id' => ['type' => 'integer', 'required' => true, 'description' => 'Person ID'],
            ],
            'Object with repositories{}: each repository_type has last_searched_at, search_count, positive_count, negative_count, notes.',
            'genealogy:read', 10, 4000
        );

        $write('update_search_coverage',
            'Record that a repository was searched for a person. REQUIRED for GPS Element 1 (exhaustive search documentation). Call after EVERY research tool call — whether positive or negative. This builds the permanent record of what was searched and accumulates over all runs.',
            $scs, 'updateCoverage',
            [
                'person_id'       => ['type' => 'integer', 'required' => true,  'description' => 'Person ID that was researched'],
                'repository_type' => ['type' => 'string',  'required' => true,  'description' => 'Repository category: vital_records, census, military, newspapers, church, immigration, probate, land, cemetery, dna, other'],
                'repository_name' => ['type' => 'string',  'required' => true,  'description' => 'Specific repository name (e.g. "LOC Chronicling America", "NARA", "WikiTree", "Fold3", "Ellis Island")'],
                'positive'        => ['type' => 'boolean', 'required' => true,  'description' => 'true if records were found, false if searched and found nothing'],
                'notes'           => ['type' => 'string',  'required' => false, 'description' => 'Optional note about what was searched and found/not found'],
            ],
            'Object with success boolean and coverage record details.',
            20, 2000
        );

        // ── Source conflicts (GPS Element 4) ───────────────────────────────

        $upsert('detect_source_conflicts',
            'Detect conflicts between sources for a person — e.g. two primary sources disagree on birth year, or a death certificate and a census record give different birthplaces. GPS Element 4 requires ALL conflicts to be resolved before a conclusion is considered proven. Run before raising confidence on any finding.',
            $scs2, 'detectConflictsForPerson',
            [
                'person_id' => ['type' => 'integer', 'required' => true, 'description' => 'Person ID to scan for conflicts'],
            ],
            'Array of conflict objects: id, field (birth_date/death_place/etc.), source_a, value_a, source_b, value_b, severity, status.',
            'genealogy:read', 8, 4000
        );

        $upsert('get_source_conflicts',
            'Get known unresolved source conflicts for a person. Returns conflicts previously detected by detect_source_conflicts. Use to check the conflict backlog before assigning confidence to new findings — unresolved conflicts cap confidence at 0.7 maximum.',
            $scs2, 'getConflictsForPerson',
            [
                'person_id' => ['type' => 'integer', 'required' => true,  'description' => 'Person ID'],
                'status'    => ['type' => 'string',  'required' => false, 'description' => 'Filter: unresolved, resolved, dismissed (default: unresolved)'],
            ],
            'Array of conflict objects with resolution status and notes.',
            'genealogy:read', 8, 4000
        );

        // ── GPS proof argument (GPS Element 5) ────────────────────────────

        $upsert('generate_gps_proof',
            'Generate a GPS-compliant proof argument (GPS Element 5) for a specific genealogical question about a person. Synthesizes all available evidence into a structured conclusion: evidence summary, correlation, conflict resolution, and written conclusion with confidence assessment. Use for brick-wall cases or to formally document a conclusion before proposing a high-confidence change.',
            $gps, 'generateProofArgument',
            [
                'person_id' => ['type' => 'integer', 'required' => true, 'description' => 'Person ID the question is about'],
                'question'  => ['type' => 'string',  'required' => true, 'description' => 'The genealogical question to answer (e.g. "Who were the parents of Johann Eberhardt born circa 1810 in Württemberg?")'],
            ],
            'Object: question, evidence_summary, correlation_analysis, conflicts_resolved, written_conclusion, confidence (0.0-1.0), gps_elements_status.',
            'genealogy:read', 4, 6000
        );

        // ── FAN methodology ────────────────────────────────────────────────

        $write('fan_extract_cooccurrences',
            'Extract co-occurring names from a search result text and store them as FAN (Friends/Associates/Neighbors) data for a person. FAN methodology: people who appeared with an ancestor in multiple records often share a common origin, migration path, or community. Call after any search that returns text mentioning other people.',
            $fan, 'extractFromSearchResult',
            [
                'person_id'          => ['type' => 'integer', 'required' => true,  'description' => 'Person ID the search text is about'],
                'search_result_text' => ['type' => 'string',  'required' => true,  'description' => 'Raw text from a search result containing names (newspaper article, census entry, etc.)'],
                'source_type'        => ['type' => 'string',  'required' => false, 'description' => 'Type of source: census, newspaper, church, military, deed, other (default: other)'],
                'source_ref'         => ['type' => 'string',  'required' => false, 'description' => 'Source citation or URL for the text'],
            ],
            'Object: extracted (total names found), stored (new co-occurrences saved), names[] (list of extracted names).',
            10, 3000
        );

        $upsert('fan_get_cooccurrences',
            'Get the accumulated FAN (Friends/Associates/Neighbors) co-occurrence list for a person. Shows who appears most frequently alongside this ancestor across all indexed records. High-occurrence names are strong candidates for further research — they may be relatives, neighbors, or migration partners.',
            $fan, 'getCooccurrences',
            [
                'person_id'      => ['type' => 'integer', 'required' => true,  'description' => 'Person ID'],
                'min_confidence' => ['type' => 'number',  'required' => false, 'description' => 'Minimum extraction confidence (default 0.5)'],
            ],
            'Array of co-occurrence objects: cooccurring_name, occurrence_count, confidence, source_types[], last_seen.',
            'genealogy:read', 8, 4000
        );

        // ── Graph deduplication ────────────────────────────────────────────

        $upsert('find_graph_duplicates',
            'Find candidate duplicate persons in a tree using graph-anchor method (BYU Wilson 2001): persons with common given names who share rare-surname relatives are strong duplicate candidates. More accurate than name-only matching for colonial-era research where names are highly reused. Run before proposing merges.',
            $gdd, 'findGraphDuplicates',
            [
                'tree_id' => ['type' => 'integer', 'required' => true,  'description' => 'Tree ID to scan for duplicates'],
                'limit'   => ['type' => 'integer', 'required' => false, 'description' => 'Max candidate pairs to return (default 50)'],
            ],
            'Array of duplicate candidate pairs: person_a{id, name}, person_b{id, name}, shared_relatives[], similarity_score.',
            'genealogy:read', 4, 5000
        );

        // ── HTR handwriting transcription ──────────────────────────────────

        $upsert('htr_status',
            'Check if the local TrOCR handwriting transcription (HTR) model is installed and available. Returns installation status and model details. Call before attempting transcribe_handwriting to avoid failures when the model is not yet downloaded.',
            $htr, 'getStatus',
            [],
            'Object: available (boolean), model_name, model_size, device (cpu/cuda), python_available, error (if unavailable).',
            'system:read', 2, 2000
        );

        $upsert('transcribe_handwriting',
            'Transcribe a handwritten image file using the local TrOCR model. Best for handwritten letters, church register entries, will books, deed books, and census returns in image format. Check htr_status first. Returns transcribed text which can then be searched with other tools.',
            $htr, 'transcribe',
            [
                'image_path' => ['type' => 'string', 'required' => true, 'description' => 'Absolute path to the image file (JPG, PNG, TIFF) containing handwriting'],
            ],
            'Object: text (transcribed content), confidence, processing_time_ms, error (if failed).',
            'genealogy:read', 6, 4000
        );

        $upsert('transcribe_media_handwriting',
            'Transcribe a handwritten genealogy media item by its media ID (from genealogy_media table). Automatically locates the file and runs TrOCR. Saves the transcription to the genealogy_media record for future use. Use when the agent has a media_id from a person record.',
            $htr, 'transcribeGenealogyMedia',
            [
                'media_id' => ['type' => 'integer', 'required' => true, 'description' => 'genealogy_media.id of the media item to transcribe'],
            ],
            'Object: media_id, text (transcription), confidence, saved (boolean), error (if failed).',
            'genealogy:read', 6, 4000
        );

        // ── RAG knowledge base ─────────────────────────────────────────────

        $upsert('rag_search',
            'Search the RAG (Retrieval Augmented Generation) knowledge base for documents, research notes, and indexed content. Returns semantically similar content to the query. Use to find previously indexed research findings, transcribed documents, or knowledge about a surname or location.',
            $rag, 'search',
            [
                'query'         => ['type' => 'string',  'required' => true,  'description' => 'Semantic search query (use natural language: "Smith family Pennsylvania 1840s census" not just "Smith")'],
                'limit'         => ['type' => 'integer', 'required' => false, 'description' => 'Max results (default 5)'],
                'document_type' => ['type' => 'string',  'required' => false, 'description' => 'Filter by doc type: genealogy_research, genealogy_finding, document, note'],
            ],
            'Array of result objects: id, title, content_snippet, score, document_type, source_url.',
            'rag:read', 8, 4000
        );

        DB::statement("
            INSERT INTO agent_tool_registry
                (name, description, service_class, method, parameters, returns_description,
                 permissions, risk_level, category, enabled, max_calls_per_run, max_tokens_per_call)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                description         = VALUES(description),
                service_class       = VALUES(service_class),
                method              = VALUES(method),
                parameters          = VALUES(parameters),
                returns_description = VALUES(returns_description),
                enabled             = VALUES(enabled)
        ", [
            'rag_index',
            'Index a research finding or document into the RAG knowledge base for future retrieval. Use at the end of each research session to preserve findings, transcriptions, and conclusions. Indexed content is searchable by all agents via rag_search.',
            $rag, 'indexDocument',
            json_encode([
                'content'       => ['type' => 'string', 'required' => true,  'description' => 'Content to index (research notes, transcribed text, finding summary)'],
                'title'         => ['type' => 'string', 'required' => true,  'description' => 'Document title (e.g. "Research session: John Smith 1845 Pennsylvania")'],
                'document_type' => ['type' => 'string', 'required' => false, 'description' => 'Type: genealogy_research, genealogy_finding, document (default: genealogy_research)'],
            ]),
            'Object with success boolean, document_id, indexed_chunks.',
            json_encode(['rag:write']), 'write', 'genealogy',
            1, 6, 3000,
        ]);

        // ── Repository routing ─────────────────────────────────────────────

        $upsert('get_repositories_for_person',
            'Get a ranked list of genealogy repositories to search for a specific person, based on their birth year, location, and era. Uses a 40+ repository matrix that routes by era × geography to highest-yield sources. ALWAYS call this first before searching — it prevents wasting iterations on low-yield sources.',
            $rrs, 'getRepositoriesForPerson',
            [
                'person_id' => ['type' => 'integer', 'required' => true, 'description' => 'Person ID to get repository recommendations for'],
            ],
            'Array of ranked repositories: name, tool_name, priority, era, region, reason. Call tools in priority order.',
            'genealogy:read', 8, 3000
        );
    }

    public function down(): void
    {
        DB::table('agent_tool_registry')->whereIn('name', [
            'list_persons', 'get_person', 'get_person_full', 'get_person_events',
            'get_person_sources', 'search_persons', 'get_siblings',
            'get_tree_statistics', 'get_missing_data_report',
            'get_research_hints', 'update_hint_status', 'generate_record_hints', 'generate_tree_hints',
            'create_research_task', 'get_open_research_tasks', 'log_research_search', 'assess_gps_compliance',
            'resolve_place', 'search_places',
            'get_search_coverage', 'update_search_coverage',
            'detect_source_conflicts', 'get_source_conflicts',
            'generate_gps_proof',
            'fan_extract_cooccurrences', 'fan_get_cooccurrences',
            'find_graph_duplicates',
            'htr_status', 'transcribe_handwriting', 'transcribe_media_handwriting',
            'rag_search', 'rag_index',
            'get_repositories_for_person',
        ])->delete();
    }
};
